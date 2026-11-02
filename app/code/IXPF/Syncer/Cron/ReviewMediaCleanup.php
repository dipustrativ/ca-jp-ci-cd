<?php

declare(strict_types=1);

namespace IXPF\Syncer\Cron;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Services\Reviews\ReviewMediaStorage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use IXPF\Syncer\Helper\Logger\ReviewLogger;
use Throwable;

/**
 * Deletes media directories for reviews that no longer exist.
 *
 * A review removed by a push cascades its photo rows away, but the files it downloaded stay on
 * disk. This is the only thing that reclaims them.
 */
class ReviewMediaCleanup
{
    public function __construct(
        private readonly State $state,
        private readonly ResourceConnection $resourceConnection,
        private readonly ReviewMediaStorage $storage,
        private readonly Config $config,
        private readonly ReviewLogger $logger
    ) {
    }

    public function execute(): void
    {
        try {
            if (!$this->config->isReviewsEnabled()) {
                return;
            }

            $this->logger->writeDaily('Review media cleanup started');
            set_time_limit(0);

            // Media path resolution needs an area code.
            $removed = $this->state->emulateAreaCode('frontend', [$this, 'removeOrphans']);

            $this->logger->writeDaily(sprintf('Review media cleanup finished, %d directories removed', $removed));
        } catch (Throwable $e) {
            $this->logger->writeFailureDaily($e, 'Review media cleanup failed');
        }
    }

    /**
     * Public only because emulateAreaCode() needs a callable.
     *
     * @return int directories removed
     */
    public function removeOrphans(): int
    {
        $storedIds = $this->storage->listStoredReviewIds();
        if ($storedIds === []) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $table      = $this->resourceConnection->getTableName('ixpf_review');

        // Compare directory names on both sides, never raw ids against directory names: a
        // review_id whose characters get replaced when it becomes a directory would otherwise
        // never match and its live files would be deleted. That rules out a `review_id IN
        // (<dir names>)` query and means reading every id.
        // ponytail: full id scan, one varchar(32) column. Batch by entity_id if the review
        // count ever makes the array too expensive to hold.
        $live = [];
        foreach ($connection->fetchCol($connection->select()->from($table, ['review_id'])) as $reviewId) {
            $live[$this->storage->directoryName((string)$reviewId)] = true;
        }

        $removed = 0;
        foreach ($storedIds as $reviewId) {
            if (isset($live[$reviewId])) {
                continue;
            }
            $this->storage->deleteReviewDirectory($reviewId);
            $removed++;
        }

        return $removed;
    }
}
