<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Catches the cost updates a save observer cannot see.
 *
 * ProductAction::updateAttributes() writes straight to the EAV tables and fires
 * no save events, and it is what the IXPF bulk endpoints use — BulkPriceUpdate,
 * ProductUpdate, BulkUpdate and AttributeMassUpdate all take that route. Without
 * this plugin, an ERP price/cost sync would leave jpy_lowest_sale_price stale.
 *
 * @package IXPF_CarpetAvenueJP
 */

declare(strict_types=1);

namespace IXPF\CarpetAvenueJP\Plugin\Catalog;

use IXPF\CarpetAvenueJP\Model\Product\JpyLowestSalePrice;
use Magento\Catalog\Model\ResourceModel\Product\Action;

class RecalculateJpyLowestSalePrice
{
    public function __construct(
        private readonly JpyLowestSalePrice $jpyLowestSalePrice
    ) {
    }

    /**
     * @param int[]|int $entityIds
     * @param array<string, mixed> $attrData
     */
    public function afterUpdateAttributes(
        Action $subject,
        Action $result,
        $entityIds,
        $attrData,
        $storeId
    ): Action {
        // Only a cost change moves the value. This condition is also what stops
        // the recursion: our own write below carries jpy_lowest_sale_price, not
        // cost, so it does not trigger another pass.
        if (!is_array($attrData) || !array_key_exists('cost', $attrData)) {
            return $result;
        }

        $productIds = array_map('intval', (array)$entityIds);
        if ($productIds !== []) {
            $this->jpyLowestSalePrice->updateForProducts($productIds);
        }

        return $result;
    }
}
