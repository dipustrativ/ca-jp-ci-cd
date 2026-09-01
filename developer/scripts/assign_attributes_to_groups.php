<?php
/**
 * Assign catalog_product attributes to attribute groups in an attribute set,
 * driven by a CSV (eag.csv).
 *
 * Standalone Magento bootstrap script — NOT a data patch, NOT auto-run.
 * Lives in developer/scripts/ and is executed by hand only.
 *
 * What it does (target attribute set defaults to id 4 — the "carpet" set):
 *   1. For every distinct attribute_group_name in the CSV, make sure a group
 *      with that NAME exists in the set. An existing group of the same name is
 *      reused (so "General" maps to the built-in General group, code
 *      product-details, instead of creating a duplicate "general" group).
 *      A name with no matching group (e.g. "Carpet") is created.
 *   2. For every CSV row, the attribute (resolved by attribute_code) is placed
 *      into its named group at the given sort_order. Carpet attributes land in
 *      the Carpet group, General attributes in the General group.
 *
 * Attributes are matched by attribute_code, NOT the CSV attribute_id (ids are
 * install-specific). An attribute code that does not exist in this install is
 * skipped and listed in the report — create it first (see
 * create_product_attributes.php).
 *
 * CSV columns (header required):
 *   attribute_group_name, attribute_id, attribute_code, frontend_label, sort_order
 *
 * Usage (run inside the phpfpm container, from the Magento root):
 *   php developer/scripts/assign_attributes_to_groups.php            # DRY RUN (default) — reports only
 *   php developer/scripts/assign_attributes_to_groups.php --dry-run  # DRY RUN (explicit)
 *   php developer/scripts/assign_attributes_to_groups.php --commit   # actually create group + assign
 *   php developer/scripts/assign_attributes_to_groups.php --commit --csv=/path/to/eag.csv --set=4
 *
 * --dry-run always wins over --commit. Default CSV path: <magento-root>/eag.csv
 */

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

/* -------------------------------------------------------------------------- */
/* CLI options                                                                */
/* -------------------------------------------------------------------------- */

$options = getopt('', ['commit', 'dry-run', 'csv:', 'set:', 'entity:']);
$dryFlag = array_key_exists('dry-run', $options);
$commit  = array_key_exists('commit', $options) && !$dryFlag;   // --dry-run wins
$dryRun  = !$commit;
$entity  = $options['entity'] ?? 'catalog_product';
$setId   = (int) ($options['set'] ?? 4);
$newGroupSortOrder = 100;   // sort order used when a missing group is created

/* -------------------------------------------------------------------------- */
/* Bootstrap Magento                                                          */
/* -------------------------------------------------------------------------- */

require __DIR__ . '/../../app/bootstrap.php';

$bootstrap     = Bootstrap::create(BP, $_SERVER);
$objectManager = $bootstrap->getObjectManager();

/** @var \Magento\Framework\App\State $state */
$state = $objectManager->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
} catch (\Throwable $e) {
    // already set
}

$csvPath = $options['csv'] ?? (BP . '/eag.csv');
if (!is_readable($csvPath)) {
    fwrite(STDERR, "CSV not found or not readable: {$csvPath}\n");
    exit(1);
}

/* -------------------------------------------------------------------------- */
/* Services                                                                   */
/* -------------------------------------------------------------------------- */

/** @var \Magento\Framework\Setup\ModuleDataSetupInterface $setup */
$setup = $objectManager->get(\Magento\Framework\Setup\ModuleDataSetupInterface::class);
/** @var \Magento\Eav\Setup\EavSetup $eavSetup */
$eavSetup = $objectManager->create(\Magento\Eav\Setup\EavSetup::class, ['setup' => $setup]);

$entityTypeId = (int) $eavSetup->getEntityTypeId($entity);
if (!$entityTypeId) {
    fwrite(STDERR, "Unknown entity type code: {$entity}\n");
    exit(1);
}

// Validate the attribute set belongs to the entity type.
$setName = $eavSetup->getAttributeSet($entityTypeId, $setId, 'attribute_set_name');
if (!$setName) {
    fwrite(STDERR, "Attribute set {$setId} not found for entity {$entity}.\n");
    exit(1);
}

/* -------------------------------------------------------------------------- */
/* Parse the CSV                                                              */
/* -------------------------------------------------------------------------- */

$rows = readCsv($csvPath);
if (!$rows) {
    fwrite(STDERR, "No data rows parsed from {$csvPath}\n");
    exit(1);
}

echo str_repeat('=', 78) . "\n";
echo ($dryRun ? 'DRY RUN' : 'COMMIT') . " — entity: {$entity} (id {$entityTypeId}) — set: {$setName} (id {$setId})\n";
echo "CSV: {$csvPath} — rows: " . count($rows) . "\n";
echo str_repeat('=', 78) . "\n";

/* -------------------------------------------------------------------------- */
/* Step 1 — resolve / create the groups named in the CSV                      */
/* -------------------------------------------------------------------------- */

$groupNames = [];
foreach ($rows as $r) {
    $name = trim((string) $r['attribute_group_name']);
    if ($name !== '') {
        $groupNames[$name] = true;
    }
}
$groupNames = array_keys($groupNames);

$groupIds     = [];               // name => group id (int) or null when pending creation in dry run
$groupsReport = ['existing' => [], 'created' => []];

