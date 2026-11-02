<?php

declare(strict_types=1);

namespace IXPF\Syncer\Services\Reviews;

use DateTimeImmutable;
use DateTimeZone;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Model\Queue\ReviewMediaMessage;
use IXPF\Syncer\Model\Queue\ReviewMediaMessageFactory;
use IXPF\Syncer\Model\Reviews\TextEncoding;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use IXPF\Syncer\Helper\Logger\ReviewLogger;
use Monolog\Logger;
use Throwable;

/**
 * Applies one IXPF review push.
 *
 * One call replaces the entire review set for one review_group — anything absent from the
 * payload is deleted. The apply is a diff, not a rewrite: each review carries an md5 of its
 * own JSON as received, so a brand reply, a translation update or a moderation change writes
 * one row instead of the whole pool.
 *
 * Raw connection use is deliberate and confined to this class. The alternative is a loop of
 * model saves, which is one round-trip per review plus full model/event overhead on tables
 * that are flat and owned entirely by this module. insertOnDuplicate on the review_id unique
 * key also preserves entity_id across pushes, which is what keeps already-fetched photo paths
 * and their derivatives valid.
 */
class UpsertReviewGroup
{
    public const MEDIA_TOPIC = 'ixpf.review.media.download';

    private const BATCH_SIZE = 500;

    /**
     * Columns updated when a review row already exists. entity_id and review_id are never
     * touched — review_id is the unique key the upsert matches on, entity_id is what must
     * survive so already-fetched photos stay valid.
     *
     * review_group IS in the list: review_id is unique platform-wide, so if IXPF ever moves a
     * review into a different pool the row has to follow it. Without this the review would
     * silently stay in its old group and disappear from both.
     */
    private const REVIEW_UPDATE_COLUMNS = [
        'review_group',
        'payload_hash', 'sku', 'rating', 'rating_quality', 'rating_delivery', 'rating_customer_service',
        'reviewer', 'created_at', 'purchased_at', 'source', 'source_locale', 'source_store_code',
        'bought_name', 'bought_variant_texts', 'thumbnail_path', 'thumbnail_local', 'has_photos',
        'reply_created_at',
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PayloadValidator $validator,
        private readonly Config $config,
        private readonly ReviewCacheCleaner $cacheCleaner,
        private readonly PublisherInterface $publisher,
        private readonly ReviewMediaMessageFactory $mediaMessageFactory,
        private readonly ReviewLogger $logger
    ) {
    }

    /**
     * @return array{status: string, review_group?: string, reviews?: int, message?: string, field?: string}
     */
    public function upsert(mixed $payload): array
    {
        $error = $this->validator->validate($payload);
        if ($error !== null) {
            $this->log('error', 'Review payload rejected: ' . $error['message'] . ' [' . $error['field'] . ']');

            return ['status' => 'invalid', 'message' => $error['message'], 'field' => $error['field']];
        }

        $group   = (string)$payload['review_group'];
        $reviews = array_values($payload['reviews']);
        $started = microtime(true);

        $threshold = $this->config->getReviewWarnThreshold();
        if (count($reviews) > $threshold) {
            $this->log('warning', sprintf(
                'Review payload for "%s" holds %d reviews, above the %d warn threshold',
                $group,
                count($reviews),
                $threshold
            ));
        }

        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            $storedGeneratedAt = $connection->fetchOne(
                $connection->select()
                    ->from($this->table('ixpf_review_group'), ['generated_at'])
                    ->where('review_group = ?', $group)
                    ->forUpdate(true)
            );

            $generatedAt = PayloadValidator::parseDate($payload['generated_at']);
            if ($storedGeneratedAt !== false && $this->isStale($generatedAt, (string)$storedGeneratedAt)) {
                $connection->commit();
                $this->log('info', sprintf(
                    'Review push for "%s" ignored as stale (generated_at %s <= stored %s)',
                    $group,
                    $payload['generated_at'],
                    $storedGeneratedAt
                ));

                return ['status' => 'ignored_stale', 'review_group' => $group];
            }

            $this->writeGroupRow($connection, $group, $payload, $generatedAt);
            $counts = $this->applyReviews($connection, $group, $reviews);

            $connection->commit();
        } catch (Throwable $e) {
            // Guarded: if commit() itself threw, there is no transaction left to roll back and
            // an unguarded rollBack() would mask the real error during an incident.
            if ($connection->getTransactionLevel() > 0) {
                $connection->rollBack();
            }
            $this->log('error', sprintf('Review push for "%s" failed, nothing written: %s', $group, $e->getMessage()));

            throw $e;
        }

