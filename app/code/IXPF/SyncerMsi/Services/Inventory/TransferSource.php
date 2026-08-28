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

class TransferSource
{
    public function __construct(
        protected SourceRepositoryInterface   $sourceRepository,
        protected SourceItemInterfaceFactory  $sourceItemFactory,
        protected SourceItemsSaveInterface    $sourceItemsSave,
        protected ResourceConnection          $resourceConnection
    ) {
    }

    /**
     * Move the full source-item quantity of each SKU from one source to another.
     * Destination qty += moved qty; source qty set to 0. Destination link is
     * created when missing so no stock is lost.
     *
     * @param string $fromSource
     * @param string $toSource
     * @param array $skus
     * @return array
     * @throws NoSuchEntityException when either source does not exist
     */
    public function transfer(string $fromSource, string $toSource, array $skus): array
    {
        // Validate both sources exist — explicit message so caller knows which one
        try {
            $this->sourceRepository->get($fromSource);
        } catch (NoSuchEntityException $e) {
            throw new NoSuchEntityException(__("from_source '%1' not found", $fromSource));
        }
        try {
            $this->sourceRepository->get($toSource);
        } catch (NoSuchEntityException $e) {
            throw new NoSuchEntityException(__("to_source '%1' not found", $toSource));
        }

        $skus = array_values(array_unique(array_filter(array_map('trim', $skus), 'strlen')));

        $transferred = [];
        $failed      = [];

        if (empty($skus)) {
            return $this->buildResult($fromSource, $toSource, $transferred, $failed);
        }

        $fromItems = $this->getSourceItems($fromSource, $skus);
        $toItems   = $this->getSourceItems($toSource, $skus);

        $itemsToSave = [];
        $meta        = [];
        foreach ($skus as $sku) {
            if (!isset($fromItems[$sku])) {
                $failed[] = ['sku' => $sku, 'reason' => 'SKU not assigned to from source'];
                continue;
            }

            $fromQty = (float)$fromItems[$sku]['quantity'];
            if ($fromQty <= 0) {
                $failed[] = ['sku' => $sku, 'reason' => 'No quantity to transfer on from source'];
                continue;
            }

            $toQtyBefore = isset($toItems[$sku]) ? (float)$toItems[$sku]['quantity'] : 0.0;
            $toStatus    = isset($toItems[$sku])
                ? (int)$toItems[$sku]['status']
                : SourceItemInterface::STATUS_IN_STOCK;

            // Destination: add the moved qty
            $toItem = $this->sourceItemFactory->create();
            $toItem->setSourceCode($toSource);
            $toItem->setSku($sku);
            $toItem->setQuantity($toQtyBefore + $fromQty);
            $toItem->setStatus($toStatus);

            // Source: emptied, status preserved
            $fromItem = $this->sourceItemFactory->create();
            $fromItem->setSourceCode($fromSource);
            $fromItem->setSku($sku);
            $fromItem->setQuantity(0);
            $fromItem->setStatus((int)$fromItems[$sku]['status']);

            $itemsToSave[] = $toItem;
            $itemsToSave[] = $fromItem;
            $meta[$sku] = [
                'quantity'    => $fromQty,
                'from_after'  => 0,
                'to_after'    => $toQtyBefore + $fromQty,
            ];
        }

        if (!empty($itemsToSave)) {
            try {
                $this->sourceItemsSave->execute($itemsToSave);
                foreach ($meta as $sku => $info) {
                    $transferred[] = array_merge(['sku' => $sku], $info);
                }
            } catch (Exception $e) {
                foreach (array_keys($meta) as $sku) {
                    $failed[] = ['sku' => $sku, 'reason' => 'Save error: ' . $e->getMessage()];
                }
            }
        }

        return $this->buildResult($fromSource, $toSource, $transferred, $failed);
    }

    /**
     * Source items for these SKUs on a source. keyed sku => [quantity, status].
     *
     * @param string $sourceCode
     * @param array $skus
     * @return array
     */
    private function getSourceItems(string $sourceCode, array $skus): array
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName('inventory_source_item');
        $select = $connection->select()
            ->from(['isi' => $table], ['sku', 'quantity', 'status'])
            ->where('isi.source_code = ?', $sourceCode)
            ->where('isi.sku IN (?)', $skus);

        $rows = $connection->fetchAll($select);
        $map = [];
        foreach ($rows as $row) {
            $map[$row['sku']] = ['quantity' => $row['quantity'], 'status' => $row['status']];
        }
        return $map;
    }

    /**
     * @param string $fromSource
     * @param string $toSource
     * @param array $transferred
     * @param array $failed
     * @return array
     */
    private function buildResult(string $fromSource, string $toSource, array $transferred, array $failed): array
    {
        return [
            'from_source' => $fromSource,
            'to_source'   => $toSource,
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
