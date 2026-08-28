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
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Reads and writes custom website-scope EAV attribute values.
 *
 * Routes writes to the correct catalog_product_entity_{backend_type} table
 * based on the attribute's backend_type (varchar, int, text, decimal, datetime).
 */
class CustomAttributeOverrideService
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Load current values for given entity IDs, store, and attribute codes.
     *
     * @return array  Rows: entity_id, sku, store_id, attribute_code, value, backend_type
     */
    public function loadCurrentValues(array $entityIds, int $storeId, array $attributeCodes): array
    {
        if (empty($entityIds) || empty($attributeCodes)) {
            return [];
        }

        $conn         = $this->resourceConnection->getConnection();
        $backendTypes = $this->resolveBackendTypes($attributeCodes, $conn);

        $byTable = [];
        foreach ($attributeCodes as $code) {
            $type = $backendTypes[$code] ?? null;
            if ($type === null || $type === 'static') {
                continue;
            }
            $byTable['catalog_product_entity_' . $type][] = $code;
        }

        $rows = [];
        foreach ($byTable as $table => $codes) {
            $select = $conn->select()
                ->from(
                    ['pev' => $this->resourceConnection->getTableName($table)],
                    ['entity_id', 'store_id', 'value']
                )
                ->join(
                    ['ea' => $this->resourceConnection->getTableName('eav_attribute')],
                    'pev.attribute_id = ea.attribute_id',
                    ['attribute_code', 'backend_type']
                )
                ->join(
                    ['cpe' => $this->resourceConnection->getTableName('catalog_product_entity')],
                    'pev.entity_id = cpe.entity_id',
                    ['sku']
                )
                ->where('pev.entity_id IN (?)', $entityIds)
                ->where('pev.store_id = ?', $storeId)
                ->where('ea.attribute_code IN (?)', $codes);

            $rows = array_merge($rows, $conn->fetchAll($select));
        }

        return $rows;
    }

    /**
     * Apply intended value for one product/store/attribute.
     * Returns affected row count.
     */
    public function applyValue(int $entityId, int $storeId, string $attributeCode, ?string $value): int
    {
        $conn    = $this->resourceConnection->getConnection();
        $attrMeta = $this->resolveAttributeMeta($attributeCode, $conn);

        if ($attrMeta === null || $attrMeta['backend_type'] === 'static') {
            return 0;
        }

        $table = 'catalog_product_entity_' . $attrMeta['backend_type'];

        return $conn->update(
            $this->resourceConnection->getTableName($table),
            ['value' => $value],
            [
                'entity_id = ?'    => $entityId,
                'store_id = ?'     => $storeId,
                'attribute_id = ?' => $attrMeta['attribute_id'],
            ]
        );
    }

    // ── Store-view scan & delete ───────────────────────────────────────────────

    /**
     * Load all store-view-specific rows for given attribute codes.
     * storeId = 0 → all non-zero stores. Empty entityIds → all products.
     * Excludes protected store IDs.
     *
     * @return array  Rows: entity_id, sku, store_id, attribute_code, value, backend_type
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

        $conn         = $this->resourceConnection->getConnection();
        $backendTypes = $this->resolveBackendTypes($attributeCodes, $conn);

        $byTable = [];
        foreach ($attributeCodes as $code) {
            $type = $backendTypes[$code] ?? null;
            if ($type === null || $type === 'static') {
                continue;
            }
            $byTable['catalog_product_entity_' . $type][] = $code;
        }

        $rows = [];
        foreach ($byTable as $table => $codes) {
            $select = $conn->select()
                ->from(
                    ['pev' => $this->resourceConnection->getTableName($table)],
                    ['entity_id', 'store_id', 'value']
                )
                ->join(
                    ['ea' => $this->resourceConnection->getTableName('eav_attribute')],
                    'pev.attribute_id = ea.attribute_id',
                    ['attribute_code', 'backend_type']
                )
                ->join(
                    ['cpe' => $this->resourceConnection->getTableName('catalog_product_entity')],
                    'pev.entity_id = cpe.entity_id',
                    ['sku']
                )
                ->where('ea.attribute_code IN (?)', $codes);

            if ($storeId > 0) {
                $select->where('pev.store_id = ?', $storeId);
            } else {
                $select->where('pev.store_id > 0');
            }

            if (!empty($entityIds)) {
                $select->where('pev.entity_id IN (?)', $entityIds);
            }
            if (!empty($protectedIds)) {
                $select->where('pev.store_id NOT IN (?)', $protectedIds);
            }

            $rows = array_merge($rows, $conn->fetchAll($select));
        }

        return $rows;
    }

    /**
     * Delete store-view-specific rows for given attribute code.
     * storeId = 0 → all non-zero stores. Empty entityIds → all products.
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
        $conn     = $this->resourceConnection->getConnection();
        $attrMeta = $this->resolveAttributeMeta($attributeCode, $conn);

        if ($attrMeta === null || $attrMeta['backend_type'] === 'static') {
            return 0;
        }

        $table = 'catalog_product_entity_' . $attrMeta['backend_type'];
        $where = ['attribute_id = ?' => $attrMeta['attribute_id']];

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
            $this->resourceConnection->getTableName($table),
            $where
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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

    private function resolveAttributeMeta(string $code, AdapterInterface $conn): ?array
    {
        $select = $conn->select()
            ->from(
                $this->resourceConnection->getTableName('eav_attribute'),
                ['attribute_id', 'backend_type']
            )
            ->where('attribute_code = ?', $code)
            ->limit(1);

        $row = $conn->fetchRow($select);
        return $row ?: null;
    }
}
