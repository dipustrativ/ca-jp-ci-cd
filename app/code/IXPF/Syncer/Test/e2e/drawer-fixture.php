<?php
/**
 * Fixture for drawer.cjs: points one product at a throwaway pool, and puts it back afterwards.
 *
 *   php drawer-fixture.php up [product_id]   prints the JSON drawer.cjs asserts against
 *   php drawer-fixture.php down              restores the product, removes the pool and files
 *
 * The pool covers every case the drawer renders: a bought line per locale with one locale left
 * out (en_US fallback), a map with only one locale (first-sent fallback), no bought line, no
 * `bought` at all, a legacy reply.author that must never show, a star-only review with a reply,
 * portrait / landscape / dimensionless photos, and a text-only review for the photo filter.
 * The "Text only" filter is switched on for every other store view, off for the rest.
 */
declare(strict_types=1);

require '/var/www/html/app/bootstrap.php';

use IXPF\Syncer\Helper\Config;
use IXPF\Syncer\Services\Reviews\ReviewCacheCleaner;
use IXPF\Syncer\Services\Reviews\ReviewMediaStorage;
use IXPF\Syncer\Services\Reviews\UpsertReviewGroup;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

const GROUP      = 'ZZDRAWER';
const STATE_FILE = BP . '/var/ixpf_review_drawer_e2e.json';
const ATTRIBUTES = ['review_group' => 'ZZDRAWER', 'review_count' => 4, 'review_average' => 3.5];
const TEXT_FILTER = 'ixpf_reviews/general/show_text_filter';

$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$conn    = $om->get(ResourceConnection::class)->getConnection();
$table   = static fn (string $name): string => $om->get(ResourceConnection::class)->getTableName($name);
$storage = $om->get(ReviewMediaStorage::class);

$command = $argv[1] ?? '';
if ($command === 'down') {
    down();
    exit(0);
}
if ($command !== 'up') {
    fwrite(STDERR, "usage: php drawer-fixture.php up [product_id] | down\n");
    exit(2);
}

// A previous run that died mid-way left its state behind; restore it before starting over.
if (is_file(STATE_FILE)) {
    down();
}

$productId = (int)($argv[2] ?? 0) ?: pickProduct();
$stores    = stores($productId);

// ---------------------------------------------------------------- payload

$bought = [
    'en_US' => 'Bought: White / Beige, 300 × 384 cm',
    'de_DE' => 'Gekauft: Weiß / Beige, 300 × 384 cm',
    'fr_FR' => 'Acheté : Blanc / Beige, 300 × 384 cm',
    'sv_SE' => 'Köpt: Vit / Beige, 300 × 384 cm',
    'pt_PT' => 'Comprado: Branco / Bege, 300 × 384 cm',
    'es_ES' => 'Comprado: Blanco / Beige, 300 × 384 cm',
    'pl_PL' => 'Kupiono: Biały / Beżowy, 300 × 384 cm',
    'nl_NL' => 'Gekocht: Wit / Beige, 300 × 384 cm',
    'it_IT' => 'Acquistato: Bianco / Beige, 300 × 384 cm',
    'fi_FI' => 'Ostettu: Valkoinen / Beige, 300 × 384 cm',
    'da_DK' => 'Købt: Hvid / Beige, 300 × 384 cm',
    'ja_JP' => '購入済み：ホワイト / ベージュ、300 × 384 cm',
    'nb_NO' => 'Kjøpt: Hvit / Beige, 300 × 384 cm',
    // hu_HU left out on purpose: that store must fall back to en_US.
];

// The reviews' source locales are always present: the upsert requires source_locale as a key in
// body, and a single-market install (JP: en_US + ja_JP) would otherwise leave sv_SE / da_DK out.
$locales = array_values(array_unique(array_merge(['en_US', 'sv_SE', 'da_DK', 'de_DE'], array_column($stores, 'locale'))));
$text    = static fn (string $what): array => array_combine(
    $locales,
    array_map(static fn (string $l): string => "[$l] $what", $locales)
);

