<?php

declare(strict_types=1);

namespace IXPF\SyncerMsi\Services\Inventory;

use Exception;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsDeleteInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;

class UnassignSource
{
    public function __construct(
        protected SourceRepositoryInterface   $sourceRepository,
        protected SourceItemInterfaceFactory  $sourceItemFactory,
        protected SourceItemsDeleteInterface  $sourceItemsDelete,
        protected ResourceConnection          $resourceConnection
    ) {
    }

    /**
     * Remove the source-item link of each SKU from a source.
     * A SKU without an existing source item is reported in `failed`.
     *
     * @param string $sourceCode
     * @param array $skus
     * @return array
     * @throws NoSuchEntityException when the source does not exist
     */
    public function unassign(string $sourceCode, array $skus): array
    {
        // Validate source exists — explicit message
        try {
            $this->sourceRepository->get($sourceCode);
        } catch (NoSuchEntityException $e) {
            throw new NoSuchEntityException(__("source_code '%1' not found", $sourceCode));
        }

        $skus = array_values(array_unique(array_filter(array_map('trim', $skus), 'strlen')));

        $removed = [];
        $failed  = [];

        if (empty($skus)) {
            return $this->buildResult($sourceCode, $removed, $failed);
        }

        $linkedSkus = $this->getLinkedSkus($sourceCode, $skus);

        $itemsToDelete = [];
        foreach ($skus as $sku) {
            if (!isset($linkedSkus[$sku])) {
                $failed[] = ['sku' => $sku, 'reason' => 'Source item does not exist for this SKU on the source'];
                continue;
            }

            /** @var SourceItemInterface $item */
            $item = $this->sourceItemFactory->create();
            $item->setSourceCode($sourceCode);
            $item->setSku($sku);
            $itemsToDelete[$sku] = $item;
        }

        if (!empty($itemsToDelete)) {
            try {
                $this->sourceItemsDelete->execute(array_values($itemsToDelete));
                $removed = array_keys($itemsToDelete);
            } catch (Exception $e) {
                foreach (array_keys($itemsToDelete) as $sku) {
                    $failed[] = ['sku' => $sku, 'reason' => 'Delete error: ' . $e->getMessage()];
                }
            }
        }

        return $this->buildResult($sourceCode, $removed, $failed);
    }

    /**
     * SKUs that have a source item on this source. keyed sku => true.
     *
     * @param string $sourceCode
     * @param array $skus
     * @return array
     */
    private function getLinkedSkus(string $sourceCode, array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('inventory_source_item');
        $select = $connection->select()
            ->from(['isi' => $table], ['sku'])
            ->where('isi.source_code = ?', $sourceCode)
            ->where('isi.sku IN (?)', $skus);

        return array_flip($connection->fetchCol($select));
    }

    /**
     * @param string $sourceCode
     * @param array $removed
     * @param array $failed
     * @return array
     */
    private function buildResult(string $sourceCode, array $removed, array $failed): array
    {
        return [
            'source_code' => $sourceCode,
            'summary' => [
                'total'   => count($removed) + count($failed),
                'removed' => count($removed),
                'failed'  => count($failed),
            ],
            'removed' => $removed,
            'failed'  => $failed,
        ];
    }
}
