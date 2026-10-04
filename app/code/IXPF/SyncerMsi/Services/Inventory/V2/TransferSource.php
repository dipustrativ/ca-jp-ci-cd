<?php

declare(strict_types=1);

namespace IXPF\SyncerMsi\Services\Inventory\V2;

use Exception;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;

class TransferSource
{
    /**
     * Max product lines accepted in one request.
     */
    public const MAX_PRODUCTS = 100;

    private const QTY_PRECISION = 4;

    public function __construct(
        protected SourceRepositoryInterface   $sourceRepository,
        protected SourceItemInterfaceFactory  $sourceItemFactory,
        protected SourceItemsSaveInterface    $sourceItemsSave,
        protected ResourceConnection          $resourceConnection
    ) {
    }

    /**
     * Move a given qty of each SKU from one source to another, per line.
     * Destination qty += qty; from-source qty -= qty. Destination link is created
     * when missing. Lines are applied in order, so a SKU sent twice (or chained
     * A → B → C) is moved against the running quantity, not the stored one.
     *
     * @param array $lines each ['from_source' => string, 'to_source' => string, 'sku' => string, 'qty' => numeric]
     * @return array
     */
    public function transfer(array $lines): array
    {
        $transferred = [];
        $failed      = [];

        // 1. Shape check per line
        $valid = [];
        foreach (array_values($lines) as $index => $line) {
            $error = $this->validateLine($line);
            if ($error !== null) {
                $failed[] = $this->failure($index, is_array($line) ? $line : [], $error);
                continue;
            }
            $valid[$index] = [
                'from_source' => trim((string)$line['from_source']),
                'to_source'   => trim((string)$line['to_source']),
                'sku'         => trim((string)$line['sku']),
                'qty'         => round((float)$line['qty'], self::QTY_PRECISION),
            ];
        }

        // 2. Resolve every source once, to the code as stored (lookup ignores case)
        $sourceCodes = [];
        foreach ($valid as $line) {
            $sourceCodes[$line['from_source']] = true;
            $sourceCodes[$line['to_source']]   = true;
        }
        $resolved = $this->resolveSources(array_keys($sourceCodes));

        $ready = [];
        foreach ($valid as $index => $line) {
            $from = $resolved[$line['from_source']] ?? null;
            $to   = $resolved[$line['to_source']] ?? null;

            if ($from === null) {
                $failed[] = $this->failure($index, $line, sprintf("from_source '%s' not found", $line['from_source']));
                continue;
            }
            if ($to === null) {
                $failed[] = $this->failure($index, $line, sprintf("to_source '%s' not found", $line['to_source']));
                continue;
            }
            // "hamburg" and "Hamburg" are one source item row — moving onto itself corrupts qty.
            if ($from === $to) {
                $failed[] = $this->failure($index, $line, 'from_source and to_source must be different');
                continue;
            }

            $line['from_source'] = $from;
            $line['to_source']   = $to;
            $ready[$index] = $line;
        }

        if (empty($ready)) {
            return $this->buildResult($transferred, $failed);
        }

        // 3. Load current source items in one query, then apply lines against running state
        $state = $this->getSourceItems(
            array_values(array_unique(array_merge(
                array_column($ready, 'from_source'),
                array_column($ready, 'to_source')
            ))),
            array_values(array_unique(array_column($ready, 'sku')))
        );

        $touched = [];
        $moved   = [];
        foreach ($ready as $index => $line) {
            $fromKey = $this->key($line['from_source'], $line['sku']);
            $toKey   = $this->key($line['to_source'], $line['sku']);

            if (!isset($state[$fromKey])) {
                $failed[] = $this->failure($index, $line, 'SKU not assigned to from source');
                continue;
            }

            $available = (float)$state[$fromKey]['quantity'];
            if ($line['qty'] > $available) {
                $failed[] = $this->failure($index, $line, sprintf(
                    'Insufficient quantity on from source (available %s, requested %s)',
                    $available + 0,
                    $line['qty'] + 0
                ));
                continue;
            }

            if (!isset($state[$toKey])) {
                $state[$toKey] = [
                    'sku'         => $state[$fromKey]['sku'],
                    'source_code' => $line['to_source'],
                    'quantity'    => 0.0,
                    'status'      => SourceItemInterface::STATUS_IN_STOCK,
                ];
            }

            $state[$fromKey]['quantity'] = round($available - $line['qty'], self::QTY_PRECISION);
            $state[$toKey]['quantity']   = round(
                (float)$state[$toKey]['quantity'] + $line['qty'],
                self::QTY_PRECISION
            );

            $touched[$fromKey] = true;
            $touched[$toKey]   = true;
            $moved[$index] = [
                'index'       => $index,
                'sku'         => $state[$fromKey]['sku'],
                'from_source' => $line['from_source'],
                'to_source'   => $line['to_source'],
                'quantity'    => $line['qty'],
                'from_after'  => $state[$fromKey]['quantity'],
                'to_after'    => $state[$toKey]['quantity'],
            ];
        }

        // 4. Save final qty of every touched row in one batch (status preserved)
        if (!empty($touched)) {
            $itemsToSave = [];
            foreach (array_keys($touched) as $key) {
                $item = $this->sourceItemFactory->create();
                $item->setSourceCode($state[$key]['source_code']);
                $item->setSku($state[$key]['sku']);
                $item->setQuantity($state[$key]['quantity']);
                $item->setStatus((int)$state[$key]['status']);
                $itemsToSave[] = $item;
            }

            try {
                $this->sourceItemsSave->execute($itemsToSave);
                $transferred = array_values($moved);
            } catch (Exception $e) {
                foreach ($moved as $index => $info) {
                    $failed[] = $this->failure($index, $info, 'Save error: ' . $e->getMessage());
                }
            }
        }

        usort($failed, static fn(array $a, array $b) => $a['index'] <=> $b['index']);

        return $this->buildResult($transferred, $failed);
    }

