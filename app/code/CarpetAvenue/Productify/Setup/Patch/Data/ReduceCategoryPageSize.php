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
 * Drops the category grid from 50 products per page to 24, and offers 24 in the
 * per-page selector. Infinite scroll is on for every category page, so nothing
 * becomes unreachable. Default scope only, and only while it is still on 50 — a
 * merchant who has since tuned it in Admin keeps their value.
 *
 * @noinspection PhpUnused
 */
class ReduceCategoryPageSize implements DataPatchInterface
{
    private const PATH_GRID_PER_PAGE = 'catalog/frontend/grid_per_page';
    private const PATH_GRID_PER_PAGE_VALUES = 'catalog/frontend/grid_per_page_values';

    /** Value this patch replaces. Anything else is a deliberate merchant choice. */
    private const EXPECTED_PER_PAGE = '50';

    private const NEW_PER_PAGE = '24';
    private const NEW_PER_PAGE_VALUES = '24,40,50,80';

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        // Read the exact row about to be written, not ScopeConfigInterface: that
        // resolves through the store fallback chain, so one store-level override
        // would make this read a different row and silently skip the update.
        $current = $connection->fetchOne(
            $connection->select()
                ->from($table, 'value')
                ->where('path = ?', self::PATH_GRID_PER_PAGE)
                ->where('scope = ?', 'default')
                ->where('scope_id = ?', 0)
        );
        if ((string)$current !== self::EXPECTED_PER_PAGE) {
            return $this;
        }

        $connection->update(
            $table,
            ['value' => self::NEW_PER_PAGE],
            [
                'path = ?'     => self::PATH_GRID_PER_PAGE,
                'scope = ?'    => 'default',
                'scope_id = ?' => 0,
            ]
        );

        $connection->update(
            $table,
            ['value' => self::NEW_PER_PAGE_VALUES],
            [
                'path = ?'     => self::PATH_GRID_PER_PAGE_VALUES,
                'scope = ?'    => 'default',
                'scope_id = ?' => 0,
            ]
        );

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
