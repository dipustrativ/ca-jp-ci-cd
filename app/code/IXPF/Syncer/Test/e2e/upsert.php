<?php
/**
 * End-to-end check of the review upsert against the real database.
 * Bootstraps Magento and calls the service directly (the local env 302s every
 * ixpfsyncer controller, including the existing production AttributeMassUpdate one).
 */
declare(strict_types=1);

require '/var/www/html/app/bootstrap.php';

use IXPF\Syncer\Services\Reviews\UpsertReviewGroup;
use Magento\Framework\App\Bootstrap;

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

/** @var UpsertReviewGroup $service */
$service = $om->get(UpsertReviewGroup::class);
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$conn = $resource->getConnection();

$fixtureDir = '/var/www/html/app/code/IXPF/Syncer/Test/fixture';
$v1 = json_decode((string)file_get_contents($fixtureDir . '/review_group_8518C.json'), true);
$v2 = json_decode((string)file_get_contents($fixtureDir . '/review_group_8518C_v2.json'), true);

// Run under a throwaway key. The fixtures name a real design group and this script deletes
// its group row on the way in and out - never point it at a pool holding real data.
const TEST_GROUP = 'ZZREVIEW';
$v1['review_group'] = TEST_GROUP;
$v2['review_group'] = TEST_GROUP;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   $name\n"; return; }
    $fail++;
    echo "  FAIL $name" . ($detail !== '' ? " -- $detail" : '') . "\n";
}

$tGroup  = $resource->getTableName('ixpf_review_group');
$tReview = $resource->getTableName('ixpf_review');
$tText   = $resource->getTableName('ixpf_review_text');
$tPhoto  = $resource->getTableName('ixpf_review_photo');

$countRows = static function (string $table, string $where = '') use ($conn): int {
    $sql = "SELECT COUNT(*) FROM $table" . ($where !== '' ? " WHERE $where" : '');
    return (int)$conn->fetchOne($sql);
};

// review_id is unique platform-wide and both e2e scripts use the same fixture ids, so
// clear every ZZ test pool first or they collide on the unique key.
$conn->delete($tGroup, ['review_group LIKE ?' => 'ZZ%']);
echo "\n--- 1. first push ---\n";
$r = $service->upsert($v1);
check('status stored', ($r['status'] ?? '') === 'stored', json_encode($r));
check('reports 3 reviews', ($r['reviews'] ?? 0) === 3);
check('group row exists', $countRows($tGroup, "review_group='" . TEST_GROUP . "'") === 1);
check('3 review rows', $countRows($tReview, "review_group='" . TEST_GROUP . "'") === 3);

$group = $conn->fetchRow("SELECT * FROM $tGroup WHERE review_group='" . TEST_GROUP . "'");
check('count stored', (int)$group['review_count'] === 3);
check('with_text_count stored', (int)$group['with_text_count'] === 3, (string)$group['with_text_count']);
check('average stored', (float)$group['review_average'] === 4.33, (string)$group['review_average']);
check('distribution stored', [(int)$group['dist_1'],(int)$group['dist_2'],(int)$group['dist_3'],(int)$group['dist_4'],(int)$group['dist_5']] === [0,0,1,0,2]);
check('sub averages stored', (float)$group['avg_quality'] === 4.7 && (float)$group['avg_delivery'] === 4.0);
$label = json_decode((string)$group['group_label'], true);
check('group_label json, 14 locales', is_array($label) && count($label) === 14);
check('group_label keeps unicode', ($label['ja_JP'] ?? '') === 'パキスタン ボハラ', (string)($label['ja_JP'] ?? ''));
check('generated_at in UTC', $group['generated_at'] === '2026-09-01 08:12:00', (string)$group['generated_at']);

// text rows: 5 + 3 + 2 locales across the three reviews
$textCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM $tText t JOIN $tReview r ON r.entity_id=t.review_entity_id WHERE r.review_group='" . TEST_GROUP . "'");
check('10 text rows (5+3+2 locales, reply merged into the same rows)', $textCount === 10, "got $textCount");

$photoCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM $tPhoto p JOIN $tReview r ON r.entity_id=p.review_entity_id WHERE r.review_group='" . TEST_GROUP . "'");
check('2 photo rows', $photoCount === 2, "got $photoCount");
check('photos start with null local_path', (int)$conn->fetchOne("SELECT COUNT(*) FROM $tPhoto p JOIN $tReview r ON r.entity_id=p.review_entity_id WHERE r.review_group='" . TEST_GROUP . "' AND p.local_path IS NULL") === 2);

