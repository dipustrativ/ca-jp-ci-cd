<?php
/**
 * Set the store-view (store_id 1, "jp") url_key on categories from a CSV.
 *
 * INPUT
 *   category_url.csv  (same directory), header: category_id, category_name, url_key
 *     - category_name is the DEFAULT-scope (store 0 / admin) category name.
 *     - url_key is the value to write for store 1.
 *
 * MATCHING IS BY NAME, NOT BY ID. The CSV category_id column comes from a
 * different system and does not line up with this database's entity_ids, so it
 * is ignored for lookup (kept only in the report). The category is found by its
 * DEFAULT-scope (store 0) name == the CSV category_name. Store-0 names are
 * unique in this tree, so this is an unambiguous key.
 *
 * WHAT IT DOES, per row:
 *   1. Looks up the category whose store-0 name equals the CSV category_name.
 *      No match  -> SKIPPED (reported as "not found").
 *   2. On a match, writes url_key at store 1 scope
 *      (catalog_category_entity_varchar, store_id = 1) via saveAttribute.
 *
 * Rows with a NULL/empty url_key are skipped (nothing to set). Root (1) and
 * Default Category (2) fall out naturally that way.
 *
 * url_key ONLY. url_path and url_rewrite rows are NOT touched here -- a url_key
 * change does not regenerate them. After --apply, run the sibling script:
 *   php developer/scripts/category_info/regenerate_category_urls.php --stores=jp --apply
 *   bin/magento cache:flush
 *
 * Usage (from the Magento root):
 *   php developer/scripts/category_info/update_store1_url_keys.php            # dry run
 *   php developer/scripts/category_info/update_store1_url_keys.php --apply
 *   php developer/scripts/category_info/update_store1_url_keys.php --csv=/path/to.csv --apply
 */

declare(strict_types=1);

use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;

/* ---------------------------------------------------------------- args -- */

$flag = static function (string $name) use ($argv): bool {
    return in_array($name, $argv, true);
};
$value = static function (string $name, string $default = '') use ($argv): string {
    foreach ($argv as $a) {
        if (str_starts_with($a, $name . '=')) {
            return substr($a, strlen($name) + 1);
        }
    }
    return $default;
};

$apply    = $flag('--apply') && !$flag('--dry-run');
$csvPath  = $value('--csv', __DIR__ . '/category_url.csv');
$storeId  = 1; // "jp"

if (!is_readable($csvPath)) {
    fwrite(STDERR, "csv not readable: {$csvPath}\n");
    exit(1);
}

/* ----------------------------------------------------------- bootstrap -- */

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

$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
try {
    $om->get(\Magento\Framework\App\State::class)->setAreaCode(Area::AREA_ADMINHTML);
} catch (\Throwable $e) {
}

/** @var \Magento\Catalog\Model\CategoryFactory $categoryFactory */
$categoryFactory = $om->get(\Magento\Catalog\Model\CategoryFactory::class);
/** @var \Magento\Framework\App\ResourceConnection $resource */
$resource   = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

// Confirm the target store exists.
/** @var \Magento\Store\Api\StoreRepositoryInterface $storeRepo */
$storeRepo = $om->get(\Magento\Store\Api\StoreRepositoryInterface::class);
try {
    $storeRepo->getById($storeId);
} catch (\Throwable $e) {
    fwrite(STDERR, "store_id {$storeId} not found\n");
    exit(1);
}

/* --------------------------------------------------- store-0 name index -- */

// name (default scope) => entity_id. Store-0 names are unique in this tree
// (verified), so a plain map is safe. Trimmed key for tolerant matching.
$nameToId = [];
$dupeNames = [];
foreach ($connection->fetchAll(
    $connection->select()
        ->from(['v' => $resource->getTableName('catalog_category_entity_varchar')], ['entity_id', 'value'])
        ->join(['a' => $resource->getTableName('eav_attribute')], 'a.attribute_id = v.attribute_id', [])
        ->join(['e' => $resource->getTableName('eav_entity_type')], 'e.entity_type_id = a.entity_type_id', [])
        ->where('a.attribute_code = ?', 'name')
        ->where('e.entity_type_code = ?', 'catalog_category')
        ->where('v.store_id = ?', 0)
) as $r) {
    $key = trim((string) $r['value']);
    if ($key === '') {
        continue;
    }
    if (isset($nameToId[$key])) {
        $dupeNames[$key] = true;
    }
    $nameToId[$key] = (int) $r['entity_id'];
}

/* ------------------------------------------------------------- csv read -- */

$isNull = static function ($v): bool {
    $v = trim((string) $v);
    return $v === '' || strcasecmp($v, 'NULL') === 0;
};

