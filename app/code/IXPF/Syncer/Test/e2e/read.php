<?php
/**
 * End-to-end check of the review read service against the real database.
 * See README.md for why this exists instead of PHPUnit.
 */
declare(strict_types=1);

require '/var/www/html/app/bootstrap.php';

use IXPF\Syncer\Api\ReviewProviderInterface;
use IXPF\Syncer\Model\Reviews\Cache as ReviewCache;
use IXPF\Syncer\Model\Reviews\ReviewFilter;
use IXPF\Syncer\Model\Reviews\ReviewItem;
use IXPF\Syncer\Services\Reviews\UpsertReviewGroup;
use Magento\Framework\App\Bootstrap;

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$upsert   = $om->get(UpsertReviewGroup::class);
$provider = $om->get(ReviewProviderInterface::class);
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$conn     = $resource->getConnection();

$pass = 0; $fail = 0;
function check(string $n, bool $ok, string $d = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $n\n"; return; }
    $fail++; echo "  FAIL $n" . ($d !== '' ? " -- $d" : '') . "\n";
}

const TEST_GROUP = 'ZZREAD';

$tGroup  = $resource->getTableName('ixpf_review_group');
$tReview = $resource->getTableName('ixpf_review');
$tPhoto  = $resource->getTableName('ixpf_review_photo');
$tText   = $resource->getTableName('ixpf_review_text');

// review_id is unique platform-wide and the e2e scripts share fixture ids, so clear every ZZ pool.
$conn->delete($tGroup, ['review_group LIKE ?' => 'ZZ%']);

$fixture = json_decode((string)file_get_contents('/var/www/html/app/code/IXPF/Syncer/Test/fixture/review_group_8518C.json'), true);
$fixture['review_group'] = TEST_GROUP;

// The read side is behind ixpf_reviews/general/enabled, which defaults to 0 so the feature can
// be dark-launched while IXPF backfills. Prove that, then turn it on for the rest of the run.
//
// Whatever the flag was set to before this run is restored at the end. Deleting the row instead
// would silently switch the storefront feature off on any environment where someone had turned
// it on - which is a test that breaks the site it was run against.
$configTable    = $resource->getTableName('core_config_data');
$enabledBefore  = $conn->fetchOne(
    "SELECT value FROM $configTable WHERE scope='default' AND scope_id=0 AND path='ixpf_reviews/general/enabled'"
);
echo "\n--- master switch ---\n";
$setEnabled = static function (int $on) use ($conn, $om, $resource): void {
    $conn->insertOnDuplicate($resource->getTableName('core_config_data'), [
        'scope' => 'default', 'scope_id' => 0, 'path' => 'ixpf_reviews/general/enabled', 'value' => (string)$on,
    ], ['value']);
    $om->get(\Magento\Framework\App\Config::class)->clean();
};
$setEnabled(0);
$upsert->upsert($fixture);
check('disabled -> summary empty', $provider->getSummary(TEST_GROUP, 'en_US')->getCount() === 0);
check('disabled -> list empty', $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter())->getItems() === []);
check('ingest is NOT gated, rows are still stored',
    (int)$conn->fetchOne("SELECT COUNT(*) FROM $tReview WHERE review_group='" . TEST_GROUP . "'") === 3);
$setEnabled(1);
$conn->delete($tGroup, ['review_group LIKE ?' => 'ZZ%']);
$upsert->upsert($fixture);

