<?php
/**
 * Sync "pricing" dropdown attribute options 1%..100%.
 *
 * Checks options 1 to 100 ("N%"), reports which exist and which are missing,
 * and (with --apply) creates the missing ones.
 *
 * Usage:
 *   php developer/scripts/pricing_options_sync.php              # dry-run: only report (default)
 *   php developer/scripts/pricing_options_sync.php --dry-run    # dry-run: only report (explicit)
 *   php developer/scripts/pricing_options_sync.php --apply      # create missing options
 *   php developer/scripts/pricing_options_sync.php --code=some_attr [--apply|--dry-run]
 *
 * Note: --dry-run always wins over --apply (safe default).
 *
 * Silence vendor deprecation noise:
 *   php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_NOTICE" developer/scripts/pricing_options_sync.php 2>/dev/null
 */

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Area;

$bootstrapPath = null;
foreach ([__DIR__ . '/../../app/bootstrap.php', __DIR__ . '/../app/bootstrap.php', __DIR__ . '/bootstrap.php'] as $p) {
    if (file_exists($p)) { $bootstrapPath = $p; break; }
}
if (!$bootstrapPath) { fwrite(STDERR, "bootstrap.php not found\n"); exit(1); }
require $bootstrapPath;

/* ---------- args ---------- */
/* accept bare flag (--apply) and valued form (--apply=true / --apply=1 / --apply=yes) */
$flag = static function (string $name, array $argv): bool {
    foreach ($argv as $a) {
        if ($a === $name) { return true; }                 // bare flag
        if (str_starts_with($a, $name . '=')) {            // valued flag
            $v = strtolower(substr($a, strlen($name) + 1));
            return in_array($v, ['1', 'true', 'yes', 'y', 'on', ''], true);
        }
    }
    return false;
};
$dryRun    = $flag('--dry-run', $argv);
$apply     = $flag('--apply', $argv) && !$dryRun;   // --dry-run always wins
$attrCode  = 'pricing';
$rangeFrom = 1;
$rangeTo   = 100;
$suffix    = '%';
foreach ($argv as $a) {
    if (str_starts_with($a, '--code='))   { $attrCode  = substr($a, 7); }
    if (str_starts_with($a, '--from='))   { $rangeFrom = (int)substr($a, 7); }
    if (str_starts_with($a, '--to='))     { $rangeTo   = (int)substr($a, 5); }
    if (str_starts_with($a, '--suffix=')) { $suffix    = substr($a, 9); }
}

/* ---------- bootstrap ---------- */
$bootstrap = Bootstrap::create(BP, $_SERVER);
/** @var \Magento\Framework\ObjectManagerInterface $om */
$om = $bootstrap->getObjectManager();

/** @var \Magento\Framework\App\State $state */
$state = $om->get(\Magento\Framework\App\State::class);
try { $state->setAreaCode(Area::AREA_ADMINHTML); } catch (\Throwable $e) {}

/** @var \Magento\Eav\Model\Config $eavConfig */
$eavConfig = $om->get(\Magento\Eav\Model\Config::class);

$attribute = $eavConfig->getAttribute(\Magento\Catalog\Model\Product::ENTITY, $attrCode);
if (!$attribute || !$attribute->getId()) {
    fwrite(STDOUT, "ERROR: attribute '{$attrCode}' not found on catalog_product.\n");
    exit(1);
}

$attrId    = (int)$attribute->getId();
$frontLabel = (string)$attribute->getDefaultFrontendLabel();
$inputType  = (string)$attribute->getFrontendInput();

fwrite(STDOUT, "Attribute: {$attrCode} (id={$attrId}, label='{$frontLabel}', input={$inputType})\n");
if (!in_array($inputType, ['select', 'multiselect'], true)) {
    fwrite(STDOUT, "WARNING: attribute input '{$inputType}' is not a dropdown. Continuing anyway.\n");
}

/* ---------- existing options ---------- */
/** @var \Magento\Eav\Api\AttributeOptionManagementInterface $optionMgmt */
$optionMgmt = $om->get(\Magento\Eav\Api\AttributeOptionManagementInterface::class);
$options = $optionMgmt->getItems(\Magento\Catalog\Model\Product::ENTITY, $attrCode);

$existingLabels = [];
foreach ($options as $opt) {
    $lbl = trim((string)$opt->getLabel());
    if ($lbl === '') { continue; }
    $existingLabels[$lbl] = (string)$opt->getValue();
}

/* normalize helper: strip spaces so "5 %" == "5%" for compare */
$norm = static fn(string $s): string => str_replace(' ', '', strtolower($s));
$existingNorm = [];
foreach ($existingLabels as $lbl => $val) { $existingNorm[$norm($lbl)] = $lbl; }

/* ---------- diff 1..100 ---------- */
$exist   = [];
$missing = [];
for ($i = $rangeFrom; $i <= $rangeTo; $i++) {
    $label = $i . $suffix;                    // "5%"
    if (isset($existingNorm[$norm($label)])) {
        $exist[] = $i;
    } else {
        $missing[] = $i;
    }
}

$fmt = static fn(array $nums, string $sfx): string =>
$nums ? implode(', ', array_map(static fn($n) => $n . $sfx, $nums)) : '(none)';

fwrite(STDOUT, "\n=== ALREADY EXISTS (" . count($exist) . ") ===\n" . $fmt($exist, $suffix) . "\n");
fwrite(STDOUT, "\n=== MISSING (" . count($missing) . ") ===\n" . $fmt($missing, $suffix) . "\n");

if (!$missing) {
    fwrite(STDOUT, "\nNothing to create. All {$rangeFrom}..{$rangeTo} present.\n");
    exit(0);
}

if (!$apply) {
    fwrite(STDOUT, "\nDRY-RUN. Re-run with --apply to create the " . count($missing) . " missing option(s).\n");
    exit(0);
}

/* ---------- create missing ---------- */
/** @var \Magento\Eav\Setup\EavSetupFactory $eavSetupFactory */
$eavSetupFactory = $om->get(\Magento\Eav\Setup\EavSetupFactory::class);
$eavSetup = $eavSetupFactory->create();

$values = [];
foreach ($missing as $i) { $values['pricing_' . $i] = $i . $suffix; }

$eavSetup->addAttributeOption([
    'attribute_id' => $attrId,
    'values'       => $values,
]);

/* clear eav cache so new options show immediately */
$eavConfig->clear();

fwrite(STDOUT, "\n=== CREATED (" . count($missing) . ") ===\n" . $fmt($missing, $suffix) . "\n");
fwrite(STDOUT, "\nDone. Run: bin/magento cache:clean eav config; bin/magento indexer:reindex\n");
