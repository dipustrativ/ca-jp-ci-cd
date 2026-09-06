<?php
/**
 * Populate the JP store-view (store 1) category content from a CSV export.
 *
 * Standalone Magento bootstrap script — NOT a data patch, NOT auto-run. Lives in
 * developer/scripts/category_info/ and is executed by hand only.
 *
 * What it does, per CSV row:
 *   1. Match the row's `category_name_default` against a category's STORE-0
 *      (admin/default) `name` in this install. IDs differ between the export and
 *      this DB, so the match is by NAME, not id.  Local store-0 names are unique;
 *      if that ever stops being true the ambiguous name is skipped and reported.
 *   2. On a match, write the target store view (default 1 = jp):
 *        - name        <- category_name        (the translated name)
 *        - description <- description           (only when the CSV has one)
 *        - image       <- image  filename      (only when the CSV has one)
 *        - thumbnail   <- thumbnail filename    (only when the CSV has one)
 *      All four category attributes are store-scoped here (is_global=0), so the
 *      store-1 value overrides the English store-0 value on the JP storefront.
 *   3. For image / thumbnail, the CSV holds a bare filename (e.g. Persian.png).
 *      The file is downloaded from
 *        https://cdn.carpetavenue.net/media/catalog/category/<filename>
 *      into pub/media/catalog/category/<filename>, and the attribute is set to
 *      the bare filename — the value form Magento resolves via catalog/category/.
 *      A file that already exists locally is reused (skip re-download) unless
 *      --force-download is given.
 *
 * Writes go straight to the EAV value tables (insertOnDuplicate on
 * entity_id+attribute_id+store_id), bypassing the model save — same approach as
 * the sibling import_store_values.php. No url_key is touched, so no URL rewrite
 * rebuild is needed; a cache flush is enough (printed at the end).
 *
 * CSV columns (header required, in this order):
 *   category_id, category_path, category_name_default, category_name,
 *   description, image, thumbnail
 * NULL cells are the literal token NULL (unquoted), as produced by the export.
 *
 * Usage (run inside the phpfpm container, from the Magento root):
 *   php developer/scripts/category_info/import_jp_categories_csv.php                 # DRY RUN (default)
 *   php developer/scripts/category_info/import_jp_categories_csv.php --check-urls    # DRY RUN + HEAD each media URL
 *   php developer/scripts/category_info/import_jp_categories_csv.php --apply         # write + download
 *   php developer/scripts/category_info/import_jp_categories_csv.php --apply --store=1
 *   php developer/scripts/category_info/import_jp_categories_csv.php --apply --no-images
 *   php developer/scripts/category_info/import_jp_categories_csv.php --apply --force-download
 *   php developer/scripts/category_info/import_jp_categories_csv.php --self-test     # pure-helper checks, no DB
 *
 * Options:
 *   --csv=PATH          CSV file (default <root>/catalog_category_entity.csv)
 *   --store=ID          target store view id (default 1)
 *   --apply             actually write + download (default is dry run)
 *   --dry-run           force dry run; always beats --apply
 *   --no-images         skip image/thumbnail entirely (only name + description)
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

$csvPath  = $value('--csv', BP . '/catalog_category_entity.csv');
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

// attribute_code => [id, backend_type] for the four we write
$attrMap = [];
foreach ($connection->fetchAll(
    $connection->select()
        ->from($resource->getTableName('eav_attribute'), ['attribute_id', 'attribute_code', 'backend_type'])
        ->where('entity_type_id = ?', $catTypeId)
        ->where('attribute_code IN (?)', ['name', 'description', 'image', 'thumbnail'])
) as $row) {
    $attrMap[(string) $row['attribute_code']] = [
        'id'           => (int) $row['attribute_id'],
        'backend_type' => (string) $row['backend_type'],
    ];
}
foreach (['name', 'description', 'image', 'thumbnail'] as $need) {
    if (!isset($attrMap[$need])) {
        fwrite(STDERR, "category attribute '{$need}' not found in this install.\n");
        exit(1);
    }
}
$nameAttrId = $attrMap['name']['id'];

// entity_id => level
$levels = [];
foreach ($connection->fetchAll(
    $connection->select()->from($resource->getTableName('catalog_category_entity'), ['entity_id', 'level'])
) as $row) {
    $levels[(int) $row['entity_id']] = (int) $row['level'];
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

$expected = ['category_id', 'category_path', 'category_name_default', 'category_name', 'description', 'image', 'thumbnail'];

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
$writes       = ['name' => 0, 'description' => 0, 'image' => 0, 'thumbnail' => 0];
$matched      = 0;
$unmatched    = [];   // default name => count
$ambiguousHit = [];   // default name => count (matched an ambiguous local name)
$skippedRoot  = [];   // default name => count (level < 2)
$conflicts    = [];   // "name" => note (same default name twice, differing values)
$mediaOk      = [];   // filename => note
$mediaFail    = [];   // filename => note
$urlStatus    = [];   // filename => http (only with --check-urls)
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
    $defName = csv_cell($row['category_name_default']);
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

    $jpName = csv_cell($row['category_name']);
    $desc   = csv_cell($row['description']);
    $imgF   = $noImages ? null : media_filename($row['image']);
    $thumbF = $noImages ? null : media_filename($row['thumbnail']);

    // Two CSV rows can share a default name (prod-side duplicate) and collapse
    // onto the one local category. First row wins; a differing later row is a
    // reported conflict.
    $sig = md5(($jpName ?? '') . '|' . ($desc ?? '') . '|' . ($imgF ?? '') . '|' . ($thumbF ?? ''));
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
$out[] = sprintf('  total rows   %d', $rowsWritten);
$out[] = '';
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

fwrite(STDOUT, "\nDone. Raw EAV writes bypass caches — now run:\n"
    . "  bin/magento cache:flush\n");
