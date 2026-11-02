<?php
/**
 * Phase 2 checks: publish, consumer idempotency, gallery reuse, derivatives, give-up path.
 */
declare(strict_types=1);

require '/var/www/html/app/bootstrap.php';

use IXPF\Syncer\Model\Queue\ReviewMediaMessage;
use IXPF\Syncer\Model\Reviews\ReviewFilter;
use IXPF\Syncer\Model\Queue\Consumer\ReviewMediaConsumer;
use IXPF\Syncer\Services\Reviews\ReviewMediaStorage;
use IXPF\Syncer\Services\Reviews\UpsertReviewGroup;
use IXPF\Syncer\Cron\ReviewMediaCleanup;
use Magento\Framework\App\Bootstrap;

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$service  = $om->get(UpsertReviewGroup::class);
$consumer = $om->get(ReviewMediaConsumer::class);
$storage  = $om->get(ReviewMediaStorage::class);
$cleanup  = $om->get(ReviewMediaCleanup::class);
$msgFactory = $om->get(\IXPF\Syncer\Model\Queue\ReviewMediaMessageFactory::class);
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$conn = $resource->getConnection();

$pass = 0; $fail = 0;
function check(string $n, bool $ok, string $d=''): void {
    global $pass,$fail;
    if ($ok) { $pass++; echo "  ok   $n\n"; return; }
    $fail++; echo "  FAIL $n" . ($d!==''?" -- $d":'') . "\n";
}

$tReview = $resource->getTableName('ixpf_review');
$tPhoto  = $resource->getTableName('ixpf_review_photo');
$tGroup  = $resource->getTableName('ixpf_review_group');
$tQueue  = $resource->getTableName('queue_message');

$fixture = json_decode((string)file_get_contents('/var/www/html/app/code/IXPF/Syncer/Test/fixture/review_group_8518C.json'), true);
$fixture['review_group'] = 'ZZMEDIA';

$makeJpeg = static function (string $absPath, int $w, int $h): void {
    if (!is_dir(dirname($absPath))) { mkdir(dirname($absPath), 0777, true); }
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 160));
    imagejpeg($im, $absPath, 90);
    imagedestroy($im);
};

echo "\n--- setup: push a group with photos + thumbnails ---\n";
// review_id is unique platform-wide and both e2e scripts use the same fixture ids, so
// clear every ZZ test pool first or they collide on the unique key.
$conn->delete($tGroup, ['review_group LIKE ?' => 'ZZ%']);
$conn->delete($tQueue, ['topic_name = ?' => UpsertReviewGroup::MEDIA_TOPIC]);
$r = $service->upsert($fixture);
check('push stored', ($r['status'] ?? '') === 'stored', json_encode($r));

$queued = (int)$conn->fetchOne("SELECT COUNT(*) FROM $tQueue WHERE topic_name='" . UpsertReviewGroup::MEDIA_TOPIC . "'");
// 2 photos + 3 thumbnails
check('5 messages published to the db queue', $queued === 5, "got $queued");

$body = (string)$conn->fetchOne("SELECT body FROM $tQueue WHERE topic_name='" . UpsertReviewGroup::MEDIA_TOPIC . "' LIMIT 1");
check('message body carries review_id/bucket_path/type',
    str_contains($body, 'review_id') && str_contains($body, 'bucket_path') && str_contains($body, 'type'), $body);

// The rest of this script hands the consumer a message built by the factory, which skips the
// step the queue framework actually performs. Decode a stored body the way the consumer runtime
// does, or a DTO the decoder cannot populate passes every other check here while the real queue
// rejects all of it.
$encoder = $om->get(\Magento\Framework\MessageQueue\MessageEncoder::class);
$decoded = null;
$decodeError = '';
try {
    $decoded = $encoder->decode(UpsertReviewGroup::MEDIA_TOPIC, $body);
} catch (\Throwable $e) {
    $decodeError = $e->getMessage();
}
check('stored body decodes back into a ReviewMediaMessage',
    $decoded instanceof ReviewMediaMessage, $decodeError);
$raw = json_decode($body, true);
check('decoded message keeps review_id',
    $decoded instanceof ReviewMediaMessage && $decoded->getReviewId() === $raw['review_id']);
check('decoded message keeps bucket_path',
    $decoded instanceof ReviewMediaMessage && $decoded->getBucketPath() === $raw['bucket_path']);
