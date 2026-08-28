<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Service\AttributeOverride;

use CarpetAvenue\Core\Helper\UpsertBulk;
use CarpetAvenue\Utilities\Helper\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Reads current live attribute values and upserts them into
 * carpetavenue_attribute_override_value as the new "intended" baseline.
 *
 * Typical use: run once after confirming all store-view values are correct,
 * then detect/fix maintains that baseline going forward.
 *
 * Result shape:
 *   ['price' => int, 'image_role' => int, 'image_position' => int, 'custom' => int]
 *   (row counts upserted per type)
 */
class SnapshotService
{
    private const IMAGE_ROLE_CODES = ['image', 'small_image', 'thumbnail', 'swatch_image'];
    private const TABLE            = 'carpetavenue_attribute_override_value';

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly UpsertBulk $upsertBulk,
    ) {
    }

    /**
     * @param string[] $types    Empty = all enabled. Values: price, image, custom.
     * @param int[]    $storeIds Empty = all store views (store_id > 0).
     * @param bool     $dryRun   Return counts without writing.
     * @return array  Counts per type.
     */
    public function snapshot(array $types = [], array $storeIds = [], bool $dryRun = false): array
    {
        $run          = empty($types) ? ['price', 'image', 'custom'] : $types;
        $counts       = ['price' => 0, 'image_role' => 0, 'image_position' => 0, 'custom' => 0];
        $conn         = $this->resourceConnection->getConnection();
        $protectedIds = $this->config->getProtectedStoreIds();

        // Remove protected stores from the explicit list; when list is empty (= all stores),
        // the NOT IN clause added in each collector handles exclusion.
        if (!empty($protectedIds) && !empty($storeIds)) {
            $storeIds = array_values(array_diff($storeIds, $protectedIds));
            if (empty($storeIds)) {
                return $counts; // all requested stores are protected
            }
        }

        if (in_array('price', $run, true)) {
            $rows = $this->collectPrice($storeIds, $protectedIds, $conn);
            $counts['price'] = count($rows);
            if (!$dryRun && !empty($rows)) {
                $this->upsertBulk->upsert($rows, self::TABLE);
            }
        }

        if (in_array('image', $run, true)) {
            $roleRows = $this->collectImageRoles($storeIds, $protectedIds, $conn);
            $counts['image_role'] = count($roleRows);
            if (!$dryRun && !empty($roleRows)) {
                $this->upsertBulk->upsert($roleRows, self::TABLE);
            }

            $posRows = $this->collectImagePositions($storeIds, $protectedIds, $conn);
            $counts['image_position'] = count($posRows);
            if (!$dryRun && !empty($posRows)) {
                $this->upsertBulk->upsert($posRows, self::TABLE);
            }
        }

        if (in_array('custom', $run, true)) {
            $rows = $this->collectCustom($storeIds, $protectedIds, $conn);
            $counts['custom'] = count($rows);
            if (!$dryRun && !empty($rows)) {
                $this->upsertBulk->upsert($rows, self::TABLE);
            }
        }

        return $counts;
    }

    // ── Collectors ────────────────────────────────────────────────────────────

    private function collectPrice(array $storeIds, array $protectedIds, AdapterInterface $conn): array
    {
        $attributes = $this->config->getAttributeOverridePriceAttributes();
        if (empty($attributes)) {
            return [];
        }

        $select = $conn->select()
            ->from(
                ['ped' => $this->resourceConnection->getTableName('catalog_product_entity_decimal')],
                ['entity_id', 'store_id', 'value']
            )
            ->join(
                ['ea' => $this->resourceConnection->getTableName('eav_attribute')],
                'ped.attribute_id = ea.attribute_id',
                ['attribute_code']
            )
            ->where('ea.attribute_code IN (?)', $attributes)
            ->where('ped.store_id > 0');

        if (!empty($storeIds)) {
            $select->where('ped.store_id IN (?)', $storeIds);
        }
        if (!empty($protectedIds)) {
            $select->where('ped.store_id NOT IN (?)', $protectedIds);
        }

        return $this->toOverrideRows($conn->fetchAll($select), 'price', 'attribute_code');
    }

    private function collectImageRoles(array $storeIds, array $protectedIds, AdapterInterface $conn): array
    {
        $select = $conn->select()
            ->from(
                ['pev' => $this->resourceConnection->getTableName('catalog_product_entity_varchar')],
                ['entity_id', 'store_id', 'value']
            )
            ->join(
                ['ea' => $this->resourceConnection->getTableName('eav_attribute')],
                'pev.attribute_id = ea.attribute_id',
                ['attribute_code']
            )
            ->where('ea.attribute_code IN (?)', self::IMAGE_ROLE_CODES)
            ->where('pev.store_id > 0');

        if (!empty($storeIds)) {
            $select->where('pev.store_id IN (?)', $storeIds);
        }
        if (!empty($protectedIds)) {
            $select->where('pev.store_id NOT IN (?)', $protectedIds);
        }

        return $this->toOverrideRows($conn->fetchAll($select), 'image_role', 'attribute_code');
    }

    private function collectImagePositions(array $storeIds, array $protectedIds, AdapterInterface $conn): array
    {
        $select = $conn->select()
            ->from(
                ['mgv' => $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value')],
                ['entity_id', 'store_id', 'position']
            )
            ->join(
                ['mg' => $this->resourceConnection->getTableName('catalog_product_entity_media_gallery')],
                'mgv.value_id = mg.value_id',
                ['file']
            )
            ->where('mgv.store_id > 0');

        if (!empty($storeIds)) {
            $select->where('mgv.store_id IN (?)', $storeIds);
        }
        if (!empty($protectedIds)) {
            $select->where('mgv.store_id NOT IN (?)', $protectedIds);
        }

        $rows = [];
        foreach ($conn->fetchAll($select) as $row) {
            $rows[] = [
                'entity_id'      => (int) $row['entity_id'],
                'store_id'       => (int) $row['store_id'],
                'attribute_type' => 'image_position',
                'attribute_code' => $row['file'],
                'value'          => (string) $row['position'],
            ];
        }
        return $rows;
    }

    private function collectCustom(array $storeIds, array $protectedIds, AdapterInterface $conn): array
    {
        $attributes = $this->config->getAttributeOverrideCustomAttributes();
        if (empty($attributes)) {
            return [];
        }

        // Resolve backend_type per attribute
        $metaSelect = $conn->select()
            ->from(
                $this->resourceConnection->getTableName('eav_attribute'),
                ['attribute_code', 'backend_type']
            )
            ->where('attribute_code IN (?)', $attributes);

        $byTable = [];
        foreach ($conn->fetchAll($metaSelect) as $row) {
            if ($row['backend_type'] === 'static') {
                continue;
            }
            $byTable['catalog_product_entity_' . $row['backend_type']][] = $row['attribute_code'];
        }

        $allRows = [];
        foreach ($byTable as $table => $codes) {
            $select = $conn->select()
                ->from(
                    ['pev' => $this->resourceConnection->getTableName($table)],
                    ['entity_id', 'store_id', 'value']
                )
                ->join(
                    ['ea' => $this->resourceConnection->getTableName('eav_attribute')],
                    'pev.attribute_id = ea.attribute_id',
                    ['attribute_code']
                )
                ->where('ea.attribute_code IN (?)', $codes)
                ->where('pev.store_id > 0');

            if (!empty($storeIds)) {
                $select->where('pev.store_id IN (?)', $storeIds);
            }
            if (!empty($protectedIds)) {
                $select->where('pev.store_id NOT IN (?)', $protectedIds);
            }

            $allRows = array_merge(
                $allRows,
                $this->toOverrideRows($conn->fetchAll($select), 'custom', 'attribute_code')
            );
        }

        return $allRows;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Convert raw DB rows to override table row format.
     */
    private function toOverrideRows(array $rows, string $attributeType, string $codeKey): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'entity_id'      => (int) $row['entity_id'],
                'store_id'       => (int) $row['store_id'],
                'attribute_type' => $attributeType,
                'attribute_code' => $row[$codeKey],
                'value'          => $row['value'] !== null ? (string) $row['value'] : null,
            ];
        }
        return $result;
    }
}