$hasPhotos = $conn->fetchPairs("SELECT review_id, has_photos FROM $tReview WHERE review_group='" . TEST_GROUP . "'");
check('has_photos denormalised correctly', (int)$hasPhotos['zztest18ec8892e02f1d9cc9'] === 1 && (int)$hasPhotos['zztest18ec8892e02f1d9daf'] === 0);

$row = $conn->fetchRow("SELECT * FROM $tReview WHERE review_id='zztest18ec8892e02f1d9cc9'");
check('created_at from payload not now', $row['created_at'] === '2026-08-26 13:33:00', (string)$row['created_at']);
check('purchased_at from bought', $row['purchased_at'] === '2026-01-15 00:00:00', (string)$row['purchased_at']);
check('source_store_code stored raw (ch_de is not a country code)', $row['source_store_code'] === 'dk');
// The spec asks for source to be stored, nothing renders it - so only this proves it survives.
check('source stored', $row['source'] === 'customer', (string)$row['source']);
check('source stored per review, not as a constant',
    $conn->fetchOne("SELECT source FROM $tReview WHERE review_id='zztest18ec8892e02f1d9daf'") === 'staff');
check('reply_author stored', $row['reply_author'] === 'Carpet Avenue Customer Service');
check('thumbnail_path stored, local null', $row['thumbnail_path'] !== null && $row['thumbnail_local'] === null);
check('payload_hash is md5', (bool)preg_match('/^[a-f0-9]{32}$/', (string)$row['payload_hash']));
$firstEntityIds = $conn->fetchPairs("SELECT review_id, entity_id FROM $tReview WHERE review_group='" . TEST_GROUP . "'");

$reply = $conn->fetchOne("SELECT reply_body FROM $tText WHERE review_entity_id={$row['entity_id']} AND locale='da_DK'");
check('reply body stored per locale', is_string($reply) && str_contains($reply, 'Tak for din anmeldelse'));
$noReplyLocale = $conn->fetchOne("SELECT reply_body FROM $tText WHERE review_entity_id={$row['entity_id']} AND locale='nb_NO'");
check('locale with text but no reply has null reply_body', $noReplyLocale === null || $noReplyLocale === false);

echo "\n--- 2. replay identical payload (stale) ---\n";
$r = $service->upsert($v1);
check('status ignored_stale', ($r['status'] ?? '') === 'ignored_stale', json_encode($r));
check('still 3 reviews', $countRows($tReview, "review_group='" . TEST_GROUP . "'") === 3);

echo "\n--- 3. older generated_at ---\n";
$older = $v1;
$older['generated_at'] = '2026-01-01T00:00:00Z';
$older['count'] = 999;
$r = $service->upsert($older);
check('status ignored_stale', ($r['status'] ?? '') === 'ignored_stale');
check('group aggregates untouched', (int)$conn->fetchOne("SELECT review_count FROM $tGroup WHERE review_group='" . TEST_GROUP . "'") === 3);

echo "\n--- 4. newer push: one removed, one updated, one unchanged ---\n";
$r = $service->upsert($v2);
check('status stored', ($r['status'] ?? '') === 'stored', json_encode($r));
check('2 review rows now', $countRows($tReview, "review_group='" . TEST_GROUP . "'") === 2);
check('removed review gone', $countRows($tReview, "review_id='zztest18ec8892e02f1d9e10'") === 0);

$afterIds = $conn->fetchPairs("SELECT review_id, entity_id FROM $tReview WHERE review_group='" . TEST_GROUP . "'");
check(
    'unchanged review kept its entity_id (photos never re-fetched)',
    $afterIds['zztest18ec8892e02f1d9cc9'] === $firstEntityIds['zztest18ec8892e02f1d9cc9']
);
check(
    'updated review kept its entity_id too',
    $afterIds['zztest18ec8892e02f1d9daf'] === $firstEntityIds['zztest18ec8892e02f1d9daf']
);
check('updated review gained a reply author',
    $conn->fetchOne("SELECT reply_author FROM $tReview WHERE review_id='zztest18ec8892e02f1d9daf'") === 'Carpet Avenue Customer Service');
check('group aggregates updated', (int)$conn->fetchOne("SELECT review_count FROM $tGroup WHERE review_group='" . TEST_GROUP . "'") === 2);
check('distribution updated', (int)$conn->fetchOne("SELECT dist_3 FROM $tGroup WHERE review_group='" . TEST_GROUP . "'") === 0);

