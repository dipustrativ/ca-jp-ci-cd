<?php
/**
 * Apply the JP category CSV: store-view names + default-scope url_keys, then
 * rebuild the category url_rewrite rows.
 *
 * INPUT
 *   category_url_key.csv (same directory), header:
 *     entity_id, name_all_stores, name_store17, url_key_store17
 *   - name_all_stores  is the DEFAULT-scope (store 0 / admin) category name.
 *   - name_store17     is the name to write for the target store view (store 1).
 *   - url_key_store17  is the url_key to write at DEFAULT scope (store 0).
 *   NULL / empty cells mean "leave that side alone".
 *
 * MATCHING IS BY NAME, NOT BY ID. The entity_id column comes from the source
 * system (store 17 there) and does not line up with this database's entity_ids,
 * so it is only echoed in the report. Categories are found by
 * DEFAULT-scope (store 0) name == name_all_stores. Store-0 names are unique in
 * this tree (verified: 81 categories, 0 duplicates), so this is unambiguous.
 *
 * WHY url_key GOES TO STORE 0 AND name GOES TO STORE 1
 * url_rewrite rows are all store_id 1 (store 0 is admin and never has any), but
 * their request_path is built from the url_key the category resolves to when
 * loaded in store 1 -- and with no store-1 override that resolves, by EAV
 * fallback, to the store-0 value. So the slug is driven from default scope while
 * the visible name is overridden per store view. That is exactly what this CSV
 * encodes. Any pre-existing store-1 url_key row would shadow the new store-0
 * value, so rows that disagree are deleted (pass --keep-store1-urlkey to leave
 * them; they then win and the CSV slug has no effect).
 *
 * WHAT IT DOES, per row:
 *   1. Look up the category by store-0 name. No match -> SKIPPED (reported).
 *   2. name_store17 set     -> write `name` at store 1 (creates the scoped row).
 *   3. url_key_store17 set  -> write `url_key` at store 0, and drop a shadowing
 *                              store-1 url_key row.
 * Then, unless --no-rewrites, regenerate url_path + url_rewrite for the whole
 * store-1 tree, parents before children, using Magento's own generators -- the
 * same thing regenerate_category_urls.php does (a raw attribute write fires no
 * save observer, so nothing regenerates on its own).
 *
 * Duplicate name_all_stores rows in the CSV are collapsed when they carry
 * identical values; when they disagree the name is CONFLICTED and skipped
 * entirely -- guessing which row wins would silently pick a slug.
 *
 * Usage (from the Magento root):
 *   php developer/scripts/category_info/url_update.php                  # dry run
 *   php developer/scripts/category_info/url_update.php --apply
 *   php developer/scripts/category_info/url_update.php --apply --no-rewrites
 *   php developer/scripts/category_info/url_update.php --csv=/path/to.csv --store=1
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

$apply        = $flag('--apply') && !$flag('--dry-run');
$doRewrites   = !$flag('--no-rewrites');
$keepStoreKey = $flag('--keep-store1-urlkey');
$csvPath      = $value('--csv', __DIR__ . '/category_url_key.csv');
$storeId      = (int) $value('--store', '1'); // "jp"

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
    // Frontend area: the url_rewrite generators are frontend services.
    $om->get(\Magento\Framework\App\State::class)->setAreaCode(Area::AREA_FRONTEND);
} catch (\Throwable $e) {
}

/** @var \Magento\Catalog\Model\CategoryFactory $categoryFactory */
$categoryFactory = $om->get(\Magento\Catalog\Model\CategoryFactory::class);
/** @var \Magento\Framework\App\ResourceConnection $resource */
$resource   = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();
/** @var \Magento\Eav\Model\Config $eavConfig */
$eavConfig = $om->get(\Magento\Eav\Model\Config::class);

/** @var \Magento\Store\Api\StoreRepositoryInterface $storeRepo */
$storeRepo = $om->get(\Magento\Store\Api\StoreRepositoryInterface::class);
try {
    $store = $storeRepo->getById($storeId);
} catch (\Throwable $e) {
    fwrite(STDERR, "store_id {$storeId} not found\n");
    exit(1);
}

$varcharTable = $resource->getTableName('catalog_category_entity_varchar');
$nameAttrId   = (int) $eavConfig->getAttribute('catalog_category', 'name')->getId();
$keyAttrId    = (int) $eavConfig->getAttribute('catalog_category', 'url_key')->getId();

/* --------------------------------------------------- current EAV state -- */

/**
 * entity_id => value for one attribute in one scope.
 *
 * @return array<int, string>
 */
