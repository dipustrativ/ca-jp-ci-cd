<?php

/**
 * Copyright © Carpient. All rights reserved.
 *
 * @category  Product
 * @package   Carpient_Theme
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace Carpient\Theme\Observer;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Type;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Ensures every simple product has a legacy cataloginventory_stock_item row.
 *
 * The IXPF Syncer purchase flow (IXPF\Syncer\Services\Purchase\Purchase::updateOrCreateProduct)
 * creates products with no stock_data and only assigns an MSI source item, so the
 * legacy stock item — the mandatory input to the stock-status indexer — is never
 * created. Products created that way render on the storefront with no price and no
 * add-to-cart even when enabled. This observer backfills a default stock item
 * (in stock, Manage Stock inherited from config = off) whenever one is missing.
 *
 * It is a no-op for products that already have a stock item, so admin / import /
 * API saves that set inventory normally are untouched.
 */
class EnsureStockItem implements ObserverInterface
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StockItemRepositoryInterface $stockItemRepository,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var ProductInterface $product */
        $product = $observer->getEvent()->getProduct();

        if (!$product instanceof ProductInterface || $product->getTypeId() !== Type::TYPE_SIMPLE) {
            return;
        }

        $productId = (int) $product->getId();
        if ($productId === 0) {
            return;
        }

        try {
            $stockItem = $this->stockRegistry->getStockItem($productId);

            // Already has a persisted stock item — nothing to do.
            if ($stockItem->getItemId()) {
                return;
            }

            $stockItem->setProductId($productId);
            $stockItem->setWebsiteId($this->stockConfiguration->getDefaultScopeId());
            $stockItem->setStockId($this->stockRegistry->getStock()->getStockId());
            $stockItem->setIsInStock(true);
            $stockItem->setQty(0);
            // Manage Stock / backorders inherit the store config (globally off here).
            $stockItem->setUseConfigManageStock(true);

            $this->stockItemRepository->save($stockItem);
        } catch (\Throwable $e) {
            // Non-fatal: never block the product save because of the backfill.
            $this->logger->error(
                'Carpient_Theme: failed to backfill stock item for product ID ' . $productId
                . ' – ' . $e->getMessage()
            );
        }
    }
}
