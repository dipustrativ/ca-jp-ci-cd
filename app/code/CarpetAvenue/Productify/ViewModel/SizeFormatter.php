<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\ViewModel;

use CarpetAvenue\Productify\Helper\Data;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Rug size rendering for templates: "W x L cm", or "∅ W cm" for round rugs.
 *
 * Keeps the round-shape option id in one place (Helper\Data, backed by
 * productify/general/round_shape_option_id) instead of hardcoding it per
 * template.
 */
class SizeFormatter implements ArgumentInterface
{
    public function __construct(
        private readonly Data $helper
    ) {
    }

    /**
     * Size label for a product, '' when width or length is missing.
     *
     * @param ProductInterface $product
     * @return string
     */
    public function format(ProductInterface $product): string
    {
        return $this->helper->formatSize(
            $product->getData('width'),
            $product->getData('length'),
            $product->getData(Data::SHAPE_ATTRIBUTE_CODE)
        );
    }

    /**
     * Size label built from explicit width/length/shape values.
     *
     * @param int|float|string|null $width
     * @param int|float|string|null $length
     * @param int|string|null $shape
     * @return string
     */
    public function formatValues($width, $length, $shape): string
    {
        return $this->helper->formatSize($width, $length, $shape);
    }

    /**
     * True when the product's shape is the configured round option.
     *
     * @param ProductInterface $product
     * @return bool
     */
    public function isRound(ProductInterface $product): bool
    {
        $shape = $product->getData(Data::SHAPE_ATTRIBUTE_CODE);

        return $shape !== null && $shape !== ''
            && (string) $shape === $this->helper->getRoundShapeOptionId();
    }

    /**
     * True when the rendered label uses diameter notation.
     *
     * @param string $label
     * @return bool
     */
    public function isDiameterLabel(string $label): bool
    {
        return str_starts_with($label, '∅');
    }
}
