<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package CarpetAvenue_Utilities
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Model;

use CarpetAvenue\Utilities\Model\ResourceModel\ImageResizeLog as ImageResizeLogResource;
use Magento\Framework\App\ResourceConnection;

/**
 * Lightweight repository for carpetavenue_image_resize_log.
 *
 * Bypasses the heavy Model layer for bulk upsert performance.
 */
class ImageResizeLogRepository
{
    private const TABLE = 'carpetavenue_image_resize_log';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Returns existing row data keyed by local_path, for the given paths.
     *
     * @param  string[] $localPaths
     * @return array<string, array>   localPath → row
     */
    public function getExistingByPaths(array $localPaths): array
    {
        if (empty($localPaths)) {
            return [];
        }
        $conn  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $rows  = $conn->fetchAll(
            $conn->select()->from($table)->where('local_path IN (?)', $localPaths)
        );
        $map = [];
        foreach ($rows as $row) {
            $map[$row['local_path']] = $row;
        }
        return $map;
    }

    /**
     * Insert or update (ON DUPLICATE KEY UPDATE) a single log row.
     */
    public function upsert(array $data): void
    {
        $conn  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        // Fields that are never overwritten on update
        $insertOnly = ['created_at', 'product_id', 'sku', 'image_type', 'local_path', 'db_path'];

        $update = [];
        foreach ($data as $col => $val) {
            if (!in_array($col, $insertOnly, true)) {
                $update[$col] = $val;
            }
        }

        $conn->insertOnDuplicate($table, $data, array_keys($update));
    }

    /**
     * Mark a row as RESTORED.
     */
    public function markRestored(string $localPath): void
    {
        $conn  = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);

        $conn->update(
            $table,
            [
                'status'      => ImageResizeLog::STATUS_RESTORED,
                'restored_at' => date('Y-m-d H:i:s'),
            ],
            ['local_path = ?' => $localPath, 'status = ?' => ImageResizeLog::STATUS_CONVERTED]
        );
    }
}

