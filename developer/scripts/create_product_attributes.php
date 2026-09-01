<?php
/**
 * Create catalog_product EAV attributes from an eav_attribute.sql dump.
 *
 * Standalone Magento bootstrap script — NOT a data patch, NOT auto-run.
 * Lives in developer/scripts/ and is executed by hand only.
 *
 * Behaviour per attribute row in the dump:
 *   - Any referenced model class (attribute_model / backend_model /
 *     frontend_model / source_model) that does NOT exist in this install
 *     => the attribute is SKIPPED and every missing class is collected into
 *        a report printed at the end.
 *   - Attribute code already exists AND its type matches
 *     (same backend_type AND same frontend_input) => SKIP (left untouched).
 *   - Attribute code exists but the type differs (backend_type or
 *     frontend_input) => the existing attribute is DELETED, then recreated
 *     from the dump.
 *   - Attribute code does not exist => created.
 *
 * The dump only carries the eav_attribute row — it does NOT carry select /
 * multiselect option values (those live in eav_attribute_option) nor the
 * catalog_eav_attribute scope row. Options are therefore NOT created here;
 * EavSetup applies its own catalog defaults for the scope row.
 *
 * Usage (run inside the phpfpm container, from the Magento root):
 *   php developer/scripts/create_product_attributes.php            # DRY RUN (default) — reports only
 *   php developer/scripts/create_product_attributes.php --dry-run  # DRY RUN (explicit) — reports only
 *   php developer/scripts/create_product_attributes.php --commit   # actually create/delete
 *   php developer/scripts/create_product_attributes.php --commit --sql=/path/to/eav_attribute.sql
 *
 * --dry-run always wins over --commit, so pairing them stays safe.
 *
 * Default SQL path: <magento-root>/eav_attribute.sql
 */

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

/* -------------------------------------------------------------------------- */
/* CLI options                                                                */
/* -------------------------------------------------------------------------- */

$options  = getopt('', ['commit', 'dry-run', 'sql:', 'entity:']);
$dryFlag  = array_key_exists('dry-run', $options);         // explicit dry run
$commit   = array_key_exists('commit', $options) && !$dryFlag; // --dry-run always wins
$dryRun   = !$commit;                                      // default: dry run
$entity   = $options['entity'] ?? 'catalog_product';       // target entity type code

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
    // area already set — ignore
}

$sqlPath = $options['sql'] ?? (BP . '/eav_attribute.sql');
if (!is_readable($sqlPath)) {
    fwrite(STDERR, "SQL dump not found or not readable: {$sqlPath}\n");
    exit(1);
}

/* -------------------------------------------------------------------------- */
/* Parse the dump                                                             */
/* -------------------------------------------------------------------------- */

// Column order of the INSERT in the dump. Keep in sync with the dump header.
$columns = [
    'attribute_id', 'entity_type_id', 'attribute_code', 'attribute_model',
    'backend_model', 'backend_type', 'backend_table', 'frontend_model',
    'frontend_input', 'frontend_label', 'frontend_class', 'source_model',
    'is_required', 'is_user_defined', 'default_value', 'is_unique', 'note',
];

