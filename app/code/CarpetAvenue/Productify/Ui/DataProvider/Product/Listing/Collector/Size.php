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

namespace CarpetAvenue\Productify\Ui\DataProvider\Product\Listing\Collector;

use CarpetAvenue\Productify\Helper\Data as SizeHelper;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductRenderInterface;
use Magento\Catalog\Ui\DataProvider\Product\ProductRenderCollectorInterface;
use Magento\Framework\Api\ExtensionAttributesFactory;

/**
 * Adds the rug size (e.g. "170 x 230 cm"), formatted the same way as
 * everywhere else on the site, to the product render payload used by
 * client-side widgets (e.g. "Recently Viewed") that fetch products via
 * /V1/products-render-info instead of a server-rendered template.
 */
class Size implements ProductRenderCollectorInterface
{
    public function __construct(
        private readonly SizeHelper $sizeHelper,
        private readonly ExtensionAttributesFactory $extensionAttributesFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function collect(ProductInterface $product, ProductRenderInterface $productRender)
    {
        $extensionAttributes = $productRender->getExtensionAttributes()
            ?? $this->extensionAttributesFactory->create(ProductRenderInterface::class);

        $extensionAttributes->setSize(
            $this->sizeHelper->formatSize(
                $product->getData('width'),
                $product->getData('length'),
                $product->getData('shape')
            )
        );

        $productRender->setExtensionAttributes($extensionAttributes);
    }
}
