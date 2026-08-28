<?php
/**
 * Rebuild category url_path + url_rewrite rows, using Magento's own generators.
 *
 * WHY THIS EXISTS
 * There is no `catalog_url_rewrite_category` indexer. Category rewrites are
 * produced on category *save*, by CategoryUrlPathAutogeneratorObserver
 * (url_path) and CategoryProcessUrlRewriteSavingObserver (url_rewrite rows).
 * A raw-SQL import writes url_key without saving, so neither observer fires.
 *
 * This calls the same core services those observers call, per category per
 * store, and nothing else:
 *   CategoryUrlPathGenerator::getUrlPath()      -> url_path
 *   Category::getResource()->saveAttribute()    -> persist url_path
 *   CategoryUrlRewriteGenerator::generate()     -> url_rewrite rows
 *   UrlPersistInterface::replace()              -> persist them
 *
 * unsUrlPath() before getUrlPath() is what core does in
 * CategoryUrlPathAutogeneratorObserver::updateUrlPathForCategory() -- without
 * it the generator short-circuits to the stored (stale) path.
 *
 * Usage (from the Magento root):
 *   php developer/scripts/category_info/regenerate_category_urls.php
 *   php developer/scripts/category_info/regenerate_category_urls.php --apply
 *   php developer/scripts/category_info/regenerate_category_urls.php --stores=de,fr --apply
 *
 * ONE TRANSACTION PER CATEGORY, committed only with --apply. Per category, not
 * per run: UrlPersist::replace() opens its own transaction, and Magento's
 * adapter poisons an outer one the moment an inner rollback happens (a URL
 * clash, a deadlock retry) -- every later category would then fail too.
 * Per-category scope keeps one bad category from taking down the run.
 *
 * Dry run is a preview, not a simulation: each category is generated then
 * rolled back, so for a nested category the parent's new url_path is only in
 * memory. Counts are right; deep paths are only guaranteed under --apply.
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

$apply       = $flag('--apply') && !$flag('--dry-run');
$storeFilter = array_filter(array_map('trim', explode(',', $value('--stores'))));

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
    $om->get(\Magento\Framework\App\State::class)->setAreaCode(Area::AREA_FRONTEND);
} catch (\Throwable $e) {
}

/** @var \Magento\Framework\App\ResourceConnection $resource */
$resource   = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();
/** @var \Magento\Catalog\Api\CategoryRepositoryInterface $categoryRepository */
$categoryRepository = $om->get(\Magento\Catalog\Api\CategoryRepositoryInterface::class);
/** @var \Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator $urlPathGenerator */
$urlPathGenerator = $om->get(\Magento\CatalogUrlRewrite\Model\CategoryUrlPathGenerator::class);
/** @var \Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator $rewriteGenerator */
$rewriteGenerator = $om->get(\Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator::class);
/** @var \Magento\UrlRewrite\Model\UrlPersistInterface $urlPersist */
$urlPersist = $om->get(\Magento\UrlRewrite\Model\UrlPersistInterface::class);

/* -------------------------------------------------------------- stores -- */

// store_id => [code, root_category_id]
$stores = [];
foreach ($connection->fetchAll(
    $connection->select()
        ->from(['s' => $resource->getTableName('store')], ['store_id', 'code'])
        ->join(['g' => $resource->getTableName('store_group')], 's.group_id = g.group_id', ['root_category_id'])
        ->where('s.store_id > 0')
        ->order('s.store_id')
) as $row) {
    $code = (string) $row['code'];
    if ($storeFilter && !in_array($code, $storeFilter, true)) {
        continue;
    }
    $stores[(int) $row['store_id']] = ['code' => $code, 'root' => (int) $row['root_category_id']];
}
if (!$stores) {
    fwrite(STDERR, "no matching store views\n");
    exit(1);
}

/**
 * Categories under a root, parents before children -- the generators read the
 * parent's freshly set url_path. `%/<root>/%` also excludes the root itself
 * (its path has no trailing segment) and any category hanging off the tree root
 * instead of this store's root.
 *
 * @return int[]
 */
