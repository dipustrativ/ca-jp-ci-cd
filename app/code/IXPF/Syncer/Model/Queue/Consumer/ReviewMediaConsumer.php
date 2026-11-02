<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Queue\Consumer;

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Model\Queue\ReviewMediaMessage;
use IXPF\Syncer\Services\PhotoStudio\ProcessGoogleBucketImages;
use IXPF\Syncer\Services\Reviews\ReviewCacheCleaner;
use IXPF\Syncer\Services\Reviews\ReviewMediaStorage;
use Magento\Framework\App\ResourceConnection;
use IXPF\Syncer\Helper\Logger\ReviewLogger;
use Throwable;

/**
 * Fetches one review photo or bought-item thumbnail from the GCS bucket.
 *
 * Idempotent by design: if the target column is already set and the file is on disk, the
 * message is a no-op. Combined with the hash diff in UpsertReviewGroup, a review whose photos
 * are unchanged is never re-fetched and its derivatives are never regenerated.
 *
 * Never rethrows. A rethrow puts the message back on the queue, and a bucket path that 404s
 * would then be retried forever. Transport failures get three attempts with backoff here;
 * after that the column stays null and the error is logged.
 */
class ReviewMediaConsumer
{
    private const MAX_ATTEMPTS = 3;
    private const BACKOFF_SECONDS = [1, 3];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ProcessGoogleBucketImages $bucket,
        private readonly ReviewMediaStorage $storage,
        private readonly ReviewCacheCleaner $cacheCleaner,
        private readonly Config $config,
        private readonly ReviewLogger $logger
    ) {
    }

    public function execute(ReviewMediaMessage $message): void
    {
        try {
            if (!$this->config->isReviewMediaEnabled()) {
                return;
            }

            if ($message->getType() === ReviewMediaMessage::TYPE_THUMBNAIL) {
                $this->processThumbnail($message);
            } else {
                $this->processPhoto($message);
            }
        } catch (Throwable $e) {
            $this->logger->writeFailureDaily($e, sprintf(
                'Review media failed for review "%s" (%s)',
                $message->getReviewId(),
                $message->getBucketPath()
            ));
        }
    }

    private function processPhoto(ReviewMediaMessage $message): void
    {
        $connection = $this->resourceConnection->getConnection();
        $photoTable = $this->resourceConnection->getTableName('ixpf_review_photo');

        // Joined rather than resolving the entity_id first: one round trip instead of two, and
        // a review deleted between publish and consume simply returns no row.
        $row = $connection->fetchRow(
            $connection->select()
                ->from(['p' => $photoTable], ['entity_id', 'local_path'])
                ->join(
                    ['r' => $this->resourceConnection->getTableName('ixpf_review')],
                    'r.entity_id = p.review_entity_id',
                    ['review_group']
                )
                ->where('r.review_id = ?', $message->getReviewId())
                ->where('p.bucket_path = ?', $message->getBucketPath())
                ->limit(1)
        );

        if ($row === false) {
            return;
        }

        if ($this->alreadyStored($row['local_path'] ?? null)) {
            return;
        }

        $relativePath = $this->fetch($message->getReviewId(), $message->getBucketPath());

        $connection->update(
            $photoTable,
            ['local_path' => $relativePath, 'downloaded_at' => gmdate('Y-m-d H:i:s')],
            ['entity_id = ?' => (int)$row['entity_id']]
        );

        // ponytail: one tag clean per message. Media lands after the push, so a pool cached in
        // between keeps serving image-less reviews for the provider's whole lifetime without this.
        // Batch per group at the end of the run if a large backfill makes the cleans hurt.
        $this->cacheCleaner->cleanGroup((string)($row['review_group'] ?? ''));
    }

    private function processThumbnail(ReviewMediaMessage $message): void
    {
        $connection  = $this->resourceConnection->getConnection();
        $reviewTable = $this->resourceConnection->getTableName('ixpf_review');

        // Matched on the path as well, like photos are: a message published before a push moved
        // the thumbnail must not store the old image against the new path. thumbnail_local is
        // never re-queued once set, so that stale file would stick for good.
        $row = $connection->fetchRow(
            $connection->select()
                ->from($reviewTable, ['entity_id', 'thumbnail_local', 'review_group'])
                ->where('review_id = ?', $message->getReviewId())
                ->where('thumbnail_path = ?', $message->getBucketPath())
                ->limit(1)
        );

        if ($row === false || $this->alreadyStored($row['thumbnail_local'] ?? null)) {
            return;
        }

        // A bought-item thumbnail points at the same bucket object the product gallery already
        // pulled, so reuse that file instead of downloading and resizing a second copy.
        $existing = $this->storage->findExistingGalleryFile($message->getBucketPath());
        $relativePath = $existing ?? $this->fetch($message->getReviewId(), $message->getBucketPath());

        // Path re-checked on write too: a push can move it while the download is in flight.
        $connection->update(
            $reviewTable,
            ['thumbnail_local' => $relativePath],
            ['entity_id = ?' => (int)$row['entity_id'], 'thumbnail_path = ?' => $message->getBucketPath()]
        );

        $this->cacheCleaner->cleanGroup((string)($row['review_group'] ?? ''));
    }

    /**
     * Downloads the object and generates both derivatives.
     *
     * @return string media-relative path of the original
     */
    private function fetch(string $reviewId, string $bucketPath): string
    {
        $relativePath = $this->storage->relativePath($reviewId, $bucketPath);
        $absolutePath = $this->storage->absolutePath($relativePath);

        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $this->bucket->downloadObject($bucketPath, $absolutePath);
                $lastError = null;
                break;
            } catch (Throwable $e) {
                $lastError = $e;

                // A 4xx is permanent - wrong bucket path, no access. Retrying burns three GCS
                // calls and four seconds of sleep per file on every push for something that
                // can never succeed, which is exactly what a wrong bucket prefix would do
                // across a whole pool.
                if ($e->getCode() >= 400 && $e->getCode() < 500) {
                    break;
                }

                $backoff = self::BACKOFF_SECONDS[$attempt - 1] ?? null;
                if ($backoff !== null) {
                    sleep($backoff);
                }
            }
        }

        if ($lastError !== null) {
            throw $lastError;
        }

        try {
            $this->storage->generateDerivatives($relativePath);
        } catch (Throwable $e) {
            // A missing derivative degrades the card; it does not invalidate the download.
            $this->logger->writeFailureDaily($e, sprintf(
                'Review media derivatives failed for "%s" (%s)',
                $reviewId,
                $relativePath
            ));
        }

        return $relativePath;
    }

    private function alreadyStored(?string $localPath): bool
    {
        return $localPath !== null && $localPath !== '' && $this->storage->exists($localPath);
    }
}
