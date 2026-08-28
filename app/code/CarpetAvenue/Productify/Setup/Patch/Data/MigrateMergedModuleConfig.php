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
 * Moves saved store-config values onto the productify/* paths after
 * CarpetAvenue_EnhanceCategory, CarpetAvenue_LayeredNavigation,
 * CarpetAvenue_ProductStatus and Lof_AjaxScroll were merged into
 * CarpetAvenue_Productify.
 *
 * Without this, every setting an operator had saved would silently revert to
 * its config.xml default, because the reading code now asks for a path that
 * has no row in core_config_data.
 *
 * Idempotent: re-running finds nothing left on the old paths and does nothing.
 *
 * @noinspection PhpUnused
 */
class MigrateMergedModuleConfig implements DataPatchInterface
{
    /**
     * Old path prefix => new path prefix. Prefix moves, applied with REPLACE on
     * the leading segment only.
     */
    private const PREFIX_MAP = [
        'lofajaxscroll/'                    => 'productify/ajaxscroll/',
        'carpetavenue_layerednav/'          => 'productify/layerednav/',
        'catalog/advanced_product_status/'  => 'productify/product_status/',
    ];

    /**
     * Exact path moves, for single fields that lived inside a core section.
     */
    private const EXACT_MAP = [
        'catalog/frontend/product_category_link_position'
            => 'productify/enhance_category/product_category_link_position',
    ];

    /**
     * Settings whose feature was dropped in the merge: the Lof brand-page and
     * similar-products layout handles do not exist in this installation, so
     * these toggles drive nothing and are not carried over.
     */
    private const DROPPED_PATHS = [
        'lofajaxscroll/instances/enabled_brand',
        'lofajaxscroll/instances/enabled_similar',
    ];

    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table      = $this->moduleDataSetup->getTable('core_config_data');

        $connection->startSetup();

        $connection->delete($table, ['path IN (?)' => self::DROPPED_PATHS]);

        foreach (self::PREFIX_MAP as $old => $new) {
            $this->move($table, $old . '%', $old, $new, true);
        }

        foreach (self::EXACT_MAP as $old => $new) {
            $this->move($table, $old, $old, $new, false);
        }

        $connection->endSetup();

        return $this;
    }

    /**
     * Move rows from an old path (or path prefix) onto the new one.
     *
     * core_config_data has a unique key on (scope, scope_id, path), so any row
     * already sitting on the destination path is deleted first — otherwise the
     * UPDATE would fail with a duplicate-key error. The destination can only be
     * populated by a re-save after a partial migration, so the old row is the
     * authoritative one.
     */
    private function move(string $table, string $match, string $old, string $new, bool $isPrefix): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $operator   = $isPrefix ? 'path LIKE ?' : 'path = ?';

        $rows = $connection->fetchAll(
            $connection->select()->from($table, ['config_id', 'scope', 'scope_id', 'path'])->where($operator, $match)
        );

        foreach ($rows as $row) {
            $newPath = $isPrefix
                ? $new . substr($row['path'], strlen($old))
                : $new;

            $connection->delete($table, [
                'scope = ?'    => $row['scope'],
                'scope_id = ?' => $row['scope_id'],
                'path = ?'     => $newPath,
            ]);

            $connection->update(
                $table,
                ['path' => $newPath],
                ['config_id = ?' => $row['config_id']]
            );
        }
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