// The fixture's photos are never downloadable here, so mark one fetched to exercise the
// "photos with a null local_path are omitted" rule from both sides.
$photoIds = $conn->fetchCol("SELECT p.entity_id FROM $tPhoto p JOIN $tReview r ON r.entity_id = p.review_entity_id
    WHERE r.review_group = '" . TEST_GROUP . "' ORDER BY p.entity_id ASC");
$conn->update($tPhoto, ['local_path' => 'ixpf/reviews/x/one.jpg'], ['entity_id = ?' => (int)$photoIds[0]]);

echo "\n--- summary ---\n";
$s = $provider->getSummary(TEST_GROUP, 'da_DK');
check('count from the group row', $s->getCount() === 3, (string)$s->getCount());
check('average from the group row', abs($s->getAverage() - 4.33) < 0.001, (string)$s->getAverage());
check('distribution five values', $s->getDistribution() === [0, 0, 1, 0, 2], json_encode($s->getDistribution()));
check('sub averages', abs((float)$s->getAverageQuality() - 4.7) < 0.001 && abs((float)$s->getAverageDelivery() - 4.0) < 0.001);
check('label resolved for requested locale', $s->getLabel() === 'Pakistan Bokhara', (string)$s->getLabel());
check('label resolves ja_JP', $provider->getSummary(TEST_GROUP, 'ja_JP')->getLabel() === 'パキスタン ボハラ');
check('unknown locale falls back to en_US label', $provider->getSummary(TEST_GROUP, 'xx_XX')->getLabel() === 'Pakistan Bokhara');

echo "\n--- unknown group is empty, not an exception ---\n";
$empty = $provider->getSummary('ZZNOSUCHGROUP', 'en_US');
check('summary zeroed', $empty->getCount() === 0 && $empty->getAverage() === 0.0);
check('distribution zeroed', $empty->getDistribution() === [0, 0, 0, 0, 0]);
$emptyList = $provider->getReviews('ZZNOSUCHGROUP', 'en_US', new ReviewFilter());
check('list empty', $emptyList->getItems() === [] && $emptyList->getTotalCount() === 0);

echo "\n--- list, unfiltered ---\n";
$list = $provider->getReviews(TEST_GROUP, 'da_DK', new ReviewFilter());
check('3 items', count($list->getItems()) === 3, (string)count($list->getItems()));
check('total from group row', $list->getTotalCount() === 3);
check('page count', $list->getPageCount() === 1);
$dates = array_map(static fn (ReviewItem $i): string => (string)$i->purchasedAt, $list->getItems());
$sorted = $dates; rsort($sorted);
check('sorted by purchase date desc', $dates === $sorted, json_encode($dates));

echo "\n--- locale resolution ---\n";
$byId = [];
foreach ($list->getItems() as $i) { $byId[$i->reviewId] = $i; }

$da = $byId['zztest18ec8892e02f1d9cc9'];
check('requested locale used when present', $da->resolvedLocale === 'da_DK', $da->resolvedLocale);
check('body is the danish text', str_contains((string)$da->body, 'Flot tæppe'));
check('not marked translated when source == requested', $da->isTranslated() === false);
check('reply body in the resolved locale', str_contains((string)$da->replyBody, 'Tak for din anmeldelse'));
check('bought line in the requested locale', $da->boughtVariantText === 'Købt: Beige, 200 × 250 cm',
    (string)$da->boughtVariantText);

$de = $byId['zztest18ec8892e02f1d9e10'];
check('review with no da_DK text falls back to its source locale', $de->resolvedLocale === 'de_DE', $de->resolvedLocale);
check('marked translated when resolved != source is false here', $de->isTranslated() === false);
check('source_store_code kept raw (ch_de is not a country code)', $de->sourceStoreCode === 'ch_de', $de->sourceStoreCode);
// Bought line ignores the body's locale (de_DE) and falls back to en_US.
check('bought line with no entry for the locale falls back to en_US',
    $de->boughtVariantText === 'Bought: Beige, 160 × 230 cm', (string)$de->boughtVariantText);

$listEn = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter());
$byIdEn = [];
foreach ($listEn->getItems() as $i) { $byIdEn[$i->reviewId] = $i; }
$daEn = $byIdEn['zztest18ec8892e02f1d9cc9'];
check('en_US requested resolves to en_US', $daEn->resolvedLocale === 'en_US');
check('marked translated when resolved differs from source', $daEn->isTranslated() === true);
check('english body returned', str_contains((string)$daEn->body, 'Beautiful rug'));
check('source text also returned for the show-original toggle', str_contains((string)$daEn->sourceBody, 'Flot tæppe'));
check('bought line in english', $daEn->boughtVariantText === 'Bought: Beige, 200 × 250 cm',
    (string)$daEn->boughtVariantText);

$listFr = $provider->getReviews(TEST_GROUP, 'fr_FR', new ReviewFilter());
$byIdFr = [];
foreach ($listFr->getItems() as $i) { $byIdFr[$i->reviewId] = $i; }
check('no fr_FR text -> falls back to source locale', $byIdFr['zztest18ec8892e02f1d9cc9']->resolvedLocale === 'da_DK');
check('no fr_FR bought line -> en_US, not the source language',
    $byIdFr['zztest18ec8892e02f1d9cc9']->boughtVariantText === 'Bought: Beige, 200 × 250 cm');

echo "\n--- filters ---\n";
$fiveStar = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(5));
check('rating filter returns only that rating', count($fiveStar->getItems()) === 2
    && array_reduce($fiveStar->getItems(), static fn ($c, ReviewItem $i) => $c && $i->rating === 5, true));
