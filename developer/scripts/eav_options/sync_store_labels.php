<?php
/**
 * Sync store-scoped EAV attribute option labels from a CSV export.
 *
 * For every CSV row it looks up the attribute by `attribute_code`, finds the option
 * whose **store 0 (admin) label** equals the CSV `label_store_0`, and writes the CSV
 * `label_store_17` value as that option's label for the target store (default store 1).
 * Existing store rows are updated, missing ones inserted. Nothing else is touched —
 * option_id, sort_order and all other store labels are left alone.
 *
 * Matching is by admin label, not by option_id: the CSV's `attribute_id`/`option_id`
 * columns come from the source environment and are informational only.
 *
 * Usage:
 *   php developer/scripts/eav_options/sync_store_labels.php                 # dry-run (default)
 *   php developer/scripts/eav_options/sync_store_labels.php --dry-run       # dry-run (explicit)
 *   php developer/scripts/eav_options/sync_store_labels.php --apply         # write changes
 *   php developer/scripts/eav_options/sync_store_labels.php --store=1 --apply
 *   php developer/scripts/eav_options/sync_store_labels.php --code=age --verbose
 *
 * Options:
 *   --file=PATH       CSV to read           (default: eav_attribute_option.csv next to this script)
 *   --store=N         target store id       (default: 1)
 *   --from=COLUMN     match column          (default: label_store_0)
 *   --value=COLUMN    source label column   (default: label_store_17)
 *   --entity=CODE     EAV entity type       (default: catalog_product)
 *   --code=ATTR       only this attribute_code (repeatable)
 *   --verbose         list unchanged rows too
 *
 * Note: --dry-run always wins over --apply (safe default).
 *
 * Silence vendor deprecation noise:
 *   php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_NOTICE" \
 *       developer/scripts/eav_options/sync_store_labels.php 2>/dev/null
 */

declare(strict_types=1);

use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;

$bootstrapPath = null;
foreach ([
    __DIR__ . '/../../../app/bootstrap.php',
    __DIR__ . '/../../app/bootstrap.php',
    __DIR__ . '/../app/bootstrap.php',
] as $p) {
    if (file_exists($p)) { $bootstrapPath = $p; break; }
}
if (!$bootstrapPath) { fwrite(STDERR, "bootstrap.php not found\n"); exit(1); }
require $bootstrapPath;

/* ---------- args ---------- */
/* accept bare flag (--apply) and valued form (--apply=true / --apply=1 / --apply=yes) */
$flag = static function (string $name, array $argv): bool {
    foreach ($argv as $a) {
        if ($a === $name) { return true; }
        if (str_starts_with($a, $name . '=')) {
            $v = strtolower(substr($a, strlen($name) + 1));
            return in_array($v, ['1', 'true', 'yes', 'y', 'on', ''], true);
        }
    }
    return false;
};
$opt = static function (string $name, array $argv, string $default): string {
    foreach ($argv as $a) {
        if (str_starts_with($a, $name . '=')) { return substr($a, strlen($name) + 1); }
    }
    return $default;
};

$dryRun     = $flag('--dry-run', $argv);
$apply      = $flag('--apply', $argv) && !$dryRun;   // --dry-run always wins
$verbose    = $flag('--verbose', $argv);
$csvPath    = $opt('--file', $argv, __DIR__ . '/eav_attribute_option.csv');
$storeId    = (int)$opt('--store', $argv, '1');
$matchCol   = $opt('--from', $argv, 'label_store_0');
$valueCol   = $opt('--value', $argv, 'label_store_17');
$entityCode = $opt('--entity', $argv, \Magento\Catalog\Model\Product::ENTITY);

$onlyCodes = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--code=')) { $onlyCodes[substr($a, 7)] = true; }
}

if ($storeId === 0) {
    fwrite(STDERR, "ERROR: --store=0 would overwrite the admin labels this script matches on. Refusing.\n");
    exit(1);
}
if (!is_readable($csvPath)) {
    fwrite(STDERR, "ERROR: CSV not readable: {$csvPath}\n");
    exit(1);
}

/* ---------- bootstrap ---------- */
$bootstrap = Bootstrap::create(BP, $_SERVER);
/** @var \Magento\Framework\ObjectManagerInterface $om */
$om = $bootstrap->getObjectManager();

/** @var \Magento\Framework\App\State $state */
$state = $om->get(\Magento\Framework\App\State::class);
try { $state->setAreaCode(Area::AREA_ADMINHTML); } catch (\Throwable $e) {}

/** @var \Magento\Framework\App\ResourceConnection $resource */
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

$tOption   = $resource->getTableName('eav_attribute_option');
$tValue    = $resource->getTableName('eav_attribute_option_value');
$tAttr     = $resource->getTableName('eav_attribute');
$tEntity   = $resource->getTableName('eav_entity_type');
$tStore    = $resource->getTableName('store');

