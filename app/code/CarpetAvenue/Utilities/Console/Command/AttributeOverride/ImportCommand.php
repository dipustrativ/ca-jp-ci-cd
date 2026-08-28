<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Console\Command\AttributeOverride;

use CarpetAvenue\Core\Helper\UpsertBulk;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:attribute-override:import --file=/path/to/values.csv
 *
 * Bulk-imports intended attribute override values from CSV.
 *
 * CSV format (header row required):
 *   sku,store_id,attribute_type,attribute_code,value
 *   OR
 *   entity_id,store_id,attribute_type,attribute_code,value
 *
 * attribute_type values: price | image_role | image_position | custom
 *
 * Existing rows for the same (entity_id, store_id, attribute_type, attribute_code) are updated.
 *
 * Examples:
 *   bin/magento carpetavenue:attribute-override:import --file=var/import/override_values.csv
 *   bin/magento carpetavenue:attribute-override:import --file=... --dry-run
 */
class ImportCommand extends Command
{
    private const TABLE         = 'carpetavenue_attribute_override_value';
    private const VALID_TYPES   = ['price', 'image_role', 'image_position', 'custom'];
    private const BATCH_SIZE    = 500;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly UpsertBulk $upsertBulk,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:attribute-override:import')
            ->setDescription('Import intended attribute override values from CSV into carpetavenue_attribute_override_value.')
            ->addOption('file',    'f', InputOption::VALUE_REQUIRED, 'Path to CSV file.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,    'Validate CSV and show row count. No writes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file   = trim((string) $input->getOption('file'));
        $dryRun = (bool) $input->getOption('dry-run');

        if ($file === '' || !file_exists($file)) {
            $output->writeln('<error>--file is required and must point to an existing CSV file.</error>');
            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<comment>[DRY RUN] No writes will be performed.</comment>');
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $output->writeln("<error>Cannot open file: {$file}</error>");
            return Command::FAILURE;
        }

        try {
            $result = $this->processFile($handle, $dryRun, $output);
        } finally {
            fclose($handle);
        }

        $verb = $dryRun ? 'Would import' : 'Imported';
        $output->writeln("<info>{$verb} {$result['imported']} row(s). Skipped: {$result['skipped']} (errors).</info>");

        return $result['skipped'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function processFile($handle, bool $dryRun, OutputInterface $output): array
    {
        // Parse header
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        if ($header === false) {
            $output->writeln('<error>CSV file is empty or unreadable.</error>');
            return ['imported' => 0, 'skipped' => 0];
        }

        $header   = array_map('trim', $header);
        $hasSkuCol = in_array('sku', $header, true);
        $hasIdCol  = in_array('entity_id', $header, true);

        if (!$hasSkuCol && !$hasIdCol) {
            $output->writeln('<error>CSV must have either "sku" or "entity_id" column.</error>');
            return ['imported' => 0, 'skipped' => 0];
        }

        $required = ['store_id', 'attribute_type', 'attribute_code', 'value'];
        $missing  = array_diff($required, $header);
        if (!empty($missing)) {
            $output->writeln('<error>CSV missing required columns: ' . implode(', ', $missing) . '</error>');
            return ['imported' => 0, 'skipped' => 0];
        }

        // Build SKU → entity_id map if needed
        $skuMap = $hasSkuCol ? $this->buildSkuMap() : [];

        $batch    = [];
        $imported = 0;
        $skipped  = 0;
        $lineNo   = 1;

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNo++;
            if (count($row) < count($header)) {
                $output->writeln("<comment>Line {$lineNo}: skipped (column count mismatch).</comment>");
                $skipped++;
                continue;
            }

            $data = array_combine($header, $row);

            // Resolve entity_id
            if ($hasIdCol) {
                $entityId = (int) $data['entity_id'];
            } else {
                $sku      = trim($data['sku']);
                $entityId = $skuMap[$sku] ?? 0;
                if ($entityId === 0) {
                    $output->writeln("<comment>Line {$lineNo}: SKU '{$sku}' not found — skipped.</comment>");
                    $skipped++;
                    continue;
                }
            }

            $attrType = trim($data['attribute_type']);
            if (!in_array($attrType, self::VALID_TYPES, true)) {
                $output->writeln("<comment>Line {$lineNo}: invalid attribute_type '{$attrType}' — skipped.</comment>");
                $skipped++;
                continue;
            }

            $batch[] = [
                'entity_id'      => $entityId,
                'store_id'       => (int) $data['store_id'],
                'attribute_type' => $attrType,
                'attribute_code' => trim($data['attribute_code']),
                'value'          => $data['value'] !== '' ? $data['value'] : null,
            ];

            $imported++;

            if (!$dryRun && count($batch) >= self::BATCH_SIZE) {
                $this->upsertBulk->upsert($batch, self::TABLE);
                $batch = [];
            }
        }

        if (!$dryRun && !empty($batch)) {
            $this->upsertBulk->upsert($batch, self::TABLE);
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    private function buildSkuMap(): array
    {
        $conn   = $this->resourceConnection->getConnection();
        $select = $conn->select()
            ->from(
                $this->resourceConnection->getTableName('catalog_product_entity'),
                ['sku', 'entity_id']
            );

        $map = [];
        foreach ($conn->fetchAll($select) as $row) {
            $map[$row['sku']] = (int) $row['entity_id'];
        }
        return $map;
    }
}