check('decoded message keeps type',
    $decoded instanceof ReviewMediaMessage && $decoded->getType() === $raw['type']);

echo "\n--- storage path conventions ---\n";
$rel = $storage->relativePath('zztest18ec8892e02f1d9cc9', 'caProdMagento/reviews/x/xyz-p1.jpg');
check('original path', $rel === 'ixpf/reviews/zztest18ec8892e02f1d9cc9/xyz-p1.jpg', $rel);
check('80 derivative path', $storage->derivativePath($rel, 80) === 'ixpf/reviews/zztest18ec8892e02f1d9cc9/80/xyz-p1.jpg');
check('800 derivative path', $storage->derivativePath($rel, 800) === 'ixpf/reviews/zztest18ec8892e02f1d9cc9/800/xyz-p1.jpg');
check('query string stripped from basename',
    $storage->relativePath('r1', 'a/b/c.jpg?v=2') === 'ixpf/reviews/r1/c.jpg',
    $storage->relativePath('r1', 'a/b/c.jpg?v=2'));
check('directoryName sanitises', $storage->directoryName('ab/cd') === 'ab_cd');

echo "\n--- derivatives on a real landscape jpeg ---\n";
$landRel = 'ixpf/reviews/ZZTESTLAND/land.jpg';
$makeJpeg($storage->absolutePath($landRel), 2000, 1500);
$storage->generateDerivatives($landRel);
$t = @getimagesize($storage->absolutePath($storage->derivativePath($landRel, 80)));
$g = @getimagesize($storage->absolutePath($storage->derivativePath($landRel, 800)));
check('80px square generated', $t !== false && $t[0] === 80 && $t[1] === 80, json_encode($t));
check('800px longest edge on landscape', $g !== false && $g[0] === 800 && $g[1] === 600, json_encode($g));

echo "\n--- derivatives on a real portrait jpeg ---\n";
$portRel = 'ixpf/reviews/ZZTESTPORT/port.jpg';
$makeJpeg($storage->absolutePath($portRel), 1200, 1600);
$storage->generateDerivatives($portRel);
$g2 = @getimagesize($storage->absolutePath($storage->derivativePath($portRel, 800)));
check('800px longest edge on portrait (height capped)', $g2 !== false && $g2[1] === 800 && $g2[0] === 600, json_encode($g2));

echo "\n--- thumbnail reuses an existing gallery file, no download ---\n";
$reviewId = 'zztest18ec8892e02f1d9daf';
$bucket   = 'caProdMagento/products/aw1646/images/standard/aw1646-00001.jpg';
$galleryRel = 'catalog/product/a/w/aw1646-00001.jpg';
$makeJpeg($storage->absolutePath($galleryRel), 500, 500);
check('findExistingGalleryFile locates it', $storage->findExistingGalleryFile($bucket) === $galleryRel,
    (string)$storage->findExistingGalleryFile($bucket));

$consumer->execute($msgFactory->create([
    'reviewId' => $reviewId, 'bucketPath' => $bucket, 'type' => ReviewMediaMessage::TYPE_THUMBNAIL,
]));
$thumbLocal = $conn->fetchOne("SELECT thumbnail_local FROM $tReview WHERE review_id='$reviewId'");
check('thumbnail_local set to the gallery path', $thumbLocal === $galleryRel, (string)$thumbLocal);
check('no ixpf/reviews dir created for it', !is_dir($storage->absolutePath('ixpf/reviews/' . $reviewId)));

echo "\n--- media landing after a push invalidates the read cache ---\n";
// The regression this guards: the consumer used to write thumbnail_local and local_path without
// cleaning anything, so a pool cached in the window between the push and the download kept
// serving image-less reviews until the entry aged out a day later.
$provider   = $om->get(\IXPF\Syncer\Api\ReviewProviderInterface::class);
$cleaner    = $om->get(\IXPF\Syncer\Services\Reviews\ReviewCacheCleaner::class);
$thumbGroup = (string)$conn->fetchOne("SELECT review_group FROM $tReview WHERE review_id='$reviewId'");
// false = review not in the list at all, so a missing review cannot pass as "no thumbnail yet".
$thumbOf = static function () use ($provider, $thumbGroup, $reviewId): string|null|false {
    foreach ($provider->getReviews($thumbGroup, 'en_US', new ReviewFilter())->getItems() as $item) {
        if ($item->reviewId === $reviewId) {
            return $item->thumbnailPath;
        }
    }

    return false;
};