check('filtered total is a real count, not the group count', $fiveStar->getTotalCount() === 2, (string)$fiveStar->getTotalCount());
check('DISTRIBUTION UNCHANGED under a filter',
    $provider->getSummary(TEST_GROUP, 'en_US')->getDistribution() === [0, 0, 1, 0, 2]);

$threeStar = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(3));
check('rating 3 returns one', count($threeStar->getItems()) === 1 && $threeStar->getTotalCount() === 1);

$withPhotos = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, true));
check('photo filter returns only reviews with photos', count($withPhotos->getItems()) === 1
    && $withPhotos->getItems()[0]->reviewId === 'zztest18ec8892e02f1d9cc9');
check('only the fetched photo is returned, the null one is omitted',
    count($withPhotos->getItems()[0]->photos) === 1, (string)count($withPhotos->getItems()[0]->photos));
$photo = $withPhotos->getItems()[0]->photos[0];
check('photo exposes both derivative paths, never the original',
    $photo->thumbPath === 'ixpf/reviews/x/80/one.jpg' && $photo->gridPath === 'ixpf/reviews/x/800/one.jpg',
    $photo->thumbPath . ' | ' . $photo->gridPath);

$noPhotos = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, false));
check('has_photos=false returns the other two', count($noPhotos->getItems()) === 2);

// has_photos records that IXPF sent photos, not that any of them downloaded. A review whose
// photos are all still pending would otherwise pass the filter and then render with no photo.
$cache   = $om->get(\Magento\Framework\App\CacheInterface::class);
$tag     = [ReviewCache::groupTag(TEST_GROUP)];
$photoId = (int)$conn->fetchOne(
    "SELECT p.entity_id FROM $tPhoto p JOIN $tReview r ON r.entity_id = p.review_entity_id
      WHERE r.review_group = '" . TEST_GROUP . "' AND p.local_path IS NOT NULL LIMIT 1"
);
$conn->update($tPhoto, ['local_path' => null], ['entity_id = ?' => $photoId]);
$cache->clean($tag);
$noneFetched = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, true));
check('photo filter excludes a review whose photos have not downloaded yet',
    $noneFetched->getItems() === [] && $noneFetched->getTotalCount() === 0,
    count($noneFetched->getItems()) . ' items, total ' . $noneFetched->getTotalCount());
$conn->update($tPhoto, ['local_path' => 'ixpf/reviews/x/one.jpg'], ['entity_id = ?' => $photoId]);
$cache->clean($tag);

echo "\n--- with-text filter ---\n";
// Every fixture review has a body, so start from all three and strip one to prove the filter
// actually reads the text rows instead of trusting the group row's with_text_count.
$allThree = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, null, 1, 10, 'newest', true));
check('with_text=true returns every review that has a body', count($allThree->getItems()) === 3,
    (string)count($allThree->getItems()));

$bodyless = (int)$conn->fetchOne(
    "SELECT entity_id FROM $tReview WHERE review_group='" . TEST_GROUP . "' ORDER BY entity_id LIMIT 1"
);
$savedBodies = $conn->fetchAll("SELECT * FROM $tText WHERE review_entity_id = $bodyless");
// Star-only: no body, but keep a reply so the filter cannot pass by merely finding a text row.
$conn->update($tText, ['body' => null, 'reply_body' => 'Thanks!'], ['review_entity_id = ?' => $bodyless]);
$conn->update($tGroup, ['with_text_count' => 2], ['review_group = ?' => TEST_GROUP]);
$cache->clean($tag);

$withText = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, null, 1, 10, 'newest', true));
check('with_text=true excludes a star-only review', count($withText->getItems()) === 2,
    (string)count($withText->getItems()));
check('a reply-only text row does not count as written text',
    !in_array($bodyless, array_map(
        static fn ($i): int => (int)$conn->fetchOne(
            "SELECT entity_id FROM $tReview WHERE review_id = " . $conn->quote($i->reviewId)
        ),
        $withText->getItems()
    ), true));
check('with-text total comes off the group row, not a COUNT(*)',
    $withText->getTotalCount() === 2, (string)$withText->getTotalCount());

$noText = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, null, 1, 10, 'newest', false));
check('with_text=false returns only the star-only review', count($noText->getItems()) === 1,
    (string)count($noText->getItems()));

$unfilteredStill = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter());
check('with_text=null still returns all three', count($unfilteredStill->getItems()) === 3,
    (string)count($unfilteredStill->getItems()));
check('summary still reports both counts',
    $provider->getSummary(TEST_GROUP, 'en_US')->getCount() === 3
    && $provider->getSummary(TEST_GROUP, 'en_US')->getWithTextCount() === 2,
    $provider->getSummary(TEST_GROUP, 'en_US')->getCount() . '/'
        . $provider->getSummary(TEST_GROUP, 'en_US')->getWithTextCount());

