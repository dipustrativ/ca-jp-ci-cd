<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Products;

use IXPF\Syncer\Helper\Logger\ProductUpdateLogger;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Validation\ValidationException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;

/**
 * Stock + MSI source writes for AttributeMassUpdate.
 *
 * "sources" — absolute quantity/status per source (merge: unlisted sources are
 * left alone). Unassigned sources are assigned by the save itself.
 * "stock"   — legacy stock item settings (manage_stock, backorders, sale qty
 * limits). qty / is_in_stock are NOT accepted there: MSI derives them from the
 * default source, so the "sources" block is the only way to set quantity.
 *
 * Source items are saved in one batch so the request costs one sync reindex and
 * one InventoryIndexerPlugin webhook. The save is all-or-nothing, so on failure
 * it is retried per row to pin the error on the row that caused it.
 */
class StockSourceUpdater
{
    /** stock item field => value kind */
    private const STOCK_FIELDS = [
        'manage_stock'            => 'flag',
        'use_config_manage_stock' => 'flag',
        'backorders'              => 'backorders',
        'use_config_backorders'   => 'flag',
        'min_qty'                 => 'qty',
        'use_config_min_qty'      => 'flag',
        'min_sale_qty'            => 'qty',
        'use_config_min_sale_qty' => 'flag',
        'max_sale_qty'            => 'qty',
        'use_config_max_sale_qty' => 'flag',
    ];

    /** @var array<string,bool>|null source_code => enabled */
    private ?array $sources = null;

    public function __construct(
        protected SourceItemInterfaceFactory $sourceItemFactory,
        protected SourceItemsSaveInterface $sourceItemsSave,
        protected IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowed,
        protected StockRegistryInterface $stockRegistry,
        protected ResourceConnection $resourceConnection,
        protected ProductUpdateLogger $logger
    ) {
    }

    /**
     * Validate one row's "sources" block and build its source items.
     *
     * @return array{items: array<string,SourceItemInterface>, invalid: array<int,array{source_code:string,reason:string}>}
     */
    public function buildSourceItems(string $sku, string $typeId, mixed $sources): array
    {
        if (!is_array($sources) || !array_is_list($sources)) {
            return $this->sourceFailure('', '"sources" must be an array of {source_code, quantity, status}');
        }
        if (!$this->isSourceItemManagementAllowed->execute($typeId)) {
            return $this->sourceFailure('', "Product type '{$typeId}' does not support source items");
        }

        $known   = $this->getSources();
        $items   = [];
        $invalid = [];
        foreach ($sources as $entry) {
            $code = is_array($entry) ? trim((string)($entry['source_code'] ?? '')) : '';
            if ($code === '') {
                $invalid[] = ['source_code' => '', 'reason' => 'Missing source_code'];
                continue;
            }
            if (!isset($known[$code])) {
                $invalid[] = ['source_code' => $code, 'reason' => 'Source not found'];
                continue;
            }
            if (!$known[$code]) {
                $invalid[] = ['source_code' => $code, 'reason' => 'Source disabled'];
                continue;
            }

            $qty = $entry['quantity'] ?? null;
            if (!is_numeric($qty) || (float)$qty < 0) {
                $invalid[] = ['source_code' => $code, 'reason' => 'quantity must be a number >= 0'];
                continue;
            }
            $qty = (float)$qty;

            $status = $entry['status'] ?? null;
            if ($status === null) {
                $status = $qty > 0 ? SourceItemInterface::STATUS_IN_STOCK : SourceItemInterface::STATUS_OUT_OF_STOCK;
            } elseif (!in_array($status, [0, 1, '0', '1'], true)) {
                $invalid[] = ['source_code' => $code, 'reason' => 'status must be 0 or 1'];
                continue;
            }

            /** @var SourceItemInterface $item */
            $item = $this->sourceItemFactory->create();
            $item->setSku($sku);
            $item->setSourceCode($code);
            $item->setQuantity($qty);
            $item->setStatus((int)$status);
            $items[$code] = $item; // same source twice in a row: last one wins
        }

        return ['items' => $items, 'invalid' => $invalid];
    }

    /**
     * Save every row's source items in one batch; on failure retry per row.
     *
     * @param array<int,array<string,SourceItemInterface>> $itemsByRow row key => items
     * @return array<int,string> row key => error, for rows that failed to save
     */
    public function saveSourceItems(array $itemsByRow): array
    {
        $itemsByRow = array_filter($itemsByRow);
        if (empty($itemsByRow)) {
            return [];
        }

        $previous = $this->getCurrentSourceItems($itemsByRow);

        try {
            $this->sourceItemsSave->execute(array_merge(...array_map('array_values', array_values($itemsByRow))));
            $this->logSaved($itemsByRow, $previous);
            return [];
        } catch (\Exception $e) {
            $this->logger->writeDaily(
                '[attribute_mass_updated] [SOURCE] Batch save failed, retrying per product: ' . $this->errorMessage($e),
                'error'
            );
        }

        $errors = [];
        foreach ($itemsByRow as $key => $items) {
            try {
                $this->sourceItemsSave->execute(array_values($items));
                $this->logSaved([$key => $items], $previous);
            } catch (\Exception $e) {
                $errors[$key] = 'Save error: ' . $this->errorMessage($e);
            }
        }
        return $errors;
    }

