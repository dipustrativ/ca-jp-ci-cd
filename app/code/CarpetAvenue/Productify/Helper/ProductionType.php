<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Helper;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Helper\AbstractHelper;

/**
 * Class ProductionType
 * @package Evalent\ProductionType\Helper
 * @api since 1.0.0
 */
class ProductionType extends AbstractHelper
{

    /**
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     *
     * @return bool
     */
    public function isProductUnique(ProductInterface $product): bool
    {
        if ($product->getCustomAttribute('unique')) {
            if ($product->getCustomAttribute('unique')->getValue() == 1) {
                return true;
            }
        } else {
            return false;
        }

        /*
        $productionType = $product->getCustomAttribute('production_type');
        $uniqueProductionTypes = $this->scopeConfig->getValue('catalog/production_type/uniquely_made_option');
        if ($productionType && $uniqueProductionTypes) {
            foreach (explode(',', $uniqueProductionTypes) as $uniqueProductionType) {
                if ($productionType->getValue() == $uniqueProductionType) {
                    return true;
                }
            }
        }
        */

        return false;
    }
}
