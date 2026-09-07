<?php
/**
 * Assign every product to an MSI source with a fixed quantity, in stock.
 *
 * Defaults to source "hamburg", quantity 1, status In Stock. For each product
 * SKU it upserts one inventory_source_item row (create if missing, overwrite
 * qty/status if present) through Magento's own MSI service, so the stock index
 * and reservations stay consistent -- no raw SQL into inventory_source_item.
 *
 * Save goes through SourceItemsSaveInterface, which runs the source-item
 * validation and fires the same events the admin grid save does.
 *
 * Usage (from the Magento root):
 *   php developer/scripts/assign_hamburg_source.php                 # dry-run (default)
 *   php developer/scripts/assign_hamburg_source.php --apply         # write
 *   php developer/scripts/assign_hamburg_source.php --qty=5 --apply
 *   php developer/scripts/assign_hamburg_source.php --source=tokyo --apply
 *   php developer/scripts/assign_hamburg_source.php --skus=EPR1006,EPR1005 --apply
 *
 * --dry-run always wins over --apply (safe default).
 *
 * Silence vendor deprecation noise:
 *   php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_NOTICE" \
 *     developer/scripts/assign_hamburg_source.php 2>/dev/null
 */

declare(strict_types=1);

use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\InventoryApi\Api\Data\SourceItemInterface;

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

$apply      = $flag('--apply') && !$flag('--dry-run');
$sourceCode = trim($value('--source', 'hamburg'));
$qty        = (float) $value('--qty', '1');
$batchSize  = max(1, (int) $value('--batch', '500'));
$skuFilter  = array_filter(array_map('trim', explode(',', $value('--skus'))));

/* ----------------------------------------------------------- bootstrap -- */

$bootstrapPath = null;
foreach ([
    __DIR__ . '/../../app/bootstrap.php',
    __DIR__ . '/../app/bootstrap.php',
    __DIR__ . '/bootstrap.php',
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

/** @var \Magento\Framework\App\ResourceConnection $resource */
$resource   = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

/* ---------------------------------------------- verify the source exists -- */

$sourceExists = (bool) $connection->fetchOne(
    $connection->select()
        ->from($resource->getTableName('inventory_source'), ['source_code'])
        ->where('source_code = ?', $sourceCode)
);
if (!$sourceExists) {
    $known = $connection->fetchCol(
        $connection->select()->from($resource->getTableName('inventory_source'), ['source_code'])
    );
    fwrite(STDERR, "ERROR: source '{$sourceCode}' not found. Known: " . implode(', ', $known) . "\n");
    exit(1);
}

/* ------------------------------------------------------ product sku list -- */

$skuSelect = $connection->select()
    ->from($resource->getTableName('catalog_product_entity'), ['sku'])
    ->order('sku');
if ($skuFilter) {
    $skuSelect->where('sku IN (?)', $skuFilter);
}
$skus = $connection->fetchCol($skuSelect);
if (!$skus) {
    fwrite(STDERR, "no products matched\n");
    exit(1);
}

/* -------------------------------------------- current rows for this source -- */

// sku => [qty, status] already on this source, to classify create vs update.
$current = [];
foreach ($connection->fetchAll(
    $connection->select()
        ->from(
            $resource->getTableName('inventory_source_item'),
            ['sku', 'quantity', 'status']
        )
        ->where('source_code = ?', $sourceCode)
) as $row) {
    $current[(string) $row['sku']] = [(float) $row['quantity'], (int) $row['status']];
}

$toCreate = [];   // sku not on source yet
$toUpdate = [];   // sku on source but qty/status differs
$noChange = [];   // already exactly qty + in-stock
foreach ($skus as $sku) {
    $sku = (string) $sku;
    if (!isset($current[$sku])) {
        $toCreate[] = $sku;
    } elseif ($current[$sku][0] !== $qty || $current[$sku][1] !== SourceItemInterface::STATUS_IN_STOCK) {
        $toUpdate[] = $sku;
    } else {
        $noChange[] = $sku;
    }
}

$targets = array_merge($toCreate, $toUpdate);

fwrite(STDOUT, sprintf(
    "=== assign source '%s' qty=%s status=IN_STOCK ===\n" .
    "mode:      %s\n" .
    "products:  %d matched\n" .
    "  create:  %d\n" .
    "  update:  %d\n" .
    "  no-op:   %d (already qty=%s in stock)\n",
    $sourceCode,
    rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.'),
    $apply ? 'APPLY' : 'DRY RUN',
    count($skus),
    count($toCreate),
    count($toUpdate),
    count($noChange),
    rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.')
));

if (!$targets) {
    fwrite(STDOUT, "\nNothing to write.\n");
    exit(0);
}

if (!$apply) {
    fwrite(STDOUT, "\nDRY RUN. Re-run with --apply to write " . count($targets) . " row(s).\n");
    exit(0);
}

/* -------------------------------------------------------------- write -- */

/** @var \Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory $sourceItemFactory */
$sourceItemFactory = $om->get(\Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory::class);
/** @var \Magento\InventoryApi\Api\SourceItemsSaveInterface $sourceItemsSave */
$sourceItemsSave = $om->get(\Magento\InventoryApi\Api\SourceItemsSaveInterface::class);

$saved   = 0;
$failed  = 0;
$errors  = [];

foreach (array_chunk($targets, $batchSize) as $chunk) {
    $items = [];
    foreach ($chunk as $sku) {
        /** @var SourceItemInterface $item */
        $item = $sourceItemFactory->create();
        $item->setSourceCode($sourceCode);
        $item->setSku($sku);
        $item->setQuantity($qty);
        $item->setStatus(SourceItemInterface::STATUS_IN_STOCK);
        $items[] = $item;
    }

    try {
        $sourceItemsSave->execute($items);
        $saved += count($items);
    } catch (\Throwable $e) {
        // fall back to per-sku so one bad sku doesn't sink the whole chunk
        foreach ($items as $item) {
            try {
                $sourceItemsSave->execute([$item]);
                $saved++;
            } catch (\Throwable $inner) {
                $failed++;
                $errors[$item->getSku()] = $inner->getMessage();
            }
        }
    }

    fwrite(STDOUT, sprintf("  ... %d/%d saved\n", $saved, count($targets)));
}

/* -------------------------------------------------------------- report -- */

fwrite(STDOUT, sprintf("\nDone. %d saved, %d failed.\n", $saved, $failed));
if ($errors) {
    fwrite(STDOUT, "--- failures (" . count($errors) . ") ---\n");
    foreach (array_slice($errors, 0, 30, true) as $sku => $msg) {
        fwrite(STDOUT, "  {$sku}: {$msg}\n");
    }
    if (count($errors) > 30) {
        fwrite(STDOUT, '  ... ' . (count($errors) - 30) . " more\n");
    }
}

fwrite(STDOUT, "\nNow reindex stock so the storefront/salable qty updates:\n" .
    "  bin/magento indexer:reindex inventory\n" .
    "  bin/magento cache:flush\n");
