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
use Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * Drop blocklisted SKUs from every frontend product collection.
 *
 * The ProductStatus plugin already disables them, which the catalog layer's
 * status filter removes; the fulltext search collection, however, builds its
 * result from the search index rather than the status attribute, so filter the
 * SKUs out here as well. Mirrors the merged ProductStatus visibility filter.
 */
class CollectionVisibility
{
    public function __construct(
        private readonly SkuProvider $skuProvider
    ) {
    }

    public function afterSetVisibility(Collection $subject, $result)
    {
        $skus = $this->skuProvider->getSkus();
        if ($skus) {
            $subject->addAttributeToFilter('sku', ['nin' => $skus]);
        }

        return $result;
    }
}