$reviews = [
    [
        'id' => 'zztestdrawer000000000001', 'sku' => 'ZZ-R1', 'rating' => 5, 'reviewer' => 'Astrid L.',
        'sub_ratings' => ['quality' => 5, 'delivery' => 4, 'customer_service' => 5],
        'created_at' => '2026-09-20T10:00:00Z', 'source' => 'customer',
        'source_locale' => 'sv_SE', 'source_store_code' => 'se',
        'bought' => ['name' => 'Test rug', 'variant_texts' => $bought, 'purchased_at' => '2026-09-01T00:00:00Z',
                     'thumbnail_path' => 'zz/drawer/r1-thumb.jpg'],
        'body' => $text('Beautiful rug, exactly as pictured. Soft, thick and the colours are true.'),
        'photos' => [
            ['bucket_path' => 'zz/drawer/r1-portrait.jpg', 'width' => 1200, 'height' => 1600],
            ['bucket_path' => 'zz/drawer/r1-landscape.jpg', 'width' => 2000, 'height' => 1333],
        ],
        // The legacy author must never reach the page.
        'reply' => ['body' => $text('Thank you for your review!'), 'author' => 'Staff Name Must Not Render',
                    'created_at' => '2026-09-21T08:00:00Z'],
    ],
    [
        'id' => 'zztestdrawer000000000002', 'sku' => 'ZZ-R2', 'rating' => 4, 'reviewer' => 'Mads K.',
        'created_at' => '2026-09-19T10:00:00Z', 'source_locale' => 'da_DK', 'source_store_code' => 'dk',
        // One locale only: every other store shows it (first-sent fallback).
        'bought' => ['name' => 'Test rug', 'variant_texts' => ['da_DK' => 'Købt: Grå, 160 × 230 cm']],
        'body' => $text('Good quality for the price.'),
        // No dimensions: nothing reserves the aspect ratio, the file decides.
        'photos' => [['bucket_path' => 'zz/drawer/r2-square.jpg']],
        'reply' => null,
    ],
    [
        'id' => 'zztestdrawer000000000003', 'sku' => 'ZZ-R3', 'rating' => 3, 'reviewer' => 'Jonas W.',
        'created_at' => '2026-09-18T10:00:00Z', 'source_locale' => 'de_DE', 'source_store_code' => 'de',
        'bought' => ['name' => 'Test rug'],
        // Star-only with a photo and a reply: the reply resolves through the source locale.
        'body' => ['de_DE' => ''],
        'photos' => [['bucket_path' => 'zz/drawer/r3-portrait.jpg', 'width' => 900, 'height' => 1200]],
        'reply' => ['body' => ['de_DE' => '[de_DE] Danke für die Bewertung!'], 'created_at' => '2026-09-19T08:00:00Z'],
    ],
    [
        'id' => 'zztestdrawer000000000004', 'sku' => 'ZZ-R4', 'rating' => 2, 'reviewer' => 'Paula M.',
        'created_at' => '2026-09-17T10:00:00Z', 'source_locale' => 'en_US', 'source_store_code' => 'en',
        'bought' => null,
        'body' => $text('Text only, no photo: the photo filter must drop this one.'),
        'reply' => null,
    ],
];

$payload = [
    'review_group' => GROUP, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    'count' => 4, 'with_text_count' => 3, 'average' => 3.5, 'distribution' => [0, 1, 1, 1, 1],
    'group_label' => ['en_US' => 'Drawer e2e'], 'reviews' => $reviews,
];

// ---------------------------------------------------------------- apply

$state = ['product_id' => $productId, 'attributes' => [], 'review_ids' => array_column($reviews, 'id')];

