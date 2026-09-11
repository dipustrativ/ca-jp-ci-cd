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

namespace CarpetAvenue\Productify\Plugin\Theme\Block\Html;

use Magento\Theme\Block\Html\Breadcrumbs;

/**
 * Puts the product-page breadcrumb back on the server.
 *
 * Magento_Catalog's catalog_product_view.xml swaps the breadcrumbs block onto
 * product/breadcrumbs.phtml, which renders an empty container and hands the job to
 * Magento_Catalog/js/product/breadcrumbs.js. That script reads document.referrer and
 * then only accepts the result if it matches an anchor in the top navigation menu, so
 * the trail is missing whenever the visitor did not arrive from a menu category — and
 * this store's menu carries two levels and omits most categories.
 *
 * Restoring the default template drops the script entirely and renders the crumbs
 * collected by Magento\Catalog\Block\Breadcrumbs, which catalog_product_view.xml adds.
 *
 * Declared against the requested type rather than the concrete class, so it survives
 * the Evalent_DynamicSimilarProducts preference on Breadcrumbs.
 *
 * Ported from the live site's Evalent_SmartBreadcrumbs.
 */
class ServerSideBreadcrumbs
{
    private const JS_TEMPLATE = 'Magento_Catalog::product/breadcrumbs.phtml';
    private const SERVER_TEMPLATE = 'Magento_Theme::html/breadcrumbs.phtml';

    /**
     * @param Breadcrumbs $subject
     * @param string|null $result
     * @return string|null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetTemplate(Breadcrumbs $subject, $result)
    {
        return $result === self::JS_TEMPLATE ? self::SERVER_TEMPLATE : $result;
    }
}