$scopeValues = static function (int $attributeId, int $scope) use ($connection, $varcharTable): array {
    $out = [];
    foreach ($connection->fetchAll(
        $connection->select()
            ->from($varcharTable, ['entity_id', 'value'])
            ->where('attribute_id = ?', $attributeId)
            ->where('store_id = ?', $scope)
    ) as $r) {
        $out[(int) $r['entity_id']] = (string) $r['value'];
    }
    return $out;
};

$name0 = $scopeValues($nameAttrId, 0);
$nameS = $scopeValues($nameAttrId, $storeId);
$key0  = $scopeValues($keyAttrId, 0);
$keyS  = $scopeValues($keyAttrId, $storeId);

// store-0 name => entity_id, with duplicate detection.
$nameToId  = [];
$dupeNames = [];
foreach ($name0 as $entityId => $v) {
    $k = trim($v);
    if ($k === '') {
        continue;
    }
    if (isset($nameToId[$k])) {
        $dupeNames[$k] = true;
    }
    $nameToId[$k] = $entityId;
}

/* ------------------------------------------------------------- csv read -- */

$isNull = static function ($v): bool {
    $v = trim((string) $v);
    return $v === '' || strcasecmp($v, 'NULL') === 0;
};

$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh, 0, ',', '"', '');
if (!$header) {
    fwrite(STDERR, "empty csv: {$csvPath}\n");
    exit(1);
}
$col = array_flip(array_map(static fn($h) => trim((string) $h), $header));
foreach (['entity_id', 'name_all_stores', 'name_store17', 'url_key_store17'] as $required) {
    if (!isset($col[$required])) {
        fwrite(STDERR, "csv missing column '{$required}' (header: " . implode(', ', $header) . ")\n");
        exit(1);
    }
}

// Collapse rows that repeat a name: identical payloads merge, disagreements are
// recorded and the name is dropped from the run.
$byName     = [];
$conflicted = [];
while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if ($r === [null] || $r === false) {
        continue;
    }
    $name = trim((string) ($r[$col['name_all_stores']] ?? ''));
    if ($name === '') {
        continue;
    }
    $row = [
        'csv_id'  => trim((string) ($r[$col['entity_id']] ?? '')),
        'name'    => $name,
        'newName' => $isNull($r[$col['name_store17']] ?? '') ? null : trim((string) $r[$col['name_store17']]),
        'newKey'  => $isNull($r[$col['url_key_store17']] ?? '') ? null : trim((string) $r[$col['url_key_store17']]),
    ];
    if (isset($byName[$name])) {
        $prev = $byName[$name];
        if ($prev['newName'] !== $row['newName'] || $prev['newKey'] !== $row['newKey']) {
            $conflicted[$name][] = $prev['csv_id'];
            $conflicted[$name][] = $row['csv_id'];
        }
        continue;
    }
    $byName[$name] = $row;
}
fclose($fh);

foreach (array_keys($conflicted) as $name) {
    unset($byName[$name]);
}

/* ---------------------------------------------------------------- work -- */

$stats = [
    'rows'            => count($byName) + count($conflicted),
    'matched'         => 0,
    'name_written'    => 0,
    'name_same'       => 0,
    'key_written'     => 0,
    'key_same'        => 0,
    'shadow_dropped'  => 0,
    'shadow_kept'     => 0,
    'nothing_to_do'   => 0,
    'not_found'       => 0,
    'ambiguous'       => 0,
    'conflicted'      => count($conflicted),
    'failed'          => 0,
];
$nameChanges = [];
$keyChanges  = [];
$shadows     = [];
$notFound    = [];
$ambiguous   = [];
$failures    = [];
$touched     = [];

