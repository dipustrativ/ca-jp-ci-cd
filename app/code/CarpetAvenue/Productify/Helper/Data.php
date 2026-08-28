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

namespace CarpetAvenue\Productify\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    /*
     * Magento Attribute Codes - GROUP Product Features
     * */
    const NAME_ATTRIBUTE_CODE  = 'name';
    const SIZE_ATTRIBUTE_CODE  = 'configurable_size';
    const COLOR_ATTRIBUTE_CODE = 'config_color';
    const DESIGN_ID_ATTRIBUTE_CODE = 'design_id';
    const DESIGN_ID_GROUP_ATTRIBUTE_CODE = 'design_id_group';
    const IS_GROUP_ATTRIBUTE_CODE = 'is_group';
    const SHAPE_ATTRIBUTE_CODE = 'shape';
    const SHAPE_ATTRIBUTE_KEY_CODE = 'shape_key';
    const NAME_ATTRIBUTE_ID    = 73;
    const SIZE_ATTRIBUTE_ID    = 222;
    const COLOR_ATTRIBUTE_ID   = 223;
    const DESIGN_ID_ATTRIBUTE_ID   = 264;

    /*
     * CSV Column Names - GROUP Product Features
     * */
    const CSV_DESIGN_ID       = 'design_id';
    const CSV_DESIGN_ID_GROUP = 'design_id_group';
    const CSV_DESIGN_ID_COLOR = 'group_color';
    const CSV_DESIGN_ID_SIZE  = 'group_size';
    const CSV_SKU             = 'sku';

    const GROUP_PRODUCT_BUILD_LOG_FILE = 'variant_build.log';

    const PRODUCT_COLLECTION_TABLE = 'carpetavenue_productify_collection';

    /*
     * Rewrite URL Issue
     * */
    const REWRITE_URL_ISSUE_CSV_COLUMN_NAME = "Item ID";
    const MAX_UNIQUE_CAN_PROCESS_SINGLE_TIME = 300;

    /*
     * Queue Process - Batch Size
     * */
    const QUEUE_BATCH_SIZE = 500;

    /**
     * Fallback round-shape attribute option id, used when the admin setting
     * (productify/general/round_shape_option_id) is empty.
     */
    const SHAPE_ROUND_OPTION_ID = '20';

    /**
     * Fallback primary-shape (rectangular) attribute option id, used when the
     * admin setting (productify/general/primary_shape_option_id) is empty.
     */
    const SHAPE_PRIMARY_OPTION_ID = '19';

    /**
     * Config path for the round-shape option id.
     */
    const XML_PATH_ROUND_SHAPE_OPTION_ID = 'productify/general/round_shape_option_id';

    /**
     * Config path for the primary-shape option id.
     */
    const XML_PATH_PRIMARY_SHAPE_OPTION_ID = 'productify/general/primary_shape_option_id';

    /**
     * Round-shape attribute option id, read from admin config and falling back
     * to the hardcoded default when the setting is unset/empty.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getRoundShapeOptionId($storeId = null): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_ROUND_SHAPE_OPTION_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return ($value === null || $value === '') ? self::SHAPE_ROUND_OPTION_ID : (string) $value;
    }

    /**
     * Primary-shape (rectangular) attribute option id, read from admin config
     * and falling back to the hardcoded default when the setting is unset/empty.
     *
     * The primary shape is listed first in the PDP size drawer and gets no
     * heading — it is the default shape of the catalogue.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPrimaryShapeOptionId($storeId = null): string
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_PRIMARY_SHAPE_OPTION_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return ($value === null || $value === '') ? self::SHAPE_PRIMARY_OPTION_ID : (string) $value;
    }

    /**
     * Same rule as formatSize(), but for an already-built size label
     * ("160x230", "160 x 230 cm") such as the variant cache stores.
     *
     * Round + square labels become "∅ W cm"; anything else keeps its original
     * spelling and only gains the "cm" unit when missing.
     *
     * @param string $label
     * @param int|string|null $shape
     * @return string
     */
    public function formatSizeLabel(string $label, $shape = null): string
    {
        $label = trim($label);

        if ($label === '') {
            return '';
        }

        if (preg_match('/^(\d+(?:[.,]\d+)?)\s*[x×]\s*(\d+(?:[.,]\d+)?)/i', $label, $matches)) {
            $formatted = $this->formatSize(
                str_replace(',', '.', $matches[1]),
                str_replace(',', '.', $matches[2]),
                $shape
            );
            // Only the diameter form differs from the stored label.
            if (str_starts_with($formatted, '∅')) {
                return $formatted;
            }
        }

        return str_contains(strtolower($label), 'cm') ? $label : $label . ' cm';
    }

    /**
     * Human-readable rug size string, rendered identically everywhere
     * (listings, widgets, cart, tiles).
     *
     * Diameter notation (∅ W cm) only when the rug is round (shape option id
     * from config) AND width == length; otherwise "W x L cm". Returns '' when
     * width or length is missing. Caller is responsible for escaping.
     *
     * @param int|float|string|null $width
     * @param int|float|string|null $length
     * @param int|string|null $shape
     * @return string
     */
    public function formatSize($width, $length, $shape = null): string
    {
        if ($width === null || $width === '' || $length === null || $length === '') {
            return '';
        }

        if ((string) $shape === $this->getRoundShapeOptionId() && (float) $width === (float) $length) {
            return '∅ ' . $width . ' cm';
        }

        return $width . ' x ' . $length . ' cm';
    }
}