// The with-text and photo filters must combine rather than one overwriting the other.
$bothFilters = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, true, 1, 10, 'newest', true));
check('with-text and photo filters combine', $bothFilters->getTotalCount() === count($bothFilters->getItems()),
    $bothFilters->getTotalCount() . ' vs ' . count($bothFilters->getItems()));

check('with_text is part of the cache fragment', count(array_unique(array_map(
    static fn (?bool $w): string => (new ReviewFilter(null, null, 1, 10, 'newest', $w))->getCacheFragment(),
    [null, true, false]
))) === 3);
check('with_text alone means the filter is not unfiltered',
    (new ReviewFilter(null, null, 1, 10, 'newest', true))->isUnfiltered() === false);

$conn->delete($tText, ['review_entity_id = ?' => $bodyless]);
foreach ($savedBodies as $restore) {
    $conn->insert($tText, $restore);
}
$conn->update($tGroup, ['with_text_count' => 3], ['review_group = ?' => TEST_GROUP]);
$cache->clean($tag);

echo "\n--- sort ---\n";
// Fixture: 9cc9 5 stars, bought 15 Jan; 9daf 5 stars, bought 2 Jun; 9e10 3 stars, bought 11 May.
// "Most recent" is the purchase date, which runs opposite to the review dates (26 Aug, 24 Aug, 30 Jul).
$sortIds = static function (string $sort) use ($provider): array {
    return array_map(
        static fn (ReviewItem $i): string => substr($i->reviewId, -4),
        $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, null, 1, 10, $sort))->getItems()
    );
};
$newest = $sortIds(ReviewFilter::SORT_NEWEST);
check('newest is purchase date desc', $newest === ['9daf', '9e10', '9cc9'], implode(',', $newest));
$highest = $sortIds(ReviewFilter::SORT_HIGHEST);
check('highest puts the two 5-star reviews first, latest purchase first',
    $highest === ['9daf', '9cc9', '9e10'], implode(',', $highest));
$lowest = $sortIds(ReviewFilter::SORT_LOWEST);
check('lowest leads with the 3-star review', $lowest === ['9e10', '9daf', '9cc9'], implode(',', $lowest));

// No purchase date (no BUYER badge) sorts after every dated purchase, even the latest one.
$conn->update($tReview, ['purchased_at' => null], ['review_id = ?' => 'zztest18ec8892e02f1d9daf']);
$cache->clean($tag);
$undated = $sortIds(ReviewFilter::SORT_NEWEST);
check('a review without a purchase date sorts last', $undated === ['9e10', '9cc9', '9daf'], implode(',', $undated));
$conn->update($tReview, ['purchased_at' => '2026-06-02 00:00:00'], ['review_id = ?' => 'zztest18ec8892e02f1d9daf']);
$cache->clean($tag);
check('default sort is newest', $sortIds('') === $newest);
check('unrecognised sort falls back to newest', $sortIds('; DROP TABLE') === $newest);
check('sort is whitelisted, never passed through',
    (new ReviewFilter(null, null, 1, 10, 'rating; DROP TABLE'))->getSort() === ReviewFilter::SORT_NEWEST);

// Without the sort in the cache key, one sort's page would be served from another's entry.
$fragments = array_map(
    static fn (string $s): string => (new ReviewFilter(null, null, 1, 10, $s))->getCacheFragment(),
    [ReviewFilter::SORT_NEWEST, ReviewFilter::SORT_HIGHEST, ReviewFilter::SORT_LOWEST]
);
check('each sort gets its own cache fragment', count(array_unique($fragments)) === 3, implode(' ', $fragments));

echo "\n--- pagination ---\n";
$p1 = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, null, 1, 2));
$p2 = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, null, 2, 2));
check('page 1 has 2', count($p1->getItems()) === 2);
check('last page has the remainder', count($p2->getItems()) === 1);
check('page count', $p1->getPageCount() === 2);
check('pages do not overlap',
    $p1->getItems()[0]->reviewId !== $p2->getItems()[0]->reviewId
    && $p1->getItems()[1]->reviewId !== $p2->getItems()[0]->reviewId);
