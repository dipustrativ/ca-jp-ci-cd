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
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the override fix workflow (delete-as-default):
 *   1. Resolve product scope (all | by SKU | by entity ID)
 *   2. If dry-run: return preview of store-view rows that would be deleted
 *   3. Load current store-view rows (the overrides to remove)
 *   4. Backup those rows to CSV (unless --no-backup)
 *   5. DELETE the store-view rows → Magento falls back to global (store_id=0) value
 *   6. Invalidate catalog/full_page cache for affected products
 *   7. Return summary
 *
 * storeId = 0 means "all non-zero stores" (delete from every non-protected store).
 * Protected stores (configured in admin) are always excluded from deletion.
 *
 * Result array shape:
 *   ['processed' => int, 'fixed' => int, 'errors' => int, 'csv_path' => string, 'preview' => array]
 */
class FixService
{
    private const CACHE_TYPES = ['catalog', 'full_page', 'block_html'];

    public function __construct(
        private readonly PriceOverrideService $priceService,
        private readonly ImageOverrideService $imageService,
        private readonly CustomAttributeOverrideService $customService,
        private readonly BackupService $backupService,
        private readonly Config $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly TypeListInterface $cacheTypeList,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run the fix for a given type/attribute/scope.
     *
     * @param string   $type          price | image | custom
     * @param string   $attributeCode Attribute code (price/custom). Empty for image.
     * @param string   $imageType     roles | position | all  (only for type=image)
     * @param string   $scope         all | sku | id
     * @param string[] $scopeValues   SKUs or entity ID strings (when scope != all)
     * @param int      $storeId       Target store view ID. 0 = all non-protected stores.
     * @param bool     $noBackup      Skip CSV backup step
     * @param bool     $dryRun        Preview only — no backup, no writes
     *
     * @return array  ['processed', 'fixed', 'errors', 'csv_path', 'preview']
     */
    public function fix(
        string $type,
        string $attributeCode,
        string $imageType,
        string $scope,
        array $scopeValues,
        int $storeId,
        bool $noBackup = false,
        bool $dryRun = false
    ): array {
        // When a specific store is requested, check if it is protected
        if ($storeId > 0 && in_array($storeId, $this->config->getProtectedStoreIds(), true)) {
            return ['processed' => 0, 'fixed' => 0, 'errors' => 0, 'csv_path' => '', 'preview' => []];
        }

        $entityIds    = $this->resolveEntityIds($scope, $scopeValues);
        $protectedIds = $this->config->getProtectedStoreIds();

        return match ($type) {
            'price'  => $this->fixPrice($entityIds, $storeId, $attributeCode, $scope, $scopeValues, $protectedIds, $noBackup, $dryRun),
            'image'  => $this->fixImage($entityIds, $storeId, $imageType, $scope, $scopeValues, $protectedIds, $noBackup, $dryRun),
            'custom' => $this->fixCustom($entityIds, $storeId, $attributeCode, $scope, $scopeValues, $protectedIds, $noBackup, $dryRun),
            default  => throw new \InvalidArgumentException("Unknown fix type: {$type}"),
        };
    }

    // ── Price ─────────────────────────────────────────────────────────────────

    private function fixPrice(
        array $entityIds,
        int $storeId,
        string $attributeCode,
        string $scope,
        array $scopeValues,
        array $protectedIds,
        bool $noBackup,
        bool $dryRun
    ): array {
        $rows = $this->priceService->loadStoreViewRows($entityIds, $storeId, [$attributeCode], $protectedIds);

        if ($dryRun) {
            return $this->buildDeletePreview($rows);
        }

        if (!$noBackup && !empty($rows)) {
            $this->backupService->backupPricePreloaded($rows, $storeId, $attributeCode, $scope, $scopeValues);
        }

        return $this->deleteRows(
            $entityIds,
            $storeId,
            $protectedIds,
            count($rows),
            function () use ($entityIds, $storeId, $attributeCode, $protectedIds): int {
                return $this->priceService->deleteStoreViewRows($entityIds, $storeId, $attributeCode, $protectedIds);
            },
            array_column($rows, 'entity_id')
        );
    }

    // ── Image ─────────────────────────────────────────────────────────────────

    private function fixImage(
        array $entityIds,
        int $storeId,
        string $imageType,
        string $scope,
        array $scopeValues,
        array $protectedIds,
        bool $noBackup,
        bool $dryRun
    ): array {
        $result = ['processed' => 0, 'fixed' => 0, 'errors' => 0, 'csv_path' => '', 'preview' => []];

        if ($imageType === 'roles' || $imageType === 'all') {
            $sub    = $this->fixImageRoles($entityIds, $storeId, $scope, $scopeValues, $protectedIds, $noBackup, $dryRun);
            $result = $this->mergeResults($result, $sub);
        }

        if ($imageType === 'position' || $imageType === 'all') {
            $sub    = $this->fixImagePositions($entityIds, $storeId, $scope, $scopeValues, $protectedIds, $noBackup, $dryRun);
            $result = $this->mergeResults($result, $sub);
        }

        return $result;
    }

    private function fixImageRoles(
        array $entityIds,
        int $storeId,
        string $scope,
        array $scopeValues,
        array $protectedIds,
        bool $noBackup,
        bool $dryRun
    ): array {
        $rows = $this->imageService->loadStoreViewRoles($entityIds, $storeId, $protectedIds);

        if ($dryRun) {
            return $this->buildDeletePreview($rows);
        }

        if (!$noBackup && !empty($rows)) {
            $this->backupService->backupImageRolesPreloaded($rows, $storeId, $scope, $scopeValues);
        }

        return $this->deleteRows(
            $entityIds,
            $storeId,
            $protectedIds,
            count($rows),
            function () use ($entityIds, $storeId, $protectedIds): int {
                return $this->imageService->deleteStoreViewRoles($entityIds, $storeId, $protectedIds);
            },
            array_column($rows, 'entity_id')
        );
    }

    private function fixImagePositions(
        array $entityIds,
        int $storeId,
        string $scope,
        array $scopeValues,
        array $protectedIds,
        bool $noBackup,
        bool $dryRun
    ): array {
        $rows = $this->imageService->loadStoreViewPositions($entityIds, $storeId, $protectedIds);

        if ($dryRun) {
            // Normalize for preview: attribute_code = file path
            $normalized = array_map(fn($r) => array_merge($r, ['attribute_code' => $r['file'], 'value' => $r['position']]), $rows);
            return $this->buildDeletePreview($normalized);
        }

        if (!$noBackup && !empty($rows)) {
            $this->backupService->backupImagePositionsPreloaded($rows, $storeId, $scope, $scopeValues);
        }

        return $this->deleteRows(
            $entityIds,
            $storeId,
            $protectedIds,
            count($rows),
            function () use ($entityIds, $storeId, $protectedIds): int {
                return $this->imageService->deleteStoreViewPositions($entityIds, $storeId, $protectedIds);
            },
            array_column($rows, 'entity_id')
        );
    }

    // ── Custom ────────────────────────────────────────────────────────────────

    private function fixCustom(
        array $entityIds,
        int $storeId,
        string $attributeCode,
        string $scope,
        array $scopeValues,
        array $protectedIds,
        bool $noBackup,
        bool $dryRun
    ): array {
        $rows = $this->customService->loadStoreViewRows($entityIds, $storeId, [$attributeCode], $protectedIds);

        if ($dryRun) {
            return $this->buildDeletePreview($rows);
        }

        if (!$noBackup && !empty($rows)) {
            $this->backupService->backupCustomPreloaded($rows, $storeId, $attributeCode, $scope, $scopeValues);
        }

        return $this->deleteRows(
            $entityIds,
            $storeId,
            $protectedIds,
            count($rows),
            function () use ($entityIds, $storeId, $attributeCode, $protectedIds): int {
                return $this->customService->deleteStoreViewRows($entityIds, $storeId, $attributeCode, $protectedIds);
            },
            array_column($rows, 'entity_id')
        );
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Execute the delete callable, invalidate cache, return summary.
     *
     * @param callable $deleteFn     Callable that performs the DELETE and returns affected row count
     * @param int[]    $entityIds    Entity IDs loaded before delete (for cache invalidation)
     */
    private function deleteRows(
        array $scopeEntityIds,
        int $storeId,
        array $protectedIds,
        int $processed,
        callable $deleteFn,
        array $loadedEntityIds
    ): array {
        $fixed  = 0;
        $errors = 0;

        try {
            $affected = $deleteFn();
            $fixed    = $affected;
        } catch (\Exception $e) {
            $errors++;
            $this->logger->error('[AttributeOverride] Delete failed: ' . $e->getMessage());
        }

        if ($fixed > 0) {
            $this->invalidateCache();
        }

        return [
            'processed' => $processed,
            'fixed'     => $fixed,
            'errors'    => $errors,
            'csv_path'  => '',
            'preview'   => [],
        ];
    }

    /**
     * Build dry-run preview: show rows that would be deleted.
     * intended_value = null (will fall back to global after deletion).
     */
    private function buildDeletePreview(array $rows): array
    {
        $preview = [];
        foreach ($rows as $row) {
            $preview[] = [
                'entity_id'      => $row['entity_id'],
                'sku'            => $row['sku'] ?? '',
                'store_id'       => $row['store_id'],
                'attribute_code' => $row['attribute_code'] ?? ($row['file'] ?? ''),
                'current_value'  => $row['value'] !== null ? (string) $row['value'] : null,
                'intended_value' => null,  // will use global (store_id=0) after deletion
            ];
        }

        return [
            'processed' => count($rows),
            'fixed'     => 0,
            'errors'    => 0,
            'csv_path'  => '',
            'preview'   => $preview,
        ];
    }

    /**
     * Resolve product entity IDs from scope and scope values.
     * Returns empty array when scope = 'all' (means: operate on all products).
     */
    private function resolveEntityIds(string $scope, array $scopeValues): array
    {
        if ($scope === 'all' || empty($scopeValues)) {
            return [];
        }

        if ($scope === 'id') {
            return array_map('intval', $scopeValues);
        }

        if ($scope === 'sku') {
            $conn   = $this->resourceConnection->getConnection();
            $select = $conn->select()
                ->from(
                    $this->resourceConnection->getTableName('catalog_product_entity'),
                    ['entity_id']
                )
                ->where('sku IN (?)', $scopeValues);
            return array_map('intval', $conn->fetchCol($select));
        }

        return [];
    }

    private function invalidateCache(): void
    {
        foreach (self::CACHE_TYPES as $type) {
            try {
                $this->cacheTypeList->invalidate($type);
            } catch (\Exception $e) {
                $this->logger->warning("[AttributeOverride] Cache invalidation failed for '{$type}': " . $e->getMessage());
            }
        }
    }

    private function mergeResults(array $base, array $sub): array
    {
        return [
            'processed' => $base['processed'] + $sub['processed'],
            'fixed'     => $base['fixed'] + $sub['fixed'],
            'errors'    => $base['errors'] + $sub['errors'],
            'csv_path'  => $sub['csv_path'] ?: $base['csv_path'],
            'preview'   => array_merge($base['preview'], $sub['preview']),
        ];
    }
}
