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

namespace CarpetAvenue\Productify\Plugin\Catalog\Helper;

use CarpetAvenue\Productify\Helper\SimilarTo;
use Magento\Catalog\Helper\Data;
use Magento\Catalog\Model\Product;

/**
 * Gives a product page a breadcrumb that does not depend on how the visitor arrived.
 *
 * Helper\Data::getCategory() is a plain read of the "current_category" registry key,
 * which on a product page holds whichever category the session last visited — or
 * nothing at all when the visitor came from the home page, a search result or an ad.
 * getBreadcrumbPath() builds the trail from it, so the same product showed three
 * different breadcrumbs depending on the route in.
 *
 * Substituting the product's deepest assigned category makes the trail a property of
 * the product instead of a property of the visit. Ported from the live site's
 * Evalent_SmartBreadcrumbs, which does the same thing from the same hook.
 */
class DeepestCategoryBreadcrumb
{
    public function __construct(
        private readonly SimilarTo $similarTo
    ) {
    }

    /**
     * @param Data $subject
     * @param mixed $result
     * @return mixed
     */
    public function afterGetCategory(Data $subject, $result)
    {
        $product = $subject->getProduct();

        //  No product in the registry means this is not a product page — a category
        //  page reaches here too, and there the registry value is the right answer.
        if (!$product instanceof Product) {
            return $result;
        }

        //  ponytail: always deepest, hard-wired. Evalent_SmartBreadcrumbs makes this a
        //  three-mode admin setting whose default still lets a browsed category win.
        //  Add the setting if the shop ever wants the other modes.
        return $this->similarTo->getDeepestCategory($product) ?? $result;
    }
}