// cascade check
$orphanText = (int)$conn->fetchOne("SELECT COUNT(*) FROM $tText t LEFT JOIN $tReview r ON r.entity_id=t.review_entity_id WHERE r.entity_id IS NULL");
check('no orphan text rows (FK cascade works)', $orphanText === 0, "got $orphanText");
$orphanPhoto = (int)$conn->fetchOne("SELECT COUNT(*) FROM $tPhoto p LEFT JOIN $tReview r ON r.entity_id=p.review_entity_id WHERE r.entity_id IS NULL");
check('no orphan photo rows', $orphanPhoto === 0, "got $orphanPhoto");
check('unchanged review still has its 2 photos', (int)$conn->fetchOne(
    "SELECT COUNT(*) FROM $tPhoto WHERE review_entity_id={$afterIds['zztest18ec8892e02f1d9cc9']}") === 2);

echo "\n--- 4b. bought-item thumbnail follows its bucket path ---\n";
// thumbnail_path is in the update column list; thumbnail_local has to be too, or a review whose
// thumbnail moved keeps pointing at the old file forever and is never re-queued (non-null
// thumbnail_local is treated as already fetched).
$thumbId = 'zztest18ec8892e02f1d9daf';
$conn->update($tReview, ['thumbnail_local' => 'catalog/product/a/w/old.jpg'], ['review_id = ?' => $thumbId]);

// (a) hash changes but the thumbnail path does NOT -> the downloaded file must survive
$sameThumb = $v2;
$sameThumb['generated_at'] = '2026-09-05T00:00:00Z';
foreach ($sameThumb['reviews'] as $k => $rv) {
    if ($rv['id'] === $thumbId) { $sameThumb['reviews'][$k]['reviewer'] = 'Henrik Larsson'; }
}
$service->upsert($sameThumb);
check('unchanged thumbnail path keeps its downloaded file',
    $conn->fetchOne("SELECT thumbnail_local FROM $tReview WHERE review_id='$thumbId'") === 'catalog/product/a/w/old.jpg');
check('the rest of the row still updated', $conn->fetchOne("SELECT reviewer FROM $tReview WHERE review_id='$thumbId'") === 'Henrik Larsson');

// (b) thumbnail path changes -> local copy dropped so the media queue fetches the new one
$newThumb = $sameThumb;
$newThumb['generated_at'] = '2026-09-06T00:00:00Z';
foreach ($newThumb['reviews'] as $k => $rv) {
    if ($rv['id'] === $thumbId) { $newThumb['reviews'][$k]['bought']['thumbnail_path'] = 'caProdMagento/products/aw9999/images/standard/new.jpg'; }
}
$service->upsert($newThumb);
$after = $conn->fetchRow("SELECT thumbnail_path, thumbnail_local FROM $tReview WHERE review_id='$thumbId'");
check('changed thumbnail path stored', $after['thumbnail_path'] === 'caProdMagento/products/aw9999/images/standard/new.jpg');
check('changed thumbnail path drops the stale local copy', $after['thumbnail_local'] === null,
    var_export($after['thumbnail_local'], true));

echo "\n--- 5. empty reviews array zeroes the group, keeps the row ---\n";
$emptied = $v2;
$emptied['generated_at'] = '2026-09-10T00:00:00Z'; // must stay ahead of section 4b
$emptied['reviews'] = [];
$emptied['count'] = 0;
$emptied['average'] = 0;
$emptied['distribution'] = [0,0,0,0,0];
$r = $service->upsert($emptied);
check('status stored', ($r['status'] ?? '') === 'stored');
check('group row kept', $countRows($tGroup, "review_group='" . TEST_GROUP . "'") === 1);
check('all reviews deleted', $countRows($tReview, "review_group='" . TEST_GROUP . "'") === 0);
check('count zeroed', (int)$conn->fetchOne("SELECT review_count FROM $tGroup WHERE review_group='" . TEST_GROUP . "'") === 0);

echo "\n--- 6. unknown group is created ---\n";
$other = $v1;
$other['review_group'] = TEST_GROUP . '1';
$conn->delete($tGroup, ['review_group = ?' => TEST_GROUP . '1']);
$r = $service->upsert($other);
check('status stored', ($r['status'] ?? '') === 'stored');
check('new group row created', $countRows($tGroup, "review_group='" . TEST_GROUP . "1'") === 1);
check('reviews attached to new group', $countRows($tReview, "review_group='" . TEST_GROUP . "1'") === 3);

