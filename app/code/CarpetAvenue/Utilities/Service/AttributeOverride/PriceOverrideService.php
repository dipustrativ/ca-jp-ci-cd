<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Service\AttributeOverride;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads and writes price attribute values in catalog_product_entity_decimal.
 *
 * Used by FixService and BackupService.
 */
class PriceOverrideService
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Load current price values for given entity IDs, store, and attribute codes.
     *
     * @return array  Rows: entity_id, sku, store_id, attribute_code, value
     */
    public function loadCurrentValues(array $entityIds, int $storeId, array $attributeCodes): array
    {
        if (empty($entityIds) || empty($attributeCodes)) {
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
            ->where('ped.entity_id IN (?)', $entityIds)
            ->where('ped.store_id = ?', $storeId)
            ->where('ea.attribute_code IN (?)', $attributeCodes);

        return $conn->fetchAll($select);
    }

    /**
     * Apply intended value for one product/store/attribute combination.
     * Writes directly to catalog_product_entity_decimal, bypassing Magento product save.
     *
     * Returns number of affected rows (0 = row not found, 1 = updated).
     */
    public function applyValue(int $entityId, int $storeId, string $attributeCode, ?string $value): int
    {
        $conn        = $this->resourceConnection->getConnection();
        $attributeId = $this->resolveAttributeId($attributeCode, $conn);
        if ($attributeId === null) {
            return 0;
        }

        return $conn->update(
            $this->resourceConnection->getTableName('catalog_product_entity_decimal'),
            ['value' => $value !== null ? (float) $value : null],
            [
                'entity_id = ?'    => $entityId,
                'store_id = ?'     => $storeId,
                'attribute_id = ?' => $attributeId,
            ]
        );
    }

    /**
     * Load all store-view-specific rows for given attributes.
     * storeId = 0 → all non-zero stores. Empty entityIds → all products.
     * Excludes protected store IDs.
     *
     * @return array  Rows: entity_id, sku, store_id, attribute_code, value
     */
    public function loadStoreViewRows(
        array $entityIds,
        int $storeId,
        array $attributeCodes,
        array $protectedIds = []
    ): array {
        if (empty($attributeCodes)) {
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
            ->where('ea.attribute_code IN (?)', $attributeCodes);

        if ($storeId > 0) {
            $select->where('ped.store_id = ?', $storeId);
        } else {
            $select->where('ped.store_id > 0');
        }

        if (!empty($entityIds)) {
            $select->where('ped.entity_id IN (?)', $entityIds);
        }
        if (!empty($protectedIds)) {
            $select->where('ped.store_id NOT IN (?)', $protectedIds);
        }

        return $conn->fetchAll($select);
    }

    /**
     * Delete store-view-specific rows for given attribute.
     * storeId = 0 → delete all non-zero stores. Empty entityIds → all products.
     * Excludes protected store IDs.
     *
     * Returns total affected row count.
     */
    public function deleteStoreViewRows(
        array $entityIds,
        int $storeId,
        string $attributeCode,
        array $protectedIds = []
    ): int {
        $conn        = $this->resourceConnection->getConnection();
        $attributeId = $this->resolveAttributeId($attributeCode, $conn);
        if ($attributeId === null) {
            return 0;
        }

        $where = ['attribute_id = ?' => $attributeId];

        if ($storeId > 0) {
            $where['store_id = ?'] = $storeId;
        } else {
            $where[] = 'store_id > 0';
        }

        if (!empty($entityIds)) {
            $where['entity_id IN (?)'] = $entityIds;
        }
        if (!empty($protectedIds)) {
            $where['store_id NOT IN (?)'] = $protectedIds;
        }

        return $conn->delete(
            $this->resourceConnection->getTableName('catalog_product_entity_decimal'),
            $where
        );
    }

    private function resolveAttributeId(string $code, $conn): ?int
    {
        $select = $conn->select()
            ->from($this->resourceConnection->getTableName('eav_attribute'), ['attribute_id'])
            ->where('attribute_code = ?', $code)
            ->limit(1);

        $id = $conn->fetchOne($select);
        return $id !== false ? (int) $id : null;
    }
}