$categoryIds = static function (int $rootId) use ($connection, $resource): array {
    return array_map('intval', $connection->fetchCol(
        $connection->select()
            ->from($resource->getTableName('catalog_category_entity'), ['entity_id'])
            ->where('path LIKE ?', "%/{$rootId}/%")
            ->order('level')
            ->order('position')
    ));
};

$rewriteTable = $resource->getTableName('url_rewrite');

/**
 * Every rewrite row one category owns in one store, as comparable strings.
 *
 * @return string[]
 */
$snapshot = static function (int $entityId, int $storeId) use ($connection, $rewriteTable): array {
    $rows = $connection->fetchAll(
        $connection->select()
            ->from($rewriteTable, ['request_path', 'target_path', 'redirect_type'])
            ->where('entity_type = ?', 'category')
            ->where('entity_id = ?', $entityId)
            ->where('store_id = ?', $storeId)
            ->order('request_path')
    );
    return array_map(static fn(array $r) => implode('|', $r), $rows);
};

/* ---------------------------------------------------------------- work -- */

$stats    = [];
$failures = [];

foreach ($stores as $storeId => $store) {
    $code = $store['code'];
    $stats[$code] = ['categories' => 0, 'changed' => 0, 'rewrites' => 0, 'failed' => 0];

    foreach ($categoryIds($store['root']) as $entityId) {
        $before = $snapshot($entityId, $storeId);

        $connection->beginTransaction();
        try {
            $category = $categoryRepository->get($entityId, $storeId);

            // Core refuses to build a path without a url_key
            // (CategoryUrlPathAutogeneratorObserver::validateUrlKey).
            if (trim((string) $category->getUrlKey()) === '') {
                throw new \RuntimeException('no url_key');
            }

            $category->unsUrlPath();
            $category->setUrlPath($urlPathGenerator->getUrlPath($category));
            $category->getResource()->saveAttribute($category, 'url_path');

            $urlPersist->replace($rewriteGenerator->generate($category));

            $after = $snapshot($entityId, $storeId);

            if ($apply) {
                $connection->commit();
            } else {
                $connection->rollBack();
            }

            $stats[$code]['categories']++;
            $stats[$code]['rewrites'] += count($after);
            if ($after !== $before) {
                $stats[$code]['changed']++;
            }
        } catch (\Throwable $e) {
            $connection->rollBack();
            $failures["{$code}/{$entityId}"] = $e->getMessage();
            $stats[$code]['failed']++;
        }
    }
}

/* -------------------------------------------------------------- report -- */

$out   = [];
$out[] = '=== category url regeneration (Magento core generators) ===';
$out[] = 'mode: ' . ($apply ? 'APPLY' : 'DRY RUN (each category rolled back)');
$out[] = '';
$out[] = sprintf('  %-8s %-12s %-10s %-10s %s', 'store', 'categories', 'changed', 'rewrites', 'failed');
foreach ($stats as $code => $c) {
    $out[] = sprintf(
        '  %-8s %-12d %-10d %-10d %d',
        $code,
        $c['categories'],
        $c['changed'],
        $c['rewrites'],
        $c['failed']
    );
}
$out[] = '';
$out[] = '--- failures (' . count($failures) . ') ---';
if (!$failures) {
    $out[] = '  (none)';
} else {
    foreach (array_slice($failures, 0, 30, true) as $k => $msg) {
        $out[] = "  {$k}: {$msg}";
    }
    if (count($failures) > 30) {
        $out[] = '  ... ' . (count($failures) - 30) . ' more';
    }
}

$report = implode("\n", $out) . "\n";
fwrite(STDOUT, $report);
@file_put_contents(BP . '/var/log/category_url_regen_report.txt', $report);

fwrite(STDOUT, $apply
    ? "\nDone. Now run:\n  bin/magento cache:flush\n"
    : "\nDRY RUN. Re-run with --apply to write.\n");