// The read side is behind ixpf_reviews/general/enabled (default 0), and with the ixpf_reviews
// cache type off nothing is cached, so this block would prove nothing. Switch both on for the
// block; the cache state is in-memory only (no persist) and the config row is restored as found.
$configTable   = $resource->getTableName('core_config_data');
$enabledBefore = $conn->fetchOne(
    "SELECT value FROM $configTable WHERE scope='default' AND scope_id=0 AND path='ixpf_reviews/general/enabled'"
);
$conn->insertOnDuplicate($configTable, [
    'scope' => 'default', 'scope_id' => 0, 'path' => 'ixpf_reviews/general/enabled', 'value' => '1',
], ['value']);
$om->get(\Magento\Framework\App\Config::class)->clean();
$cacheState = $om->get(\Magento\Framework\App\Cache\StateInterface::class);
$cacheWasOn = $cacheState->isEnabled(\IXPF\Syncer\Model\Reviews\Cache::TYPE_IDENTIFIER);
$cacheState->setEnabled(\IXPF\Syncer\Model\Reviews\Cache::TYPE_IDENTIFIER, true);

// Back to "not fetched yet", which is the state every review is in right after a push.
$conn->update($tReview, ['thumbnail_local' => null], ['review_id = ?' => $reviewId]);
$cleaner->cleanGroup($thumbGroup);
check('pool caches the review before its thumbnail lands', $thumbOf() === null, var_export($thumbOf(), true));

$consumer->execute($msgFactory->create([
    'reviewId' => $reviewId, 'bucketPath' => $bucket, 'type' => ReviewMediaMessage::TYPE_THUMBNAIL,
]));
check('thumbnail is visible with no manual cache clean', $thumbOf() === $galleryRel, var_export($thumbOf(), true));

$cacheState->setEnabled(\IXPF\Syncer\Model\Reviews\Cache::TYPE_IDENTIFIER, $cacheWasOn);
if ($enabledBefore === false) {
    $conn->delete($configTable, ['path = ?' => 'ixpf_reviews/general/enabled']);
} else {
    $conn->update($configTable, ['value' => $enabledBefore],
        ['path = ?' => 'ixpf_reviews/general/enabled', 'scope = ?' => 'default', 'scope_id = ?' => 0]);
}
$om->get(\Magento\Framework\App\Config::class)->clean();

echo "\n--- consumer is idempotent: already-stored file is skipped ---\n";
$before = $conn->fetchOne("SELECT thumbnail_local FROM $tReview WHERE review_id='$reviewId'");
$consumer->execute($msgFactory->create([
    'reviewId' => $reviewId, 'bucketPath' => $bucket, 'type' => ReviewMediaMessage::TYPE_THUMBNAIL,
]));
check('second run leaves it unchanged', $conn->fetchOne("SELECT thumbnail_local FROM $tReview WHERE review_id='$reviewId'") === $before);

echo "\n--- outdated thumbnail message does not store the old image ---\n";
// A message queued before a push moved thumbnail_path. The stale path resolves to a real gallery
// file, so without the path guard the consumer would happily store it - and a set thumbnail_local
// is never re-queued, so it would stick.
$staleBucket = 'caProdMagento/products/aw1646/images/standard/aw1646-00009.jpg';
$staleRel    = 'catalog/product/a/w/aw1646-00009.jpg';
$makeJpeg($storage->absolutePath($staleRel), 500, 500);
$conn->update($tReview, ['thumbnail_local' => null], ['review_id = ?' => $reviewId]);
$consumer->execute($msgFactory->create([
    'reviewId' => $reviewId, 'bucketPath' => $staleBucket, 'type' => ReviewMediaMessage::TYPE_THUMBNAIL,
]));
$afterStale = $conn->fetchOne("SELECT thumbnail_local FROM $tReview WHERE review_id='$reviewId'");
check('stale-path message leaves thumbnail_local null', $afterStale === null, var_export($afterStale, true));
@unlink($storage->absolutePath($staleRel));
$conn->update($tReview, ['thumbnail_local' => $before], ['review_id = ?' => $reviewId]);

