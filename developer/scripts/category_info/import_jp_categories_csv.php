<?php
/**
 * Populate the JP store-view (store 1) category content from a CSV export.
 *
 * Standalone Magento bootstrap script — NOT a data patch, NOT auto-run. Lives in
 * developer/scripts/category_info/ and is executed by hand only.
 *
 * What it does, per CSV row:
 *   1. Match the row's `name_store0` against a category's STORE-0
 *      (admin/default) `name` in this install. IDs differ between the export and
 *      this DB, so the match is by NAME, not id (`entity_id` is kept only for the
 *      report). Local store-0 names are unique; if that ever stops being true the
 *      ambiguous name is skipped and reported.
 *   2. On a match, write the target store view (default 1 = jp):
 *        - name        <- name_store17         (the translated name)
 *        - description <- description_store17  (only when the CSV has one)
 *        - image       <- image filename       (only when the CSV has one)
 *        - thumbnail   <- thumbnail filename   (only when the CSV has one)
 *        - url_key     <- url_key_store17      (only when the CSV has one)
 *      All five category attributes are store-scoped here (is_global=0), so the
 *      store-1 value overrides the English store-0 value on the JP storefront.
 *   3. For image / thumbnail, the CSV holds a bare filename (e.g. Persian.png).
 *      The file is downloaded from
 *        https://cdn.carpetavenue.net/media/catalog/category/<filename>
 *      into pub/media/catalog/category/<filename>, and the attribute is set to
 *      the bare filename — the value form Magento resolves via catalog/category/.
 *      A file that already exists locally is reused (skip re-download) unless
 *      --force-download is given.
 *   4. url_key is validated and collision-checked before it is staged. A key is
 *      rejected when it is not [a-z0-9][a-z0-9_-]*, or when it would duplicate
 *      another category's effective url_key under the same parent (which would
 *      produce clashing URL rewrites). First claimant wins; later ones are
 *      dropped and reported.
 *
 * Writes go straight to the EAV value tables (insertOnDuplicate on
 * entity_id+attribute_id+store_id), bypassing the model save — same approach as
 * the sibling import_store_values.php.
 *
 * url_key WRITES DO NOT REGENERATE url_path OR url_rewrite. After --apply run:
 *   php developer/scripts/category_info/regenerate_category_urls.php --stores=jp --apply
 *   bin/magento cache:flush
 * Use --no-url-key to keep the old behaviour (content only, cache flush enough).
 *
 * CSV columns (header required, in this order):
 *   entity_id, name_store0, name_store17, description_store17,
 *   thumbnail, image, url_key_store17
 * Note thumbnail comes BEFORE image, as the export writes it.
 * NULL cells are the literal token NULL (unquoted), as produced by the export.
 *
 * Usage (run inside the phpfpm container, from the Magento root):
 *   php developer/scripts/category_info/import_jp_categories_csv.php                 # DRY RUN (default)
 *   php developer/scripts/category_info/import_jp_categories_csv.php --check-urls    # DRY RUN + HEAD each media URL
 *   php developer/scripts/category_info/import_jp_categories_csv.php --apply         # write + download
 *   php developer/scripts/category_info/import_jp_categories_csv.php --apply --store=1
 *   php developer/scripts/category_info/import_jp_categories_csv.php --apply --no-images
 *   php developer/scripts/category_info/import_jp_categories_csv.php --apply --no-url-key
 *   php developer/scripts/category_info/import_jp_categories_csv.php --apply --force-download
 *   php developer/scripts/category_info/import_jp_categories_csv.php --self-test     # pure-helper checks, no DB
 *
 * Options:
 *   --csv=PATH          CSV file (default <script dir>/category_export.csv)
 *   --store=ID          target store view id (default 1)
 *   --apply             actually write + download (default is dry run)
 *   --dry-run           force dry run; always beats --apply
 *   --no-images         skip image/thumbnail entirely
 *   --no-url-key        skip url_key entirely (no rewrite regeneration needed)
 *   --check-urls        in dry run, HEAD every media URL and report its status
 *   --force-download    re-download media even if the file already exists locally
 *   --media-base=URL    CDN prefix (default https://cdn.carpetavenue.net/media/catalog/category/)
 *   --media-dir=PATH    local target dir (default <root>/pub/media/catalog/category)
 *   --self-test         run the pure-helper assertions and exit
 *
 * Report is printed and saved to var/log/jp_category_csv_import_report.txt.
 */

declare(strict_types=1);

use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;

