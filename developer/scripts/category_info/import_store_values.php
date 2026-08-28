<?php
/**
 * Import per-store-view CATEGORY attribute values exported from the live
 * carpetavenue DB into carpient.
 *
 * The two installs share store CODES but not store IDs (fr is 3 on prod, 4 here;
 * se is 4 there, 11 here), and share category NAMES but not category IDs. So the
 * export is keyed on store_code + the store-0 category name path, and this script
 * resolves both against the local DB.
 *
 * Input: TSV produced by category_info/export_store_values.sql, run on prod with
 *   mysql --batch ... > category_store_values.tsv
 * Columns: name_path, store_code, attribute_code, backend_type, value
 *
 * Usage (from the Magento root, inside the phpfpm container):
 *   php developer/scripts/category_info/import_store_values.php --file=var/import/category_store_values.tsv
 *   php developer/scripts/category_info/import_store_values.php --file=... --apply
 *   php developer/scripts/category_info/import_store_values.php --file=... --stores=de,fr --attributes=name
 *   php developer/scripts/category_info/import_store_values.php --file=... --all --apply
 *   php developer/scripts/category_info/import_store_values.php --self-test
 *
 * By default only translatable content is written (see CONTENT_ATTRIBUTES): name,
 * url_key, description, short_description and the three meta fields. The export
 * carries every store-scoped category attribute, but layout switches, media
 * filenames, generated url_path and behaviour flags are not translations --
 * --all opts into those. Reference attributes (REFERENCE_ATTRIBUTES) are never
 * written at all, since their ids point at different rows in the two installs.
 *
 * Dry-run is the default; --dry-run always beats --apply.
 * Report is written to var/log/store_value_import_report.txt either way.
 *
 * Full step-by-step: developer/scripts/category_info/README.md
 *
 * Silence vendor deprecation noise:
 *   php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_NOTICE" developer/scripts/category_info/import_store_values.php ... 2>/dev/null
 */

declare(strict_types=1);

use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;

/* ------------------------------------------------------------------ *
 * Pure helpers (covered by --self-test, no DB needed)
 * ------------------------------------------------------------------ */

/**
 * Undo the escaping `mysql --batch` applies: \t \n \r \\ and \N for NULL.
 * Single pass so a literal backslash before an 'n' is not mistaken for a newline.
 */
function tsv_unescape(string $s): ?string
{
    if ($s === '\\N') {
        return null;
    }
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        if ($s[$i] !== '\\' || $i + 1 >= $len) {
            $out .= $s[$i];
            continue;
        }
        $next = $s[++$i];
        $out .= match ($next) {
            'n'     => "\n",
            't'     => "\t",
            'r'     => "\r",
            '0'     => "\0",
            '\\'    => '\\',
            default => $next,
        };
    }
    return $out;
}

/**
 * Translatable content. Everything else a category carries at store scope is
 * either a reference into another table (block/page/theme/option ids, which mean
 * different things in the two installs), a media filename whose file may not
 * exist here, a value the indexer regenerates (url_path), or a behaviour switch
 * (is_active, display_mode, sort order). None of that is translation, so none of
 * it is imported unless asked for.
 */
const CONTENT_ATTRIBUTES = [
    'name',
    'url_key',
    'description',
    'short_description',
    'meta_title',
    'meta_description',
    'meta_keywords',
];

/**
 * Attributes whose value is an ID or identifier pointing into another table.
 * Never written, not even under --all: copying them aims the category at the
 * wrong CMS block, page, theme or attribute option.
 */
const REFERENCE_ATTRIBUTES = [
    'landing_page',
    'custom_design',
    'custom_block',
    'custom_bottom_block',
    'additional_megamenu_data',
];

/**
 * Decide whether an attribute should be written.
 *
 * @param string        $code     attribute code from the export
 * @param array<string> $explicit --attributes=... list, empty when not given
 * @param bool          $all      --all was passed
 * @return string  '' to write it, otherwise the reason it was skipped
 */
function attr_skip_reason(string $code, array $explicit, bool $all): string
{
    if (in_array($code, REFERENCE_ATTRIBUTES, true)) {
        return 'reference';
    }
    if ($explicit !== []) {
        return in_array($code, $explicit, true) ? '' : 'not requested';
    }
    if ($all) {
        return '';
    }
    return in_array($code, CONTENT_ATTRIBUTES, true) ? '' : 'not content';
}

/**
 * Pull the data rows out of a phpMyAdmin JSON export.
 *
 * phpMyAdmin wraps results in an envelope:
 *   [{"type":"header",...},{"type":"database",...},{"type":"table","data":[ {...}, ... ]}]
 * A plain array of row objects (hand-made, or another tool) is accepted too.
 *
 * @return array<int,array<string,string|null>>
 */