$p9 = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, null, 9, 2));
check('page past the end is empty, not an error', $p9->getItems() === [] && $p9->getTotalCount() === 3);
// page is an anonymous query param: a cache entry per page number would grow without bound.
// The cache type may be off on this install, so switch it on in memory for the check (no
// persist). The in-range page 2 is the control that proves the cache is actually writing.
$listKey = static fn (int $page): string => 'ixpf_review_' . md5(
    TEST_GROUP . '|list_en_US_' . (new ReviewFilter(null, null, $page, 2))->getCacheFragment()
);
$typedCache = $om->get(ReviewCache::class);
$cacheState = $om->get(\Magento\Framework\App\Cache\StateInterface::class);
$cacheWasOn = $cacheState->isEnabled(ReviewCache::TYPE_IDENTIFIER);
$cacheState->setEnabled(ReviewCache::TYPE_IDENTIFIER, true);
$cache->clean($tag);
$provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, null, 2, 2));
$provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, null, 9, 2));
check('in-range page is cached (control)', $typedCache->load($listKey(2)) !== false);
check('page past the end is not cached', $typedCache->load($listKey(9)) === false);
$cache->clean($tag);
$cacheState->setEnabled(ReviewCache::TYPE_IDENTIFIER, $cacheWasOn);

echo "\n--- filter clamping (an HTTP wrapper will pass query params straight through) ---\n";
$f = new ReviewFilter(99, null, -5, 100000);
check('out of range rating dropped', $f->getRating() === null);
check('page floored to 1', $f->getPage() === 1);
check('page size capped', $f->getPageSize() === ReviewFilter::MAX_PAGE_SIZE);

echo "\n--- cache is tagged and cleaned by the upsert ---\n";
$before = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter())->getTotalCount();
check('cached read matches', $before === 3);
$v2 = json_decode((string)file_get_contents('/var/www/html/app/code/IXPF/Syncer/Test/fixture/review_group_8518C_v2.json'), true);
$v2['review_group'] = TEST_GROUP;
$upsert->upsert($v2);
$after = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter());
check('a new push invalidates the cached list', $after->getTotalCount() === 2, (string)$after->getTotalCount());
check('removed review is gone from the list', count($after->getItems()) === 2);
check('summary cache invalidated too', $provider->getSummary(TEST_GROUP, 'en_US')->getCount() === 2);

echo "\n--- product -> group key ---\n";
check('missing product returns null', $provider->getGroupKeyForProduct(0) === null);
check('nonexistent product returns null', $provider->getGroupKeyForProduct(999999999) === null);

$attrId = (int)$conn->fetchOne("SELECT attribute_id FROM eav_attribute WHERE attribute_code='review_group' AND entity_type_id=4");
// A product that has no review_group of its own. This test overwrites the attribute and then
// deletes the row, so grabbing the first product in the table would silently un-wire whichever
// real product happened to be first - only luck kept that from being one with live reviews.
$productId = (int)$conn->fetchOne(
    "SELECT p.entity_id FROM catalog_product_entity p
     LEFT JOIN catalog_product_entity_varchar v
       ON v.entity_id = p.entity_id AND v.attribute_id = $attrId AND v.store_id = 0
     WHERE v.value_id IS NULL
     LIMIT 1"
);
$conn->insertOnDuplicate('catalog_product_entity_varchar',
    ['attribute_id' => $attrId, 'store_id' => 0, 'entity_id' => $productId, 'value' => TEST_GROUP], ['value']);
check('product with review_group returns it', $provider->getGroupKeyForProduct($productId) === TEST_GROUP,
    (string)$provider->getGroupKeyForProduct($productId));
$conn->delete('catalog_product_entity_varchar', ['attribute_id = ?' => $attrId, 'entity_id = ?' => $productId, 'store_id = ?' => 0]);
check('product with the attribute cleared returns null', $provider->getGroupKeyForProduct($productId) === null);

echo "\n--- star split and source domain (view model) ---\n";
$vm = $om->get(\IXPF\Syncer\ViewModel\Reviews\Reviews::class);

// Floor plus a half for any fraction, and never a sixth glyph. The averages here are the real
// ones in the imported pools: 1.00, 3.00, 3.33, 3.67, 4.50.
$starCases = [
    [1.00, 1, false], [3.00, 3, false], [3.33, 3, true],
    [3.67, 3, true],  [4.50, 4, true],  [4.80, 4, true],
    [5.00, 5, false], [0.00, 0, false],
];
$starOk = true;
foreach ($starCases as [$rating, $filled, $half]) {
    if ($vm->getFilledStars($rating) !== $filled || $vm->hasHalfStar($rating) !== $half) {
        $starOk = false;
        echo sprintf("       %.2f -> filled=%d half=%s\n", $rating,
            $vm->getFilledStars($rating), var_export($vm->hasHalfStar($rating), true));
    }
}
check('star split floors and adds a half for any fraction', $starOk);
check('no rating paints a sixth star', (static function () use ($vm, $starCases): bool {
    foreach ($starCases as [$rating]) {
        if ($vm->getFilledStars($rating) + ($vm->hasHalfStar($rating) ? 1 : 0) > 5) {
            return false;
        }
    }
    return true;
})());
check('5.0 is five full stars, not five plus a half',
    $vm->getFilledStars(5.0) === 5 && $vm->hasHalfStar(5.0) === false);