/* ------------------------------------------------------------------ *
 * Pure helpers (covered by --self-test, no DB / no Magento needed)
 * ------------------------------------------------------------------ */

/**
 * Normalise a raw CSV cell. The export writes the unquoted token NULL for empty
 * cells; treat that (and an empty/whitespace string) as null. Everything else is
 * returned trimmed.
 */
function csv_cell(?string $s): ?string
{
    if ($s === null) {
        return null;
    }
    $t = trim($s);
    if ($t === '' || $t === 'NULL') {
        return null;
    }
    return $t;
}

/**
 * The value stored in the category image/thumbnail attribute: the bare filename,
 * whatever leading path the export may carry. Returns null for an empty cell.
 */
function media_filename(?string $cell): ?string
{
    $v = csv_cell($cell);
    if ($v === null) {
        return null;
    }
    $base = basename(str_replace('\\', '/', $v));
    return $base === '' ? null : $base;
}

/**
 * Build the CDN download URL for a media filename. Spaces are the only character
 * the export filenames could realistically carry that breaks a bare URL.
 */
function media_url(string $base, string $filename): string
{
    return rtrim($base, '/') . '/' . str_replace(' ', '%20', $filename);
}

/**
 * A url_key cell is taken as-is (the export is already slugged) — this script
 * never invents a slug from a Japanese name. It only accepts a value Magento
 * would accept in a URL: lowercase alphanumerics, hyphen and underscore,
 * starting on an alphanumeric. Anything else returns null and is reported.
 */
function url_key_value(?string $cell): ?string
{
    $v = csv_cell($cell);
    if ($v === null) {
        return null;
    }
    return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $v) === 1 ? $v : null;
}

/* ------------------------------------------------------------------ *
 * --self-test
 * ------------------------------------------------------------------ */

if (in_array('--self-test', $argv, true)) {
    assert(csv_cell(null) === null);
    assert(csv_cell('') === null);
    assert(csv_cell('   ') === null);
    assert(csv_cell('NULL') === null);
    assert(csv_cell(' All Carpets ') === 'All Carpets');
    assert(csv_cell('すべての絨毯') === 'すべての絨毯');

    assert(media_filename('NULL') === null);
    assert(media_filename('') === null);
    assert(media_filename('Persian.png') === 'Persian.png');
    assert(media_filename('Carpient_-_Nain.png') === 'Carpient_-_Nain.png');
    assert(media_filename('/media/catalog/category/oriental_1.jpg') === 'oriental_1.jpg');
    assert(media_filename('catalog\\category\\x.webp') === 'x.webp');

    assert(media_url('https://cdn.x/media/catalog/category/', 'a.png') === 'https://cdn.x/media/catalog/category/a.png');
    assert(media_url('https://cdn.x/media/catalog/category', 'a.png') === 'https://cdn.x/media/catalog/category/a.png');
    assert(media_url('https://cdn.x/c/', 'a b.png') === 'https://cdn.x/c/a%20b.png');

    assert(url_key_value('NULL') === null);
    assert(url_key_value('') === null);
    assert(url_key_value('orientaru-ka-petto') === 'orientaru-ka-petto');
    assert(url_key_value('nain-4-la-nain-6-la') === 'nain-4-la-nain-6-la');
    assert(url_key_value('modan_2') === 'modan_2');
    assert(url_key_value(' se-ru ') === 'se-ru');
    assert(url_key_value('Se-Ru') === null);           // uppercase
    assert(url_key_value('オリエンタル') === null);        // not a slug
    assert(url_key_value('a b') === null);             // space
    assert(url_key_value('-leading') === null);        // must start alphanumeric
    assert(url_key_value('slash/key') === null);

    fwrite(STDOUT, "self-test OK\n");
    exit(0);
}

/* ------------------------------------------------------------------ *
 * args
 * ------------------------------------------------------------------ */

$flag = static function (string $name) use ($argv): bool {
    foreach ($argv as $a) {
        if ($a === $name) {
            return true;
        }
        if (str_starts_with($a, $name . '=')) {
            $v = strtolower(substr($a, strlen($name) + 1));
            return in_array($v, ['1', 'true', 'yes', 'y', 'on', ''], true);
        }
    }
    return false;
};
$value = static function (string $name, string $default = '') use ($argv): string {
    foreach ($argv as $a) {
        if (str_starts_with($a, $name . '=')) {
            return substr($a, strlen($name) + 1);
        }
    }
    return $default;
};

