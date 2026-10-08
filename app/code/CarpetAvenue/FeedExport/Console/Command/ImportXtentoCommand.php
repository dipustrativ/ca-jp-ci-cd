<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_FeedExport
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\FeedExport\Console\Command;

use CarpetAvenue\FeedExport\Model\Migration\XtentoProfileImporter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:feedexport:import-xtento --dry-run
 * bin/magento carpetavenue:feedexport:import-xtento [--keep-cron]
 */
class ImportXtentoCommand extends Command
{
    private const OPT_DRY_RUN = 'dry-run';
    private const OPT_KEEP_CRON = 'keep-cron';

    public function __construct(
        private readonly XtentoProfileImporter $importer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:feedexport:import-xtento')
            ->setDescription('Copy Xtento product export profiles into CarpetAvenue Feed Export')
            ->addOption(self::OPT_DRY_RUN, null, InputOption::VALUE_NONE, 'Only show what would be imported')
            ->addOption(
                self::OPT_KEEP_CRON,
                null,
                InputOption::VALUE_NONE,
                'Keep Xtento cron enabled flag (default: imported profiles start with cron off, '
                . 'so both modules never write the same file)'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool)$input->getOption(self::OPT_DRY_RUN);
        try {
            $report = $this->importer->import($dryRun, (bool)$input->getOption(self::OPT_KEEP_CRON));
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $table = new Table($output);
        $table->setHeaders(['Xtento ID', 'Name', 'Action', 'New ID']);
        foreach ($report as $row) {
            $table->addRow([$row['xtento_id'], $row['name'], $row['action'], $row['profile_id'] ?? '-']);
        }
        $table->render();
        if ($dryRun) {
            $output->writeln('<comment>Dry run: nothing saved.</comment>');
        }
        return Command::SUCCESS;
    }
}
