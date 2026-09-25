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

namespace CarpetAvenue\Productify\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Drops the stored productify/general/round_shape_option_id rows.
 *
 * Size labels now use diameter notation whenever width == length (matching
 * carpient.com) instead of matching a shape option id, so Data::formatSize()
 * no longer reads this setting and its admin field is gone. The rows would
 * otherwise sit in core_config_data forever, read by nothing.
 *
 * Idempotent: re-running finds no rows and does nothing.
 *
 * @noinspection PhpUnused
 */
class RemoveRoundShapeOptionIdConfig implements DataPatchInterface
{
    private const DROPPED_PATH = 'productify/general/round_shape_option_id';

    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();

        $connection->startSetup();
        $connection->delete(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['path = ?' => self::DROPPED_PATH]
        );
        $connection->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