echo "\n--- 7. malformed payload writes nothing ---\n";
$before = $countRows($tReview);
$bad = $v1;
$bad['review_group'] = 'ZZTEST2';
$bad['generated_at'] = '2027-01-01T00:00:00Z';
$bad['reviews'][1]['rating'] = 9;
$r = $service->upsert($bad);
check('status invalid', ($r['status'] ?? '') === 'invalid', json_encode($r));
check('field named', ($r['field'] ?? '') === 'reviews[1].rating', (string)($r['field'] ?? ''));
check('no group row created', $countRows($tGroup, "review_group='ZZTEST2'") === 0);
check('no review rows written', $countRows($tReview) === $before);

// --------------------------------------------------------------------------------------------
// Logging destinations.
//
// The ingest endpoint's own request/response lines cannot be reached from here - this harness
// calls the service directly because the controller needs a real HTTP request. What is worth
// pinning is where the writes land and that the token header is on the redaction list, because
// both are one edit away from silently changing: a wrong folder constant still "works", and a
// dropped redaction entry writes the integration token to disk on every push.
echo "\n--- logging ---\n";
$reviewLogger = $om->get(\IXPF\Syncer\Helper\Logger\ReviewLogger::class);
$today        = date('Y-m-d');
$activityLog  = \IXPF\Syncer\Helper\Logger\ReviewLogger::CORE_LOG_FOLDER . "/review-$today.log";
$exceptionLog = \IXPF\Syncer\Helper\Logger\BaseLogger::EXCEPTION_FOLDER . "/review-$today.log";

$marker = 'ZZE2E-' . bin2hex(random_bytes(4));
$reviewLogger->writeDaily("activity probe $marker");
check('activity goes to var/log/ixpf/review/review-<date>.log',
    is_file($activityLog) && str_contains((string)file_get_contents($activityLog), "activity probe $marker"),
    $activityLog);

$failMarker = 'ZZE2E-' . bin2hex(random_bytes(4));
$reviewLogger->writeFailureDaily(new RuntimeException("failure probe $failMarker"), 'e2e probe');
$activity  = is_file($activityLog) ? (string)file_get_contents($activityLog) : '';
$exception = is_file($exceptionLog) ? (string)file_get_contents($exceptionLog) : '';
check('writeFailureDaily summarises in the activity log', str_contains($activity, $failMarker));
check('writeFailureDaily traces in var/log/ixpf/exception/review-<date>.log',
    str_contains($exception, $failMarker), $exceptionLog);
check('failure summary is logged at ERROR, not INFO',
    (bool)preg_match('/ERROR: e2e probe: failure probe ' . preg_quote($failMarker, '/') . '/', $activity));

echo "\n--- with_text_count is optional ---\n";
// IXPF added the field on 2026-09-07. A push predating that must still be accepted, and the
// column has to land at 0 rather than mirroring count - otherwise every old pool would claim
// every rating was a written review.
$noWtc = $v1;
unset($noWtc['with_text_count']);
$noWtc['review_group'] = 'ZZNOWTC';
$noWtc['generated_at'] = '2026-09-01T09:00:00Z';
$resNoWtc = $service->upsert($noWtc);
check('payload without with_text_count is still stored', ($resNoWtc['status'] ?? '') === 'stored',
    json_encode($resNoWtc));
check('absent with_text_count defaults to 0',
    (int)$conn->fetchOne("SELECT with_text_count FROM $tGroup WHERE review_group='ZZNOWTC'") === 0);
check('a stray review title is ignored, not rejected', (function () use ($v1, $service) {
    $stray = $v1;
    $stray['review_group'] = 'ZZSTRAYTITLE';
    $stray['generated_at'] = '2026-09-01T09:05:00Z';
    $stray['reviews'][0]['title'] = ['da_DK' => 'God kvalitet'];
    return ($service->upsert($stray)['status'] ?? '') === 'stored';
})());

$redacted = (new ReflectionClass(\IXPF\Syncer\Controller\V1\Reviews\Upsert::class))
    ->getConstant('REDACTED_HEADERS');
check('the integration token header is redacted before logging',
    is_array($redacted) && in_array('x-auth-token', $redacted, true), json_encode($redacted));

// cleanup
$conn->delete($tGroup, ['review_group IN (?)' => [
    TEST_GROUP, TEST_GROUP . '1', 'ZZTEST2', 'ZZNOWTC', 'ZZSTRAYTITLE',
]]);
echo "\ncleaned up test rows\n";

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
