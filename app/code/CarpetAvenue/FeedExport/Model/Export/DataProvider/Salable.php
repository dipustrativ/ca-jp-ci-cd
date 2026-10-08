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
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Catalog\Model\Product;

/**
 * ca_is_salable = (stock index qty + open reservations - min qty) and stock index is_salable.
 * Reads the fe_* columns joined by InventoryJoin; same formula the Xtento feeds used (Evalent_ProductAdminMods).
 */
class Salable implements DataProviderInterface
{
    public function __construct(
        private readonly StockConfigurationInterface $stockConfiguration
    ) {
    }

    public function provide(Product $product, ExportContext $context): array
    {
        if (!$context->isRequired('ca_is_salable')) {
            return [];
        }
        $minQty = (string)$product->getData('fe_use_config_min_qty') === '1'
            ? (float)$this->stockConfiguration->getMinQty()
            : (float)$product->getData('fe_min_qty');
        $qty = (float)$product->getData('fe_stock_qty') + (float)$product->getData('fe_reserved_qty') - $minQty;

        // Non-zero qty (any sign) counted as salable, kept identical to the previous feed output.
        return ['ca_is_salable' => (int)($qty != 0.0 && (bool)$product->getData('fe_is_salable'))];
    }
}
