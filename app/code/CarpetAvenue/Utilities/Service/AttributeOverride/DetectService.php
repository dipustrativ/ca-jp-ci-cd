<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Service\AttributeOverride;

use CarpetAvenue\Utilities\Helper\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Scans live DB attribute values for store-view-specific overrides.
 *
 * A violation is any row with store_id > 0 for a configured attribute
 * (excluding protected stores). These rows override the global (store_id=0)
 * value and should be deleted so Magento falls back to the global value.
 *
 * Violation row shape:
 * [
 *   'entity_id'      => int,
 *   'sku'            => string,
 *   'store_id'       => int,
 *   'attribute_type' => string,   // price | image_role | image_position | custom
 *   'attribute_code' => string,
 *   'current_value'  => string|null,   // the offending store-view value
 *   'intended_value' => null,          // null = will fall back to global after fix
 * ]
 */
class DetectService
{
    private const IMAGE_ROLE_CODES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    public function __construct(
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Run detection across configured types and stores.
     *
     * @param string[] $types    Empty = all enabled. Values: price, image, custom.
     * @param int[]    $storeIds Empty = all non-protected store views. Otherwise limit to these IDs.
     * @return array  Violation rows.
     */
    public function detect(array $types = [], array $storeIds = []): array
    {
        $violations   = [];
        $run          = empty($types) ? ['price', 'image', 'custom'] : $types;
        $protectedIds = $this->config->getProtectedStoreIds();

        if (in_array('price', $run, true) && $this->config->isDetectPriceEnabled()) {
            $violations = array_merge($violations, $this->detectPrice($storeIds, $protectedIds));
        }

        if (in_array('image', $run, true) && $this->config->isDetectImageEnabled()) {
            $violations = array_merge($violations, $this->detectImage($storeIds, $protectedIds));
        }

        if (in_array('custom', $run, true) && $this->config->isDetectCustomEnabled()) {
            $violations = array_merge($violations, $this->detectCustom($storeIds, $protectedIds));
        }

        return $violations;
    }

    // ── Price ─────────────────────────────────────────────────────────────────

    private function detectPrice(array $storeIds, array $protectedIds): array
    {
        $attributes = $this->config->getDetectPriceAttributes();
        if (empty($attributes)) {
            return [];
        }

        $conn   = $this->resourceConnection->getConnection();
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
            ->join(
                ['cpe' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'ped.entity_id = cpe.entity_id',
                ['sku']
            )
            ->where('ea.attribute_code IN (?)', $attributes)
            ->where('ped.store_id > 0');

        $this->applyStoreFilters($select, 'ped', $storeIds, $protectedIds);

        return $this->toViolations($conn->fetchAll($select), 'price');
    }

    // ── Image ─────────────────────────────────────────────────────────────────

    private function detectImage(array $storeIds, array $protectedIds): array
    {
        $violations = [];

        if ($this->config->isDetectImageRoles()) {
            $violations = array_merge($violations, $this->detectImageRoles($storeIds, $protectedIds));
        }

        if ($this->config->isDetectImagePosition()) {
            $violations = array_merge($violations, $this->detectImagePosition($storeIds, $protectedIds));
        }

        return $violations;
    }

    private function detectImageRoles(array $storeIds, array $protectedIds): array
    {
        $conn   = $this->resourceConnection->getConnection();
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
            ->join(
                ['cpe' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'pev.entity_id = cpe.entity_id',
                ['sku']
            )
            ->where('ea.attribute_code IN (?)', self::IMAGE_ROLE_CODES)
            ->where('pev.store_id > 0');

        $this->applyStoreFilters($select, 'pev', $storeIds, $protectedIds);

        return $this->toViolations($conn->fetchAll($select), 'image_role');
    }

    private function detectImagePosition(array $storeIds, array $protectedIds): array
    {
        $conn   = $this->resourceConnection->getConnection();
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
            ->join(
                ['cpe' => $this->resourceConnection->getTableName('catalog_product_entity')],
                'mgv.entity_id = cpe.entity_id',
                ['sku']
            )
            ->where('mgv.store_id > 0');

        $this->applyStoreFilters($select, 'mgv', $storeIds, $protectedIds);

        $violations = [];
        foreach ($conn->fetchAll($select) as $row) {
            $violations[] = [
                'entity_id'      => (int) $row['entity_id'],
                'sku'            => $row['sku'],
                'store_id'       => (int) $row['store_id'],
                'attribute_type' => 'image_position',
                'attribute_code' => $row['file'],
                'current_value'  => $row['position'] !== null ? (string) $row['position'] : null,
                'intended_value' => null,
            ];
        }

        return $violations;
    }

    // ── Custom ────────────────────────────────────────────────────────────────

    private function detectCustom(array $storeIds, array $protectedIds): array
    {
        $attributes = $this->config->getDetectCustomAttributes();
        if (empty($attributes)) {
            return [];
        }

        $conn        = $this->resourceConnection->getConnection();
        $backendTypes = $this->resolveBackendTypes($attributes, $conn);

        $byTable = [];
        foreach ($attributes as $code) {
            $type = $backendTypes[$code] ?? null;
            if ($type === null || $type === 'static') {
                continue;
            }
            $byTable['catalog_product_entity_' . $type][] = $code;
        }

        $violations = [];
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
                ->join(
                    ['cpe' => $this->resourceConnection->getTableName('catalog_product_entity')],
                    'pev.entity_id = cpe.entity_id',
                    ['sku']
                )
                ->where('ea.attribute_code IN (?)', $codes)
                ->where('pev.store_id > 0');

            $this->applyStoreFilters($select, 'pev', $storeIds, $protectedIds);

            $violations = array_merge($violations, $this->toViolations($conn->fetchAll($select), 'custom'));
        }

        return $violations;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Apply optional store ID filter and protected store exclusion to a select.
     */
    private function applyStoreFilters(
        \Magento\Framework\DB\Select $select,
        string $tableAlias,
        array $storeIds,
        array $protectedIds
    ): void {
        if (!empty($storeIds)) {
            $select->where("{$tableAlias}.store_id IN (?)", $storeIds);
        }
        if (!empty($protectedIds)) {
            $select->where("{$tableAlias}.store_id NOT IN (?)", $protectedIds);
        }
    }

    /**
     * Convert raw DB rows to violation shape.
     * intended_value is always null: fix = delete row → fall back to global.
     */
    private function toViolations(array $rows, string $attributeType): array
    {
        $violations = [];
        foreach ($rows as $row) {
            $violations[] = [
                'entity_id'      => (int) $row['entity_id'],
                'sku'            => $row['sku'],
                'store_id'       => (int) $row['store_id'],
                'attribute_type' => $attributeType,
                'attribute_code' => $row['attribute_code'],
                'current_value'  => $row['value'] !== null ? (string) $row['value'] : null,
                'intended_value' => null,
            ];
        }
        return $violations;
    }

    /**
     * Returns attribute_code => backend_type map for the given codes.
     */
    private function resolveBackendTypes(array $codes, AdapterInterface $conn): array
    {
        $select = $conn->select()
            ->from(
                $this->resourceConnection->getTableName('eav_attribute'),
                ['attribute_code', 'backend_type']
            )
            ->where('attribute_code IN (?)', $codes);

        $result = [];
        foreach ($conn->fetchAll($select) as $row) {
            $result[$row['attribute_code']] = $row['backend_type'];
        }
        return $result;
    }
}
