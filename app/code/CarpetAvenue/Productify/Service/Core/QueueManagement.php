<?php

declare(strict_types=1);

namespace CarpetAvenue\Productify\Service\Core;

use Exception;
use Magento\Framework\App\ResourceConnection;
use Zend_Db_Expr;

class QueueManagement
{
    public function __construct(
        protected ResourceConnection $resourceConnection,
    ) {
    }

    /**
     * Add all products (full reindex)
     */
    public function addAllProductsToQueue(): void
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(['cpe' => $connection->getTableName('catalog_product_entity')], ['entity_id'])
            ->where('cpe.entity_id IS NOT NULL');

        $productIds = $connection->fetchCol($select);

        $this->addProductsToQueue($productIds, 'full_reindex');
    }

    /**
     * Add specific product IDs (product changes)
     */
    public function addProductsByIdToQueue($ids = []): void
    {
        if (empty($ids)) {
            return;
        }

        $this->addProductsToQueue($ids, 'product_changes', 1);
    }

    /**
     * Internal queue insert
     */
    private function addProductsToQueue(array $productIds, string $triggerFrom, $priority = 5): void
    {
        if (empty($productIds)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $queueTable = $connection->getTableName('carpetavenue_productify_queue');

        $queueData = [];
        foreach ($productIds as $productId) {
            $queueData[] = [
                'product_id'     => (int)$productId,
                'trigger_from'   => $triggerFrom,
                'priority'       => $priority,
                'status'         => 'pending',
                'added_on_queue' => new Zend_Db_Expr('NOW()')
            ];
        }

        $connection->insertOnDuplicate(
            $queueTable,
            $queueData,
            ['trigger_from', 'status', 'added_on_queue']
        );
    }

    /**
     * Safely fetch next batch and mark them as processing
     * @throws Exception
     */
    public function getNextProductBatch(int $batchSize = 50): array
    {
        $connection = $this->resourceConnection->getConnection();
        $queueTable = $connection->getTableName('carpetavenue_productify_queue');

        $connection->beginTransaction();

        try {
            $select = $connection->select()
                ->from($queueTable, ['id', 'product_id'])
                ->where('status = ?', 'pending')
                ->order('priority ASC')
                ->order('added_on_queue ASC')
                ->limit($batchSize)
                ->forUpdate();

            $rows = $connection->fetchAll($select);

            if (!empty($rows)) {
                $ids = array_column($rows, 'id');

                $connection->update(
                    $queueTable,
                    [
                        'status'     => 'processing',
                        'process_at' => new Zend_Db_Expr('NOW()')
                    ],
                    ['id IN (?)' => $ids]
                );
            }

            $connection->commit();
        } catch (Exception $e) {
            $connection->rollBack();
            throw $e;
        }

        return $rows;
    }

    public function markBatchComplete($productIds = []): void
    {
        if (empty($productIds)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $queueTable = $connection->getTableName('carpetavenue_productify_queue');

        $connection->update(
            $queueTable,
            [
                'status'     => 'complete',
                'process_at' => new Zend_Db_Expr('NOW()')
            ],
            ['product_id IN (?)' => $productIds, 'status = ?' => 'processing']
        );
    }

    /**
     * Mark complete
     */
    public function markComplete(int|array $ids, ?string $message = null): void
    {
        $this->updateStatus($ids, 'complete', $message ?? 'Successfully reindexed');
    }

    /**
     * Mark failed
     */
    public function markFailed(int|array $ids, ?string $message = null): void
    {
        $this->updateStatus($ids, 'failed', $message ?? 'Unknown error');
    }

    /**
     * Retry specific failed rows
     */
    public function retryFailed(int|array $ids, int $newPriority = 3, bool $clearMessage = true): void
    {
        $ids = (array)$ids;
        if (empty($ids)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $queueTable = $connection->getTableName('carpetavenue_productify_queue');

        $updateData = [
            'status'         => 'pending',
            'priority'       => $newPriority,
            'process_at'     => null,
            'added_on_queue' => new Zend_Db_Expr('NOW()')
        ];

        if ($clearMessage) {
            $updateData['message'] = null;
        }

        $connection->update(
            $queueTable,
            $updateData,
            ['id IN (?)' => $ids, 'status = ?' => 'failed']
        );
    }

    /**
     * Retry all failed rows
     */
    public function retryAllFailed(int $newPriority = 3, bool $clearMessage = true): int
    {
        $connection = $this->resourceConnection->getConnection();
        $queueTable = $connection->getTableName('carpetavenue_productify_queue');

        $updateData = [
            'status'         => 'pending',
            'priority'       => $newPriority,
            'process_at'     => null,
            'added_on_queue' => new Zend_Db_Expr('NOW()')
        ];

        if ($clearMessage) {
            $updateData['message'] = null;
        }

        return $connection->update(
            $queueTable,
            $updateData,
            ['status = ?' => 'failed']
        );
    }

    /**
     * Mark stuck jobs as failed if they exceed timeout
     */
    public function markStuckAsFailed(int $timeoutMinutes = 30, ?string $message = null): int
    {
        $connection = $this->resourceConnection->getConnection();
        $queueTable = $connection->getTableName('carpetavenue_productify_queue');

        $message = $message ?? sprintf('Marked failed due to timeout > %d minutes', $timeoutMinutes);

        $where = [
            'status = ?'        => 'processing',
            'process_at < ?'    => new Zend_Db_Expr("NOW() - INTERVAL {$timeoutMinutes} MINUTE")
        ];

        return $connection->update(
            $queueTable,
            [
                'status'  => 'failed',
                'message' => $message
            ],
            $where
        );
    }

    /**
     * Internal generic updater
     */
    private function updateStatus(int|array $ids, string $status, ?string $message = null): void
    {
        $ids = (array)$ids;
        if (empty($ids)) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $queueTable = $connection->getTableName('carpetavenue_productify_queue');

        $connection->update(
            $queueTable,
            [
                'status'  => $status,
                'message' => $message,
            ],
            ['id IN (?)' => $ids]
        );
    }
}
