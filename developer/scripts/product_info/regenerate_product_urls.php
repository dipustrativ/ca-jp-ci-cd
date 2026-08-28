<?php
/**
 * Rebuild product url_rewrite rows, using Magento's own generator.
 *
 * WHY THIS EXISTS
 * There is no `catalog_url_rewrite_product` indexer. Product rewrites are
 * produced on product *save*, by ProductProcessUrlRewriteSavingObserver.
 * A raw-SQL import writes url_key without saving, so the observer never fires
 * and url_rewrite keeps the old paths.
 *
 * This calls the one core service that observer calls, per product, and
 * nothing else:
 *   AppendUrlRewritesToProducts::execute([$product], $storeIds)
 *
 * That service is what handles per-store url_key overrides (translated URLs):
 * for each store it reads the store-scoped url_key, regenerates url_path, runs
 * ProductUrlRewriteGenerator and persists through UrlPersistInterface::replace().
 * Visibility, rewrite history and the category-path setting are core's
 * business, not this script's. url_key is the only input -- seo_category_id is
 * never read.
 *
 * The product is loaded at store 0 so the service takes its global-scope
 * branch: stores with a url_key override are generated individually, the rest
 * come from the store-0 pass over the product's store list. That covers every
 * store in one call per product.
 *
 * Usage (from the Magento root):
 *   php developer/scripts/product_info/regenerate_product_urls.php
 *   php developer/scripts/product_info/regenerate_product_urls.php --apply
 *   php developer/scripts/product_info/regenerate_product_urls.php --skus=EPR1006,EPR1005
 *
 * ONE TRANSACTION PER PRODUCT, committed only with --apply. Per product, not
 * per run: UrlPersist::replace() opens its own transaction, and Magento's
 * adapter poisons an outer one the moment an inner rollback happens (a URL
 * clash, a deadlock retry) -- every later product would then fail too.
 * Per-product scope keeps one bad product from taking down the run.
 *
 * NOT HANDLED, on purpose
 *   A product core would give no rewrite at all (Not Visible Individually)
 *   keeps whatever rows it already has; core deletes those on save, this does
 *   not. Nothing links to them.
 *
 * Slow by design -- one product load per product. ~2k products take a few
 * minutes. Run it in a maintenance window, not on a loaded storefront.
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

$apply     = $flag('--apply') && !$flag('--dry-run');
$skuFilter = array_filter(array_map('trim', explode(',', $value('--skus'))));

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
/** @var \Magento\Catalog\Model\ProductFactory $productFactory */
$productFactory = $om->get(\Magento\Catalog\Model\ProductFactory::class);
/** @var \Magento\CatalogUrlRewrite\Model\Products\AppendUrlRewritesToProducts $appendRewrites */
$appendRewrites = $om->get(\Magento\CatalogUrlRewrite\Model\Products\AppendUrlRewritesToProducts::class);

/* ------------------------------------------------------------ entities -- */

// store_id => code
$storeCodes = [];
foreach ($connection->fetchAll(
    $connection->select()->from($resource->getTableName('store'), ['store_id', 'code'])
        ->where('store_id > 0')->order('store_id')
) as $row) {
    $storeCodes[(int) $row['store_id']] = (string) $row['code'];
}
if (!$storeCodes) {
    fwrite(STDERR, "no store views\n");
    exit(1);
}
$storeIds = array_keys($storeCodes);

$idSelect = $connection->select()
    ->from($resource->getTableName('catalog_product_entity'), ['entity_id'])
    ->order('entity_id');
if ($skuFilter) {
    $idSelect->where('sku IN (?)', $skuFilter);
}
$productIds = array_map('intval', $connection->fetchCol($idSelect));
if (!$productIds) {
    fwrite(STDERR, "no products matched\n");
    exit(1);
}

$rewriteTable = $resource->getTableName('url_rewrite');

/**
 * Every rewrite row one product owns, across all stores.
 *
 * @return array<int,string[]> store_id => comparable row strings
 */
$snapshot = static function (int $entityId) use ($connection, $rewriteTable): array {
    $out = [];
    foreach ($connection->fetchAll(
        $connection->select()
            ->from($rewriteTable, ['store_id', 'request_path', 'target_path', 'redirect_type'])
            ->where('entity_type = ?', 'product')
            ->where('entity_id = ?', $entityId)
            ->order('store_id')
            ->order('request_path')
    ) as $row) {
        $storeId = (int) $row['store_id'];
        unset($row['store_id']);
        $out[$storeId][] = implode('|', $row);
    }
    return $out;
};

/* ---------------------------------------------------------------- work -- */

$processed = 0;
$changed   = 0;
$perStore  = array_fill_keys($storeIds, 0);
$failures  = [];

foreach ($productIds as $entityId) {
    $before = $snapshot($entityId);

    $connection->beginTransaction();
    try {
        $product = $productFactory->create()->setStoreId(0)->load($entityId);
        if (!$product->getId()) {
            throw new \RuntimeException('product did not load');
        }

        $appendRewrites->execute([$product], $storeIds);

        $after = $snapshot($entityId);

        if ($apply) {
            $connection->commit();
        } else {
            $connection->rollBack();
        }

        $processed++;
        if ($after !== $before) {
            $changed++;
        }
        foreach ($storeIds as $storeId) {
            $perStore[$storeId] += count($after[$storeId] ?? []);
        }
    } catch (\Throwable $e) {
        $connection->rollBack();
        $failures[$entityId] = $e->getMessage();
    }
}

/* -------------------------------------------------------------- report -- */

$out   = [];
$out[] = '=== product url regeneration (Magento core generator) ===';
$out[] = 'mode: ' . ($apply ? 'APPLY' : 'DRY RUN (each product rolled back)');
$out[] = sprintf('products: %d processed, %d changed, %d failed, %d total',
    $processed, $changed, count($failures), count($productIds));
$out[] = '';
$out[] = sprintf('  %-8s %s', 'store', 'rewrites');
foreach ($storeIds as $storeId) {
    $out[] = sprintf('  %-8s %d', $storeCodes[$storeId], $perStore[$storeId]);
}
$out[] = '';
$out[] = '--- failures (' . count($failures) . ') ---';
if (!$failures) {
    $out[] = '  (none)';
} else {
    foreach (array_slice($failures, 0, 30, true) as $id => $msg) {
        $out[] = "  {$id}: {$msg}";
    }
    if (count($failures) > 30) {
        $out[] = '  ... ' . (count($failures) - 30) . ' more';
    }
}

$report = implode("\n", $out) . "\n";
fwrite(STDOUT, $report);
@file_put_contents(BP . '/var/log/product_url_regen_report.txt', $report);

fwrite(STDOUT, $apply
    ? "\nDone. Now run:\n  bin/magento cache:flush\n"
    : "\nDRY RUN. Re-run with --apply to write.\n");