echo "\n--- photo already downloaded is skipped ---\n";
$photoRow = $conn->fetchRow("SELECT p.entity_id, p.bucket_path, r.review_id FROM $tPhoto p JOIN $tReview r ON r.entity_id=p.review_entity_id WHERE r.review_group='ZZMEDIA' LIMIT 1");
$photoRel = $storage->relativePath($photoRow['review_id'], $photoRow['bucket_path']);
$makeJpeg($storage->absolutePath($photoRel), 300, 200);
$conn->update($tPhoto, ['local_path' => $photoRel], ['entity_id = ?' => (int)$photoRow['entity_id']]);
$mtimeBefore = filemtime($storage->absolutePath($photoRel));
sleep(1);
$consumer->execute($msgFactory->create([
    'reviewId' => $photoRow['review_id'], 'bucketPath' => $photoRow['bucket_path'], 'type' => ReviewMediaMessage::TYPE_PHOTO,
]));
clearstatcache();
check('file not re-downloaded (mtime unchanged)', filemtime($storage->absolutePath($photoRel)) === $mtimeBefore);
check('local_path unchanged', $conn->fetchOne("SELECT local_path FROM $tPhoto WHERE entity_id={$photoRow['entity_id']}") === $photoRel);

echo "\n--- unreachable bucket path gives up cleanly ---\n";
$other = $conn->fetchRow("SELECT p.entity_id, p.bucket_path, r.review_id FROM $tPhoto p JOIN $tReview r ON r.entity_id=p.review_entity_id WHERE r.review_group='ZZMEDIA' AND p.local_path IS NULL LIMIT 1");
$threw = false;
$t0 = microtime(true);
try {
    $consumer->execute($msgFactory->create([
        'reviewId' => $other['review_id'], 'bucketPath' => $other['bucket_path'], 'type' => ReviewMediaMessage::TYPE_PHOTO,
    ]));
} catch (Throwable $e) { $threw = true; }
$elapsed = microtime(true) - $t0;
check('consumer never rethrows (message not requeued forever)', $threw === false);
check('local_path left null after giving up',
    $conn->fetchOne("SELECT local_path FROM $tPhoto WHERE entity_id={$other['entity_id']}") === null);
// A permanent failure - missing credentials here, a wrong bucket prefix in production - must
// short-circuit rather than sleep through the backoff ladder. Three attempts at 1s + 3s per
// file adds four wasted seconds to every file of every push across a whole pool.
// This asserts the classification, not that sleep() sleeps.
check('permanent failure skips the retry backoff', $elapsed < 1.0, sprintf('%.1fs', $elapsed));

echo "\n--- republish only queues what is still missing ---\n";
$conn->delete($tQueue, ['topic_name = ?' => UpsertReviewGroup::MEDIA_TOPIC]);
$again = $fixture;
$again['generated_at'] = '2026-10-01T00:00:00Z';
$service->upsert($again);
$requeued = (int)$conn->fetchOne("SELECT COUNT(*) FROM $tQueue WHERE topic_name='" . UpsertReviewGroup::MEDIA_TOPIC . "'");
// 1 photo has local_path, 1 thumbnail resolved -> 1 photo + 2 thumbnails left
check('already-fetched files are not re-queued', $requeued === 3, "got $requeued");

echo "\n--- cleanup cron removes orphan dirs only ---\n";
$liveDir = 'ixpf/reviews/' . $storage->directoryName($photoRow['review_id']);
check('live review dir exists before cleanup', is_dir($storage->absolutePath($liveDir)));
$removed = $cleanup->removeOrphans();
check('orphan dirs removed', $removed >= 2, "removed $removed");
check('live review dir kept', is_dir($storage->absolutePath($liveDir)));
check('orphan ZZTESTLAND gone', !is_dir($storage->absolutePath('ixpf/reviews/ZZTESTLAND')));
check('orphan ZZTESTPORT gone', !is_dir($storage->absolutePath('ixpf/reviews/ZZTESTPORT')));

// cleanup
$conn->delete($tGroup, ['review_group = ?' => 'ZZMEDIA']);
$conn->delete($tQueue, ['topic_name = ?' => UpsertReviewGroup::MEDIA_TOPIC]);
@unlink($storage->absolutePath($galleryRel));
$cleanup->removeOrphans();
echo "\ncleaned up\n";

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
