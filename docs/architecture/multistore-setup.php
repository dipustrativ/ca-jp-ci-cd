<?php
/**
 * Multistore setup — path-based store views via Magento's native web/url/use_store.
 *
 * Environment-agnostic and idempotent: safe to run on a fresh local checkout, on
 * another developer's machine, or in production. It never writes a hostname, so
 * it does NOT need to know the domain — base URLs stay untouched at default
 * scope and Magento appends the store code at runtime.
 *
 * Run from the Magento root:
 *
 *     php docs/architecture/multistore-setup.php              # dry run — prints the plan, changes nothing
 *     php docs/architecture/multistore-setup.php --apply      # actually apply
 *
 * Local (docker-magento):
 *     docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php docs/architecture/multistore-setup.php --apply"
 *
 * What it does, in order:
 *   1. Deletes store-scope web/*url* overrides   (they would produce /de/de/)
 *   2. Deletes orphan eav_attribute_label rows   (FK violation blocks step 4)
 *   3. Sets web/url/use_store = 1
 *   4. Sets general/locale/code per store view
 *   5. Enables catalog canonical tags
 *   6. Flushes the cache
 *
 * See docs/architecture/multistore.md for the reasoning behind each step.
 */

declare(strict_types=1);

/**
 * Store view code => locale. Extend as store views are added.
 * The store view must already exist (Admin -> Stores -> All Stores -> Create
 * Store View); Magento core has no store:create CLI. Codes absent from the DB
 * are reported and skipped, not created.
 */
const LOCALES = [
    'de' => 'de_DE',
    'se' => 'sv_SE',
];

/**
 * Storefront SEO. Both default to 0 in Magento. With use_store=1 the default
 * store view's content is reachable both bare (/all-carpets) and prefixed
 * (/en/all-carpets), so canonicals are what tells crawlers which one wins.
 * Set to false to skip if the SEO change is not wanted.
 */
const ENABLE_CANONICALS = true;

// Locate the Magento root by walking up from this file, so the script runs
// regardless of where it is placed or which directory it is invoked from.
$bootstrapFile = null;
for ($dir = __DIR__, $i = 0; $i < 5; $i++, $dir = dirname($dir)) {
    if (is_file($dir . '/app/bootstrap.php')) {
        $bootstrapFile = $dir . '/app/bootstrap.php';
        break;
    }
}
if ($bootstrapFile === null) {
    fwrite(STDERR, "Could not locate app/bootstrap.php — run this from inside the Magento installation.\n");
    exit(1);
}
require $bootstrapFile;

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Cache\Manager as CacheManager;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Model\ScopeInterface;

$apply = in_array('--apply', $argv, true);

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(State::class)->setAreaCode('adminhtml');

$resource   = $om->get(ResourceConnection::class);
$conn       = $resource->getConnection();
$config     = $resource->getTableName('core_config_data');
$labels     = $resource->getTableName('eav_attribute_label');
$storeTable = $resource->getTableName('store');
$writer     = $om->get(WriterInterface::class);
$storeRepo  = $om->get(StoreRepositoryInterface::class);

$mode = $apply ? 'APPLY' : 'DRY RUN — nothing will be changed';
echo "\n=== Multistore setup [$mode] ===\n\n";

$changes = 0;

// ---------------------------------------------------------------------------
// 1. Store-scope URL overrides.
//    Magento appends the store code to link-type URLs at runtime. A store-scope
//    base_url or base_link_url of https://host/de/ therefore yields /de/de/.
//    base_link_url is a separate config path from base_url and is the one
//    usually missed.
// ---------------------------------------------------------------------------
$urlRows = $conn->fetchAll(
    "SELECT config_id, scope, scope_id, path, value FROM {$config}
      WHERE scope = 'stores' AND path LIKE 'web/%url%'"
);
echo "1. Store-scope web/*url* overrides: " . count($urlRows) . " row(s)\n";
foreach ($urlRows as $r) {
    echo "     - scope_id={$r['scope_id']}  {$r['path']} = {$r['value']}\n";
}
if ($urlRows && $apply) {
    $n = $conn->delete($config, ["scope = 'stores'", "path LIKE 'web/%url%'"]);
    echo "   deleted $n row(s)\n";
    $changes += $n;
} elseif ($urlRows) {
    echo "   would delete " . count($urlRows) . " row(s)\n";
}

