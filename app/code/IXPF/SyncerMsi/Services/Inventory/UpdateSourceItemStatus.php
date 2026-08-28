<?php

declare(strict_types=1);

namespace IXPF\SyncerMsi\Services\Inventory;

use Exception;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;

class UpdateSourceItemStatus
{
    public function __construct(
        protected SourceRepositoryInterface   $sourceRepository,
        protected SourceItemInterfaceFactory  $sourceItemFactory,
        protected SourceItemsSaveInterface    $sourceItemsSave,
        protected ResourceConnection          $resourceConnection
    ) {
    }

    /**
     * Update the source-item status for SKUs already linked to a source.
     * This route does NOT create links — a SKU without an existing source item
     * is reported in `failed`. Existing qty is preserved.
     *
     * @param string $sourceCode
     * @param int $status One of SourceItemInterface::STATUS_*
     * @param array $skus
     * @return array
     * @throws NoSuchEntityException when the source does not exist
     */
    public function update(string $sourceCode, int $status, array $skus): array
    {
        // Validate source exists (throws NoSuchEntityException -> handled by controller)
        $this->sourceRepository->get($sourceCode);

        $skus = array_values(array_unique(array_filter(array_map('trim', $skus), 'strlen')));

        $updated = [];
        $failed  = [];

        if (empty($skus)) {
            return $this->buildResult($sourceCode, $status, $updated, $failed);
        }

        // Only SKUs that already have a source item on this source can be updated
        $linkedQtyMap = $this->getLinkedSkuQty($sourceCode, $skus);

        $itemsToSave = [];
        foreach ($skus as $sku) {
            if (!array_key_exists($sku, $linkedQtyMap)) {
                $failed[] = ['sku' => $sku, 'reason' => 'Source item does not exist for this SKU on the source'];
                continue;
            }

            /** @var SourceItemInterface $item */
            $item = $this->sourceItemFactory->create();
            $item->setSourceCode($sourceCode);
            $item->setSku($sku);
            $item->setStatus($status);
            // Preserve existing qty — only status changes
            $item->setQuantity((float)$linkedQtyMap[$sku]);

            $itemsToSave[$sku] = $item;
        }

        if (!empty($itemsToSave)) {
            try {
                $this->sourceItemsSave->execute(array_values($itemsToSave));
                $updated = array_keys($itemsToSave);
            } catch (Exception $e) {
                foreach (array_keys($itemsToSave) as $sku) {
                    $failed[] = ['sku' => $sku, 'reason' => 'Save error: ' . $e->getMessage()];
                }
            }
        }

        return $this->buildResult($sourceCode, $status, $updated, $failed);
    }

    /**
     * Existing source-item qty for these SKUs on the source. keyed sku => qty.
     *
     * @param string $sourceCode
     * @param array $skus
     * @return array
     */
    private function getLinkedSkuQty(string $sourceCode, array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('inventory_source_item');
        $select = $connection->select()
            ->from(['isi' => $table], ['sku', 'quantity'])
            ->where('isi.source_code = ?', $sourceCode)
            ->where('isi.sku IN (?)', $skus);

        return $connection->fetchPairs($select);
    }

    /**
     * @param string $sourceCode
     * @param int $status
     * @param array $updated
     * @param array $failed
     * @return array
     */
    private function buildResult(string $sourceCode, int $status, array $updated, array $failed): array
    {
        return [
            'source_code'        => $sourceCode,
            'source_item_status' => $status,
            'summary' => [
                'total'   => count($updated) + count($failed),
                'updated' => count($updated),
                'failed'  => count($failed),
            ],
            'updated' => $updated,
            'failed'  => $failed,
        ];
    }
}
