<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Reservation;

use IXPF\Syncer\Helper\Logger\ReservationLogger;
use IXPF\Syncer\Model\IxpfReservation;
use IXPF\Syncer\Model\ResourceModel\IxpfReservation as ReservationResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Throwable;

/**
 * Applies an IXPF-pushed quantity adjustment to MSI source items.
 *
 * This endpoint is DELTA-ADJUST: the supplied qty is added to the current source
 * item quantity. Note the two other conventions already in the codebase —
 * SyncerMsi TransferSource writes absolute totals, AssignSource writes 0.
 *
 * No reindex call is needed. cataloginventory/indexer/strategy defaults to
 * `sync`, so SourceItemsSaveInterface::execute() runs
 * ReindexAfterSourceItemsSavePlugin -> SourceItemIndexer -> Strategy\Sync, which
 * rebuilds the stock index directly and bypasses mview. Salable quantity is
 * correct by the time this method returns.
 */
class Apply
{
    public function __construct(
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly SourceItemsSaveInterface $sourceItemsSave,
        private readonly ResourceConnection $resourceConnection,
        private readonly ReservationResource $reservationResource,
        private readonly ReservationLogger $logger
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $products Raw payload entries
     * @return array{summary: array<string, int>, applied: array<int, array<string, mixed>>, failed: array<int, array<string, mixed>>}
     */
    public function execute(array $products, string $batchId): array
    {
        $applied = [];
        $failed = [];
        $itemsToSave = [];
        $pending = [];

        // Rows were written before any validation ran; pair each payload entry
        // back to its row so outcomes are recorded line by line.
        $rowIds = $this->mapRowIds($batchId);

        $normalised = $this->normalise($products, $failed, $rowIds);

        foreach ($normalised as $entry) {
            $sku = $entry['sku'];
            $source = $entry['source'];
            $qty = $entry['qty'];

            if (!$this->sourceExists($source)) {
                $failed[] = [
                    'sku' => $sku,
                    'source' => $source,
                    'reason' => "Source '$source' not found",
                    'entity_id' => $entry['entity_id'],
                ];
                continue;
            }

            $current = $this->getSourceItem($source, $sku);
            if ($current === null) {
                $failed[] = [
                    'sku' => $sku,
                    'source' => $source,
                    'reason' => "SKU not assigned to source '$source'",
                    'entity_id' => $entry['entity_id'],
                ];
                continue;
            }

            $before = (float)$current['quantity'];
            $after = $before + $qty;

            // Reject rather than clamp. A negative physical quantity is a data bug,
            // and silently flooring it at zero would hide the divergence between
            // Magento and IXPF instead of surfacing it.
            if ($after < 0) {
                $failed[] = [
                    'sku' => $sku,
                    'source' => $source,
                    'reason' => sprintf(
                        'Adjustment would drive quantity negative (%s %+.4f = %s)',
                        $before,
                        $qty,
                        $after
                    ),
                    'entity_id' => $entry['entity_id'],
                ];
                continue;
            }

            $sourceItem = $this->sourceItemFactory->create();
            $sourceItem->setSourceCode($source);
            $sourceItem->setSku($sku);
            $sourceItem->setQuantity($after);
            $sourceItem->setStatus((int)$current['status']);

            $itemsToSave[] = $sourceItem;
            $pending[] = [
                'sku' => $sku,
                'source' => $source,
                'qty' => $qty,
                'qty_before' => $before,
                'qty_after' => $after,
                'entity_id' => $entry['entity_id'],
            ];
        }

        if ($itemsToSave) {
            try {
                $this->sourceItemsSave->execute($itemsToSave);
                $applied = $pending;
            } catch (Throwable $e) {
                // One save call covers the whole batch, so a throw means none of
                // these landed — every pending line becomes a failure.
                $this->logger->writeExceptionDaily($e, 'Reservation apply save exception');
                foreach ($pending as $item) {
                    $failed[] = [
                        'sku' => $item['sku'],
                        'source' => $item['source'],
                        'reason' => 'Save error: ' . $e->getMessage(),
                        'entity_id' => $item['entity_id'],
                    ];
                }
            }
        }

        $this->recordOutcome($applied, $failed);

        return [
            'summary' => [
                'total' => count($applied) + count($failed),
                'applied' => count($applied),
                'failed' => count($failed),
            ],
            // entity_id is bookkeeping, not part of the contract with IXPF.
            'applied' => $this->stripRowIds($applied),
            'failed' => $this->stripRowIds($failed),
        ];
    }

    /**
     * Row ids of the batch, in insertion order.
     *
     * @return array<int, int>
     */
    private function mapRowIds(string $batchId): array
    {
        $ids = [];
        foreach ($this->reservationResource->getBatch($batchId) as $row) {
            $ids[] = (int)$row['entity_id'];
        }

        return $ids;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function stripRowIds(array $items): array
    {
        return array_map(
            static function (array $item): array {
                unset($item['entity_id']);
                return $item;
            },
            $items
        );
    }

    /**
     * Validate payload shape, collecting malformed entries as failures.
     *
     * Entries are paired with their recorded row by position: the controller
     * writes rows straight from the same products array, skipping the same
     * non-array entries, so index N here is row N there.
     *
     * @param array<int, mixed> $products
     * @param array<int, array<string, mixed>> $failed
     * @param array<int, int> $rowIds
     * @return array<int, array{sku: string, source: string, qty: float, entity_id: int|null}>
     */
    private function normalise(array $products, array &$failed, array $rowIds): array
    {
        $normalised = [];
        $rowIndex = 0;

        foreach ($products as $product) {
            if (!is_array($product)) {
                $failed[] = [
                    'sku' => '',
                    'source' => '',
                    'reason' => 'Malformed product entry',
                    'entity_id' => null,
                ];
                continue;
            }

            $entityId = $rowIds[$rowIndex] ?? null;
            $rowIndex++;

            $sku = trim((string)($product['sku'] ?? ''));
            $source = trim((string)($product['source'] ?? ''));
            $qty = (float)($product['qty'] ?? 0);

            if ($sku === '' || $source === '') {
                $failed[] = [
                    'sku' => $sku,
                    'source' => $source,
                    'reason' => 'sku and source are required',
                    'entity_id' => $entityId,
                ];
                continue;
            }

            if ($qty == 0.0) {
                $failed[] = [
                    'sku' => $sku,
                    'source' => $source,
                    'reason' => 'qty must not be zero',
                    'entity_id' => $entityId,
                ];
                continue;
            }

            $normalised[] = [
                'sku' => $sku,
                'source' => $source,
                'qty' => $qty,
                'entity_id' => $entityId,
            ];
        }

        return $normalised;
    }

    private function sourceExists(string $sourceCode): bool
    {
        try {
            $source = $this->sourceRepository->get($sourceCode);
        } catch (NoSuchEntityException) {
            return false;
        }

        return (bool)$source->isEnabled();
    }

    /**
     * @return array{quantity: string, status: string}|null
     */
    private function getSourceItem(string $sourceCode, string $sku): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['isi' => $this->resourceConnection->getTableName('inventory_source_item')],
                ['quantity', 'status']
            )
            ->where('isi.source_code = ?', $sourceCode)
            ->where('isi.sku = ?', $sku);

        $row = $connection->fetchRow($select);

        return $row ?: null;
    }

    /**
     * Mark each recorded row with what actually happened to that line.
     *
     * Per line, not per batch: one bad entry does not stop the others, so a
     * batch is routinely part applied and part failed. Marking the whole batch
     * `applied` would leave rows claiming a write that never happened.
     *
     * @param array<int, array<string, mixed>> $applied
     * @param array<int, array<string, mixed>> $failed
     */
    private function recordOutcome(array $applied, array $failed): void
    {
        $outcomes = [];

        foreach ($applied as $item) {
            if ($item['entity_id'] === null) {
                continue;
            }
            $outcomes[] = [
                'entity_id' => (int)$item['entity_id'],
                'status' => IxpfReservation::REQUEST_APPLIED,
                'information' => null,
            ];
        }

        foreach ($failed as $item) {
            if (($item['entity_id'] ?? null) === null) {
                continue;
            }
            $outcomes[] = [
                'entity_id' => (int)$item['entity_id'],
                'status' => IxpfReservation::REQUEST_FAILED,
                'information' => (string)($item['reason'] ?? ''),
            ];
        }

        $this->reservationResource->markLines($outcomes);
    }
}
