<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Console\Command\AttributeOverride;

use CarpetAvenue\Utilities\Service\AttributeOverride\SnapshotService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:attribute-override:snapshot
 *
 * Reads current live attribute values and saves them as the intended baseline
 * in carpetavenue_attribute_override_value (upsert — existing rows are updated).
 *
 * Run once after confirming all store-view values are correct.
 * After snapshot, use detect to monitor and fix deviations.
 *
 * Examples:
 *   # Dry-run: show what would be snapshotted
 *   bin/magento carpetavenue:attribute-override:snapshot --dry-run
 *
 *   # Snapshot price attributes on store 2 only
 *   bin/magento carpetavenue:attribute-override:snapshot --type=price --store=2
 *
 *   # Full snapshot (all configured types, all stores)
 *   bin/magento carpetavenue:attribute-override:snapshot
 */
class SnapshotCommand extends Command
{
    public function __construct(
        private readonly SnapshotService $snapshotService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:attribute-override:snapshot')
            ->setDescription('Capture current live attribute values as the intended baseline (upserts carpetavenue_attribute_override_value).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,    'Show what would be captured. No writes.')
            ->addOption('type',    null, InputOption::VALUE_OPTIONAL, 'Limit to: price, image, or custom. Default: all.', '')
            ->addOption('store',   null, InputOption::VALUE_OPTIONAL, 'Comma-separated store view IDs. Default: all.', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun   = (bool) $input->getOption('dry-run');
        $typeOpt  = trim((string) $input->getOption('type'));
        $storeOpt = trim((string) $input->getOption('store'));

        $types    = $typeOpt  !== '' ? array_map('trim', explode(',', $typeOpt))   : [];
        $storeIds = $storeOpt !== '' ? array_map('intval', explode(',', $storeOpt)) : [];

        if ($dryRun) {
            $output->writeln('<comment>[DRY RUN] No writes will be performed.</comment>');
        }

        $output->writeln('Taking snapshot...');

        $counts = $this->snapshotService->snapshot($types, $storeIds, $dryRun);

        $total = array_sum($counts);

        if ($total === 0) {
            $output->writeln('<comment>No rows found for configured attributes/stores. Check Override settings in admin.</comment>');
            return Command::SUCCESS;
        }

        $verb = $dryRun ? 'Would upsert' : 'Upserted';
        $output->writeln("<info>{$verb} {$total} intended value row(s):</info>");

        foreach ($counts as $type => $count) {
            if ($count > 0) {
                $output->writeln("  {$type}: {$count}");
            }
        }

        if (!$dryRun) {
            $output->writeln('<info>Snapshot complete. Run detect to verify.</info>');
        }

        return Command::SUCCESS;
    }
}