$dryRun    = $flag('--dry-run');
$apply     = $flag('--apply') && !$dryRun;          // --dry-run wins
$noImages  = $flag('--no-images');
$noUrlKey  = $flag('--no-url-key');
$checkUrls = $flag('--check-urls');
$force     = $flag('--force-download');
$storeId   = (int) $value('--store', '1');
$mediaBase = $value('--media-base', 'https://cdn.carpetavenue.net/media/catalog/category/');

/* ------------------------------------------------------------------ *
 * bootstrap
 * ------------------------------------------------------------------ */

$bootstrapPath = null;
foreach ([
    __DIR__ . '/../../../app/bootstrap.php',
    __DIR__ . '/../../app/bootstrap.php',
    __DIR__ . '/../app/bootstrap.php',
] as $p) {
    if (file_exists($p)) {
        $bootstrapPath = $p;
        break;
    }
}
if (!$bootstrapPath) {
    fwrite(STDERR, "bootstrap.php not found\n");
    exit(1);
}
require $bootstrapPath;

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om        = $bootstrap->getObjectManager();
try {
    $om->get(\Magento\Framework\App\State::class)->setAreaCode(Area::AREA_ADMINHTML);
} catch (\Throwable $e) {
}

$csvPath  = $value('--csv', __DIR__ . '/category_export.csv');
$mediaDir = $value('--media-dir', BP . '/pub/media/catalog/category');

if (!is_readable($csvPath)) {
    fwrite(STDERR, "CSV not found or not readable: {$csvPath}\n");
    exit(1);
}
if ($storeId <= 0) {
    fwrite(STDERR, "--store must be a positive store-view id (0 is the default scope; refusing).\n");
    exit(1);
}

/** @var \Magento\Framework\App\ResourceConnection $resource */
$resource   = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

/* ------------------------------------------------------------------ *
 * validate the target store exists
 * ------------------------------------------------------------------ */

$storeCode = $connection->fetchOne(
    $connection->select()->from($resource->getTableName('store'), 'code')->where('store_id = ?', $storeId)
);
if ($storeCode === false) {
    fwrite(STDERR, "store id {$storeId} does not exist.\n");
    exit(1);
}

/* ------------------------------------------------------------------ *
 * attribute + category lookup maps
 * ------------------------------------------------------------------ */

$catTypeId = (int) $connection->fetchOne(
    $connection->select()
        ->from($resource->getTableName('eav_entity_type'), 'entity_type_id')
        ->where('entity_type_code = ?', 'catalog_category')
);

$wanted = ['name', 'description', 'image', 'thumbnail', 'url_key'];

// attribute_code => [id, backend_type] for the five we write
$attrMap = [];
foreach ($connection->fetchAll(
    $connection->select()
        ->from($resource->getTableName('eav_attribute'), ['attribute_id', 'attribute_code', 'backend_type'])
        ->where('entity_type_id = ?', $catTypeId)
        ->where('attribute_code IN (?)', $wanted)
) as $row) {
    $attrMap[(string) $row['attribute_code']] = [
        'id'           => (int) $row['attribute_id'],
        'backend_type' => (string) $row['backend_type'],
    ];
}
foreach ($wanted as $need) {
    if (!isset($attrMap[$need])) {
        fwrite(STDERR, "category attribute '{$need}' not found in this install.\n");
        exit(1);
    }
}
$nameAttrId   = $attrMap['name']['id'];
$urlKeyAttrId = $attrMap['url_key']['id'];

// entity_id => level / parent_id
$levels  = [];
$parents = [];
foreach ($connection->fetchAll(
    $connection->select()->from($resource->getTableName('catalog_category_entity'), ['entity_id', 'level', 'parent_id'])
) as $row) {
    $levels[(int) $row['entity_id']]  = (int) $row['level'];
    $parents[(int) $row['entity_id']] = (int) $row['parent_id'];
}

// store-0 name (trimmed) => entity_id ; duplicate names are dropped as ambiguous
$nameToId       = [];
$ambiguousNames = [];
foreach ($connection->fetchAll(
    $connection->select()
        ->from($resource->getTableName('catalog_category_entity_varchar'), ['entity_id', 'value'])
        ->where('store_id = 0')
        ->where('attribute_id = ?', $nameAttrId)
) as $row) {
    $key = trim((string) $row['value']);
    if ($key === '') {
        continue;
    }
    if (isset($nameToId[$key])) {
        $ambiguousNames[$key] = true;
        continue;
    }
    $nameToId[$key] = (int) $row['entity_id'];
}
foreach (array_keys($ambiguousNames) as $key) {
    unset($nameToId[$key]);
}