    /**
     * Validate and write one row's "stock" block to the legacy stock item.
     *
     * Setting a value without its use_config_* flag would be ignored by Magento
     * (config wins), so the matching use_config_* is switched off unless the
     * payload sets it explicitly.
     *
     * @return array{updated: string[], invalid: string[]}
     * @throws \Exception on save failure
     */
    public function updateStockItem(string $sku, mixed $stock): array
    {
        if (!is_array($stock) || empty($stock) || array_is_list($stock)) {
            return ['updated' => [], 'invalid' => ['"stock" must be an object of stock item fields']];
        }

        $data    = [];
        $invalid = [];
        foreach ($stock as $field => $value) {
            $kind = self::STOCK_FIELDS[$field] ?? null;
            if ($kind === null) {
                $invalid[] = "{$field}: not allowed";
                continue;
            }
            if (!$this->isValidStockValue($kind, $value)) {
                $invalid[] = "{$field}: invalid value";
                continue;
            }
            $data[$field] = $kind === 'qty' ? (float)$value : (int)$value;
        }

        foreach (array_keys($data) as $field) {
            $flag = 'use_config_' . $field;
            if (isset(self::STOCK_FIELDS[$flag]) && !array_key_exists($flag, $data)) {
                $data[$flag] = 0;
            }
        }

        if (empty($data)) {
            return ['updated' => [], 'invalid' => $invalid];
        }

        $stockItem = $this->stockRegistry->getStockItemBySku($sku);
        foreach ($data as $field => $value) {
            $stockItem->{'set' . str_replace('_', '', ucwords($field, '_'))}($value);
        }
        $this->stockRegistry->updateStockItemBySku($sku, $stockItem);

        $this->logger->writeDaily("[attribute_mass_updated] [STOCK] SKU {$sku}: " . json_encode($data));

        return ['updated' => array_keys($data), 'invalid' => $invalid];
    }

    private function isValidStockValue(string $kind, mixed $value): bool
    {
        return match ($kind) {
            'flag'       => in_array($value, [0, 1, '0', '1', true, false], true),
            'backorders' => in_array($value, [0, 1, 2, '0', '1', '2'], true),
            'qty'        => is_numeric($value) && (float)$value >= 0,
            default      => false,
        };
    }

    /**
     * @return array<string,bool> source_code => enabled
     */
    private function getSources(): array
    {
        if ($this->sources === null) {
            $connection    = $this->resourceConnection->getConnection();
            $select        = $connection->select()->from(
                $this->resourceConnection->getTableName('inventory_source'),
                ['source_code', 'enabled']
            );
            $this->sources = array_map(
                static fn ($enabled) => (bool)$enabled,
                $connection->fetchPairs($select)
            );
        }
        return $this->sources;
    }

    /**
     * @param array<int,array<string,SourceItemInterface>> $itemsByRow
     * @return array<string,array{quantity:float,status:int}> "sku|source" => current values
     */
    private function getCurrentSourceItems(array $itemsByRow): array
    {
        $skus = [];
        foreach ($itemsByRow as $items) {
            foreach ($items as $item) {
                $skus[$item->getSku()] = true;
            }
        }

        $connection = $this->resourceConnection->getConnection();
        $select     = $connection->select()
            ->from($this->resourceConnection->getTableName('inventory_source_item'), ['sku', 'source_code', 'quantity', 'status'])
            ->where('sku IN (?)', array_keys($skus));

        $current = [];
        foreach ($connection->fetchAll($select) as $row) {
            $current[$row['sku'] . '|' . $row['source_code']] = [
                'quantity' => (float)$row['quantity'],
                'status'   => (int)$row['status'],
            ];
        }
        return $current;
    }

    /**
     * @param array<int,array<string,SourceItemInterface>> $itemsByRow
     * @param array<string,array{quantity:float,status:int}> $previous
     */
    private function logSaved(array $itemsByRow, array $previous): void
    {
        foreach ($itemsByRow as $items) {
            foreach ($items as $item) {
                $before = $previous[$item->getSku() . '|' . $item->getSourceCode()] ?? null;
                $from   = $before === null ? 'unassigned' : "qty {$before['quantity']}, status {$before['status']}";
                $this->logger->writeDaily(sprintf(
                    '[attribute_mass_updated] [SOURCE] SKU %s source %s: %s -> qty %s, status %d',
                    $item->getSku(),
                    $item->getSourceCode(),
                    $from,
                    $item->getQuantity(),
                    $item->getStatus()
                ));
            }
        }
    }

    private function errorMessage(\Exception $e): string
    {
        if ($e instanceof ValidationException && !empty($e->getErrors())) {
            return implode('; ', array_map(static fn ($error) => (string)$error->getMessage(), $e->getErrors()));
        }
        return $e->getMessage();
    }

    /**
     * @return array{items: array, invalid: array<int,array{source_code:string,reason:string}>}
     */
    private function sourceFailure(string $code, string $reason): array
    {
        return ['items' => [], 'invalid' => [['source_code' => $code, 'reason' => $reason]]];
    }
}