function json_rows(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return [];
    }
    $rows = [];
    foreach ($decoded as $item) {
        if (!is_array($item)) {
            continue;
        }
        if (isset($item['data']) && is_array($item['data'])) {
            foreach ($item['data'] as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            continue;
        }
        if (array_key_exists('name_path', $item)) {
            $rows[] = $item;
        }
    }
    return $rows;
}

/**
 * Build the store-0 name path for a category, level 2 and deeper.
 * Mirrors the GROUP_CONCAT in export_store_values.sql exactly.
 *
 * @param string             $path   catalog_category_entity.path, e.g. '1/2/3/11/12'
 * @param array<int,string>  $names  entity_id => store-0 name
 * @param array<int,int>     $levels entity_id => level
 */
function build_name_path(string $path, array $names, array $levels): ?string
{
    $parts = [];
    foreach (explode('/', $path) as $idStr) {
        $id = (int) $idStr;
        if (!isset($levels[$id])) {
            return null; // broken tree: path references a category that does not exist
        }
        if ($levels[$id] < 2) {
            continue;
        }
        if (!isset($names[$id])) {
            return null; // an ancestor has no store-0 name -- cannot key this row
        }
        $parts[] = $names[$id];
    }
    return $parts ? implode('/', $parts) : null;
}

/* ------------------------------------------------------------------ *
 * --self-test
 * ------------------------------------------------------------------ */

if (in_array('--self-test', $argv, true)) {
    assert(tsv_unescape('plain') === 'plain');
    assert(tsv_unescape('a\\nb') === "a\nb");
    assert(tsv_unescape('a\\tb') === "a\tb");
    assert(tsv_unescape('C:\\\\path') === 'C:\\path');
    // a literal backslash followed by 'n' must stay two characters, not a newline
    assert(tsv_unescape('C:\\\\new') === 'C:\\new');
    assert(tsv_unescape('\\N') === null);
    assert(tsv_unescape('') === '');

    $names  = [1 => 'Root Catalog', 2 => 'Default Category', 3 => 'All Carpets', 4 => 'Oriental Carpets', 10 => 'Nain'];
    $levels = [1 => 0, 2 => 1, 3 => 2, 4 => 3, 10 => 4];
    assert(build_name_path('1/2/3/4/10', $names, $levels) === 'All Carpets/Oriental Carpets/Nain');
    assert(build_name_path('1/2/3', $names, $levels) === 'All Carpets');
    assert(build_name_path('1/2', $names, $levels) === null);          // nothing at level 2+
    assert(build_name_path('1/2/3/99', $names, $levels) === null);     // unknown ancestor
    // duplicate leaf names are disambiguated by their parents
    assert(build_name_path('1/2/3/4/10', $names, $levels) !== build_name_path('1/2/3/4', $names, $levels));

    // phpMyAdmin envelope
    $pma = json_decode('[
        {"type":"header","version":"5.2","comment":"Export to JSON plugin for PHPMyAdmin"},
        {"type":"database","name":"magento"},
        {"type":"table","name":"results","database":"magento","data":[
            {"name_path":"All Carpets","store_code":"de","attribute_code":"name","backend_type":"varchar","value":"Alle Teppiche"},
            {"name_path":"All Carpets/Colors","store_code":"fr","attribute_code":"name","backend_type":"varchar","value":null}
        ]}
    ]', true);
    $pmaRows = json_rows($pma);
    assert(count($pmaRows) === 2);
    assert($pmaRows[0]['value'] === 'Alle Teppiche');
    assert($pmaRows[1]['value'] === null);
    assert($pmaRows[0]['store_code'] === 'de');

    // plain array of row objects
    $plain = json_rows(json_decode('[{"name_path":"X","store_code":"de","attribute_code":"name","backend_type":"varchar","value":"v"}]', true));
    assert(count($plain) === 1 && $plain[0]['name_path'] === 'X');

    // junk in, nothing out
    assert(json_rows(json_decode('[{"type":"header"}]', true)) === []);
    assert(json_rows(null) === []);

    // attribute selection: content by default
    assert(attr_skip_reason('name', [], false) === '');
    assert(attr_skip_reason('description', [], false) === '');
    assert(attr_skip_reason('display_mode', [], false) === 'not content');
    assert(attr_skip_reason('url_path', [], false) === 'not content');
    assert(attr_skip_reason('is_active', [], false) === 'not content');
    // --all opens the non-content ones
    assert(attr_skip_reason('display_mode', [], true) === '');
    assert(attr_skip_reason('is_active', [], true) === '');
    // reference attributes are never written, whatever the flags
    assert(attr_skip_reason('custom_block', [], true) === 'reference');
    assert(attr_skip_reason('landing_page', [], false) === 'reference');
    assert(attr_skip_reason('additional_megamenu_data', ['additional_megamenu_data'], true) === 'reference');
    // explicit --attributes wins over the content default, both ways
    assert(attr_skip_reason('display_mode', ['display_mode'], false) === '');
    assert(attr_skip_reason('name', ['display_mode'], false) === 'not requested');

    fwrite(STDOUT, "self-test OK\n");
    exit(0);
}