        $this->cacheCleaner->cleanGroup($group);
        $published = $this->publishPendingMedia($group);

        $this->log('info', sprintf(
            'Review push for "%s" stored: %d added, %d updated, %d removed, %d skipped, %d media pending, %d ms',
            $group,
            $counts['added'],
            $counts['updated'],
            $counts['removed'],
            $counts['skipped'],
            $published,
            (int)round((microtime(true) - $started) * 1000)
        ));

        return ['status' => 'stored', 'review_group' => $group, 'reviews' => count($reviews)];
    }

    /**
     * Queues a download for every file this group is still missing.
     *
     * Runs after the commit and reads the current state rather than tracking what this push
     * touched, so anything a previous failed fetch left null is picked up on the next push
     * instead of staying dark forever. An unchanged review has its local_path already set and
     * is therefore never re-queued.
     *
     * @return int messages published
     */
    private function publishPendingMedia(string $group): int
    {
        if (!$this->config->isReviewMediaEnabled()) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();

        $photos = $connection->fetchAll(
            $connection->select()
                ->from(['p' => $this->table('ixpf_review_photo')], ['bucket_path'])
                ->join(['r' => $this->table('ixpf_review')], 'r.entity_id = p.review_entity_id', ['review_id'])
                ->where('r.review_group = ?', $group)
                ->where('p.local_path IS NULL')
        );

        $thumbnails = $connection->fetchAll(
            $connection->select()
                ->from($this->table('ixpf_review'), ['review_id', 'bucket_path' => 'thumbnail_path'])
                ->where('review_group = ?', $group)
                ->where('thumbnail_path IS NOT NULL')
                ->where('thumbnail_local IS NULL')
        );

        $published = 0;
        foreach (
            [
                [ReviewMediaMessage::TYPE_PHOTO, $photos],
                [ReviewMediaMessage::TYPE_THUMBNAIL, $thumbnails],
            ] as [$type, $rows]
        ) {
            foreach ($rows as $row) {
                try {
                    $this->publisher->publish(self::MEDIA_TOPIC, $this->mediaMessageFactory->create([
                        'reviewId'   => (string)$row['review_id'],
                        'bucketPath' => (string)$row['bucket_path'],
                        'type'       => $type,
                    ]));
                    $published++;
                } catch (Throwable $e) {
                    // The push itself is already committed; a queue outage must not undo it.
                    $this->log('error', sprintf(
                        'Could not queue review media for "%s" (%s): %s',
                        $row['review_id'],
                        $row['bucket_path'],
                        $e->getMessage()
                    ));
                }
            }
        }

        return $published;
    }

    /**
     * Equal timestamps count as stale — a push that is not strictly newer changes nothing.
     *
     * @param string $stored MySQL datetime, always UTC
     */
    public function isStale(DateTimeImmutable $incoming, string $stored): bool
    {
        $storedAt = new DateTimeImmutable($stored, new DateTimeZone('UTC'));

        return $incoming->getTimestamp() <= $storedAt->getTimestamp();
    }

    private function writeGroupRow(
        AdapterInterface $connection,
        string $group,
        array $payload,
        DateTimeImmutable $generatedAt
    ): void {
        $distribution = array_values($payload['distribution']);
        $subAverages  = $payload['sub_averages'] ?? [];

        $row = [
            'review_group'         => $group,
            'review_count'         => (int)$payload['count'],
            'with_text_count'      => (int)($payload['with_text_count'] ?? 0),
            'review_average'       => (float)$payload['average'],
            'dist_1'               => (int)$distribution[0],
            'dist_2'               => (int)$distribution[1],
            'dist_3'               => (int)$distribution[2],
            'dist_4'               => (int)$distribution[3],
            'dist_5'               => (int)$distribution[4],
            'avg_quality'          => $this->nullableFloat($subAverages['quality'] ?? null),
            'avg_delivery'         => $this->nullableFloat($subAverages['delivery'] ?? null),
            'avg_customer_service' => $this->nullableFloat($subAverages['customer_service'] ?? null),
            'group_label'          => isset($payload['group_label'])
                ? TextEncoding::encode(
                    (string)json_encode($payload['group_label'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                )
                : null,
            'generated_at'         => $this->toDbDate($generatedAt),
        ];

        $connection->insertOnDuplicate(
            $this->table('ixpf_review_group'),
            $row,
            array_keys(array_diff_key($row, ['review_group' => null]))
        );
    }

    /**
     * @param array<int, array<string, mixed>> $reviews
     * @return array{added: int, updated: int, removed: int, skipped: int}
     */
    private function applyReviews(AdapterInterface $connection, string $group, array $reviews): array
    {
        // Hash is md5 over the review object exactly as received, before any normalisation.
        $incoming = [];
        foreach ($reviews as $review) {
            $incoming[(string)$review['id']] = [
                'review' => $review,
                'hash'   => md5((string)json_encode($review)),
            ];
        }

        // thumbnail_path/thumbnail_local come along so writeReviewRows can tell whether the
        // bought-item thumbnail moved — see the note there.
        $stored = $connection->fetchAssoc(
            $connection->select()
                ->from(
                    $this->table('ixpf_review'),
                    ['review_id', 'entity_id', 'payload_hash', 'thumbnail_path', 'thumbnail_local']
                )
                ->where('review_group = ?', $group)
        );

        $removed = array_diff_key($stored, $incoming);
        if ($removed !== []) {
            // Cascades to ixpf_review_text and ixpf_review_photo.
            foreach (array_chunk(array_keys($removed), self::BATCH_SIZE) as $chunk) {
                $connection->delete($this->table('ixpf_review'), ['review_id IN (?)' => $chunk]);
            }
        }

        $dirty   = [];
        $added   = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($incoming as $reviewId => $item) {
            if (!isset($stored[$reviewId])) {
                $dirty[$reviewId] = $item;
                $added++;
            } elseif ($stored[$reviewId]['payload_hash'] !== $item['hash']) {
                $dirty[$reviewId] = $item;
                $updated++;
            } else {
                $skipped++;
            }
        }

        if ($dirty === []) {
            return [
                'added' => 0, 'updated' => 0, 'removed' => count($removed), 'skipped' => $skipped,
            ];
        }

        $this->writeReviewRows($connection, $group, $dirty, $stored);

        // Re-read the id map: insertOnDuplicate does not return entity_ids, and an updated review
        // keeps the entity_id it already had.
        $entityIds = $this->fetchEntityIds($connection, array_keys($dirty));

        $this->replaceText($connection, $dirty, $entityIds);
        $this->replacePhotos($connection, $dirty, $entityIds);

        return [
            'added'         => $added,
            'updated'       => $updated,
            'removed'       => count($removed),
            'skipped'       => $skipped,
        ];
    }

    /**
     * @param array<string, array{review: array, hash: string}> $dirty
     * @param array<string, array<string, mixed>> $stored existing rows keyed by review_id
     */
    private function writeReviewRows(
        AdapterInterface $connection,
        string $group,
        array $dirty,
        array $stored
    ): void {
        $rows = [];
        foreach ($dirty as $reviewId => $item) {
            $review  = $item['review'];
            $bought  = is_array($review['bought'] ?? null) ? $review['bought'] : [];
            $reply   = is_array($review['reply'] ?? null) ? $review['reply'] : [];
            $ratings = is_array($review['sub_ratings'] ?? null) ? $review['sub_ratings'] : [];
            $photos  = is_array($review['photos'] ?? null) ? $review['photos'] : [];

            // Same rule photos get, applied to the bought-item thumbnail: an unchanged bucket
            // path keeps its downloaded file, a changed one drops it so the media queue fetches
            // the new image. Without this the row would keep pointing at the old file forever,
            // because a non-null thumbnail_local is never re-queued.
            $thumbnailPath = $this->nullableString($bought['thumbnail_path'] ?? null);
            $thumbnailLocal = ($stored[$reviewId]['thumbnail_path'] ?? null) === $thumbnailPath
                ? $this->nullableString($stored[$reviewId]['thumbnail_local'] ?? null)
                : null;

            $rows[] = [
                'review_id'               => $reviewId,
                'review_group'            => $group,
                'payload_hash'            => $item['hash'],
                'sku'                     => (string)($review['sku'] ?? ''),
                'rating'                  => (int)$review['rating'],
                'rating_quality'          => $this->nullableInt($ratings['quality'] ?? null),
                'rating_delivery'         => $this->nullableInt($ratings['delivery'] ?? null),
                'rating_customer_service' => $this->nullableInt($ratings['customer_service'] ?? null),
                'reviewer'                => (string)TextEncoding::encode((string)($review['reviewer'] ?? '')),
                'created_at'              => $this->toDbDate(PayloadValidator::parseDate($review['created_at'])),
                'purchased_at'            => $this->toDbDate(PayloadValidator::parseDate($bought['purchased_at'] ?? null)),
                'source'                  => $this->nullableString($review['source'] ?? null),
                'source_locale'           => (string)$review['source_locale'],
                'source_store_code'       => (string)($review['source_store_code'] ?? ''),
                'bought_name'             => TextEncoding::encode($this->nullableString($bought['name'] ?? null)),
                'bought_variant_texts'    => $this->localeMapJson($bought['variant_texts'] ?? null),
                'thumbnail_path'          => $thumbnailPath,
                'thumbnail_local'         => $thumbnailLocal,
                'has_photos'              => $photos === [] ? 0 : 1,
                'reply_created_at'        => $this->toDbDate(PayloadValidator::parseDate($reply['created_at'] ?? null)),
            ];
        }

        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            $connection->insertOnDuplicate($this->table('ixpf_review'), $chunk, self::REVIEW_UPDATE_COLUMNS);
        }
    }

    /**
     * @param string[] $reviewIds
     * @return array<string, int> review_id => entity_id
     */
    private function fetchEntityIds(AdapterInterface $connection, array $reviewIds): array
    {
        $map = [];
        foreach (array_chunk($reviewIds, self::BATCH_SIZE) as $chunk) {
            $map += $connection->fetchPairs(
                $connection->select()
                    ->from($this->table('ixpf_review'), ['review_id', 'entity_id'])
                    ->where('review_id IN (?)', $chunk)
            );
        }

        return array_map('intval', $map);
    }

    /**
     * @param array<string, array{review: array, hash: string}> $dirty
     * @param array<string, int> $entityIds
     */
    private function replaceText(AdapterInterface $connection, array $dirty, array $entityIds): void
    {
        $targets = array_values(array_intersect_key($entityIds, $dirty));
        foreach (array_chunk($targets, self::BATCH_SIZE) as $chunk) {
            $connection->delete($this->table('ixpf_review_text'), ['review_entity_id IN (?)' => $chunk]);
        }

        $rows = [];
        foreach ($dirty as $reviewId => $item) {
            $entityId = $entityIds[$reviewId] ?? null;
            if ($entityId === null) {
                continue;
            }

            $review    = $item['review'];
            $bodies    = is_array($review['body'] ?? null) ? $review['body'] : [];
            $replyBody = is_array($review['reply']['body'] ?? null) ? $review['reply']['body'] : [];

            foreach (array_keys($bodies + $replyBody) as $locale) {
                $rows[] = [
                    'review_entity_id' => $entityId,
                    'locale'           => (string)$locale,
                    'body'             => TextEncoding::encode($this->nullableString($bodies[$locale] ?? null)),
                    'reply_body'       => TextEncoding::encode($this->nullableString($replyBody[$locale] ?? null)),
                ];
            }
        }

        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            $connection->insertMultiple($this->table('ixpf_review_text'), $chunk);
        }
    }

    /**
     * Photo rows are compared by bucket_path. An unchanged path set keeps its local_path, so
     * nothing is re-fetched and no derivatives are regenerated.
     *
     * @param array<string, array{review: array, hash: string}> $dirty
     * @param array<string, int> $entityIds
     */
    private function replacePhotos(AdapterInterface $connection, array $dirty, array $entityIds): void
    {
        $targets = array_values(array_intersect_key($entityIds, $dirty));
        if ($targets === []) {
            return;
        }

        $existing = [];
        foreach (array_chunk($targets, self::BATCH_SIZE) as $chunk) {
            $found = $connection->fetchAll(
                $connection->select()
                    ->from($this->table('ixpf_review_photo'), ['review_entity_id', 'bucket_path'])
                    ->where('review_entity_id IN (?)', $chunk)
            );
            foreach ($found as $row) {
                $existing[(int)$row['review_entity_id']][] = (string)$row['bucket_path'];
            }
        }

        $staleEntityIds = [];
        $rows           = [];
        foreach ($dirty as $reviewId => $item) {
            $entityId = $entityIds[$reviewId] ?? null;
            if ($entityId === null) {
                continue;
            }

            $photos   = is_array($item['review']['photos'] ?? null) ? array_values($item['review']['photos']) : [];
            $incoming = array_map(static fn (array $p): string => (string)$p['bucket_path'], $photos);
            $current  = $existing[$entityId] ?? [];

            sort($incoming);
            $sortedCurrent = $current;
            sort($sortedCurrent);
            if ($incoming === $sortedCurrent) {
                continue;
            }

            $staleEntityIds[] = $entityId;
            foreach ($photos as $position => $photo) {
                $rows[] = [
                    'review_entity_id' => $entityId,
                    'bucket_path'      => (string)$photo['bucket_path'],
                    'local_path'       => null,
                    'width'            => $this->nullableInt($photo['width'] ?? null),
                    'height'           => $this->nullableInt($photo['height'] ?? null),
                    'position'         => $position,
                ];
            }
        }

        foreach (array_chunk($staleEntityIds, self::BATCH_SIZE) as $chunk) {
            $connection->delete($this->table('ixpf_review_photo'), ['review_entity_id IN (?)' => $chunk]);
        }
        foreach (array_chunk($rows, self::BATCH_SIZE) as $chunk) {
            $connection->insertMultiple($this->table('ixpf_review_photo'), $chunk);
        }
    }

    /**
     * Logging must never break a push.
     *
     * The dedicated channel writes to a file, and a file can be unwritable — wrong owner after
     * a CLI run, a full disk, a read-only mount. Everything logged here happens either before
     * a write or after the commit, so a logging failure that escaped would 500 a request whose
     * data is already safe. This endpoint's contract makes that actively harmful: any 5xx makes
     * IXPF retry a push that has in fact succeeded.
     *
     * Falls back to the PHP error log so a broken channel stays visible rather than silent.
     *
     * The level is translated to its Monolog number on the way out. BaseLogger types its $type
     * parameter as a string but switches on the Monolog constants, which are ints, so a level
     * passed by name matches no case and silently comes out as INFO - which would leave every
     * rejection and rollback in here indistinguishable from a successful push.
     */
    private function log(string $level, string $message): void
    {
        $levels = [
            'error'   => Logger::ERROR,
            'warning' => Logger::WARNING,
            'info'    => Logger::INFO,
        ];

        try {
            $this->logger->writeDaily($message, (string)($levels[$level] ?? Logger::INFO));
        } catch (Throwable $e) {
            error_log(sprintf('[ixpf_reviews] %s: %s (log channel failed: %s)', $level, $message, $e->getMessage()));
        }
    }

    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }

    private function toDbDate(?DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Locale map as JSON, null when empty. Filtered, not validated: display text must not fail a push.
     */
    private function localeMapJson(mixed $map): ?string
    {
        if (!is_array($map)) {
            return null;
        }

        $clean = [];
        foreach ($map as $locale => $text) {
            if (is_string($locale) && $locale !== '' && is_string($text) && trim($text) !== '') {
                $clean[$locale] = $text;
            }
        }

        $json = $clean === [] ? false : json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : TextEncoding::encode($json);
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }
}