check('the rendered row carries exactly one is-half for a fractional average',
    substr_count($vm->getStarsHtml(3.33), 'is-half') === 1, $vm->getStarsHtml(3.33));
check('a whole average renders no half star',
    !str_contains($vm->getStarsHtml(3.0), 'is-half'));

// The note names the source market's domain, not the language - the spec is explicit, and `de`
// and `ch_de` both resolve to "German" so only the domain tells them apart.
// These need the multi-market store set (de, ch_de, dk); a single-market install such as
// the JP one only has `jp`, so they are skipped there rather than reported as failures.
$storeCodes = array_keys($om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStores(false, true));
if (array_diff(['de', 'ch_de', 'dk'], $storeCodes) === []) {
    $deDomain   = $vm->getSourceDomain('de', 'de_DE');
    $chDeDomain = $vm->getSourceDomain('ch_de', 'de_DE');
    check('store code resolves to a host', str_contains($deDomain, 'carpetavenue'), $deDomain);
    check('de and ch_de are distinguishable, which the language name cannot do',
        $deDomain !== $chDeDomain, "$deDomain vs $chDeDomain");
    check('no scheme or path leaks into the note',
        !str_contains($deDomain, '://') && !str_contains($deDomain, '/'), $deDomain);
    check('www is stripped', !str_starts_with($deDomain, 'www.'), $deDomain);
    // se/dk/jp carry their base URL at website scope, not store scope - the lookup must fall through.
    check('a store whose base URL is set at website scope still resolves',
        str_contains($vm->getSourceDomain('dk', 'da_DK'), 'carpetavenue'),
        $vm->getSourceDomain('dk', 'da_DK'));
} else {
    echo "  skip source-domain checks: stores de/ch_de/dk not on this install\n";
}
// IXPF pushes markets that are not live in Magento; the note must degrade, not vanish or throw.
check('an unknown store code falls back to the language name',
    $vm->getSourceDomain('zz_nope', 'da_DK') === $vm->getLanguageName('da_DK'),
    $vm->getSourceDomain('zz_nope', 'da_DK'));
// source_store_code is not a required payload field, so it can be stored empty - and
// getStore('') resolves to the *current* store, which would have the note name the domain the
// customer is already reading.
check('an empty store code does not resolve to the current store',
    $vm->getSourceDomain('', 'da_DK') === $vm->getLanguageName('da_DK'),
    $vm->getSourceDomain('', 'da_DK'));
check('a whitespace-only store code is treated as empty',
    $vm->getSourceDomain('   ', 'da_DK') === $vm->getLanguageName('da_DK'));
check('a padded store code still resolves rather than missing the lookup',
    $vm->getSourceDomain('  de  ', 'de_DE') === $vm->getSourceDomain('de', 'de_DE'),
    $vm->getSourceDomain('  de  ', 'de_DE'));
check('a nonsensical negative rating paints nothing rather than a lone half',
    $vm->getFilledStars(-1.5) === 0 && $vm->hasHalfStar(-1.5) === false);