$rows = parseEavAttributeDump(file_get_contents($sqlPath), $columns);
if (!$rows) {
    fwrite(STDERR, "No INSERT rows parsed from {$sqlPath}\n");
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

/* -------------------------------------------------------------------------- */
/* Work through the rows                                                      */
/* -------------------------------------------------------------------------- */

$modelColumns = ['attribute_model', 'backend_model', 'frontend_model', 'source_model'];

$missingClasses = []; // class => [attribute_code, ...]
$report = ['created' => [], 'recreated' => [], 'skipped_same' => [], 'skipped_missing_class' => []];

echo str_repeat('=', 78) . "\n";
echo ($dryRun ? 'DRY RUN' : 'COMMIT') . " — entity: {$entity} (id {$entityTypeId}) — rows: " . count($rows) . "\n";
echo str_repeat('=', 78) . "\n";

foreach ($rows as $row) {
    $code = (string) $row['attribute_code'];
    if ($code === '') {
        continue;
    }

    // 1) Referenced classes must all exist, otherwise skip + record.
    $rowMissing = [];
    foreach ($modelColumns as $col) {
        $class = $row[$col];
        if ($class !== null && $class !== '' && !class_exists($class)) {
            $rowMissing[]                 = $class;
            $missingClasses[$class][]     = $code;
        }
    }
    if ($rowMissing) {
        $report['skipped_missing_class'][] = $code;
        printf("SKIP  %-32s missing class: %s\n", $code, implode(', ', array_unique($rowMissing)));
        continue;
    }

    // 2) Look at the existing attribute (direct DB read, no EAV cache).
    $existing      = $eavSetup->getAttribute($entityTypeId, $code); // array|false
    $newBackend    = (string) ($row['backend_type'] ?? '');
    $newInput      = (string) ($row['frontend_input'] ?? '');

    if (is_array($existing) && !empty($existing['attribute_id'])) {
        $curBackend = (string) ($existing['backend_type'] ?? '');
        $curInput   = (string) ($existing['frontend_input'] ?? '');

        // "type" = backend_type + frontend_input. Same => leave it alone.
        if ($curBackend === $newBackend && $curInput === $newInput) {
            $report['skipped_same'][] = sprintf('%s (%s/%s)', $code, $curBackend, $curInput);
            printf("SKIP  %-32s exists, same type (%s/%s)\n", $code, $curBackend, $curInput);
            continue;
        }

        // Type differs => delete then recreate.
        printf(
            "DIFF  %-32s type %s/%s -> %s/%s : delete + recreate\n",
            $code, $curBackend, $curInput, $newBackend, $newInput
        );
        if (!$dryRun) {
            $eavSetup->removeAttribute($entityTypeId, $code);
            $eavSetup->addAttribute($entityTypeId, $code, buildAttributeData($row));
        }
        $report['recreated'][] = sprintf('%s (%s/%s -> %s/%s)', $code, $curBackend, $curInput, $newBackend, $newInput);
        continue;
    }

    // 3) Does not exist => create.
    printf("NEW   %-32s create (%s/%s)\n", $code, $newBackend, $newInput);
    if (!$dryRun) {
        $eavSetup->addAttribute($entityTypeId, $code, buildAttributeData($row));
    }
    $report['created'][] = $code;
}

/* -------------------------------------------------------------------------- */
/* Summary                                                                    */
/* -------------------------------------------------------------------------- */

echo "\n" . str_repeat('-', 78) . "\n";
echo "SUMMARY" . ($dryRun ? ' (dry run — nothing written)' : '') . "\n";
echo str_repeat('-', 78) . "\n";
printf("  created                : %d\n", count($report['created']));
printf("  recreated (type diff)  : %d\n", count($report['recreated']));
printf("  skipped (same type)    : %d\n", count($report['skipped_same']));
printf("  skipped (missing class): %d\n", count($report['skipped_missing_class']));

if ($missingClasses) {
    echo "\nMISSING REFERENCED CLASSES (attribute skipped):\n";
    foreach ($missingClasses as $class => $codes) {
        printf("  %s\n      used by: %s\n", $class, implode(', ', array_unique($codes)));
    }
}

// Dry run only: spell out every bucket so the plan can be reviewed before
// committing. On --commit this detail is omitted (the per-line log above and
// the counts are enough).
if ($dryRun) {
    printBucket('WILL CREATE (new attribute)', $report['created']);
    printBucket('WILL RECREATE (code exists, type differs -> delete + insert)', $report['recreated']);
    printBucket('SKIPPED — already exists with same type (backend_type/frontend_input)', $report['skipped_same']);
    printBucket('SKIPPED — referenced model class not found', $report['skipped_missing_class']);

    echo "\nRe-run with --commit to apply. Nothing above has been written.\n";
}

echo "\nDone.\n";

/* ========================================================================== */
/* Helpers                                                                    */
/* ========================================================================== */

/**
 * Print a titled, numbered bucket of attribute codes (dry-run breakdown).
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

/**
 * Map a parsed dump row to the EavSetup::addAttribute() data array.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function buildAttributeData(array $row): array
{
    return [
        'type'            => $row['backend_type']    ?: 'varchar',
        'backend'         => $row['backend_model']   ?: '',
        'frontend'        => $row['frontend_model']  ?: '',
        'input'           => $row['frontend_input']  ?: 'text',
        'label'           => $row['frontend_label']  ?: $row['attribute_code'],
        'frontend_class'  => $row['frontend_class']  ?: '',
        'source'          => $row['source_model']    ?: '',
        'attribute_model' => $row['attribute_model'] ?: '',
        'required'        => (bool) (int) $row['is_required'],
        'user_defined'    => (bool) (int) $row['is_user_defined'],
        'default'         => $row['default_value'],
        'unique'          => (bool) (int) $row['is_unique'],
        'note'            => $row['note'] ?: '',
        // Scope + visibility defaults (dump carries no catalog_eav_attribute row).
        'global'          => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
        'visible'         => true,
        'searchable'      => false,
        'filterable'      => false,
        'comparable'      => false,
        'used_in_product_listing' => false,
    ];
}

/**
 * Parse the `INSERT INTO eav_attribute (...) VALUES (...),(...);` block into
 * an array of associative rows keyed by $columns.
 *
 * Tokeniser aware of quoted strings, escaped quotes (\' \\ \") and unquoted
 * NULL, so commas inside labels like '1,999€ Exclusive' are handled.
 *
 * @param string   $sql
 * @param string[] $columns
 * @return array<int,array<string,mixed>>
 */
function parseEavAttributeDump(string $sql, array $columns): array
{
    // Isolate the VALUES payload of the eav_attribute INSERT.
    if (!preg_match('/INSERT\s+INTO\s+`?eav_attribute`?\s*\([^)]*\)\s*VALUES\s*(.+?);\s*$/ims', $sql, $m)) {
        // Fallback: everything after the first VALUES up to the next semicolon.
        if (!preg_match('/VALUES\s*(.+?);/ims', $sql, $m)) {
            return [];
        }
    }
    $payload = $m[1];

    $rows      = [];
    $current   = [];
    $buffer    = '';
    $inString  = false;
    $inRow     = false;
    $sawValue  = false; // whether the current field buffer holds anything meaningful
    $len       = strlen($payload);

    for ($i = 0; $i < $len; $i++) {
        $ch = $payload[$i];

        if ($inString) {
            if ($ch === '\\' && $i + 1 < $len) {
                // Unescape the next char.
                $next   = $payload[$i + 1];
                $map    = ['n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0"];
                $buffer .= $map[$next] ?? $next;
                $i++;
                continue;
            }
            if ($ch === "'") {
                $inString = false;
                continue;
            }
            $buffer .= $ch;
            continue;
        }

        // Not in a string:
        switch ($ch) {
            case "'":
                // Opening quote of a field. Drop any whitespace collected
                // between the delimiter and the quote (", 'value'") so the
                // string value is captured exactly.
                $buffer   = '';
                $inString = true;
                $sawValue = true;      // this field is an explicit (possibly empty) string
                break;

            case '(':
                if (!$inRow) {
                    $inRow    = true;
                    $current  = [];
                    $buffer   = '';
                    $sawValue = false;
                } else {
                    $buffer .= $ch;
                }
                break;

            case ',':
                if ($inRow) {
                    $current[] = finishField($buffer, $sawValue);
                    $buffer    = '';
                    $sawValue  = false;
                }
                break;

            case ')':
                if ($inRow) {
                    $current[] = finishField($buffer, $sawValue);
                    if (count($current) === count($columns)) {
                        $rows[] = array_combine($columns, $current);
                    }
                    $inRow    = false;
                    $buffer   = '';
                    $sawValue = false;
                }
                break;

            default:
                if ($inRow) {
                    $buffer .= $ch;
                }
        }
    }

    return $rows;
}

/**
 * Convert a raw field buffer to its typed value.
 * Unquoted NULL (case-insensitive) becomes null; quoted values stay strings.
 *
 * @return string|null
 */
function finishField(string $buffer, bool $wasQuoted): ?string
{
    if ($wasQuoted) {
        return $buffer; // explicit string (may be '')
    }
    $trimmed = trim($buffer);
    if ($trimmed === '' || strcasecmp($trimmed, 'NULL') === 0) {
        return null;
    }
    return $trimmed; // numeric literal, kept as string
}