// entity_id => url_key currently EFFECTIVE at the target store: the store-0
// value, overridden by an existing store-<target> value. Used for collision
// detection against siblings this import does not touch.
$effectiveUrlKey = [];
foreach ([0, $storeId] as $scope) {
    foreach ($connection->fetchAll(
        $connection->select()
            ->from($resource->getTableName('catalog_category_entity_varchar'), ['entity_id', 'value'])
            ->where('store_id = ?', $scope)
            ->where('attribute_id = ?', $urlKeyAttrId)
    ) as $row) {
        $v = trim((string) $row['value']);
        if ($v !== '') {
            $effectiveUrlKey[(int) $row['entity_id']] = $v;
        }
    }
}

/* ------------------------------------------------------------------ *
 * media download helper
 * ------------------------------------------------------------------ */

/**
 * Fetch $url into $destDir/$filename. Returns [ok(bool), note(string)].
 * Reuses an existing non-empty file unless $force.
 */
$downloadMedia = static function (string $url, string $destDir, string $filename, bool $force) use ($apply): array {
    $dest = rtrim($destDir, '/') . '/' . $filename;

    if (!$force && is_file($dest) && filesize($dest) > 0) {
        return [true, 'exists'];
    }
    if (!$apply) {
        return [true, 'would download'];
    }

    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        return [false, "cannot create dir {$destDir}"];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FAILONERROR    => true,
    ]);
    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    unset($ch); // curl_close() is a no-op / deprecated since PHP 8.0

    if ($body === false || $body === '' || $http < 200 || $http >= 300) {
        return [false, "download failed (http {$http}" . ($err ? ", {$err}" : '') . ')'];
    }
    if (@file_put_contents($dest, $body) === false) {
        return [false, "cannot write {$dest}"];
    }
    @chmod($dest, 0664);
    return [true, 'downloaded'];
};

/**
 * HEAD-check a media URL (used only by --check-urls in dry run).
 */
$headCheck = static function (string $url): int {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch); // curl_close() is a no-op / deprecated since PHP 8.0
    return $http;
};

/* ------------------------------------------------------------------ *
 * parse the CSV
 * ------------------------------------------------------------------ */

$expected = ['entity_id', 'name_store0', 'name_store17', 'description_store17', 'thumbnail', 'image', 'url_key_store17'];

$fh = fopen($csvPath, 'rb');
if ($fh === false) {
    fwrite(STDERR, "cannot open {$csvPath}\n");
    exit(1);
}
// escape '' so backslashes inside HTML descriptions are never swallowed.
$header = fgetcsv($fh, 0, ',', '"', '');
$header = is_array($header) ? array_map(static fn ($h) => trim((string) $h), $header) : [];
if ($header !== $expected) {
    fwrite(STDERR, "unexpected header:\n  " . implode(', ', $header) . "\nexpected:\n  " . implode(', ', $expected) . "\n");
    fclose($fh);
    exit(1);
}