echo "\n--- rating vs review counts, headline and filter availability (admin settings) ---\n";
// Own pool with one entry of each kind: A text + photo + sub-ratings, B sub-ratings only,
// C stars only. Every setting touched here is put back exactly as it was found.
const COUNT_GROUP = 'ZZREADCOUNT';
$settingPaths = [
    'ixpf_reviews/general/review_criteria', 'ixpf_reviews/general/count_display',
    'ixpf_reviews/general/show_photo_filter', 'ixpf_reviews/general/show_text_filter',
];
$settingsBefore = $conn->fetchPairs(
    $conn->select()->from($configTable, ['path', 'value'])
        ->where('scope = ?', 'default')->where('scope_id = ?', 0)->where('path IN (?)', $settingPaths)
);
$setSetting = static function (string $path, string $value) use ($conn, $om, $configTable): void {
    $conn->insertOnDuplicate($configTable, ['scope' => 'default', 'scope_id' => 0, 'path' => $path, 'value' => $value], ['value']);
    $om->get(\Magento\Framework\App\Config::class)->clean();
};
$entry = static fn (string $id, int $rating, array $extra): array => $extra + [
    'id' => $id, 'sku' => 'ZZ-C', 'rating' => $rating, 'reviewer' => 'Count T.', 'created_at' => '2026-09-01T10:00:00Z',
    'source' => 'customer', 'source_locale' => 'en_US', 'source_store_code' => 'en', 'body' => ['en_US' => ''],
    'bought' => ['name' => 'Count rug', 'purchased_at' => '2026-08-01T10:00:00Z'],
];
$upsert->upsert([
    'review_group' => COUNT_GROUP, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'count' => 3, 'with_text_count' => 1,
    'average' => 4.0, 'distribution' => [0, 0, 1, 1, 1],
    'reviews' => [
        $entry('zzcounta00000000000000001', 5, ['body' => ['en_US' => 'Lovely rug'],
            'sub_ratings' => ['quality' => 5, 'delivery' => 5, 'customer_service' => 5],
            'photos' => [['bucket_path' => 'reviews/zz/count-a.jpg', 'width' => 800, 'height' => 600]]]),
        $entry('zzcountb00000000000000002', 4, ['sub_ratings' => ['quality' => 4, 'delivery' => 1, 'customer_service' => 5]]),
        $entry('zzcountc00000000000000003', 3, []),
    ],
]);
$conn->update($tPhoto, ['local_path' => 'ixpf/reviews/zz/count-a.jpg'], ['bucket_path = ?' => 'reviews/zz/count-a.jpg']);
$countTag = [ReviewCache::groupTag(COUNT_GROUP)];
$summaryFor = static function () use ($cache, $countTag, $provider): \IXPF\Syncer\Api\Data\ReviewSummaryInterface {
    $cache->clean($countTag);
    return $provider->getSummary(COUNT_GROUP, 'en_US');
};

foreach ([
    ['text,photo,sub_ratings', 2], ['text', 1], ['photo', 1], ['sub_ratings', 2], ['text,photo', 1], ['', 0],
] as [$criteria, $expected]) {
    $setSetting('ixpf_reviews/general/review_criteria', $criteria);
    $got = $summaryFor()->getReviewCount();
    check("review count with criteria \"$criteria\" is $expected", $got === $expected, (string)$got);
}
$setSetting('ixpf_reviews/general/review_criteria', 'text,photo,sub_ratings');
$summary = $summaryFor();
check('text count is the one written review', $summary->getTextCount() === 1, (string)$summary->getTextCount());
check('photo count is the one review with a downloaded photo', $summary->getPhotoCount() === 1, (string)$summary->getPhotoCount());
$conn->update($tPhoto, ['local_path' => null], ['bucket_path = ?' => 'reviews/zz/count-a.jpg']);
check('a photo still in the queue does not count for the filter', $summaryFor()->getPhotoCount() === 0);
check('...but it still makes the entry a review', $summaryFor()->getReviewCount() === 2);
$conn->update($tPhoto, ['local_path' => 'ixpf/reviews/zz/count-a.jpg'], ['bucket_path = ?' => 'reviews/zz/count-a.jpg']);

$headline = static function (string $mode, string $criteria) use ($setSetting, $summaryFor, $om): string {
    $setSetting('ixpf_reviews/general/count_display', $mode);
    $setSetting('ixpf_reviews/general/review_criteria', $criteria);
    return $om->create(\IXPF\Syncer\ViewModel\Reviews\Reviews::class)->getSummaryCountLabel($summaryFor());
};
foreach ([
    ['split', 'text,photo,sub_ratings', 'Based on 2 reviews and 1 rating'],
    ['overlap', 'text,photo,sub_ratings', 'Based on 3 ratings and 2 reviews'],
    ['combined', 'text,photo,sub_ratings', 'Based on 3 reviews'],
    ['split', 'text', 'Based on 1 review and 2 ratings'],
    ['split', '', 'Based on 3 ratings'],
    ['overlap', '', 'Based on 3 ratings'],
    ['nonsense', 'text,photo,sub_ratings', 'Based on 2 reviews and 1 rating'],
] as [$mode, $criteria, $expected]) {
    $got = $headline($mode, $criteria);
    check("headline $mode / \"$criteria\": $expected", $got === $expected, $got);
}
$setSetting('ixpf_reviews/general/review_criteria', 'text,photo,sub_ratings');