// "Text only" on for every other store, so one run covers both states in every layout.
$state['config'] = $conn->fetchAll($conn->select()->from($table('core_config_data'))->where('path = ?', TEXT_FILTER));
$conn->delete($table('core_config_data'), ['path = ?' => TEXT_FILTER]);
foreach ($stores as $i => $store) {
    $stores[$i]['textFilter'] = $i % 2 === 0;
    $conn->insert($table('core_config_data'), [
        'scope' => 'stores', 'scope_id' => $store['id'], 'path' => TEXT_FILTER, 'value' => (int)$stores[$i]['textFilter'],
    ]);
}

foreach (ATTRIBUTES as $code => $value) {
    [$attributeId, $valueTable] = attribute($code);
    $where = ['entity_id = ?' => $productId, 'attribute_id = ?' => $attributeId];
    $state['attributes'][$code] = $conn->fetchAll(
        $conn->select()->from($valueTable)->where('entity_id = ?', $productId)->where('attribute_id = ?', $attributeId)
    );
    $conn->delete($valueTable, $where);
    $conn->insert($valueTable, ['entity_id' => $productId, 'attribute_id' => $attributeId, 'store_id' => 0, 'value' => $value]);
}
file_put_contents(STATE_FILE, json_encode($state));

$result = $om->get(UpsertReviewGroup::class)->upsert($payload);
if (($result['status'] ?? '') !== 'stored') {
    down();
    fwrite(STDERR, 'upsert failed: ' . json_encode($result) . "\n");
    exit(1);
}

// Stand-in files: no bucket access locally, so the downloads the push queued never land.
$photos = [];
foreach ($reviews as $review) {
    $dir = ReviewMediaStorage::ROOT . '/' . $review['id'];
    foreach ($review['photos'] ?? [] as $photo) {
        [$w, $h] = [$photo['width'] ?? 1000, $photo['height'] ?? 1000];
        $local = $dir . '/' . basename($photo['bucket_path']);
        image($storage->derivativePath($local, ReviewMediaStorage::SIZE_GRID), $w, $h, 800);
        $conn->update($table('ixpf_review_photo'), ['local_path' => $local], ['bucket_path = ?' => $photo['bucket_path']]);
        $photos[] = $storage->derivativePath($local, ReviewMediaStorage::SIZE_GRID);
    }
    if (isset($review['bought']['thumbnail_path'])) {
        $local = $dir . '/' . basename($review['bought']['thumbnail_path']);
        image($storage->derivativePath($local, ReviewMediaStorage::SIZE_THUMB), 80, 80, 80);
        $conn->update($table('ixpf_review'), ['thumbnail_local' => $local], ['review_id = ?' => $review['id']]);
    }
}

cleanCaches($productId);

