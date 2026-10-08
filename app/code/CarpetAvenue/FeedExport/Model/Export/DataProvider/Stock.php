<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Model\Export\DataProvider;

use CarpetAvenue\FeedExport\Model\Export\ExportContext;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventorySalesAdminUi\Model\GetSalableQuantityDataBySku;

/**
 * <stock> (legacy stock item) and <msi_stocks><msi_stock> (stock_name, qty, manage_stock per MSI stock).
 */
class Stock implements DataProviderInterface
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly GetSalableQuantityDataBySku $getSalableQuantityDataBySku
    ) {
    }

    public function provide(Product $product, ExportContext $context): array
    {
        $data = [];
        if ($context->isRequired('msi_stocks')) {
            $data['msi_stocks'] = $this->getMsiStocks((string)$product->getSku(), $context);
        }
        if ($context->isRequired('stock')) {
            $stock = [];
            $stockItem = $this->stockRegistry->getStockItem((int)$product->getId(), $context->getStoreId());
            if ($stockItem->getItemId()) {
                foreach ($stockItem->getData() as $key => $value) {
                    if ($key === 'qty' && is_numeric($value)) {
                        $value = sprintf('%d', $value);
                    }
                    if (is_scalar($value)) {
                        $context->write($stock, (string)$key, $value);
                    }
                }
            }
            $data['stock'] = $stock;
        }
        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getMsiStocks(string $sku, ExportContext $context): array
    {
        try {
            $stocks = $this->getSalableQuantityDataBySku->execute($sku);
        } catch (NoSuchEntityException|InputException $e) {
            return [];
        }
        $nodes = [];
        foreach ($stocks as $stock) {
            $node = [];
            foreach ($stock as $key => $value) {
                if (is_scalar($value)) {
                    $context->write($node, (string)$key, $value);
                }
            }
            $nodes[] = $node;
        }
        return $nodes;
    }
}