$rows = [];
$fh   = fopen($csvPath, 'r');
$header = fgetcsv($fh, 0, ',', '"', ''); // discard header
while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if ($r === [null] || $r === false) {
        continue;
    }
    $rows[] = [
        'id'      => (int) ($r[0] ?? 0),
        'name'    => (string) ($r[1] ?? ''),
        'url_key' => (string) ($r[2] ?? ''),
    ];
}
fclose($fh);

/* ---------------------------------------------------------------- work -- */

$stats = ['total' => count($rows), 'updated' => 0, 'unchanged' => 0, 'skipped_nullkey' => 0, 'skipped_notfound' => 0, 'skipped_ambiguous' => 0, 'failed' => 0];
$changes   = [];
$notFound  = [];
$ambiguous = [];
$failures  = [];

foreach ($rows as $row) {
    $csvId   = $row['id'];
    $csvName = trim($row['name']);
    $newKey  = trim($row['url_key']);

    if ($isNull($newKey)) {
        $stats['skipped_nullkey']++;
        continue;
    }

    if (isset($dupeNames[$csvName])) {
        $stats['skipped_ambiguous']++;
        $ambiguous[] = "'{$csvName}' (csv id {$csvId})";
        continue;
    }
    if (!isset($nameToId[$csvName])) {
        $stats['skipped_notfound']++;
        $notFound[] = "'{$csvName}' (csv id {$csvId})";
        continue;
    }

    $id = $nameToId[$csvName];

    // Load at store 1 to read the current effective url_key and to write scoped.
    $cat1 = $categoryFactory->create();
    $cat1->setStoreId($storeId);
    $cat1->load($id);
    $currentKey = trim((string) $cat1->getUrlKey());

    if ($currentKey === $newKey) {
        $stats['unchanged']++;
        continue;
    }

    if (!$apply) {
        $stats['updated']++;
        $changes[] = "id {$id} '{$csvName}': '{$currentKey}' -> '{$newKey}'";
        continue;
    }

    try {
        $cat1->setUrlKey($newKey);
        // Writes catalog_category_entity_varchar for attribute url_key at store 1.
        $cat1->getResource()->saveAttribute($cat1, 'url_key');
        $stats['updated']++;
        $changes[] = "id {$id} '{$csvName}': '{$currentKey}' -> '{$newKey}'";
    } catch (\Throwable $e) {
        $stats['failed']++;
        $failures["{$id}"] = $e->getMessage();
    }
}

/* -------------------------------------------------------------- report -- */

$out   = [];
$out[] = '=== store 1 (jp) url_key update from CSV ===';
$out[] = 'csv : ' . $csvPath;
$out[] = 'mode: ' . ($apply ? 'APPLY' : 'DRY RUN (nothing written)');
$out[] = '';
$out[] = sprintf('  rows              : %d', $stats['total']);
$out[] = sprintf('  %-18s: %d', $apply ? 'updated' : 'would update', $stats['updated']);
$out[] = sprintf('  already correct   : %d', $stats['unchanged']);
$out[] = sprintf('  skipped null key  : %d', $stats['skipped_nullkey']);
$out[] = sprintf('  name not found    : %d', $stats['skipped_notfound']);
$out[] = sprintf('  ambiguous name    : %d', $stats['skipped_ambiguous']);
$out[] = sprintf('  failed            : %d', $stats['failed']);

$section = static function (string $title, array $items, int $limit = 40) use (&$out): void {
    $out[] = '';
    $out[] = "--- {$title} (" . count($items) . ') ---';
    if (!$items) {
        $out[] = '  (none)';
        return;
    }
    foreach (array_slice($items, 0, $limit) as $line) {
        $out[] = '  ' . $line;
    }
    if (count($items) > $limit) {
        $out[] = '  ... ' . (count($items) - $limit) . ' more';
    }
};

$section($apply ? 'updated' : 'would update', $changes);
$section('name not found (skipped)', $notFound);
$section('ambiguous name (skipped)', $ambiguous);
$section('failures', array_map(static fn($k, $v) => "{$k}: {$v}", array_keys($failures), $failures));

$report = implode("\n", $out) . "\n";
fwrite(STDOUT, $report);
@file_put_contents(BP . '/var/log/update_store1_url_keys_report.txt', $report);

fwrite(STDOUT, $apply
    ? "\nDone. url_key written for store 1. Now regenerate paths + rewrites:\n"
      . "  php developer/scripts/category_info/regenerate_category_urls.php --stores=jp --apply\n"
      . "  bin/magento cache:flush\n"
    : "\nDRY RUN. Re-run with --apply to write.\n");