$rows = [];
while (($cols = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if ($cols === [null] || $cols === []) {
        continue; // blank line
    }
    $rows[] = array_combine($expected, array_pad($cols, count($expected), null));
}
fclose($fh);

/* ------------------------------------------------------------------ *
 * process rows
 * ------------------------------------------------------------------ */

echo str_repeat('=', 78) . "\n";
echo ($apply ? 'APPLY' : 'DRY RUN') . " — target store: {$storeCode} (id {$storeId})\n";
echo "CSV: {$csvPath} — data rows: " . count($rows) . "\n";
echo "media base: {$mediaBase}\n";
echo "media dir:  {$mediaDir}\n";
echo str_repeat('=', 78) . "\n";

$batches      = []; // table => rows for insertOnDuplicate
$writes       = ['name' => 0, 'description' => 0, 'image' => 0, 'thumbnail' => 0, 'url_key' => 0];
$matched      = 0;
$unmatched    = [];   // default name => count
$ambiguousHit = [];   // default name => count (matched an ambiguous local name)
$skippedRoot  = [];   // default name => count (level < 2)
$conflicts    = [];   // "name" => note (same default name twice, differing values)
$mediaOk      = [];   // filename => note
$mediaFail    = [];   // filename => note
$urlStatus    = [];   // filename => http (only with --check-urls)
$badUrlKeys   = [];   // "name" => the rejected raw cell
$keyClashes   = [];   // "name" => note (url_key collides under the same parent)
$keyUnchanged = 0;    // url_key already effective at the target store
$proposedKeys = [];   // entity_id => [key, default name] — staged after collision check
$seen         = [];   // default name => signature, first wins

$stage = static function (string $backendType, int $attrId, int $entityId, int $storeId, ?string $val) use (&$batches): void {
    $table                = 'catalog_category_entity_' . $backendType;
    $batches[$table][]    = [
        'attribute_id' => $attrId,
        'store_id'     => $storeId,
        'entity_id'    => $entityId,
        'value'        => $val,
    ];
};

foreach ($rows as $row) {
    $defName = csv_cell($row['name_store0']);
    if ($defName === null) {
        continue;
    }

    if (isset($ambiguousNames[$defName])) {
        $ambiguousHit[$defName] = ($ambiguousHit[$defName] ?? 0) + 1;
        continue;
    }
    if (!isset($nameToId[$defName])) {
        $unmatched[$defName] = ($unmatched[$defName] ?? 0) + 1;
        continue;
    }

    $entityId = $nameToId[$defName];
    if (($levels[$entityId] ?? 0) < 2) {
        $skippedRoot[$defName] = ($skippedRoot[$defName] ?? 0) + 1;
        continue; // Root Catalog / Default Category — not translated
    }

    $jpName = csv_cell($row['name_store17']);
    $desc   = csv_cell($row['description_store17']);
    $imgF   = $noImages ? null : media_filename($row['image']);
    $thumbF = $noImages ? null : media_filename($row['thumbnail']);
    $rawKey = $noUrlKey ? null : csv_cell($row['url_key_store17']);
    $urlKey = $noUrlKey ? null : url_key_value($row['url_key_store17']);

    // Two CSV rows can share a default name (prod-side duplicate) and collapse
    // onto the one local category. First row wins; a differing later row is a
    // reported conflict.
    $sig = md5(($jpName ?? '') . '|' . ($desc ?? '') . '|' . ($imgF ?? '') . '|' . ($thumbF ?? '') . '|' . ($urlKey ?? ''));
    if (isset($seen[$defName])) {
        if ($seen[$defName] !== $sig) {
            $conflicts[$defName] = 'differing 2nd row skipped';
        }
        continue;
    }
    $seen[$defName] = $sig;
    $matched++;

    if ($jpName !== null) {
        $stage($attrMap['name']['backend_type'], $attrMap['name']['id'], $entityId, $storeId, $jpName);
        $writes['name']++;
    }
    if ($desc !== null) {
        $stage($attrMap['description']['backend_type'], $attrMap['description']['id'], $entityId, $storeId, $desc);
        $writes['description']++;
    }

    // url_key is not staged here — it goes through the sibling-collision pass
    // below, so a key that would clash never reaches the DB.
    if ($rawKey !== null && $urlKey === null) {
        $badUrlKeys[$defName] = $rawKey;
    } elseif ($urlKey !== null) {
        $proposedKeys[$entityId] = [$urlKey, $defName];
    }

    foreach (['image' => $imgF, 'thumbnail' => $thumbF] as $code => $file) {
        if ($file === null) {
            continue;
        }
        $url = media_url($mediaBase, $file);

        if ($checkUrls && !$apply) {
            $urlStatus[$file] = $headCheck($url);
        }

        [$ok, $note] = $downloadMedia($url, $mediaDir, $file, $force);
        if (!$ok) {
            $mediaFail[$file] = $note;
            continue; // do not point the attribute at a file that is not there
        }
        $mediaOk[$file] = $note;
        $stage($attrMap[$code]['backend_type'], $attrMap[$code]['id'], $entityId, $storeId, $file);
        $writes[$code]++;
    }
}

/* ------------------------------------------------------------------ *
 * url_key collision pass
 *
 * Magento builds a category URL from the keys along its path, so two siblings
 * holding the same url_key produce clashing rewrites and the regeneration step
 * fails (or silently suffixes). Check every proposed key against what its
 * siblings will effectively have after this import; first claimant under a
 * parent wins, later ones are dropped and reported.
 * ------------------------------------------------------------------ */

$claimed = []; // parent_id => [url_key => entity_id]

// Seed with the categories this import does NOT give a new key to.
foreach ($effectiveUrlKey as $eid => $key) {
    if (isset($proposedKeys[$eid])) {
        continue;
    }
    $claimed[$parents[$eid] ?? 0][$key] = $eid;
}

ksort($proposedKeys); // deterministic: lowest entity_id claims first
foreach ($proposedKeys as $eid => [$key, $defName]) {
    $parentId = $parents[$eid] ?? 0;
    $holder   = $claimed[$parentId][$key] ?? null;

    if ($holder !== null && $holder !== $eid) {
        $keyClashes[$defName] = "'{$key}' already taken under parent {$parentId} by category {$holder} — url_key skipped";
        continue;
    }

    $claimed[$parentId][$key] = $eid;

    if (($effectiveUrlKey[$eid] ?? null) === $key) {
        $keyUnchanged++;
        continue; // already effective at this store — nothing to write
    }

    $stage($attrMap['url_key']['backend_type'], $attrMap['url_key']['id'], $eid, $storeId, $key);
    $writes['url_key']++;
}

/* ------------------------------------------------------------------ *
 * flush EAV batches
 * ------------------------------------------------------------------ */

$rowsWritten = 0;
foreach ($batches as $table => $data) {
    $rowsWritten += count($data);
    if ($apply && $data) {
        foreach (array_chunk($data, 500) as $chunk) {
            $connection->insertOnDuplicate($resource->getTableName($table), $chunk, ['value']);
        }
    }
}

/* ------------------------------------------------------------------ *
 * report
 * ------------------------------------------------------------------ */

$verb = $apply ? 'Wrote' : 'Would write';
$out  = [];
$out[] = '=== JP category CSV import ===';
$out[] = 'mode:            ' . ($apply ? 'APPLY' : 'DRY RUN (no writes, no downloads)');
$out[] = 'target store:    ' . $storeCode . ' (id ' . $storeId . ')';
$out[] = 'csv:             ' . $csvPath;
$out[] = 'data rows:       ' . count($rows);
$out[] = 'matched cats:    ' . $matched;
$out[] = '';
$out[] = $verb . ' attribute values:';
$out[] = sprintf('  name         %d', $writes['name']);
$out[] = sprintf('  description  %d', $writes['description']);
$out[] = sprintf('  image        %d', $writes['image']);
$out[] = sprintf('  thumbnail    %d', $writes['thumbnail']);
$out[] = sprintf('  url_key      %d%s', $writes['url_key'], $noUrlKey ? ' (--no-url-key)' : '');
$out[] = sprintf('  total rows   %d', $rowsWritten);
$out[] = '';
$out[] = sprintf('url_key already correct: %d', $keyUnchanged);
$out[] = sprintf('media %s: %d', $apply ? 'downloaded/reused' : 'to fetch', count($mediaOk));
$out[] = sprintf('media failed:     %d', count($mediaFail));

$section = static function (string $title, array $entries) use (&$out): void {
    $out[] = '';
    $out[] = '--- ' . $title . ' (' . count($entries) . ') ---';
    if (!$entries) {
        $out[] = '  (none)';
        return;
    }
    foreach ($entries as $k => $v) {
        $out[] = '  ' . $k . (is_int($v) ? " x{$v}" : (is_string($v) && $v !== '' ? " — {$v}" : ''));
    }
};

$section('default names with no local match — SKIPPED', $unmatched);
$section('default names matching an ambiguous local name — SKIPPED', $ambiguousHit);
$section('matched Root/Default (level<2) — SKIPPED', $skippedRoot);
$section('CONFLICT: same default name twice, values differ — first won', $conflicts);
$section('url_key rejected (not a valid slug) — attribute left unset', $badUrlKeys);
$section('url_key COLLISION under the same parent — attribute left unset', $keyClashes);
$section('media DOWNLOAD/REUSE FAILED — attribute left unset', $mediaFail);
if ($checkUrls && !$apply) {
    $section('media URL HEAD status (--check-urls)', $urlStatus);
}

$report = implode("\n", $out) . "\n";
fwrite(STDOUT, $report);

$reportPath = BP . '/var/log/jp_category_csv_import_report.txt';
@file_put_contents($reportPath, $report);
fwrite(STDOUT, "\nreport: {$reportPath}\n");

if (!$apply) {
    fwrite(STDOUT, "\nDRY RUN. Re-run with --apply to write values and download media.\n");
    exit(0);
}

fwrite(STDOUT, "\nDone. Raw EAV writes bypass caches — now run:\n");
if ($writes['url_key'] > 0) {
    fwrite(STDOUT, "  php developer/scripts/category_info/regenerate_category_urls.php --stores={$storeCode} --apply\n");
}
fwrite(STDOUT, "  bin/magento cache:flush\n");