// ---------------------------------------------------------------------------
// 2. Orphan attribute labels.
//    A DB imported with FK checks disabled can retain eav_attribute_label rows
//    for store IDs that no longer exist. Any save to the `catalog` config
//    section fires SwitchPriceAttributeScopeOnConfigChange -> attribute save ->
//    rewrites store labels including the dead IDs -> FK violation. That blocks
//    every catalog config change, admin included.
// ---------------------------------------------------------------------------
$orphans = $conn->fetchAll(
    "SELECT DISTINCT store_id FROM {$labels}
      WHERE store_id NOT IN (SELECT store_id FROM {$storeTable})"
);
$orphanIds = array_column($orphans, 'store_id');
echo "\n2. Orphan eav_attribute_label store IDs: "
    . ($orphanIds ? implode(', ', $orphanIds) : 'none') . "\n";
if ($orphanIds && $apply) {
    $n = $conn->delete($labels, "store_id NOT IN (SELECT store_id FROM {$storeTable})");
    echo "   deleted $n row(s)\n";
    $changes += $n;
} elseif ($orphanIds) {
    echo "   would delete orphan rows\n";
}

// ---------------------------------------------------------------------------
// 3-5. Config. Written via WriterInterface rather than the config:set command,
//      so no observers fire and step 5 cannot trip the FK issue above.
// ---------------------------------------------------------------------------
$settings = [['web/url/use_store', '1', 'default', 0]];

if (ENABLE_CANONICALS) {
    $settings[] = ['catalog/seo/category_canonical_tag', '1', 'default', 0];
    $settings[] = ['catalog/seo/product_canonical_tag', '1', 'default', 0];
}

echo "\n3. Store views and locales\n";
foreach (LOCALES as $code => $locale) {
    try {
        $store = $storeRepo->get($code);
    } catch (\Magento\Framework\Exception\NoSuchEntityException) {
        echo "     ! store view '$code' does not exist — create it in Admin first, skipping\n";
        continue;
    }
    echo "     - $code (store_id {$store->getId()}) -> $locale\n";
    $settings[] = ['general/locale/code', $locale, ScopeInterface::SCOPE_STORES, (int)$store->getId()];
}

echo "\n4. Config values\n";
foreach ($settings as [$path, $value, $scope, $scopeId]) {
    $current = $conn->fetchOne(
        "SELECT value FROM {$config} WHERE path = ? AND scope = ? AND scope_id = ?",
        [$path, $scope, $scopeId]
    );
    $state = ($current === (string)$value) ? 'ok' : ($current === false ? 'set' : "change from '$current'");
    printf("     %-38s %-8s [%s/%d] %s\n", $path, $value, $scope, $scopeId, $state);
    if ($apply && $state !== 'ok') {
        $writer->save($path, $value, $scope, $scopeId);
        $changes++;
    }
}

// ---------------------------------------------------------------------------
// 6. Cache.
// ---------------------------------------------------------------------------
if ($apply) {
    $cacheManager = $om->get(CacheManager::class);
    $cacheManager->flush($cacheManager->getAvailableTypes());
    echo "\n5. Cache flushed.\n";
    echo "\nDone — $changes change(s) applied.\n";
    echo "Production mode also needs:\n";
    echo "  bin/magento setup:static-content:deploy en_US " . implode(' ', LOCALES) . " -f\n";
} else {
    echo "\nDry run complete. Re-run with --apply to write these changes.\n";
}

// ---------------------------------------------------------------------------
// Verification summary.
// ---------------------------------------------------------------------------
echo "\n=== Current state ===\n";
$base = $conn->fetchOne(
    "SELECT value FROM {$config} WHERE path = 'web/secure/base_url' AND scope = 'default'"
);
echo "base_url (default scope): " . ($base ?: '(unset — inherits config.xml)') . "\n";
echo "store views: ";
foreach ($storeRepo->getList() as $s) {
    if ((int)$s->getId() === 0) {
        continue;
    }
    echo $s->getCode() . ' ';
}
echo "\n\nVerify with (substitute the environment's own host):\n";
echo "  for u in \"\" en/ de/ se/ admin; do\n";
echo "    printf '%-10s ' \"/\$u\"\n";
echo "    curl -sk -o /tmp/o.html -w 'HTTP %{http_code}  ' \"{$base}\$u\"\n";
echo "    grep -o 'lang=\"[a-zA-Z-]*\"' /tmp/o.html | head -1\n";
echo "  done\n\n";
