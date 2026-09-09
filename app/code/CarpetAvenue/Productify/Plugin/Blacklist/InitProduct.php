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
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Product as ProductHelper;

/**
 * Hard-block the PDP for a blocklisted SKU.
 *
 * initProduct is the single choke point every product-view request passes
 * through, whatever the product's type or visibility. Returning false makes the
 * view controller render noroute (404). This is the definitive guard: it does
 * not depend on the status/canShow chain, so it also covers group variants
 * whose PDP is rendered even though they are not individually visible.
 *
 * The status and collection plugins still handle listings; this only closes the
 * direct-URL path.
 */
class InitProduct
{
    public function __construct(
        private readonly SkuProvider $skuProvider
    ) {
    }

    /**
     * @param ProductHelper $subject
     * @param ProductInterface|false $result
     * @return ProductInterface|false
     */
    public function afterInitProduct(ProductHelper $subject, $result)
    {
        if ($result instanceof ProductInterface
            && $this->skuProvider->isBlacklisted((string) $result->getSku())
        ) {
            return false;
        }

        return $result;
    }
}
