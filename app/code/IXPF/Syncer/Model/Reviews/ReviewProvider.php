<?php

declare(strict_types=1);

namespace IXPF\Syncer\Model\Reviews;

use IXPF\Syncer\Api\Data\ReviewFilterInterface;
use IXPF\Syncer\Api\Data\ReviewListInterface;
use IXPF\Syncer\Api\Data\ReviewSummaryInterface;
use IXPF\Syncer\Api\ReviewProviderInterface;
use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Model\Config\Source\ReviewCriteria;
use IXPF\Syncer\Services\Reviews\ReviewMediaStorage;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\SerializerInterface;
use IXPF\Syncer\Helper\Logger\ReviewLogger;
use Monolog\Logger;
use Throwable;

/**
 * Read side of the review store.
 *
 * Nothing is aggregated here — the summary is one row, and the distribution always comes from
 * that row rather than from the filtered set, so the bars do not move when a filter is applied.
 *
 * Results are cached under the same tag the upsert cleans, so correctness comes from the tag,
 * not from expiry.
 */
class ReviewProvider implements ReviewProviderInterface
{
    public const FALLBACK_LOCALE = 'en_US';

    private const CACHE_LIFETIME = 86400;

    /** Locale fallbacks that were exhausted, logged once per group per request. */
    private array $reportedFallbackFailures = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly ProductResource $productResource,
        private readonly ReviewMediaStorage $storage,
        // The typed cache, not CacheInterface: only it honours the ixpf_reviews enabled/disabled state.
        private readonly Cache $cache,
        private readonly SerializerInterface $serializer,
        private readonly Config $config,
        private readonly ReviewLogger $logger
    ) {
    }

    public function getGroupKeyForProduct(int $productId): ?string
    {
        if ($productId <= 0) {
            return null;
        }

        try {
            // review_group is global scope, so store 0 is the only value there is.
            $value = $this->productResource->getAttributeRawValue($productId, 'review_group', 0);
        } catch (Throwable $e) {
            $this->logger->writeFailureDaily(
                $e,
                sprintf('Could not read review_group for product %d', $productId)
            );

            return null;
        }

        $value = is_array($value) ? ($value['review_group'] ?? null) : $value;
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    public function getSummary(string $reviewGroup, string $locale): ReviewSummaryInterface
    {
        // Master switch gates reads and the cleanup cron, never ingest - IXPF backfills while
        // everything downstream is dark.
        if (!$this->config->isReviewsEnabled()) {
            return new ReviewSummary();
        }

        $cacheKey = $this->cacheKey($reviewGroup, 'summary_' . $locale);
        $cached   = $this->loadCache($cacheKey);
        if ($cached !== null) {
            return $this->hydrateSummary($cached + $this->getCounts($reviewGroup));
        }

        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table('ixpf_review_group'))
                ->where('review_group = ?', $reviewGroup)
        );

        if ($row === false) {
            // Unknown group is an empty result, not an exception.
            return new ReviewSummary();
        }

        $data = [
            'count'           => (int)$row['review_count'],
            'with_text_count' => (int)($row['with_text_count'] ?? 0),
            'average'         => (float)$row['review_average'],
            'distribution'    => [
                (int)$row['dist_1'],
                (int)$row['dist_2'],
                (int)$row['dist_3'],
                (int)$row['dist_4'],
                (int)$row['dist_5'],
            ],
            'quality'          => $this->nullableFloat($row['avg_quality'] ?? null),
            'delivery'         => $this->nullableFloat($row['avg_delivery'] ?? null),
            'customer_service' => $this->nullableFloat($row['avg_customer_service'] ?? null),
            'label'            => $this->resolveLabel($row['group_label'] ?? null, $locale),
        ];

        $this->saveCache($cacheKey, $data, $reviewGroup);

        return $this->hydrateSummary($data + $this->getCounts($reviewGroup));
    }

    /**
     * Review / text / photo counts, taken from the stored reviews rather than IXPF's aggregates:
     * what makes an entry a review is an admin setting (review_criteria), and the photo count has
     * to follow the files actually on disk.
     *
     * Cached per pool, not per locale - the numbers are the same in every language - under the
     * pool tag, so a push or a landed photo refreshes them. The criteria are part of the key, so
     * changing the setting never serves the old split.
     *
     * One pass over the pool's index range; each flag is evaluated once per review in the derived
     * table, with primary-key lookups for the text and photo EXISTS.
     *
     * @return array{reviews_count: int, text_count: int, photo_count: int}
     */
    private function getCounts(string $reviewGroup): array
    {
        $criteria = $this->config->getReviewCriteria();
        $cacheKey = $this->cacheKey($reviewGroup, 'counts_' . implode('+', $criteria));
        $cached   = $this->loadCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $connection = $this->resourceConnection->getConnection();
        $flags = $connection->select()
            ->from(['r' => $this->table('ixpf_review')], [
                'has_text'   => new \Zend_Db_Expr(sprintf(
                    'EXISTS (SELECT 1 FROM %s t'
                    . " WHERE t.review_entity_id = r.entity_id AND t.body IS NOT NULL AND t.body <> '')",
                    $this->table('ixpf_review_text')
                )),
                'has_file'   => new \Zend_Db_Expr(sprintf(
                    'EXISTS (SELECT 1 FROM %s p WHERE p.review_entity_id = r.entity_id AND p.local_path IS NOT NULL)',
                    $this->table('ixpf_review_photo')
                )),
                'has_photos' => 'r.has_photos',
                'has_sub'    => new \Zend_Db_Expr(
                    'r.rating_quality IS NOT NULL OR r.rating_delivery IS NOT NULL'
                    . ' OR r.rating_customer_service IS NOT NULL'
                ),
            ])
            ->where('r.review_group = ?', $reviewGroup);

        // A photo counts as sent (has_photos): the customer did add one, even if it is still queued.
        $parts = array_intersect_key(
            [
                ReviewCriteria::TEXT        => 'f.has_text',
                ReviewCriteria::PHOTO       => 'f.has_photos = 1',
                ReviewCriteria::SUB_RATINGS => 'f.has_sub',
            ],
            array_flip($criteria)
        );
        $isReview = $parts === [] ? '0' : implode(' OR ', $parts);

        $row = $connection->fetchRow(
            $connection->select()->from(['f' => $flags], [
                'reviews_count' => new \Zend_Db_Expr("COALESCE(SUM($isReview), 0)"),
                'text_count'    => new \Zend_Db_Expr('COALESCE(SUM(f.has_text), 0)'),
                'photo_count'   => new \Zend_Db_Expr('COALESCE(SUM(f.has_file), 0)'),
            ])
        );

        $data = [
            'reviews_count' => (int)($row['reviews_count'] ?? 0),
            'text_count'    => (int)($row['text_count'] ?? 0),
            'photo_count'   => (int)($row['photo_count'] ?? 0),
        ];

        $this->saveCache($cacheKey, $data, $reviewGroup);

        return $data;
    }

    public function getReviews(
        string $reviewGroup,
        string $locale,
        ReviewFilterInterface $filter
    ): ReviewListInterface {
        if (!$this->config->isReviewsEnabled()) {
            return new ReviewList([], 0, $filter->getPage(), $filter->getPageSize());
        }

        $fragment = $filter instanceof ReviewFilter
            ? $filter->getCacheFragment()
            : sprintf(
                'r%s_p%s_t%s_%d_%d_%s',
                $filter->getRating() ?? 'x',
                $filter->getHasPhotos() === null ? 'x' : ($filter->getHasPhotos() ? '1' : '0'),
                $filter->getWithText() === null ? 'x' : ($filter->getWithText() ? '1' : '0'),
                $filter->getPage(),
                $filter->getPageSize(),
                $filter->getSort()
            );

        $cacheKey = $this->cacheKey($reviewGroup, 'list_' . $locale . '_' . $fragment);
        $cached   = $this->loadCache($cacheKey);
        if ($cached !== null) {
            return $this->hydrateList($cached, $filter);
        }

        $total = $this->countTotal($reviewGroup, $filter);

        // A page past the last one is empty by definition: answer it without the deep-offset
        // query and without a cache entry. page comes straight off an anonymous query string, so
        // caching these would let anyone mint a 24h entry per page number, without bound.
        if ($filter->getPage() > 1 && ($filter->getPage() - 1) * $filter->getPageSize() >= $total) {
            return $this->hydrateList(['items' => [], 'total' => $total], $filter);
        }

        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from($this->table('ixpf_review'))
            ->where('review_group = ?', $reviewGroup);
        $this->applyFilter($select, $filter);

        $this->applySort($select, $filter);
        $select->limit($filter->getPageSize(), ($filter->getPage() - 1) * $filter->getPageSize());

        $rows = $connection->fetchAll($select);

        $items = $rows === [] ? [] : $this->buildItems($rows, $locale, $reviewGroup);

        $data = [
            'items' => array_map(static fn (ReviewItem $i): array => $i->toArray(), $items),
            'total' => $total,
        ];

        $this->saveCache($cacheKey, $data, $reviewGroup);

        return $this->hydrateList($data, $filter);
    }

    /**
     * The unfiltered total is the group row's own count, which is the common case. A COUNT(*)
     * only runs when a filter is actually applied.
     */
    private function countTotal(string $reviewGroup, ReviewFilterInterface $filter): int
    {
        $connection = $this->resourceConnection->getConnection();

        $unfiltered = $filter->getRating() === null
            && $filter->getHasPhotos() === null
            && $filter->getWithText() === null;

        // With-text alone is the drawer's other common case, and the group row already stores
        // that number, so it costs a column read instead of a COUNT(*) over the text table.
        $withTextOnly = $filter->getWithText() === true
            && $filter->getRating() === null
            && $filter->getHasPhotos() === null;

        if ($unfiltered || $withTextOnly) {
            return (int)$connection->fetchOne(
                $connection->select()
                    ->from(
                        $this->table('ixpf_review_group'),
                        [$unfiltered ? 'review_count' : 'with_text_count']
                    )
                    ->where('review_group = ?', $reviewGroup)
            );
        }

        $select = $connection->select()
            ->from($this->table('ixpf_review'), ['COUNT(*)'])
            ->where('review_group = ?', $reviewGroup);
        $this->applyFilter($select, $filter);

        return (int)$connection->fetchOne($select);
    }

    /**
     * entity_id is the tiebreaker in every branch, so pagination stays stable when several
     * reviews share a timestamp or a rating. Without it a rating sort would reshuffle equal
     * ratings between requests and a review could appear on two pages or none.
     *
     * "Most recent" is the purchase date, not the review date (client, 2026-09-25): a pool's
     * reviews often arrive on the same day, which left the list in insert order. A review with no
     * purchase date sorts after every dated one - MySQL puts NULL last on DESC - and by review
     * date among themselves; mixed in by review date they would always lead, because a review is
     * written after its purchase. The rating sorts use the same recency within each star level.
     */
    private function applySort(Select $select, ReviewFilterInterface $filter): void
    {
        match ($filter->getSort()) {
            ReviewFilterInterface::SORT_HIGHEST => $select->order('rating DESC'),
            ReviewFilterInterface::SORT_LOWEST  => $select->order('rating ASC'),
            default                             => null,
        };

        $select->order('purchased_at DESC')->order('created_at DESC')->order('entity_id DESC');
    }

    private function applyFilter(Select $select, ReviewFilterInterface $filter): void
    {
        if ($filter->getRating() !== null) {
            $select->where('rating = ?', $filter->getRating());
        }

        if ($filter->getHasPhotos() !== null) {
            $select->where('has_photos = ?', $filter->getHasPhotos() ? 1 : 0);

            if ($filter->getHasPhotos()) {
                // has_photos records that IXPF *sent* photos, not that they are on disk.
                // fetchPhotos() omits any photo whose download has not landed, so filtering on
                // the flag alone returns reviews that then render with no photo at all - which
                // makes "only with photos" look broken. The flag stays as the indexed pre-filter
                // (review_group, has_photos, created_at); this confirms a file actually exists.
                // Both names come from ResourceConnection, so there is nothing to bind here.
                $select->where(sprintf(
                    'EXISTS (SELECT 1 FROM %s p'
                    . ' WHERE p.review_entity_id = %s.entity_id AND p.local_path IS NOT NULL)',
                    $this->table('ixpf_review_photo'),
                    $this->table('ixpf_review')
                ));
            }
        }

        if ($filter->getWithText() === null) {
            return;
        }

        // No denormalised has_text flag: a pool is a few dozen rows and ixpf_review_text's
        // primary key (review_entity_id, locale) already covers this lookup.
        //
        // The body predicate is explicit because a star-only review that received a reply still
        // has text rows - with reply_body set and body null. Testing for the row alone would
        // count those as written reviews and disagree with with_text_count.
        $exists = sprintf(
            'EXISTS (SELECT 1 FROM %s t'
            . " WHERE t.review_entity_id = %s.entity_id AND t.body IS NOT NULL AND t.body <> '')",
            $this->table('ixpf_review_text'),
            $this->table('ixpf_review')
        );

        $select->where($filter->getWithText() ? $exists : 'NOT ' . $exists);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return ReviewItem[]
     */
    private function buildItems(array $rows, string $locale, string $reviewGroup): array
    {
        $entityIds = array_map(static fn (array $r): int => (int)$r['entity_id'], $rows);

        $text   = $this->fetchText($entityIds, $locale);
        $photos = $this->fetchPhotos($entityIds);

        $items = [];
        foreach ($rows as $row) {
            $entityId     = (int)$row['entity_id'];
            $sourceLocale = (string)$row['source_locale'];
            $available    = $text[$entityId] ?? [];

            // requested -> the review's own language -> en_US
            $resolvedLocale = null;
            foreach ([$locale, $sourceLocale, self::FALLBACK_LOCALE] as $candidate) {
                if (isset($available[$candidate])) {
                    $resolvedLocale = $candidate;
                    break;
                }
            }

            if ($resolvedLocale === null) {
                // Nothing usable to show. Log once per group per request, not per review.
                if (!isset($this->reportedFallbackFailures[$reviewGroup])) {
                    $this->reportedFallbackFailures[$reviewGroup] = true;
                    $this->logger->writeDaily(sprintf(
                        'Locale fallback exhausted for group "%s" at locale "%s"; affected reviews omitted',
                        $reviewGroup,
                        $locale
                    ), (string)Logger::WARNING);
                }
                continue;
            }

            $resolved = $available[$resolvedLocale];
            $source   = $available[$sourceLocale] ?? $resolved;

            $items[] = new ReviewItem(
                (string)$row['review_id'],
                (string)$row['sku'],
                (int)$row['rating'],
                [
                    'quality'          => $this->nullableInt($row['rating_quality'] ?? null),
                    'delivery'         => $this->nullableInt($row['rating_delivery'] ?? null),
                    'customer_service' => $this->nullableInt($row['rating_customer_service'] ?? null),
                ],
                (string)TextEncoding::decode((string)$row['reviewer']),
                (string)$row['created_at'],
                $this->nullableString($row['purchased_at'] ?? null),
                $sourceLocale,
                (string)$row['source_store_code'],
                $resolvedLocale,
                TextEncoding::decode($this->nullableString($resolved['body'] ?? null)),
                TextEncoding::decode($this->nullableString($source['body'] ?? null)),
                TextEncoding::decode($this->nullableString($resolved['reply_body'] ?? null)),
                $this->nullableString($row['reply_created_at'] ?? null),
                TextEncoding::decode($this->nullableString($row['bought_name'] ?? null)),
                // Viewer's locale, independent of the body's resolved locale.
                $this->resolveLabel($row['bought_variant_texts'] ?? null, $locale),
                $this->nullableString($row['thumbnail_local'] ?? null),
                $photos[$entityId] ?? []
            );
        }

        return $items;
    }

    /**
     * Only the locales that can actually be resolved, so a fourteen-locale review costs three
     * rows instead of fourteen.
     *
     * @param int[] $entityIds
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function fetchText(array $entityIds, string $locale): array
    {
        $connection = $this->resourceConnection->getConnection();

        // The join is only here for r.source_locale: it varies per review, so it cannot be
        // folded into the IN list. Pagination has already happened, so this reads at most
        // three rows per review on the page rather than all fourteen locales.
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(['t' => $this->table('ixpf_review_text')])
                ->join(['r' => $this->table('ixpf_review')], 'r.entity_id = t.review_entity_id', [])
                ->where('t.review_entity_id IN (?)', $entityIds)
                ->where(
                    $connection->quoteInto('t.locale IN (?)', [$locale, self::FALLBACK_LOCALE])
                    . ' OR t.locale = r.source_locale'
                )
        );

        $byEntity = [];
        foreach ($rows as $row) {
            $byEntity[(int)$row['review_entity_id']][(string)$row['locale']] = $row;
        }

        return $byEntity;
    }

    /**
     * A second query keyed by entity_id rather than a join — joining text and photos in one
     * statement multiplies rows and breaks the page size.
     *
     * Photos with a null local_path are omitted: they are not on disk yet.
     *
     * @param int[] $entityIds
     * @return array<int, ReviewPhoto[]>
     */
    private function fetchPhotos(array $entityIds): array
    {
        $connection = $this->resourceConnection->getConnection();

        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->table('ixpf_review_photo'))
                ->where('review_entity_id IN (?)', $entityIds)
                ->where('local_path IS NOT NULL')
                ->order('position ASC')
                ->order('entity_id ASC')
        );

        $byEntity = [];
        foreach ($rows as $row) {
            $original = (string)$row['local_path'];
            $byEntity[(int)$row['review_entity_id']][] = new ReviewPhoto(
                $this->storage->derivativePath($original, ReviewMediaStorage::SIZE_THUMB),
                $this->storage->derivativePath($original, ReviewMediaStorage::SIZE_GRID),
                $this->nullableInt($row['width'] ?? null),
                $this->nullableInt($row['height'] ?? null)
            );
        }

        return $byEntity;
    }

    /**
     * Picks one entry from a locale-keyed JSON map: requested -> en_US -> first sent.
     */
    private function resolveLabel(mixed $json, string $locale): ?string
    {
        if (!is_string($json) || $json === '') {
            return null;
        }

        $labels = json_decode($json, true);
        if (!is_array($labels)) {
            return null;
        }

        foreach ([$locale, self::FALLBACK_LOCALE] as $candidate) {
            if (isset($labels[$candidate]) && is_string($labels[$candidate])) {
                return TextEncoding::decode($labels[$candidate]);
            }
        }

        $first = reset($labels);

        return is_string($first) ? TextEncoding::decode($first) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateSummary(array $data): ReviewSummaryInterface
    {
        return new ReviewSummary(
            (int)($data['count'] ?? 0),
            // ?? 0, not a bare index: summary entries cached before this field existed have no
            // such key and must degrade to zero rather than throw.
            (int)($data['with_text_count'] ?? 0),
            (float)($data['average'] ?? 0),
            array_map('intval', $data['distribution'] ?? [0, 0, 0, 0, 0]),
            $this->nullableFloat($data['quality'] ?? null),
            $this->nullableFloat($data['delivery'] ?? null),
            $this->nullableFloat($data['customer_service'] ?? null),
            $this->nullableString($data['label'] ?? null),
            (int)($data['reviews_count'] ?? 0),
            (int)($data['text_count'] ?? 0),
            (int)($data['photo_count'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function hydrateList(array $data, ReviewFilterInterface $filter): ReviewListInterface
    {
        $items = [];
        foreach ($data['items'] ?? [] as $item) {
            $photos = [];
            foreach ($item['photos'] ?? [] as $photo) {
                $photos[] = new ReviewPhoto(
                    (string)$photo['thumb_path'],
                    (string)$photo['grid_path'],
                    $this->nullableInt($photo['width'] ?? null),
                    $this->nullableInt($photo['height'] ?? null)
                );
            }

            $items[] = new ReviewItem(
                (string)$item['review_id'],
                (string)$item['sku'],
                (int)$item['rating'],
                $item['sub_ratings'] ?? [],
                (string)$item['reviewer'],
                (string)$item['created_at'],
                $this->nullableString($item['purchased_at'] ?? null),
                (string)$item['source_locale'],
                (string)$item['source_store_code'],
                (string)$item['resolved_locale'],
                $this->nullableString($item['body'] ?? null),
                $this->nullableString($item['source_body'] ?? null),
                $this->nullableString($item['reply_body'] ?? null),
                $this->nullableString($item['reply_created_at'] ?? null),
                $this->nullableString($item['bought_name'] ?? null),
                $this->nullableString($item['bought_variant_text'] ?? null),
                $this->nullableString($item['thumbnail_path'] ?? null),
                $photos
            );
        }

        return new ReviewList($items, (int)($data['total'] ?? 0), $filter->getPage(), $filter->getPageSize());
    }

    private function cacheKey(string $reviewGroup, string $suffix): string
    {
        return 'ixpf_review_' . md5($reviewGroup . '|' . $suffix);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadCache(string $key): ?array
    {
        $raw = $this->cache->load($key);
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }

        try {
            $data = $this->serializer->unserialize($raw);
        } catch (Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveCache(string $key, array $data, string $reviewGroup): void
    {
        // TTL is only a backstop: correctness comes from the tags, which every writer cleans.
        // Both are needed - the group tag for a single pool, the type tag so `cache:clean` and the
        // admin Cache Management page can reach these entries at all.
        $this->cache->save(
            $this->serializer->serialize($data),
            $key,
            [Cache::groupTag($reviewGroup), Cache::CACHE_TAG],
            self::CACHE_LIFETIME
        );
    }

    private function table(string $name): string
    {
        return $this->resourceConnection->getTableName($name);
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
