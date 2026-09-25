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

use CarpetAvenue\Productify\Model\PrimaryShapeResolver;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
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
    // No attribute ids here on purpose: they differ per database. Resolve them
    // from the codes above (Eav Config::getAttribute()->getAttributeId()).

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
     * Config path for the primary-shape option id.
     */
    const XML_PATH_PRIMARY_SHAPE_OPTION_ID = 'productify/general/primary_shape_option_id';

    /**
     * @param Context $context
     * @param PrimaryShapeResolver $primaryShapeResolver
     */
    public function __construct(
        Context $context,
        private readonly PrimaryShapeResolver $primaryShapeResolver
    ) {
        parent::__construct($context);
    }

    /**
     * Primary-shape (rectangular) attribute option id.
     *
     * The admin setting wins when it is a real `shape` option on this install;
     * otherwise (empty, or an id from another database) it is the shape most
     * products have. See PrimaryShapeResolver.
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

        return $this->primaryShapeResolver->resolve((string) $value);
    }

    /**
     * Same rule as formatSize(), but for an already-built size label
     * ("160x230", "160 x 230 cm") such as the variant cache stores.
     *
     * Square labels become "∅ W cm"; anything else keeps its original
     * spelling and only gains the "cm" unit when missing.
     *
     * @param string $label
     * @param int|string|null $shape Unused — kept for call-site compatibility
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
     * Diameter notation (∅ W cm) whenever width == length, matching
     * carpient.com; otherwise "W x L cm". Shape is deliberately not consulted —
     * only Round and Square rugs ever have equal sides, and both read as ∅ on
     * the live site. Returns '' when width or length is missing. Caller is
     * responsible for escaping.
     *
     * @param int|float|string|null $width
     * @param int|float|string|null $length
     * @param int|string|null $shape Unused — kept for call-site compatibility
     * @return string
     */
    public function formatSize($width, $length, $shape = null): string
    {
        if ($width === null || $width === '' || $length === null || $length === '') {
            return '';
        }

        if ((float) $width === (float) $length) {
            return '∅ ' . $width . ' cm';
        }

        return $width . ' x ' . $length . ' cm';
    }
}
