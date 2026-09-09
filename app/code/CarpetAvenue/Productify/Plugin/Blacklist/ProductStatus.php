<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Plugin\Blacklist;

use CarpetAvenue\Productify\Model\Blacklist\SkuProvider;
use Magento\Catalog\Model\Product as ProductModel;
use Magento\Catalog\Model\Product\Attribute\Source\Status;

/**
 * Force a blocklisted product to report as disabled on the storefront.
 *
 * A disabled status makes the product invisible in catalog/search collections
 * and makes Catalog\Helper\Product::initProduct return false -> 404 on the PDP
 * URL. Registered in the frontend area only, so the admin still sees the real
 * status and can remove the SKU from the blocklist.
 */
class ProductStatus
{
    public function __construct(
        private readonly SkuProvider $skuProvider
    ) {
    }

    public function afterGetStatus(ProductModel $subject, $result)
    {
        $sku = $subject->getSku();
        if ($sku !== null && $this->skuProvider->isBlacklisted((string) $sku)) {
            return Status::STATUS_DISABLED;
        }

        return $result;
    }
}
