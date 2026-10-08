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

namespace CarpetAvenue\FeedExport\Model\Source;

use Magento\Catalog\Api\ProductTypeListInterface;
use Magento\Framework\Data\OptionSourceInterface;

class ProductType implements OptionSourceInterface
{
    public function __construct(
        private readonly ProductTypeListInterface $productTypeList
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->productTypeList->getProductTypes() as $type) {
            $options[] = ['value' => $type->getName(), 'label' => $type->getLabel()];
        }
        return $options;
    }
}
