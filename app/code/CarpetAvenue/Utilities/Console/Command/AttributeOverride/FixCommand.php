<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Console\Command\AttributeOverride;

use CarpetAvenue\Utilities\Service\AttributeOverride\FixService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:attribute-override:fix
 *
 * Delete store-view-specific attribute overrides so Magento falls back to global (store_id=0) value.
 * Always backs up current values to CSV first (unless --dry-run or --no-backup).
 * Protected stores (configured in admin) are always excluded.
 *
 * Examples:
 *   # Dry-run: see which special_price overrides exist on store 2
 *   bin/magento carpetavenue:attribute-override:fix --type=price --attribute=special_price --store=2 --dry-run
 *
 *   # Delete special_price overrides from ALL non-protected stores (omit --store or pass --store=0)
 *   bin/magento carpetavenue:attribute-override:fix --type=price --attribute=special_price
 *
 *   # Delete image role overrides for specific SKUs on store 2
 *   bin/magento carpetavenue:attribute-override:fix --type=image --image-type=roles --store=2 --scope=sku --values=SKU001,SKU002
 *
 *   # Delete ca_material overrides from store 1 (all products)
 *   bin/magento carpetavenue:attribute-override:fix --type=custom --attribute=ca_material --store=1
 */
class FixCommand extends Command
{
    public function __construct(
        private readonly FixService $fixService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:attribute-override:fix')
            ->setDescription('Delete store-view-specific attribute overrides so Magento falls back to global value. Backs up current values to CSV first.')
            ->addOption('dry-run',    null, InputOption::VALUE_NONE,     'Show what would be deleted. No backup, no writes.')
            ->addOption('type',       null, InputOption::VALUE_REQUIRED,  'Required. One of: price, image, custom.')
            ->addOption('attribute',  null, InputOption::VALUE_OPTIONAL,  'Attribute code. Required for type=price and type=custom.', '')
            ->addOption('image-type', null, InputOption::VALUE_OPTIONAL,  'For type=image: roles, position, or all.', 'all')
            ->addOption('scope',      null, InputOption::VALUE_OPTIONAL,  'all (default), sku, or id.', 'all')
            ->addOption('values',     null, InputOption::VALUE_OPTIONAL,  'Comma-separated SKUs or entity IDs (required when scope != all).', '')
            ->addOption('store',      null, InputOption::VALUE_OPTIONAL,  'Target store view ID. 0 or omit = all non-protected stores.', '0')
            ->addOption('no-backup',  null, InputOption::VALUE_NONE,     'Skip CSV backup step. Use with caution.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun    = (bool) $input->getOption('dry-run');
        $type      = trim((string) $input->getOption('type'));
        $attribute = trim((string) $input->getOption('attribute'));
        $imageType = trim((string) $input->getOption('image-type')) ?: 'all';
        $scope     = trim((string) $input->getOption('scope')) ?: 'all';
        $valuesOpt = trim((string) $input->getOption('values'));
        $storeId   = (int) $input->getOption('store');
        $noBackup  = (bool) $input->getOption('no-backup');

        // Validation
        if (empty($type) || !in_array($type, ['price', 'image', 'custom'], true)) {
            $output->writeln('<error>--type is required and must be: price, image, or custom.</error>');
            return Command::FAILURE;
        }

        if ($type !== 'image' && empty($attribute)) {
            $output->writeln("<error>--attribute is required for type={$type}.</error>");
            return Command::FAILURE;
        }

        if (!in_array($scope, ['all', 'sku', 'id'], true)) {
            $output->writeln('<error>--scope must be: all, sku, or id.</error>');
            return Command::FAILURE;
        }

        $scopeValues = $valuesOpt !== '' ? array_map('trim', explode(',', $valuesOpt)) : [];

        if ($scope !== 'all' && empty($scopeValues)) {
            $output->writeln("<error>--values is required when --scope={$scope}.</error>");
            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<comment>[DRY RUN] No backup, no writes.</comment>');
        }

        $storeLabel = $storeId > 0 ? (string) $storeId : 'all non-protected';
        $output->writeln(sprintf(
            'Fix (delete overrides): type=<info>%s</info> attribute=<info>%s</info> store=<info>%s</info> scope=<info>%s</info>',
            $type,
            $type === 'image' ? "image-type={$imageType}" : $attribute,
            $storeLabel,
            $scope . ($scopeValues ? ':' . implode(',', array_slice($scopeValues, 0, 3)) . (count($scopeValues) > 3 ? '...' : '') : '')
        ));

        try {
            $result = $this->fixService->fix(
                $type,
                $attribute,
                $imageType,
                $scope,
                $scopeValues,
                $storeId,
                $noBackup,
                $dryRun
            );
        } catch (\Exception $e) {
            $output->writeln('<error>Fix failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($dryRun) {
            if (empty($result['preview'])) {
                $output->writeln('<info>No store-view overrides found for this scope.</info>');
            } else {
                $output->writeln('<comment>' . count($result['preview']) . ' row(s) would be deleted (will use global value):</comment>');
                $this->renderPreview($output, $result['preview']);
            }
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Done.</info> Processed: %d | Fixed: %d | Errors: %d',
            $result['processed'],
            $result['fixed'],
            $result['errors']
        ));

        if (!empty($result['csv_path'])) {
            $output->writeln('Backup CSV: <comment>' . $result['csv_path'] . '</comment>');
        }

        return $result['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function renderPreview(OutputInterface $output, array $rows): void
    {
        $table = new Table($output);
        $table->setHeaders(['entity_id', 'sku', 'store_id', 'attribute', 'current_value', 'after_fix']);

        foreach ($rows as $row) {
            $table->addRow([
                $row['entity_id'],
                $row['sku'],
                $row['store_id'],
                $row['attribute_code'],
                $this->truncate((string) ($row['current_value'] ?? '')),
                '(global)',
            ]);
        }

        $table->render();
    }

    private function truncate(string $val, int $max = 40): string
    {
        return mb_strlen($val) > $max ? mb_substr($val, 0, $max - 3) . '...' : $val;
    }
}