/* ---------- target store ---------- */
$storeRow = $connection->fetchRow(
    $connection->select()->from($tStore, ['store_id', 'code', 'name'])->where('store_id = ?', $storeId)
);
if (!$storeRow) {
    fwrite(STDERR, "ERROR: store_id {$storeId} does not exist.\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "CSV        : %s\nEntity     : %s\nMatch on   : %s (store 0)\nWrite from : %s\nTarget     : store_id=%d (%s / %s)\nMode       : %s\n\n",
    $csvPath,
    $entityCode,
    $matchCol,
    $valueCol,
    $storeId,
    $storeRow['code'],
    $storeRow['name'],
    $apply ? 'APPLY' : 'DRY-RUN'
));

/* ---------- read CSV ---------- */
$fh = fopen($csvPath, 'rb');
if (!$fh) { fwrite(STDERR, "ERROR: cannot open {$csvPath}\n"); exit(1); }

$header = fgetcsv($fh, 0, ",", "\"", "");
if (!$header) { fwrite(STDERR, "ERROR: empty CSV\n"); exit(1); }
// strip UTF-8 BOM from the first header cell
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
$header = array_map(static fn($h) => trim((string)$h), $header);

foreach (['attribute_code', $matchCol, $valueCol] as $required) {
    if (!in_array($required, $header, true)) {
        fwrite(STDERR, "ERROR: CSV is missing required column '{$required}'. Found: " . implode(', ', $header) . "\n");
        exit(1);
    }
}

$rows = [];
$lineNo = 1;
while (($data = fgetcsv($fh, 0, ",", "\"", "")) !== false) {
    $lineNo++;
    if ($data === [null] || (count($data) === 1 && trim((string)$data[0]) === '')) { continue; }
    $row = @array_combine($header, array_pad(array_slice($data, 0, count($header)), count($header), ''));
    if (!$row) { continue; }
    $row['__line'] = $lineNo;
    $rows[] = $row;
}
fclose($fh);

/* ---------- helpers ---------- */
/** trim + lowercase + collapse whitespace, so "Blue  Grey " == "blue grey" */
$norm = static function (string $s): string {
    $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
};

$report = [
    'update'           => [],
    'insert'           => [],
    'unchanged'        => [],
    'no_match'         => [],
    'ambiguous_db'     => [],
    'conflict_csv'     => [],
    'attr_not_found'   => [],
    'skip_empty_match' => [],
    'skip_empty_value' => [],
];

/* ---------- group CSV rows by attribute, detect CSV-side conflicts ---------- */
$byAttr = [];   // attribute_code => normalized match label => row
foreach ($rows as $row) {
    $code = trim((string)$row['attribute_code']);
    if ($code === '') { continue; }
    if ($onlyCodes && !isset($onlyCodes[$code])) { continue; }

    // the export writes SQL NULLs as a bare NULL token — treat it as no value, not the text "NULL"
    $cell = static fn(string $v): string => (trim($v) === 'NULL' || trim($v) === '\N') ? '' : trim($v);

    $matchLabel = $cell((string)$row[$matchCol]);
    $newLabel   = $cell((string)$row[$valueCol]);
    $where      = "{$code} line {$row['__line']}";

    if ($matchLabel === '') {
        $report['skip_empty_match'][] = "{$where}: empty {$matchCol} (option_id={$row['option_id']}, {$valueCol}='{$row[$valueCol]}')";
        continue;
    }
    if ($newLabel === '') {
        $report['skip_empty_value'][] = "{$where}: empty {$valueCol} for '{$matchLabel}'";
        continue;
    }
    $row[$matchCol] = $matchLabel;
    $row[$valueCol] = $newLabel;

    $key = $norm($matchLabel);
    if (isset($byAttr[$code][$key])) {
        $prev = $byAttr[$code][$key];
        if ($norm($prev[$valueCol]) !== $norm($newLabel)) {
            // two CSV rows claim the same admin label with different translations — cannot pick one
            $report['conflict_csv'][] = "{$code}: '{$matchLabel}' → '{$prev[$valueCol]}' (line {$prev['__line']}) vs '{$newLabel}' (line {$row['__line']})";
            $byAttr[$code][$key] = false;
        }
        continue;
    }
    $byAttr[$code][$key] = $row;
}

/* ---------- resolve attributes and diff ---------- */
$writes = [];   // ['value_id'=>?, 'option_id'=>int, 'value'=>string]