/* ------------------------------------------------------------------ *
 * args
 * ------------------------------------------------------------------ */

$flag = static function (string $name, array $argv): bool {
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
$value = static function (string $name, array $argv, string $default = ''): string {
    foreach ($argv as $a) {
        if (str_starts_with($a, $name . '=')) {
            return substr($a, strlen($name) + 1);
        }
    }
    return $default;
};

$dryRun      = $flag('--dry-run', $argv);
$apply       = $flag('--apply', $argv) && !$dryRun;
$all         = $flag('--all', $argv);
$file        = $value('--file', $argv);
$storeFilter = array_filter(array_map('trim', explode(',', $value('--stores', $argv))));
$attrFilter  = array_filter(array_map('trim', explode(',', $value('--attributes', $argv))));

if ($file === '' || !is_readable($file)) {
    fwrite(STDERR, "--file is required and must point to a readable TSV or JSON file.\n");
    exit(1);
}

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

/** @var \Magento\Framework\App\ResourceConnection $resource */
$resource   = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

/* ------------------------------------------------------------------ *
 * local lookup maps
 * ------------------------------------------------------------------ */

$catTypeId = (int) $connection->fetchOne(
    $connection->select()
        ->from($resource->getTableName('eav_entity_type'), 'entity_type_id')
        ->where('entity_type_code = ?', 'catalog_category')
);

$nameAttrId = (int) $connection->fetchOne(
    $connection->select()
        ->from($resource->getTableName('eav_attribute'), 'attribute_id')
        ->where('attribute_code = ?', 'name')
        ->where('entity_type_id = ?', $catTypeId)
);

// entity_id => level / path
$categories = $connection->fetchAll(
    $connection->select()->from($resource->getTableName('catalog_category_entity'), ['entity_id', 'path', 'level'])
);
$levels = [];
$paths  = [];
foreach ($categories as $row) {
    $levels[(int) $row['entity_id']] = (int) $row['level'];
    $paths[(int) $row['entity_id']]  = (string) $row['path'];
}

// entity_id => store-0 name
$names = [];
foreach ($connection->fetchAll(
    $connection->select()
        ->from($resource->getTableName('catalog_category_entity_varchar'), ['entity_id', 'value'])
        ->where('store_id = 0')
        ->where('attribute_id = ?', $nameAttrId)
) as $row) {
    $names[(int) $row['entity_id']] = (string) $row['value'];
}

// name_path => entity_id, ambiguous paths dropped
$pathMap   = [];
$ambiguous = [];
foreach ($paths as $entityId => $path) {
    if (($levels[$entityId] ?? -1) < 2) {
        continue;
    }
    $namePath = build_name_path($path, $names, $levels);
    if ($namePath === null) {
        continue;
    }
    $key = trim($namePath);
    if (isset($pathMap[$key])) {
        $ambiguous[$key] = true;
        continue;
    }
    $pathMap[$key] = $entityId;
}
foreach (array_keys($ambiguous) as $key) {
    unset($pathMap[$key]);
}

// store code => store_id (store 0 excluded -- the base value already covers it)
$storeMap = [];
foreach ($connection->fetchAll(
    $connection->select()->from($resource->getTableName('store'), ['store_id', 'code'])->where('store_id > 0')
) as $row) {
    $storeMap[(string) $row['code']] = (int) $row['store_id'];
}

// attribute_code => [attribute_id, backend_type]
$attrMap = [];
foreach ($connection->fetchAll(
    $connection->select()
        ->from($resource->getTableName('eav_attribute'), ['attribute_id', 'attribute_code', 'backend_type'])
        ->where('entity_type_id = ?', $catTypeId)
) as $row) {
    $attrMap[(string) $row['attribute_code']] = [
        'id'           => (int) $row['attribute_id'],
        'backend_type' => (string) $row['backend_type'],
    ];
}

/* ------------------------------------------------------------------ *
 * read + import
 * ------------------------------------------------------------------ */

$expected = ['name_path', 'store_code', 'attribute_code', 'backend_type', 'value'];

/**
 * Rows are read up front into memory as assoc arrays, whichever format the file is.
 * Category exports are a few thousand rows at most, so streaming buys nothing.
 *
 * @var array<int,array<string,string|null>> $rows
 */
$rows       = [];
$badLines   = [];
$raw        = file_get_contents($file);
if ($raw === false || trim($raw) === '') {
    fwrite(STDERR, "empty or unreadable file\n");
    exit(1);
}

$isJson = str_starts_with(ltrim($raw), '[') || str_ends_with(strtolower($file), '.json');

if ($isJson) {
    // phpMyAdmin "Export -> JSON" of the query result.
    $decoded = json_decode($raw, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        fwrite(STDERR, 'invalid JSON: ' . json_last_error_msg() . "\n");
        exit(1);
    }
    $rows = json_rows($decoded);
    if (!$rows) {
        fwrite(STDERR, "no rows found in JSON. Expected objects with a 'name_path' key,\n"
            . "either at the top level or under a phpMyAdmin \"data\" array.\n");
        exit(1);
    }
    $missingCols = array_diff($expected, array_keys($rows[0]));
    if ($missingCols) {
        fwrite(STDERR, 'JSON rows missing column(s): ' . implode(', ', $missingCols) . "\n");
        exit(1);
    }
} else {
    // `mysql --batch` TSV (NOT --raw; --raw turns the escaping off).
    // mysql escapes \n, \t and \\ inside values but leaves a bare CR (0x0D)
    // alone, and category HTML is full of CRLF. So split on LF only -- any \r
    // that survives is data, not a line ending.
    $lines  = explode("\n", $raw);
    $header = array_map('trim', explode("\t", (string) array_shift($lines)));
    if ($header !== $expected) {
        fwrite(STDERR, "unexpected header: " . implode(', ', $header)
            . "\nexpected: " . implode(', ', $expected)
            . "\n(shell export must use `mysql --batch`; from phpMyAdmin export as JSON instead)\n");
        exit(1);
    }
    foreach ($lines as $i => $line) {
        if ($line === '') {
            continue;
        }
        $cols = explode("\t", $line);
        if (count($cols) !== 5) {
            $badLines['line ' . ($i + 2) . ': column count ' . count($cols)] = true;
            continue;
        }
        $rows[] = array_combine($expected, array_map('tsv_unescape', $cols));
    }
}

$written        = [];   // store_code => count
$unmatchedPath  = [];
$unknownStore   = [];
$unknownAttr    = [];
$skippedRef     = [];   // reference attributes -- never written
$skippedOther   = [];   // not content, and --all not given
$typeMismatch   = [];
$conflicts      = [];   // same key seen twice with different values
$seenValues     = [];   // "name_path|store_code|attribute_code" => value
$batches        = [];   // table => rows
$rowsSeen       = 0;

$BATCH = 500;
$flushBatch = static function (string $table) use (&$batches, $connection, $resource, $apply): void {
    if (empty($batches[$table])) {
        return;
    }
    if ($apply) {
        $connection->insertOnDuplicate($resource->getTableName($table), $batches[$table], ['value']);
    }
    $batches[$table] = [];
};

foreach ($rows as $row) {
    $rowsSeen++;

    $namePath  = trim((string) ($row['name_path'] ?? ''));
    $storeCode = trim((string) ($row['store_code'] ?? ''));
    $attrCode  = trim((string) ($row['attribute_code'] ?? ''));
    $srcType   = trim((string) ($row['backend_type'] ?? ''));
    $val       = $row['value'] ?? null;
    $val       = $val === null ? null : (string) $val;

    if ($storeFilter && !in_array($storeCode, $storeFilter, true)) {
        continue;
    }

    $skipReason = attr_skip_reason($attrCode, $attrFilter, $all);
    if ($skipReason === 'reference') {
        $skippedRef[$attrCode] = ($skippedRef[$attrCode] ?? 0) + 1;
        continue;
    }
    if ($skipReason === 'not content') {
        $skippedOther[$attrCode] = ($skippedOther[$attrCode] ?? 0) + 1;
        continue;
    }
    if ($skipReason !== '') {
        continue; // 'not requested' -- explicit --attributes list, no need to report
    }
    if (!isset($pathMap[$namePath])) {
        $unmatchedPath[$namePath] = ($unmatchedPath[$namePath] ?? 0) + 1;
        continue;
    }
    if (!isset($storeMap[$storeCode])) {
        $unknownStore[$storeCode] = ($unknownStore[$storeCode] ?? 0) + 1;
        continue;
    }
    if (!isset($attrMap[$attrCode])) {
        $unknownAttr[$attrCode] = ($unknownAttr[$attrCode] ?? 0) + 1;
        continue;
    }

    $localType = $attrMap[$attrCode]['backend_type'];
    if ($srcType !== '' && $srcType !== $localType) {
        // Local schema wins -- writing to the prod table name would hit the wrong table.
        $typeMismatch["{$attrCode}: prod={$srcType} local={$localType}"] = true;
    }
    if (!in_array($localType, ['varchar', 'text', 'int', 'decimal', 'datetime'], true)) {
        $unknownAttr["{$attrCode} (backend_type {$localType})"] = ($unknownAttr[$attrCode] ?? 0) + 1;
        continue;
    }

    // Two different SOURCE categories can share one name path (prod-side duplicate).
    // They collapse onto the same local category, and without this the last row
    // silently wins. First value in wins; the rest are reported and skipped.
    $dedupeKey = $namePath . '|' . $storeCode . '|' . $attrCode;
    if (array_key_exists($dedupeKey, $seenValues)) {
        if ($seenValues[$dedupeKey] !== $val) {
            $conflicts[$dedupeKey] = ($conflicts[$dedupeKey] ?? 0) + 1;
        }
        continue;
    }
    $seenValues[$dedupeKey] = $val;

    $table              = 'catalog_category_entity_' . $localType;
    $batches[$table][]  = [
        'attribute_id' => $attrMap[$attrCode]['id'],
        'store_id'     => $storeMap[$storeCode],
        'entity_id'    => $pathMap[$namePath],
        'value'        => $val,
    ];
    $written[$storeCode] = ($written[$storeCode] ?? 0) + 1;

    if (count($batches[$table]) >= $BATCH) {
        $flushBatch($table);
    }
}

foreach (array_keys($batches) as $table) {
    $flushBatch($table);
}

/* ------------------------------------------------------------------ *
 * report
 * ------------------------------------------------------------------ */

$verb  = $apply ? 'Wrote' : 'Would write';
$total = array_sum($written);

$out   = [];
$out[] = '=== category store-value import ===';
$out[] = 'file:       ' . $file;
$out[] = 'format:     ' . ($isJson ? 'JSON (phpMyAdmin export)' : 'TSV (mysql --batch)');
$out[] = 'mode:       ' . ($apply ? 'APPLY' : 'DRY RUN (no writes)');
$out[] = 'rows read:  ' . $rowsSeen;
$out[] = $verb . ':      ' . $total . ' row(s)';
$out[] = '';
$out[] = '--- per store ---';
ksort($written);
foreach ($written as $code => $n) {
    $out[] = sprintf('  %-8s %d', $code, $n);
}
$section = static function (string $title, array $entries) use (&$out): void {
    $out[] = '';
    $out[] = '--- ' . $title . ' (' . count($entries) . ') ---';
    if (!$entries) {
        $out[] = '  (none)';
        return;
    }
    foreach ($entries as $k => $v) {
        $out[] = '  ' . $k . (is_int($v) ? " x{$v}" : '');
    }
};
$section('malformed TSV lines -- SKIPPED', $badLines);
$section('category name paths with no local match -- SKIPPED', $unmatchedPath);
$section('store codes with no local store view -- SKIPPED', $unknownStore);
$section('attributes not present locally -- SKIPPED', $unknownAttr);
$section('reference attributes -- NEVER written (block/page/theme/option ids)', $skippedRef);
$section('not translatable content -- SKIPPED (--all, or name them in --attributes)', $skippedOther);
$section('CONFLICT: same name path + store + attribute, different values -- first won', $conflicts);
$section('backend_type differs prod vs local -- local table used', $typeMismatch);
$section('ambiguous local name paths -- unmatchable', $ambiguous);

$report = implode("\n", $out) . "\n";
fwrite(STDOUT, $report);

$reportPath = BP . '/var/log/store_value_import_report.txt';
@file_put_contents($reportPath, $report);
fwrite(STDOUT, "\nreport: {$reportPath}\n");

if (!$apply) {
    fwrite(STDOUT, "\nDRY RUN. Re-run with --apply to write.\n");
    exit(0);
}

fwrite(STDOUT, "\nDone. Raw writes bypass indexers -- now run:\n"
    . "  bin/magento indexer:reindex catalog_category_product catalog_url_rewrite_category\n"
    . "  bin/magento cache:flush\n");