    /**
     * @param mixed $line
     * @return string|null error message, null when valid
     */
    private function validateLine($line): ?string
    {
        if (!is_array($line)) {
            return 'Product line must be an object';
        }
        foreach (['from_source', 'to_source', 'sku'] as $field) {
            if (!isset($line[$field]) || !is_scalar($line[$field]) || trim((string)$line[$field]) === '') {
                return sprintf('%s is required', $field);
            }
        }
        if (!isset($line['qty']) || !is_numeric($line['qty'])) {
            return 'qty is required and must be numeric';
        }
        if (round((float)$line['qty'], self::QTY_PRECISION) <= 0) {
            return 'qty must be greater than 0';
        }
        return null;
    }

    /**
     * Request code => stored code. Unknown codes are left out.
     *
     * @param array $codes
     * @return array
     */
    private function resolveSources(array $codes): array
    {
        $map = [];
        foreach ($codes as $code) {
            try {
                $map[$code] = (string)$this->sourceRepository->get($code)->getSourceCode();
            } catch (NoSuchEntityException $e) {
                continue;
            }
        }
        return $map;
    }

    /**
     * Source items for these SKUs on these sources, keyed by key().
     *
     * @param array $sourceCodes
     * @param array $skus
     * @return array
     */
    private function getSourceItems(array $sourceCodes, array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('inventory_source_item');
        $select = $connection->select()
            ->from(['isi' => $table], ['source_code', 'sku', 'quantity', 'status'])
            ->where('isi.source_code IN (?)', $sourceCodes)
            ->where('isi.sku IN (?)', $skus);

        $map = [];
        foreach ($connection->fetchAll($select) as $row) {
            $map[$this->key((string)$row['source_code'], (string)$row['sku'])] = [
                'sku'         => (string)$row['sku'],
                'source_code' => (string)$row['source_code'],
                'quantity'    => (float)$row['quantity'],
                'status'      => (int)$row['status'],
            ];
        }
        return $map;
    }

    /**
     * MySQL matches source code and SKU without regard to case, so the key does too.
     */
    private function key(string $sourceCode, string $sku): string
    {
        return mb_strtolower($sourceCode) . "\0" . mb_strtolower($sku);
    }

    private function failure(int $index, array $line, string $reason): array
    {
        return [
            'index'       => $index,
            'sku'         => is_scalar($line['sku'] ?? null) ? (string)$line['sku'] : null,
            'from_source' => is_scalar($line['from_source'] ?? null) ? (string)$line['from_source'] : null,
            'to_source'   => is_scalar($line['to_source'] ?? null) ? (string)$line['to_source'] : null,
            'reason'      => $reason,
        ];
    }

    private function buildResult(array $transferred, array $failed): array
    {
        return [
            'summary' => [
                'total'       => count($transferred) + count($failed),
                'transferred' => count($transferred),
                'failed'      => count($failed),
            ],
            'transferred' => $transferred,
            'failed'      => $failed,
        ];
    }
}
