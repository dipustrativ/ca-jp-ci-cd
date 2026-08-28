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

class AssignSource
{
    public function __construct(
        protected SourceRepositoryInterface   $sourceRepository,
        protected SourceItemInterfaceFactory  $sourceItemFactory,
        protected SourceItemsSaveInterface    $sourceItemsSave,
        protected ResourceConnection          $resourceConnection
    ) {
    }

    /**
     * Assign an MSI source to a list of product SKUs.
     *
     * @param string $sourceCode
     * @param array $skus
     * @return array
     * @throws NoSuchEntityException when the source does not exist
     */
    public function assign(string $sourceCode, array $skus): array
    {
        // Validate source exists (throws NoSuchEntityException -> handled by controller)
        $this->sourceRepository->get($sourceCode);

        // Normalise + dedupe requested SKUs
        $skus = array_values(array_unique(array_filter(array_map('trim', $skus), 'strlen')));

        $added   = [];
        $skipped = [];
        $failed  = [];

        if (empty($skus)) {
            return $this->buildResult($sourceCode, $added, $skipped, $failed);
        }

        // Which SKUs actually exist (single query — no N+1)
        $existingSkus = $this->getExistingSkus($skus);

        // Which SKUs already linked to this source (single query)
        $alreadyLinked = $this->getLinkedSkus($sourceCode, $skus);

        $itemsToSave = [];
        foreach ($skus as $sku) {
            if (!isset($existingSkus[$sku])) {
                $failed[] = ['sku' => $sku, 'reason' => 'SKU not found in catalog'];
                continue;
            }
            if (isset($alreadyLinked[$sku])) {
                $skipped[] = ['sku' => $sku, 'reason' => 'Already assigned to source'];
                continue;
            }

            /** @var SourceItemInterface $item */
            $item = $this->sourceItemFactory->create();
            $item->setSourceCode($sourceCode);
            $item->setSku($sku);
            $item->setQuantity(0);
            $item->setStatus(SourceItemInterface::STATUS_IN_STOCK);
            $itemsToSave[$sku] = $item;
        }

        if (!empty($itemsToSave)) {
            try {
                $this->sourceItemsSave->execute(array_values($itemsToSave));
                $added = array_keys($itemsToSave);
            } catch (Exception $e) {
                // Save is all-or-nothing — report every attempted SKU as failed
                foreach (array_keys($itemsToSave) as $sku) {
                    $failed[] = ['sku' => $sku, 'reason' => 'Save error: ' . $e->getMessage()];
                }
            }
        }

        return $this->buildResult($sourceCode, $added, $skipped, $failed);
    }

    /**
     * @param array $skus
     * @return array keyed by sku
     */
    private function getExistingSkus(array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('catalog_product_entity');
        $select = $connection->select()
            ->from(['cpe' => $table], ['sku'])
            ->where('cpe.sku IN (?)', $skus);

        return array_flip($connection->fetchCol($select));
    }

    /**
     * @param string $sourceCode
     * @param array $skus
     * @return array keyed by sku
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
     * @param array $added
     * @param array $skipped
     * @param array $failed
     * @return array
     */
    private function buildResult(string $sourceCode, array $added, array $skipped, array $failed): array
    {
        return [
            'source_code' => $sourceCode,
            'summary' => [
                'total'   => count($added) + count($skipped) + count($failed),
                'added'   => count($added),
                'skipped' => count($skipped),
                'failed'  => count($failed),
            ],
            'added'   => $added,
            'skipped' => $skipped,
            'failed'  => $failed,
        ];
    }
}
