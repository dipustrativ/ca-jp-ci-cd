<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Service\AttributeOverride;

use CarpetAvenue\Utilities\Model\OverrideBackupLogFactory;
use CarpetAvenue\Utilities\Model\ResourceModel\OverrideBackupLog as OverrideBackupLogResource;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Psr\Log\LoggerInterface;

/**
 * Exports current attribute values to CSV before a fix run.
 *
 * CSV paths: var/carpetavenue/override_backup/{type}_{attribute}_{store}_{ts}.csv
 *
 * CSV formats:
 *   price   → entity_id, sku, store_id, attribute_code, value
 *   image   → entity_id, sku, store_id, file, label, position, disabled
 *   custom  → entity_id, sku, store_id, attribute_code, value
 */
class BackupService
{
    private const BACKUP_DIR = 'carpetavenue/override_backup';

    public function __construct(
        private readonly PriceOverrideService $priceService,
        private readonly ImageOverrideService $imageService,
        private readonly CustomAttributeOverrideService $customService,
        private readonly OverrideBackupLogFactory $backupLogFactory,
        private readonly OverrideBackupLogResource $backupLogResource,
        private readonly DirectoryList $directoryList,
        private readonly FileDriver $fileDriver,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Backup current values before a price fix.
     *
     * @return array ['csv_path' => string, 'row_count' => int, 'log_id' => int]
     */
    public function backupPrice(
        array $entityIds,
        int $storeId,
        array $attributeCodes,
        string $scope,
        array $scopeValues
    ): array {
        $rows = $this->priceService->loadCurrentValues($entityIds, $storeId, $attributeCodes);
        $csvPath = $this->buildPath('price', implode('-', $attributeCodes), $storeId);

        $this->writeCsv($csvPath, ['entity_id', 'sku', 'store_id', 'attribute_code', 'value'], $rows, [
            'entity_id'      => 'entity_id',
            'sku'            => 'sku',
            'store_id'       => 'store_id',
            'attribute_code' => 'attribute_code',
            'value'          => 'value',
        ]);

        $logId = $this->writeLog('price', implode(',', $attributeCodes), $storeId, $scope, $scopeValues, $csvPath, count($rows));

        return ['csv_path' => $csvPath, 'row_count' => count($rows), 'log_id' => $logId];
    }

    /**
     * Backup current image role values before a fix.
     */
    public function backupImageRoles(
        array $entityIds,
        int $storeId,
        string $scope,
        array $scopeValues
    ): array {
        $rows    = $this->imageService->loadCurrentRoles($entityIds, $storeId);
        $csvPath = $this->buildPath('image_roles', 'roles', $storeId);

        $this->writeCsv($csvPath, ['entity_id', 'sku', 'store_id', 'attribute_code', 'value'], $rows, [
            'entity_id'      => 'entity_id',
            'sku'            => 'sku',
            'store_id'       => 'store_id',
            'attribute_code' => 'attribute_code',
            'value'          => 'value',
        ]);

        $logId = $this->writeLog('image', 'roles', $storeId, $scope, $scopeValues, $csvPath, count($rows));

        return ['csv_path' => $csvPath, 'row_count' => count($rows), 'log_id' => $logId];
    }

    /**
     * Backup current gallery position values before a fix.
     */
    public function backupImagePositions(
        array $entityIds,
        int $storeId,
        string $scope,
        array $scopeValues
    ): array {
        $rows    = $this->imageService->loadCurrentPositions($entityIds, $storeId);
        $csvPath = $this->buildPath('image_positions', 'position', $storeId);

        $this->writeCsv($csvPath, ['entity_id', 'sku', 'store_id', 'file', 'label', 'position', 'disabled'], $rows, [
            'entity_id' => 'entity_id',
            'sku'       => 'sku',
            'store_id'  => 'store_id',
            'file'      => 'file',
            'label'     => 'label',
            'position'  => 'position',
            'disabled'  => 'disabled',
        ]);

        $logId = $this->writeLog('image', 'position', $storeId, $scope, $scopeValues, $csvPath, count($rows));

        return ['csv_path' => $csvPath, 'row_count' => count($rows), 'log_id' => $logId];
    }

    /**
     * Backup current custom attribute values before a fix.
     */
    public function backupCustom(
        array $entityIds,
        int $storeId,
        array $attributeCodes,
        string $scope,
        array $scopeValues
    ): array {
        $rows    = $this->customService->loadCurrentValues($entityIds, $storeId, $attributeCodes);
        $csvPath = $this->buildPath('custom', implode('-', $attributeCodes), $storeId);

        $this->writeCsv($csvPath, ['entity_id', 'sku', 'store_id', 'attribute_code', 'value'], $rows, [
            'entity_id'      => 'entity_id',
            'sku'            => 'sku',
            'store_id'       => 'store_id',
            'attribute_code' => 'attribute_code',
            'value'          => 'value',
        ]);

        $logId = $this->writeLog('custom', implode(',', $attributeCodes), $storeId, $scope, $scopeValues, $csvPath, count($rows));

        return ['csv_path' => $csvPath, 'row_count' => count($rows), 'log_id' => $logId];
    }

    // ── Preloaded backup (rows already fetched by FixService) ─────────────────

    /**
     * Backup pre-loaded price rows to CSV without re-querying the DB.
     *
     * @param array  $rows        Rows: entity_id, sku, store_id, attribute_code, value
     * @param int    $storeId     Used in filename (0 = all stores)
     * @param string $attrLabel   Attribute code(s) string for filename
     * @return array ['csv_path' => string, 'row_count' => int, 'log_id' => int]
     */
    public function backupPricePreloaded(
        array $rows,
        int $storeId,
        string $attrLabel,
        string $scope,
        array $scopeValues
    ): array {
        $csvPath = $this->buildPath('price', $attrLabel, $storeId);
        $this->writeCsv($csvPath, ['entity_id', 'sku', 'store_id', 'attribute_code', 'value'], $rows, [
            'entity_id' => 'entity_id', 'sku' => 'sku', 'store_id' => 'store_id',
            'attribute_code' => 'attribute_code', 'value' => 'value',
        ]);
        $logId = $this->writeLog('price', $attrLabel, $storeId, $scope, $scopeValues, $csvPath, count($rows));
        return ['csv_path' => $csvPath, 'row_count' => count($rows), 'log_id' => $logId];
    }

    /**
     * Backup pre-loaded image role rows to CSV.
     *
     * @param array $rows  Rows: entity_id, sku, store_id, attribute_code, value
     */
    public function backupImageRolesPreloaded(
        array $rows,
        int $storeId,
        string $scope,
        array $scopeValues
    ): array {
        $csvPath = $this->buildPath('image_roles', 'roles', $storeId);
        $this->writeCsv($csvPath, ['entity_id', 'sku', 'store_id', 'attribute_code', 'value'], $rows, [
            'entity_id' => 'entity_id', 'sku' => 'sku', 'store_id' => 'store_id',
            'attribute_code' => 'attribute_code', 'value' => 'value',
        ]);
        $logId = $this->writeLog('image', 'roles', $storeId, $scope, $scopeValues, $csvPath, count($rows));
        return ['csv_path' => $csvPath, 'row_count' => count($rows), 'log_id' => $logId];
    }

    /**
     * Backup pre-loaded gallery position rows to CSV.
     *
     * @param array $rows  Rows: entity_id, sku, store_id, file, label, position, disabled
     */
    public function backupImagePositionsPreloaded(
        array $rows,
        int $storeId,
        string $scope,
        array $scopeValues
    ): array {
        $csvPath = $this->buildPath('image_positions', 'position', $storeId);
        $this->writeCsv($csvPath, ['entity_id', 'sku', 'store_id', 'file', 'label', 'position', 'disabled'], $rows, [
            'entity_id' => 'entity_id', 'sku' => 'sku', 'store_id' => 'store_id',
            'file' => 'file', 'label' => 'label', 'position' => 'position', 'disabled' => 'disabled',
        ]);
        $logId = $this->writeLog('image', 'position', $storeId, $scope, $scopeValues, $csvPath, count($rows));
        return ['csv_path' => $csvPath, 'row_count' => count($rows), 'log_id' => $logId];
    }

    /**
     * Backup pre-loaded custom attribute rows to CSV.
     *
     * @param array  $rows      Rows: entity_id, sku, store_id, attribute_code, value
     * @param string $attrLabel Attribute code(s) string for filename
     */
    public function backupCustomPreloaded(
        array $rows,
        int $storeId,
        string $attrLabel,
        string $scope,
        array $scopeValues
    ): array {
        $csvPath = $this->buildPath('custom', $attrLabel, $storeId);
        $this->writeCsv($csvPath, ['entity_id', 'sku', 'store_id', 'attribute_code', 'value'], $rows, [
            'entity_id' => 'entity_id', 'sku' => 'sku', 'store_id' => 'store_id',
            'attribute_code' => 'attribute_code', 'value' => 'value',
        ]);
        $logId = $this->writeLog('custom', $attrLabel, $storeId, $scope, $scopeValues, $csvPath, count($rows));
        return ['csv_path' => $csvPath, 'row_count' => count($rows), 'log_id' => $logId];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildPath(string $type, string $attribute, int $storeId): string
    {
        $varDir  = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        $dir     = $varDir . '/' . self::BACKUP_DIR;
        $safe    = preg_replace('/[^a-z0-9_\-]/', '_', strtolower($attribute));
        $file    = sprintf('%s_%s_%d_%s.csv', $type, $safe, $storeId, date('Ymd_His'));

        return $dir . '/' . $file;
    }

    private function writeCsv(string $path, array $headers, array $rows, array $columnMap): void
    {
        $dir = dirname($path);
        if (!$this->fileDriver->isDirectory($dir)) {
            $this->fileDriver->createDirectory($dir, 0755);
        }

        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV for writing: {$path}");
        }

        try {
            fputcsv($handle, $headers, ',', '"', '\\');
            foreach ($rows as $row) {
                $line = [];
                foreach ($columnMap as $csvCol => $rowKey) {
                    $line[] = $row[$rowKey] ?? '';
                }
                fputcsv($handle, $line, ',', '"', '\\');
            }
        } finally {
            fclose($handle);
        }
    }

    private function writeLog(
        string $type,
        string $code,
        int $storeId,
        string $scope,
        array $scopeValues,
        string $csvPath,
        int $rowCount
    ): int {
        try {
            $log = $this->backupLogFactory->create();
            $log->setData([
                'attribute_type' => $type,
                'attribute_code' => $code,
                'store_id'       => $storeId,
                'scope'          => $scope,
                'scope_values'   => !empty($scopeValues) ? implode(',', $scopeValues) : null,
                'csv_path'       => $csvPath,
                'row_count'      => $rowCount,
                'status'         => 'done',
            ]);
            $this->backupLogResource->save($log);
            return (int) $log->getId();
        } catch (\Exception $e) {
            $this->logger->error('[AttributeOverride] Backup log write failed: ' . $e->getMessage());
            return 0;
        }
    }
}