foreach ($byAttr as $code => $keyed) {
    $attrRow = $connection->fetchRow(
        $connection->select()
            ->from(['a' => $tAttr], ['attribute_id', 'attribute_code', 'frontend_input'])
            ->join(['e' => $tEntity], 'e.entity_type_id = a.entity_type_id', [])
            ->where('a.attribute_code = ?', $code)
            ->where('e.entity_type_code = ?', $entityCode)
    );
    if (!$attrRow) {
        $report['attr_not_found'][] = "{$code} (not on {$entityCode})";
        continue;
    }
    $attrId = (int)$attrRow['attribute_id'];

    // store 0 labels for every option of this attribute
    $dbRows = $connection->fetchAll(
        $connection->select()
            ->from(['o' => $tOption], ['option_id'])
            ->joinInner(['v0' => $tValue], 'v0.option_id = o.option_id AND v0.store_id = 0', ['admin_label' => 'v0.value'])
            ->joinLeft(['vs' => $tValue], $connection->quoteInto('vs.option_id = o.option_id AND vs.store_id = ?', $storeId), [
                'store_value_id' => 'vs.value_id',
                'store_label'    => 'vs.value',
            ])
            ->where('o.attribute_id = ?', $attrId)
    );

    $dbByLabel = [];   // normalized admin label => list of db rows
    foreach ($dbRows as $dbRow) {
        $dbByLabel[$norm((string)$dbRow['admin_label'])][] = $dbRow;
    }

    foreach ($keyed as $key => $row) {
        if ($row === false) { continue; }   // CSV conflict, already reported

        $matchLabel = trim((string)$row[$matchCol]);
        $newLabel   = trim((string)$row[$valueCol]);

        if (!isset($dbByLabel[$key])) {
            $report['no_match'][] = "{$code}: no store-0 option labelled '{$matchLabel}'";
            continue;
        }
        if (count($dbByLabel[$key]) > 1) {
            $ids = implode(', ', array_column($dbByLabel[$key], 'option_id'));
            $report['ambiguous_db'][] = "{$code}: '{$matchLabel}' matches option_id {$ids} — skipped";
            continue;
        }

        $dbRow    = $dbByLabel[$key][0];
        $optionId = (int)$dbRow['option_id'];
        $valueId  = $dbRow['store_value_id'] !== null ? (int)$dbRow['store_value_id'] : null;
        $current  = $dbRow['store_label'];

        if ($valueId !== null && (string)$current === $newLabel) {
            $report['unchanged'][] = "{$code}: '{$matchLabel}' already '{$newLabel}' (option_id={$optionId})";
            continue;
        }

        if ($valueId !== null) {
            $report['update'][] = "{$code}: '{$matchLabel}' (option_id={$optionId}) '{$current}' → '{$newLabel}'";
        } else {
            $report['insert'][] = "{$code}: '{$matchLabel}' (option_id={$optionId}) → '{$newLabel}'";
        }
        $writes[] = ['value_id' => $valueId, 'option_id' => $optionId, 'value' => $newLabel];
    }
}

/* ---------- report ---------- */
$section = static function (string $title, array $lines, bool $show = true): void {
    fwrite(STDOUT, "\n=== {$title} (" . count($lines) . ") ===\n");
    if (!$show) { fwrite(STDOUT, "(use --verbose to list)\n"); return; }
    fwrite(STDOUT, $lines ? implode("\n", $lines) . "\n" : "(none)\n");
};

$section('TO UPDATE', $report['update']);
$section('TO INSERT', $report['insert']);
$section('UNCHANGED', $report['unchanged'], $verbose);
$section('NO MATCHING STORE-0 OPTION', $report['no_match']);
$section('AMBIGUOUS IN DB', $report['ambiguous_db']);
$section('CONFLICTING CSV ROWS', $report['conflict_csv']);
$section('ATTRIBUTE NOT FOUND', $report['attr_not_found']);
$section("SKIPPED — empty {$matchCol}", $report['skip_empty_match']);
$section("SKIPPED — empty {$valueCol}", $report['skip_empty_value']);

if (!$writes) {
    fwrite(STDOUT, "\nNothing to write.\n");
    exit(0);
}

if (!$apply) {
    fwrite(STDOUT, "\nDRY-RUN. Re-run with --apply to write " . count($writes) . " label(s) to store {$storeId}.\n");
    exit(0);
}

/* ---------- apply ----------
 * Written straight to eav_attribute_option_value. Saving through the attribute model
 * would run Eav\Model\ResourceModel\Entity\Attribute::_updateAttributeOptionValues(),
 * which DELETEs every store row for the option and resets sort_order to 0 — it rebuilds
 * options wholesale, which is not what a per-store label sync should do.
 */
$updated = 0;
$inserted = 0;
$connection->beginTransaction();
try {
    foreach ($writes as $w) {
        if ($w['value_id'] !== null) {
            $connection->update($tValue, ['value' => $w['value']], ['value_id = ?' => $w['value_id']]);
            $updated++;
        } else {
            $connection->insert($tValue, [
                'option_id' => $w['option_id'],
                'store_id'  => $storeId,
                'value'     => $w['value'],
            ]);
            $inserted++;
        }
    }
    $connection->commit();
} catch (\Throwable $e) {
    $connection->rollBack();
    fwrite(STDERR, "\nFAILED, rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

/* clear eav cache so the new labels show immediately */
$om->get(\Magento\Eav\Model\Config::class)->clear();

fwrite(STDOUT, "\nDone. store {$storeId}: {$updated} updated, {$inserted} inserted.\n");
fwrite(STDOUT, "Run: bin/magento cache:clean eav config full_page; bin/magento indexer:reindex catalog_product_attribute\n");