foreach ($byName as $name => $row) {
    if (isset($dupeNames[$name])) {
        $stats['ambiguous']++;
        $ambiguous[] = "'{$name}' (csv id {$row['csv_id']})";
        continue;
    }
    if (!isset($nameToId[$name])) {
        $stats['not_found']++;
        $notFound[] = "'{$name}' (csv id {$row['csv_id']})";
        continue;
    }

    $id = $nameToId[$name];
    $stats['matched']++;

    $writeName = $row['newName'] !== null && ($nameS[$id] ?? null) !== $row['newName'];
    $writeKey  = $row['newKey'] !== null && ($key0[$id] ?? null) !== $row['newKey'];
    $dropShadow = $row['newKey'] !== null
        && isset($keyS[$id])
        && $keyS[$id] !== $row['newKey'];

    if ($row['newName'] !== null && !$writeName) {
        $stats['name_same']++;
    }
    if ($row['newKey'] !== null && !$writeKey) {
        $stats['key_same']++;
    }
    if (!$writeName && !$writeKey && !$dropShadow) {
        $stats['nothing_to_do']++;
        continue;
    }

    try {
        if ($writeName) {
            $from = $nameS[$id] ?? '(inherits "' . ($name0[$id] ?? '') . '")';
            if ($apply) {
                $cat = $categoryFactory->create();
                $cat->setStoreId($storeId);
                $cat->load($id);
                $cat->setName($row['newName']);
                // store_id != 0 -> writes/creates the store-scope varchar row.
                $cat->getResource()->saveAttribute($cat, 'name');
            }
            $stats['name_written']++;
            $nameChanges[] = "id {$id} '{$name}': name[{$storeId}] {$from} -> '{$row['newName']}'";
            $touched[$id]  = true;
        }

        if ($writeKey) {
            $from = $key0[$id] ?? '(unset)';
            if ($apply) {
                $cat0 = $categoryFactory->create();
                $cat0->setStoreId(0);
                $cat0->load($id);
                $cat0->setUrlKey($row['newKey']);
                $cat0->getResource()->saveAttribute($cat0, 'url_key');
            }
            $stats['key_written']++;
            $keyChanges[] = "id {$id} '{$name}': url_key[0] '{$from}' -> '{$row['newKey']}'";
            $touched[$id] = true;
        }

        if ($dropShadow) {
            if ($keepStoreKey) {
                $stats['shadow_kept']++;
                $shadows[] = "id {$id} '{$name}': KEPT url_key[{$storeId}] '{$keyS[$id]}' -- it wins over '{$row['newKey']}'";
            } else {
                if ($apply) {
                    $connection->delete($varcharTable, [
                        'attribute_id = ?' => $keyAttrId,
                        'store_id = ?'     => $storeId,
                        'entity_id = ?'    => $id,
                    ]);
                }
                $stats['shadow_dropped']++;
                $shadows[] = "id {$id} '{$name}': dropped url_key[{$storeId}] '{$keyS[$id]}' (shadowed store-0 '{$row['newKey']}')";
                $touched[$id] = true;
            }
        }
    } catch (\Throwable $e) {
        $stats['failed']++;
        $failures["{$id} '{$name}'"] = $e->getMessage();
    }
}

/* ------------------------------------------------------------ rewrites -- */

$regen = ['categories' => 0, 'changed' => 0, 'rewrites' => 0, 'failed' => 0];
$regenFailures = [];
$regenRan = false;

if ($doRewrites && $apply && $stats['failed'] === 0) {
    $regenRan = true;

    /** @var \Magento\Catalog\Api\CategoryRepositoryInterface $categoryRepository */
    $categoryRepository = $om->get(\Magento\Catalog\Api\CategoryRepositoryInterface::class);
    /** @var \Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator $urlPathGenerator */
    $urlPathGenerator = $om->get(\Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator::class);
    /** @var \Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator $rewriteGenerator */
    $rewriteGenerator = $om->get(\Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator::class);
    /** @var \Magento\UrlRewrite\Model\UrlPersistInterface $urlPersist */
    $urlPersist = $om->get(\Magento\UrlRewrite\Model\UrlPersistInterface::class);

    // Stale in-process EAV/category state would feed the generators the values
    // read before the writes above.
    $eavConfig->clear();

    $rootId = (int) $connection->fetchOne(
        $connection->select()
            ->from(['s' => $resource->getTableName('store')], [])
            ->join(['g' => $resource->getTableName('store_group')], 's.group_id = g.group_id', ['root_category_id'])
            ->where('s.store_id = ?', $storeId)
    );

    // Whole tree, parents before children: a child's url_path is built from the
    // parent's freshly saved one, so a partial (touched-only) pass would leave
    // children of a renamed parent pointing at the old segment.
    $ids = array_map('intval', $connection->fetchCol(
        $connection->select()
            ->from($resource->getTableName('catalog_category_entity'), ['entity_id'])
            ->where('path LIKE ?', "%/{$rootId}/%")
            ->order('level')
            ->order('position')
    ));

    $rewriteTable = $resource->getTableName('url_rewrite');
    $snapshot = static function (int $entityId) use ($connection, $rewriteTable, $storeId): array {
        return array_map(
            static fn(array $r) => implode('|', $r),
            $connection->fetchAll(
                $connection->select()
                    ->from($rewriteTable, ['request_path', 'target_path', 'redirect_type'])
                    ->where('entity_type = ?', 'category')
                    ->where('entity_id = ?', $entityId)
                    ->where('store_id = ?', $storeId)
                    ->order('request_path')
            )
        );
    };

    // One transaction per category, not per run: UrlPersist::replace() opens its
    // own, and an inner rollback poisons an outer one for every later category.
    foreach ($ids as $entityId) {
        $before = $snapshot($entityId);
        $connection->beginTransaction();
        try {
            $category = $categoryRepository->get($entityId, $storeId);
            if (trim((string) $category->getUrlKey()) === '') {
                throw new \RuntimeException('no url_key');
            }
            // Core does this in CategoryUrlPathAutogeneratorObserver -- without
            // it the generator short-circuits to the stored (stale) path.
            $category->unsUrlPath();
            $category->setUrlPath($urlPathGenerator->getUrlPath($category));
            $category->getResource()->saveAttribute($category, 'url_path');

            $urlPersist->replace($rewriteGenerator->generate($category));

            $after = $snapshot($entityId);
            $connection->commit();

            $regen['categories']++;
            $regen['rewrites'] += count($after);
            if ($after !== $before) {
                $regen['changed']++;
            }
        } catch (\Throwable $e) {
            $connection->rollBack();
            $regen['failed']++;
            $regenFailures[(string) $entityId] = $e->getMessage();
        }
    }
}

