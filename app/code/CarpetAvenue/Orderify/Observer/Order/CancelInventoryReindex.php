<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Order
 * @package   CarpetAvenue_Orderify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Observer\Order;

use CarpetAvenue\Orderify\Helper\Config;
use Exception;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryIndexer\Indexer\SourceItem\GetSourceItemIds;
use Magento\InventoryIndexer\Indexer\SourceItem\SourceItemIndexer;
use Psr\Log\LoggerInterface;

class CancelInventoryReindex implements ObserverInterface
{
    public function __construct(
        private readonly Config                       $config,
        private readonly GetSourceItemsBySkuInterface $getSourceItemsBySku,
        private readonly GetSourceItemIds             $getSourceItemIds,
        private readonly SourceItemIndexer            $sourceItemIndexer,
        private readonly LoggerInterface              $logger
    ) {}

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $observer->getEvent()->getOrder();

        if (!$this->config->isCancelInventoryReindexEnabled((int) $order->getStoreId())) {
            return;
        }

        $itemDetails = [];
        foreach ($order->getAllItems() as $item) {
            if ($item->getParentItemId()) {
                continue;
            }
            $sku = $item->getSku();
            $itemDetails[$sku] = [
                'name' => $item->getName(),
                'qty'  => (int) $item->getQtyOrdered(),
            ];
        }

        if (empty($itemDetails)) {
            return;
        }

        $skus = array_keys($itemDetails);

        try {
            $allSourceItems = [];
            foreach ($skus as $sku) {
                $sourceItems = $this->getSourceItemsBySku->execute($sku);
                foreach ($sourceItems as $sourceItem) {
                    $allSourceItems[] = $sourceItem;
                }
            }

            $sourceItemIds = $this->getSourceItemIds->execute($allSourceItems);

            if (empty($sourceItemIds)) {
                return;
            }

            $this->sourceItemIndexer->executeList($sourceItemIds);

            $itemLines = [];
            foreach ($itemDetails as $sku => $info) {
                $itemLines[] = sprintf('"%s" (SKU: %s, qty: %d)', $info['name'], $sku, $info['qty']);
            }

            $this->logger->info(sprintf(
                '[Orderify] Inventory reindexed after cancel. Order #%s | %d item(s): %s',
                $order->getIncrementId(),
                count($itemDetails),
                implode(', ', $itemLines)
            ));
        } catch (Exception $e) {
            $this->logger->error(sprintf(
                '[Orderify] Inventory reindex failed after cancel. Order #%s: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }
}