echo json_encode([
    'productId' => $productId,
    'perPage'   => $om->get(Config::class)->getReviewsPerPage(),
    'stores'    => $stores,
    'photos'    => $photos,
    'reviews'   => array_map(static fn (array $r): array => [
        'id'         => $r['id'],
        'reviewer'   => $r['reviewer'],
        'bought'     => $r['bought']['variant_texts'] ?? null,
        'photos'     => array_map(static fn (array $p): ?array => isset($p['width']) ? [$p['width'], $p['height']] : null, $r['photos'] ?? []),
        'reply'      => $r['reply'] !== null,
        'hasBody'    => array_filter($r['body']) !== [],
    ], $reviews),
    'staffName' => $reviews[0]['reply']['author'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

// ---------------------------------------------------------------- helpers

function down(): void
{
    global $om, $conn, $table, $storage;

    $state = is_file(STATE_FILE) ? json_decode((string)file_get_contents(STATE_FILE), true) : null;
    $conn->delete($table('ixpf_review_group'), ['review_group = ?' => GROUP]);

    if (is_array($state)) {
        $conn->delete($table('core_config_data'), ['path = ?' => TEXT_FILTER]);
        foreach ($state['config'] ?? [] as $row) {
            unset($row['config_id']);
            $conn->insert($table('core_config_data'), $row);
        }
        foreach ($state['attributes'] as $code => $rows) {
            [$attributeId, $valueTable] = attribute($code);
            $conn->delete($valueTable, ['entity_id = ?' => $state['product_id'], 'attribute_id = ?' => $attributeId]);
            foreach ($rows as $row) {
                unset($row['value_id']);
                $conn->insert($valueTable, $row);
            }
        }
        foreach ($state['review_ids'] as $id) {
            $storage->getMediaDirectory()->delete(ReviewMediaStorage::ROOT . '/' . $id);
        }
        cleanCaches((int)$state['product_id']);
        unlink(STATE_FILE);
    }
}

/**
 * First enabled product in every website that already shows the trigger.
 */
function pickProduct(): int
{
    global $conn, $table;

    [$countId, $countTable] = attribute('review_count');
    $websites = (int)$conn->fetchOne('SELECT COUNT(*) FROM ' . $table('store_website') . ' WHERE website_id > 0');

    return (int)$conn->fetchOne(
        'SELECT w.product_id FROM ' . $table('catalog_product_website') . " w
           JOIN $countTable c ON c.entity_id = w.product_id AND c.attribute_id = $countId AND c.store_id = 0 AND c.value > 0
          GROUP BY w.product_id HAVING COUNT(*) = $websites ORDER BY w.product_id LIMIT 1"
    );
}

/**
 * One entry per reachable storefront: stores sharing a base URL (ot on the default domain) are
 * the same page, and a store whose website lacks the product has no page at all.
 *
 * @return array<int, array{code: string, locale: string, base: string, replyName: string}>
 */
function stores(int $productId): array
{
    global $om, $conn, $table;

    $websites = array_map('intval', $conn->fetchCol(
        'SELECT website_id FROM ' . $table('catalog_product_website') . ' WHERE product_id = ?',
        [$productId]
    ));

    $stores = [];
    foreach ($om->get(StoreManagerInterface::class)->getStores() as $store) {
        $base = $store->getBaseUrl(UrlInterface::URL_TYPE_LINK);
        if (!$store->isActive() || !in_array((int)$store->getWebsiteId(), $websites, true) || isset($stores[$base])) {
            continue;
        }
        $stores[$base] = [
            'id'        => (int)$store->getId(),
            'code'      => $store->getCode(),
            'locale'    => (string)$om->get(ScopeConfigInterface::class)
                ->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $store->getId()),
            'base'      => $base,
            'replyName' => $om->get(Config::class)->getReviewReplyName((int)$store->getId()),
        ];
    }

    return array_values($stores);
}

/**
 * @return array{0: int, 1: string} attribute_id, value table
 */
function attribute(string $code): array
{
    global $conn, $table;

    $row = $conn->fetchRow(
        'SELECT attribute_id, backend_type FROM ' . $table('eav_attribute') . ' WHERE entity_type_id = 4 AND attribute_code = ?',
        [$code]
    );

    return [(int)$row['attribute_id'], $table('catalog_product_entity_' . $row['backend_type'])];
}

/**
 * A JPEG at the source's aspect ratio, longest edge $edge, so previews measure like real photos.
 */
function image(string $relativePath, int $w, int $h, int $edge): void
{
    global $storage;

    $scale = $edge / max($w, $h);
    [$w, $h] = [(int)round($w * $scale), (int)round($h * $scale)];
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 150, 40, 50));
    imagerectangle($im, 4, 4, $w - 5, $h - 5, imagecolorallocate($im, 235, 225, 205));
    imagestring($im, 3, 10, 10, basename($relativePath) . " {$w}x{$h}", imagecolorallocate($im, 255, 255, 255));

    $absolute = $storage->getMediaDirectory()->getAbsolutePath($relativePath);
    @mkdir(dirname($absolute), 0775, true);
    imagejpeg($im, $absolute, 80);
}

function cleanCaches(int $productId): void
{
    global $om;

    $om->get(ReviewCacheCleaner::class)->cleanGroup(GROUP);
    $om->get(CacheInterface::class)->clean(['cat_p_' . $productId, 'FPC']);
    foreach (['config', 'full_page', 'block_html'] as $type) {
        $om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType($type);
    }
}