$availability = static function (string $photo, string $text) use ($setSetting, $summaryFor, $om): array {
    $setSetting('ixpf_reviews/general/show_photo_filter', $photo);
    $setSetting('ixpf_reviews/general/show_text_filter', $text);
    $vm = $om->create(\IXPF\Syncer\ViewModel\Reviews\Reviews::class);
    (new ReflectionProperty($vm, 'summary'))->setValue($vm, $summaryFor());
    return $vm->getFilterAvailability();
};
check('both filters offered when switched on and the pool has photos and text',
    $availability('1', '1') === ['photos' => true, 'text' => true], json_encode($availability('1', '1')));
check('switched off in admin -> not offered, even with data',
    $availability('0', '0') === ['photos' => false, 'text' => false], json_encode($availability('0', '0')));
$conn->update($tPhoto, ['local_path' => null], ['bucket_path = ?' => 'reviews/zz/count-a.jpg']);
$conn->update($tText, ['body' => null], ['review_entity_id = ?' => (int)$conn->fetchOne(
    "SELECT entity_id FROM $tReview WHERE review_id = 'zzcounta00000000000000001'"
)]);
check('no downloaded photo and no text -> neither offered, even when switched on',
    $availability('1', '1') === ['photos' => false, 'text' => false], json_encode($availability('1', '1')));

$request = $om->get(\Magento\Framework\App\RequestInterface::class);
$request->setParam('has_photos', '1');
$setSetting('ixpf_reviews/general/show_photo_filter', '0');
check('has_photos is ignored while the "Images only" filter is switched off',
    $om->create(\IXPF\Syncer\ViewModel\Reviews\Reviews::class)->getFilter()->getHasPhotos() === null);
$setSetting('ixpf_reviews/general/show_photo_filter', '1');
check('...and applied when it is on',
    $om->create(\IXPF\Syncer\ViewModel\Reviews\Reviews::class)->getFilter()->getHasPhotos() === true);
$request->setParam('has_photos', null);

$conn->delete($tGroup, ['review_group = ?' => COUNT_GROUP]);
$conn->delete($configTable, ['scope = ?' => 'default', 'scope_id = ?' => 0, 'path IN (?)' => $settingPaths]);
foreach ($settingsBefore as $path => $value) {
    $conn->insert($configTable, ['scope' => 'default', 'scope_id' => 0, 'path' => $path, 'value' => $value]);
}
$om->get(\Magento\Framework\App\Config::class)->clean();

echo "\n--- a review with no usable locale is omitted ---\n";
// Runs last: it strips a review's text rows, and the hash diff would skip a restoring push
// because the review's JSON is unchanged.
$strandedId = (int)$conn->fetchOne("SELECT entity_id FROM $tReview WHERE review_id='zztest18ec8892e02f1d9daf'");
$storedBefore = (int)$conn->fetchOne("SELECT COUNT(*) FROM $tReview WHERE review_group='" . TEST_GROUP . "'");
$totalBefore  = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, null, 1, 10))->getTotalCount();
// Leave it only a locale that is neither requested, nor its source, nor en_US.
$conn->delete($tText, ['review_entity_id = ?' => $strandedId]);
$conn->insert($tText, ['review_entity_id' => $strandedId, 'locale' => 'zz_ZZ', 'body' => 'x']);
// Editing text rows behind the service's back does not clean the tag, which is correct - only
// a push does. Clean it by hand so this reads the database rather than the cached page.
$om->get(\Magento\Framework\App\CacheInterface::class)
    ->clean([ReviewCache::groupTag(TEST_GROUP)]);
$stranded = $provider->getReviews(TEST_GROUP, 'en_US', new ReviewFilter(null, null, 1, 10));
$strandedIds = array_map(static fn (ReviewItem $i): string => $i->reviewId, $stranded->getItems());
check('review with no resolvable locale omitted from items',
    !in_array('zztest18ec8892e02f1d9daf', $strandedIds, true), json_encode($strandedIds));
check('every other review still returned', count($stranded->getItems()) === $storedBefore - 1,
    count($stranded->getItems()) . ' of ' . ($storedBefore - 1));
check('total still reports the stored count, items are what got dropped',
    $stranded->getTotalCount() === $totalBefore, $stranded->getTotalCount() . ' vs ' . $totalBefore);

// cleanup - put the master switch back exactly as it was found
$conn->delete($tGroup, ['review_group LIKE ?' => 'ZZ%']);
if ($enabledBefore === false) {
    $conn->delete($configTable, ['path = ?' => 'ixpf_reviews/general/enabled']);
} else {
    $setEnabled((int)$enabledBefore);
}
$om->get(\Magento\Framework\App\Config::class)->clean();
echo "\ncleaned up test rows; master switch restored to "
    . ($enabledBefore === false ? 'unset (config.xml default)' : (string)$enabledBefore) . "\n";

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