foreach ($groupNames as $name) {
    $existingId = resolveGroupIdByName($setup, $setId, $name);

    if ($existingId) {
        $groupIds[$name]           = $existingId;
        $groupsReport['existing'][] = sprintf('%s (id %d)', $name, $existingId);
        printf("GROUP  %-16s exists (id %d)\n", $name, $existingId);
        continue;
    }

    // Missing -> create (commit) or note (dry run).
    if (!$dryRun) {
        $eavSetup->addAttributeGroup($entityTypeId, $setId, $name, $newGroupSortOrder);
        $groupIds[$name] = resolveGroupIdByName($setup, $setId, $name);
        printf("GROUP  %-16s CREATED (id %d)\n", $name, (int) $groupIds[$name]);
    } else {
        $groupIds[$name] = null; // pending
        printf("GROUP  %-16s will be CREATED\n", $name);
    }
    $groupsReport['created'][] = $name;
}

/* -------------------------------------------------------------------------- */
/* Step 2 — assign each attribute to its group                               */
/* -------------------------------------------------------------------------- */

$report = ['assigned' => [], 'missing_attribute' => []];

foreach ($rows as $r) {
    $code      = trim((string) $r['attribute_code']);
    $groupName = trim((string) $r['attribute_group_name']);
    $sortOrder = (int) $r['sort_order'];

    if ($code === '' || $groupName === '') {
        continue;
    }

    $attributeId = (int) $eavSetup->getAttributeId($entityTypeId, $code);
    if (!$attributeId) {
        $report['missing_attribute'][] = sprintf('%s (-> %s)', $code, $groupName);
        printf("SKIP   %-32s attribute code not found\n", $code);
        continue;
    }

    $groupId = $groupIds[$groupName] ?? null;

    if (!$dryRun) {
        // addAttributeToGroup writes the eav_entity_attribute link (set+group+attr).
        $eavSetup->addAttributeToGroup($entityTypeId, $setId, $groupId, $attributeId, $sortOrder);
        printf("ASSIGN %-32s -> %-16s (sort %d)\n", $code, $groupName, $sortOrder);
    } else {
        printf("ASSIGN %-32s -> %-16s (sort %d) [pending]\n", $code, $groupName, $sortOrder);
    }
    $report['assigned'][] = sprintf('%s -> %s (sort %d)', $code, $groupName, $sortOrder);
}

/* -------------------------------------------------------------------------- */
/* Summary                                                                    */
/* -------------------------------------------------------------------------- */

echo "\n" . str_repeat('-', 78) . "\n";
echo 'SUMMARY' . ($dryRun ? ' (dry run — nothing written)' : '') . "\n";
echo str_repeat('-', 78) . "\n";
printf("  groups existing        : %d\n", count($groupsReport['existing']));
printf("  groups created         : %d\n", count($groupsReport['created']));
printf("  attributes assigned    : %d\n", count($report['assigned']));
printf("  attributes not found   : %d\n", count($report['missing_attribute']));

// Dry run only: full breakdown for review before committing.
if ($dryRun) {
    printBucket('GROUPS — existing (reused)', $groupsReport['existing']);
    printBucket('GROUPS — will be created', $groupsReport['created']);
    printBucket('WILL ASSIGN (attribute -> group)', $report['assigned']);
    printBucket('SKIPPED — attribute code not found (create it first)', $report['missing_attribute']);

    echo "\nRe-run with --commit to apply. Nothing above has been written.\n";
}

echo "\nDone.\n";

/* ========================================================================== */
/* Helpers                                                                    */
/* ========================================================================== */

/**
 * Resolve an existing attribute group id by its EXACT display name within a set.
 *
 * Done with a direct query on purpose: EavSetup::getAttributeGroupId() falls
 * back to the set's default group when the name is unknown (so a missing
 * "Carpet" would silently resolve to "General"), and getAttributeGroup()
 * matches by slugified code, not display name. Returns null when absent.
 */
function resolveGroupIdByName(
    \Magento\Framework\Setup\ModuleDataSetupInterface $setup,
    int $setId,
    string $name
): ?int {
    $conn   = $setup->getConnection();
    $table  = $setup->getTable('eav_attribute_group');
    $select = $conn->select()
        ->from($table, 'attribute_group_id')
        ->where('attribute_set_id = ?', $setId)
        ->where('attribute_group_name = ?', $name)
        ->limit(1);
    $id = $conn->fetchOne($select);

    return $id ? (int) $id : null;
}

/**
 * Read a headered CSV into associative rows.
 *
 * @return array<int,array<string,string>>
 */
function readCsv(string $path): array
{
    $out    = [];
    $handle = fopen($path, 'r');
    if ($handle === false) {
        return $out;
    }
    $header = fgetcsv($handle, 0, ',', '"', '');
    if ($header === false) {
        fclose($handle);
        return $out;
    }
    $header = array_map(static fn ($h) => trim((string) $h), $header);

    while (($data = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        if ($data === [null] || $data === false) {
            continue; // blank line
        }
        // pad/truncate to header width
        $data = array_slice(array_pad($data, count($header), ''), 0, count($header));
        $out[] = array_combine($header, $data);
    }
    fclose($handle);
    return $out;
}

/**
 * Print a titled, numbered bucket (dry-run breakdown).
 *
 * @param string   $title
 * @param string[] $items
 */
function printBucket(string $title, array $items): void
{
    echo "\n" . $title . ' (' . count($items) . "):\n";
    if (!$items) {
        echo "  (none)\n";
        return;
    }
    $i = 0;
    foreach ($items as $item) {
        printf("  %3d. %s\n", ++$i, $item);
    }
}
