<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Model\Config\Source;

use CarpetAvenue\Productify\Helper\Data;
use CarpetAvenue\Productify\Model\PrimaryShapeResolver;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options of the `shape` attribute on this install, for the primary-shape setting
 *
 * A dropdown instead of a free-text id: the ids differ per database, so only options
 * that exist here can be chosen.
 */
class ShapeOptions implements OptionSourceInterface
{
    /**
     * @param EavConfig $eavConfig
     * @param PrimaryShapeResolver $primaryShapeResolver
     */
    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly PrimaryShapeResolver $primaryShapeResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('Automatic: the shape most products have')]];

        $attribute = $this->eavConfig->getAttribute(Product::ENTITY, Data::SHAPE_ATTRIBUTE_CODE);
        if (!$attribute->getAttributeId()) {
            return $options;
        }

        $usage = $this->primaryShapeResolver->getUsage();
        foreach ($attribute->getSource()->getAllOptions() as $option) {
            if ((string) $option['value'] === '') {
                continue;
            }
            $options[] = [
                'value' => (string) $option['value'],
                'label' => __(
                    '%1 (ID %2, %3 products)',
                    $option['label'],
                    $option['value'],
                    $usage[$option['value']] ?? 0
                ),
            ];
        }
        return $options;
    }
}
