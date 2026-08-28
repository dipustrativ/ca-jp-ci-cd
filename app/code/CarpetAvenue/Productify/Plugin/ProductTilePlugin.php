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

namespace CarpetAvenue\Productify\Plugin;

use Magento\Catalog\Block\Product\AbstractProduct;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use CarpetAvenue\Productify\ViewModel\VariantCacheView;
use CarpetAvenue\Productify\Helper\Data;

class ProductTilePlugin
{
    protected LayoutInterface $layout;
    protected VariantCacheView $viewModel;

    /**
     * Renderer reused across tiles — this runs once per product tile, so
     * creating a block per call costs one object-manager create + interceptor
     * per group product on the page.
     */
    private ?Template $renderer = null;

    public function __construct(
        LayoutInterface $layout,
        VariantCacheView $viewModel
    ) {
        $this->layout = $layout;
        $this->viewModel = $viewModel;
    }
    public function afterGetProductDetailsHtml(AbstractProduct $subject, $result, Product $product)
    {
        if (!$product->getData(Data::IS_GROUP_ATTRIBUTE_CODE) || !$product->getData(Data::DESIGN_ID_GROUP_ATTRIBUTE_CODE)) {
            return $result;
        }

        if ($this->renderer === null) {
            $this->renderer = $this->layout->createBlock(Template::class);
            $this->renderer->setTemplate('CarpetAvenue_Productify::tile/variants.phtml');
            $this->renderer->setData('variantCache', $this->viewModel);
        }

        $this->renderer->setData('product', $product);

        return $result . $this->renderer->toHtml();
    }
}
