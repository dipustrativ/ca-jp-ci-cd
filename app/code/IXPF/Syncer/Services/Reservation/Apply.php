<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Reservation;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Helper\Logger\ReservationLogger;
use IXPF\Syncer\Helper\Webhook;
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
 *
 * When the SKU is not assigned to the source IXPF names, the assignment is
 * created at qty 0 and the adjustment applied on top — see
 * ixpfng_reservation/general/auto_assign_source. The source itself is never
 * created: a warehouse that does not exist in inventory_source is a
 * configuration gap, not something an adjustment may invent.
 */
class Apply
{
    public function __construct(
        private readonly SourceRepositoryInterface $sourceRepository,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly SourceItemsSaveInterface $sourceItemsSave,
        private readonly ResourceConnection $resourceConnection,
        private readonly ReservationResource $reservationResource,
        private readonly Config $config,
        private readonly Webhook $webhook,
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
            $autoAssigned = false;

            if ($current === null) {
                if (!$this->config->isReservationAutoAssignSourceEnabled()) {
                    $failed[] = [
                        'sku' => $sku,
                        'source' => $source,
                        'reason' => "SKU not assigned to source '$source'",
                        'entity_id' => $entry['entity_id'],
                    ];
                    continue;
                }

                // A source item can only be created for a product that exists.
                if (!$this->skuExists($sku)) {
                    $failed[] = [
                        'sku' => $sku,
                        'source' => $source,
                        'reason' => "SKU not assigned to source '$source' and not found in the catalog,"
                            . ' so the source could not be assigned automatically',
                        'entity_id' => $entry['entity_id'],
                    ];
                    continue;
                }

                // Treated as an assignment at zero, so the negative guard below
                // still applies: IXPF asking us to take stock off a source the
                // SKU never had is a divergence, not a new assignment.
                $current = [
                    'quantity' => '0',
                    'status' => (string)SourceItemInterface::STATUS_IN_STOCK,
                ];
                $autoAssigned = true;
            }

            $before = (float)$current['quantity'];
            $after = $before + $qty;

            // Reject rather than clamp. A negative physical quantity is a data bug,
            // and silently flooring it at zero would hide the divergence between
            // Magento and IXPF instead of surfacing it.
            if ($after < 0) {
                $reason = sprintf(
                    'Adjustment would drive quantity negative (%s %+.4f = %s)',
                    $before,
                    $qty,
                    $after
                );

                // Nothing was written, so say the assignment did not happen
                // either — otherwise the row reads as if it had.
                if ($autoAssigned) {
                    $reason = sprintf(
                        "SKU %s is not stocked at source '%s' and IXPF asked to remove %s from it."
                        . ' The source was NOT assigned and no quantity was changed. %s',
                        $sku,
                        $source,
                        rtrim(rtrim(number_format(abs($qty), 4, '.', ''), '0'), '.'),
                        $reason
                    );
                }

                $failed[] = [
                    'sku' => $sku,
                    'source' => $source,
                    'reason' => $reason,
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
                'source_assigned' => $autoAssigned,
                // Written once the numbers are known, so the row says what the
                // quantity became rather than only what was done to it.
                'note' => $autoAssigned ? $this->assignmentNote($sku, $source, $qty, $after) : null,
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
                    $reason = 'Save error: ' . $e->getMessage();
                    if ($item['source_assigned']) {
                        $reason = sprintf(
                            'SKU %s was not stocked at source %s and adding it failed,'
                            . ' so the source is still not assigned. %s',
                            $item['sku'],
                            $item['source'],
                            $reason
                        );
                    }
                    $failed[] = [
                        'sku' => $item['sku'],
                        'source' => $item['source'],
                        'reason' => $reason,
                        'entity_id' => $item['entity_id'],
                    ];
                }
            }
        }

        foreach ($applied as $item) {
            if ($item['source_assigned']) {
                $this->logger->writeDaily('Inbound reservation auto-assigned source: ' . $item['note']);
            }
        }

        // Outside the save try/catch on purpose: a webhook bookkeeping failure
        // must not turn lines that were written into reported failures.
        $this->queueInventoryWebhook($applied);

        $this->recordOutcome($applied, $failed);

        return [
            'summary' => [
                'total' => count($applied) + count($failed),
                'applied' => count($applied),
                'failed' => count($failed),
            ],
            'applied' => $this->shapeForResponse($applied),
            'failed' => $this->shapeForResponse($failed),
        ];
    }

    /**
     * Queue an `inventory` webhook job per product whose stock this batch moved.
     *
     * The mview route already reaches the same place — the DB triggers on
     * inventory_source_item fill inventory_cl, cron reindexes, and
     * SyncerMsi's InventoryIndexerPlugin calls updateOrCreateJob('inventory')
     * — but only on the next cron tick, and only while the indexer stays on
     * Update by Schedule. Writing the row here makes the job exist the moment
     * the adjustment lands. `type` + `object_id` is unique, so the two routes
     * upsert the same row rather than queueing the product twice.
     *
     * @param array<int, array<string, mixed>> $applied
     */
    private function queueInventoryWebhook(array $applied): void
    {
        if (!$applied) {
            return;
        }

        try {
            $productIds = $this->getProductIds(array_unique(array_column($applied, 'sku')));

            if ($productIds) {
                $this->webhook->updateOrCreateJob('inventory', $productIds);
            }
        } catch (Throwable $e) {
            $this->logger->writeExceptionDaily($e, 'Reservation apply webhook queue exception');
        }
    }

    /**
     * @param array<int, string> $skus
     * @return array<int, int>
     */
    private function getProductIds(array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['entity_id'])
            ->where('sku IN (?)', $skus);

        return array_map('intval', $connection->fetchCol($select));
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
     * Drop bookkeeping that is not part of the contract with IXPF.
     *
     * `entity_id` never goes out. `source_assigned` and `note` go out only on
     * the lines they happened to, so an ordinary line keeps the shape it has
     * always had.
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function shapeForResponse(array $items): array
    {
        return array_map(
            static function (array $item): array {
                unset($item['entity_id']);

                if (!($item['source_assigned'] ?? false)) {
                    unset($item['source_assigned'], $item['note']);
                }

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
     * What the row says when Magento had to create the sku-source link itself.
     *
     * Spelled out rather than abbreviated: whoever reads this row months later
     * is asking "where did this stock come from", and the answer is that IXPF
     * named a warehouse Magento had never linked this SKU to.
     */
    private function assignmentNote(string $sku, string $source, float $qty, float $after): string
    {
        return sprintf(
            'SKU %s was not stocked at source %s (no source item existed).'
            . ' IXPF sent a stock update for %s, so Magento added %s to that source'
            . ' and set its quantity to %s. Requested by IXPF, not entered by an admin.',
            $sku,
            $source,
            $source,
            $sku,
            $this->formatQty($after)
        );
    }

    /**
     * Quantities read as 1 and 2.5, not 1.0000 — these strings are read by people.
     */
    private function formatQty(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }

    /**
     * Does the catalogue hold this SKU?
     *
     * Only consulted before creating a source item. MSI will happily store a
     * source item for a SKU that does not exist — an orphan row no stock index
     * will ever read — so an unknown SKU is rejected instead.
     */
    private function skuExists(string $sku): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName('catalog_product_entity'), ['sku'])
            ->where('sku = ?', $sku)
            ->limit(1);

        return (bool)$connection->fetchOne($select);
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
                // Empty for an ordinary line; an auto-assigned source says so,
                // because the row is the only record that the assignment was
                // Magento's doing and not part of the catalogue already.
                'information' => $item['note'] ?? null,
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