/* -------------------------------------------------------------- report -- */

$out   = [];
$out[] = '=== category CSV apply: name[store ' . $storeId . '] + url_key[store 0] ===';
$out[] = 'csv  : ' . $csvPath;
$out[] = 'store: ' . $storeId . ' (' . $store->getCode() . ')';
$out[] = 'mode : ' . ($apply ? 'APPLY' : 'DRY RUN (nothing written)');
$out[] = '';
$out[] = sprintf('  csv names (deduped) : %d', $stats['rows']);
$out[] = sprintf('  matched by store-0  : %d', $stats['matched']);
$out[] = sprintf('  %-19s: %d', $apply ? 'name written' : 'name would write', $stats['name_written']);
$out[] = sprintf('  name already correct: %d', $stats['name_same']);
$out[] = sprintf('  %-19s: %d', $apply ? 'url_key written' : 'url_key would write', $stats['key_written']);
$out[] = sprintf('  url_key already ok  : %d', $stats['key_same']);
$out[] = sprintf('  shadow rows dropped : %d', $stats['shadow_dropped']);
$out[] = sprintf('  shadow rows kept    : %d', $stats['shadow_kept']);
$out[] = sprintf('  nothing to do       : %d', $stats['nothing_to_do']);
$out[] = sprintf('  name not found      : %d', $stats['not_found']);
$out[] = sprintf('  ambiguous in DB     : %d', $stats['ambiguous']);
$out[] = sprintf('  conflicting csv rows: %d', $stats['conflicted']);
$out[] = sprintf('  failed              : %d', $stats['failed']);

$section = static function (string $title, array $items, int $limit = 200) use (&$out): void {
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

$section($apply ? 'names written' : 'names to write', $nameChanges);
$section($apply ? 'url_keys written' : 'url_keys to write', $keyChanges);
$section('store-scope url_key shadows', $shadows);
$section('name not found in DB (skipped)', $notFound);
$section('ambiguous store-0 name (skipped)', $ambiguous);
$section(
    'conflicting duplicate csv names (skipped)',
    array_map(
        static fn($n, $ids) => "'{$n}' -- csv ids " . implode(', ', array_unique($ids)),
        array_keys($conflicted),
        $conflicted
    )
);
$section('failures', array_map(static fn($k, $v) => "{$k}: {$v}", array_keys($failures), $failures));

$out[] = '';
if ($regenRan) {
    $out[] = '--- url_rewrite regeneration (store ' . $storeId . ') ---';
    $out[] = sprintf(
        '  categories %d, changed %d, rewrite rows %d, failed %d',
        $regen['categories'],
        $regen['changed'],
        $regen['rewrites'],
        $regen['failed']
    );
    foreach (array_slice($regenFailures, 0, 30, true) as $k => $msg) {
        $out[] = "  category {$k}: {$msg}";
    }
    if (count($regenFailures) > 30) {
        $out[] = '  ... ' . (count($regenFailures) - 30) . ' more';
    }
} elseif (!$doRewrites) {
    $out[] = '--- url_rewrite regeneration: SKIPPED (--no-rewrites) ---';
} elseif ($apply) {
    $out[] = '--- url_rewrite regeneration: SKIPPED (attribute writes failed) ---';
} else {
    $out[] = '--- url_rewrite regeneration: not run in dry run ---';
}

$report = implode("\n", $out) . "\n";
fwrite(STDOUT, $report);
@file_put_contents(BP . '/var/log/category_url_update_report.txt', $report);

fwrite(STDOUT, $apply
    ? "\nDone. Now run:\n  bin/magento cache:flush\n"
    : "\nDRY RUN. Re-run with --apply to write.\n");
