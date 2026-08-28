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
 * Reads and writes image role and gallery position values.
 *
 * Roles    → catalog_product_entity_varchar (image, small_image, thumbnail, swatch_image)
 * Position → catalog_product_entity_media_gallery_value
 */
class ImageOverrideService
{
    private const ROLE_CODES = ['image', 'small_image', 'thumbnail', 'swatch_image'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    // ── Roles ─────────────────────────────────────────────────────────────────

    /**
     * Load current image role values for given entity IDs and store.
     *
     * @return array  Rows: entity_id, sku, store_id, attribute_code, value
     */
    public function loadCurrentRoles(array $entityIds, int $storeId): array
    {
        if (empty($entityIds)) {
            return [];
        }

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
            ->where('pev.entity_id IN (?)', $entityIds)
            ->where('pev.store_id = ?', $storeId)
            ->where('ea.attribute_code IN (?)', self::ROLE_CODES);

        return $conn->fetchAll($select);
    }

    /**
     * Apply intended image role value.
     * Returns affected row count.
     */
    public function applyRoleValue(int $entityId, int $storeId, string $roleCode, ?string $value): int
    {
        $conn        = $this->resourceConnection->getConnection();
        $attributeId = $this->resolveAttributeId($roleCode, $conn);
        if ($attributeId === null) {
            return 0;
        }

        return $conn->update(
            $this->resourceConnection->getTableName('catalog_product_entity_varchar'),
            ['value' => $value],
            [
                'entity_id = ?'    => $entityId,
                'store_id = ?'     => $storeId,
                'attribute_id = ?' => $attributeId,
            ]
        );
    }

    // ── Position ──────────────────────────────────────────────────────────────

    /**
     * Load current gallery position values for given entity IDs and store.
     *
     * @return array  Rows: entity_id, sku, store_id, file (attribute_code), label, position, disabled, roles
     */
    public function loadCurrentPositions(array $entityIds, int $storeId): array
    {
        if (empty($entityIds)) {
            return [];
        }

        $conn   = $this->resourceConnection->getConnection();
        $select = $conn->select()
            ->from(
                ['mgv' => $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value')],
                ['entity_id', 'store_id', 'position', 'label', 'disabled']
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
            ->where('mgv.entity_id IN (?)', $entityIds)
            ->where('mgv.store_id = ?', $storeId);

        return $conn->fetchAll($select);
    }

    /**
     * Apply intended gallery position for one image file per product/store.
     * attribute_code here is the gallery file path (e.g. /a/b/abc.jpg).
     * Returns affected row count.
     */
    public function applyPositionValue(int $entityId, int $storeId, string $filePath, ?string $value): int
    {
        $conn    = $this->resourceConnection->getConnection();
        $valueId = $this->resolveValueId($filePath, $entityId, $conn);
        if ($valueId === null) {
            return 0;
        }

        return $conn->update(
            $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value'),
            ['position' => $value !== null ? (int) $value : null],
            [
                'value_id = ?'  => $valueId,
                'store_id = ?'  => $storeId,
            ]
        );
    }

    // ── Store-view scan & delete ───────────────────────────────────────────────

    /**
     * Load all store-view-specific image role rows.
     * storeId = 0 → all non-zero stores. Empty entityIds → all products.
     *
     * @return array  Rows: entity_id, sku, store_id, attribute_code, value
     */
    public function loadStoreViewRoles(array $entityIds, int $storeId, array $protectedIds = []): array
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
            ->where('ea.attribute_code IN (?)', self::ROLE_CODES);

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

        return $conn->fetchAll($select);
    }

    /**
     * Delete store-view-specific image role rows.
     * storeId = 0 → all non-zero stores. Empty entityIds → all products.
     *
     * Returns total affected row count.
     */
    public function deleteStoreViewRoles(array $entityIds, int $storeId, array $protectedIds = []): int
    {
        $conn = $this->resourceConnection->getConnection();

        // Resolve attribute IDs for all role codes
        $attrSelect = $conn->select()
            ->from($this->resourceConnection->getTableName('eav_attribute'), ['attribute_id'])
            ->where('attribute_code IN (?)', self::ROLE_CODES);
        $attrIds = $conn->fetchCol($attrSelect);

        if (empty($attrIds)) {
            return 0;
        }

        $where = ['attribute_id IN (?)' => $attrIds];

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
            $this->resourceConnection->getTableName('catalog_product_entity_varchar'),
            $where
        );
    }

    /**
     * Load all store-view-specific gallery position rows.
     * storeId = 0 → all non-zero stores. Empty entityIds → all products.
     *
     * @return array  Rows: entity_id, sku, store_id, file, label, position, disabled
     */
    public function loadStoreViewPositions(array $entityIds, int $storeId, array $protectedIds = []): array
    {
        $conn   = $this->resourceConnection->getConnection();
        $select = $conn->select()
            ->from(
                ['mgv' => $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value')],
                ['entity_id', 'store_id', 'position', 'label', 'disabled']
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
            );

        if ($storeId > 0) {
            $select->where('mgv.store_id = ?', $storeId);
        } else {
            $select->where('mgv.store_id > 0');
        }

        if (!empty($entityIds)) {
            $select->where('mgv.entity_id IN (?)', $entityIds);
        }
        if (!empty($protectedIds)) {
            $select->where('mgv.store_id NOT IN (?)', $protectedIds);
        }

        return $conn->fetchAll($select);
    }

    /**
     * Delete store-view-specific gallery position rows.
     * storeId = 0 → all non-zero stores. Empty entityIds → all products.
     *
     * Returns total affected row count.
     */
    public function deleteStoreViewPositions(array $entityIds, int $storeId, array $protectedIds = []): int
    {
        $conn = $this->resourceConnection->getConnection();

        // Find value_ids belonging to the product scope
        $mgTable  = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery');
        $mgvTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value');
        $mveTable = $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value_to_entity');

        $valueIdSelect = $conn->select()
            ->from(['mgve' => $mveTable], ['value_id' => 'mgve.value_id'])
            ->join(['mg' => $mgTable], 'mgve.value_id = mg.value_id', []);

        if (!empty($entityIds)) {
            $valueIdSelect->where('mgve.entity_id IN (?)', $entityIds);
        }

        $valueIds = $conn->fetchCol($valueIdSelect);
        if (empty($valueIds)) {
            return 0;
        }

        $where = ['value_id IN (?)' => $valueIds];

        if ($storeId > 0) {
            $where['store_id = ?'] = $storeId;
        } else {
            $where[] = 'store_id > 0';
        }

        if (!empty($protectedIds)) {
            $where['store_id NOT IN (?)'] = $protectedIds;
        }

        return $conn->delete($mgvTable, $where);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function resolveAttributeId(string $code, $conn): ?int
    {
        $select = $conn->select()
            ->from($this->resourceConnection->getTableName('eav_attribute'), ['attribute_id'])
            ->where('attribute_code = ?', $code)
            ->limit(1);

        $id = $conn->fetchOne($select);
        return $id !== false ? (int) $id : null;
    }

    private function resolveValueId(string $filePath, int $entityId, $conn): ?int
    {
        $select = $conn->select()
            ->from(
                ['mg' => $this->resourceConnection->getTableName('catalog_product_entity_media_gallery')],
                ['value_id']
            )
            ->join(
                ['mgve' => $this->resourceConnection->getTableName('catalog_product_entity_media_gallery_value_to_entity')],
                'mg.value_id = mgve.value_id',
                []
            )
            ->where('mg.file = ?', $filePath)
            ->where('mgve.entity_id = ?', $entityId)
            ->limit(1);

        $id = $conn->fetchOne($select);
        return $id !== false ? (int) $id : null;
    }
}
