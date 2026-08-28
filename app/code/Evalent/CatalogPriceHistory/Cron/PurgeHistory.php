<?php

declare(strict_types=1);

namespace Evalent\CatalogPriceHistory\Cron;

use Evalent\CatalogPriceHistory\Api\ConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Trims catalog_product_index_price_history, which ProcessProducts only ever
 * appends to. Omnibus needs the lowest price over the last lowest_price_days
 * only; nothing else reads the table.
 */
class PurgeHistory
{
    private const TABLE = 'catalog_product_index_price_history';

    /** Headroom so raising lowest_price_days in Admin does not lose history. */
    private const RETENTION_MARGIN_DAYS = 7;

    /** Batched so the job never long-locks a table the hourly indexer writes to. */
    private const BATCH_SIZE = 5000;

    /** Caps a first run against a large backlog; the next run continues. */
    private const MAX_BATCHES_PER_RUN = 40;

    /**
     * @param ResourceConnection $resourceConnection
     * @param ConfigInterface $config
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ConfigInterface $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Delete history rows older than the retention window, in bounded batches.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->config->isCronEnabled()) {
            return;
        }

        $days = $this->getRetentionDays();
        if ($days <= 0) {
            return;
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $days), new \DateTimeZone('UTC'));

        $deleted = 0;
        try {
            for ($batch = 0; $batch < self::MAX_BATCHES_PER_RUN; $batch++) {
                $rows = $connection->query(
                    sprintf(
                        'DELETE FROM %s WHERE created_at < ? LIMIT %d',
                        $connection->quoteIdentifier($table),
                        self::BATCH_SIZE
                    ),
                    [$cutoff->format('Y-m-d H:i:s')]
                )->rowCount();

                $deleted += $rows;
                if ($rows < self::BATCH_SIZE) {
                    break;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('PRICE HISTORY PURGE: ' . $e->getMessage());
            return;
        }

        if ($deleted > 0) {
            $this->logger->info(
                sprintf('PRICE HISTORY PURGE: removed %d rows older than %d days', $deleted, $days)
            );
        }
    }

    /**
     * Longest retention any store view needs, plus the margin.
     *
     * Config lowest_price_days is store-scoped, so one store's value would
     * under-retain for the others.
     *
     * @return int
     */
    private function getRetentionDays(): int
    {
        $maxDays = 0;
        foreach ($this->storeManager->getStores() as $store) {
            $maxDays = max($maxDays, (int) $this->config->getLowestPriceDays($store->getId()));
        }

        return $maxDays > 0 ? $maxDays + self::RETENTION_MARGIN_DAYS : 0;
    }
}
