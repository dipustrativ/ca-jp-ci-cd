<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Order
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Console\Command;

use CarpetAvenue\ExportImport\Model\Config;
use CarpetAvenue\ExportImport\Model\Order\CsvReader;
use CarpetAvenue\ExportImport\Model\Order\Importer;
use CarpetAvenue\ExportImport\Model\Order\Validator;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: validate and import an order CSV. Validation always runs first (the manual
 * check); --validate-only stops before writing anything.
 *
 *   bin/magento exportimport:orders:import --file=var/import/orders.csv
 *   bin/magento exportimport:orders:import --file=var/import/orders.csv --validate-only
 */
class ImportOrdersCommand extends Command
{
    private const OPT_FILE = 'file';
    private const OPT_VALIDATE_ONLY = 'validate-only';

    public function __construct(
        private readonly State $state,
        private readonly Config $config,
        private readonly CsvReader $csvReader,
        private readonly Validator $validator,
        private readonly Importer $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('exportimport:orders:import')
            ->setDescription('Validate and import an order CSV (create/update by increment_id).')
            ->addOption(self::OPT_FILE, null, InputOption::VALUE_REQUIRED, 'Path to the CSV file.')
            ->addOption(self::OPT_VALIDATE_ONLY, null, InputOption::VALUE_NONE, 'Only validate; do not write any data.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode('adminhtml');
        } catch (\Exception $e) {
            // Already set — fine.
        }

        if (!$this->config->isEnabled()) {
            $output->writeln('<error>Order Export / Import is disabled in configuration.</error>');
            return Cli::RETURN_FAILURE;
        }

        $file = (string)$input->getOption(self::OPT_FILE);
        if ($file === '') {
            $output->writeln('<error>--file is required.</error>');
            return Cli::RETURN_FAILURE;
        }

        try {
            $parsed = $this->csvReader->read($file);
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not read CSV: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        // Always validate first — the manual check.
        $validation = $this->validator->validate($parsed);
        $summary = $validation->getSummary();
        $output->writeln(sprintf(
            '<info>Validation: %d order(s) [%d create / %d update], %d error(s), %d warning(s).</info>',
            $summary['orders'],
            $summary['creates'],
            $summary['updates'],
            $summary['errors'],
            $summary['warnings']
        ));

        $this->renderRows($output, 'Errors', $validation->getErrors());
        $this->renderRows($output, 'Warnings', $validation->getWarnings());

        if (!$validation->isValid()) {
            $output->writeln('<error>Validation failed — nothing imported. Fix the CSV and retry.</error>');
            return Cli::RETURN_FAILURE;
        }

        if ($input->getOption(self::OPT_VALIDATE_ONLY)) {
            $output->writeln('<comment>--validate-only set: no data written.</comment>');
            return Cli::RETURN_SUCCESS;
        }

        $result = $this->importer->import($parsed);
        $output->writeln(sprintf(
            '<info>Import: %d created, %d updated, %d failed.</info>',
            count($result['created']),
            count($result['updated']),
            count($result['failed'])
        ));

        if (!empty($result['relinked'])) {
            $output->writeln(sprintf(
                '<info>Re-linked %d item(s) to a catalog product that was missing at first import:</info>',
                array_sum($result['relinked'])
            ));
            $table = new Table($output);
            $table->setHeaders(['order', 'items linked']);
            foreach ($result['relinked'] as $inc => $count) {
                $table->addRow([$inc, $count]);
            }
            $table->render();
        }

        if (!empty($result['failed'])) {
            $table = new Table($output);
            $table->setHeaders(['order', 'reason']);
            foreach ($result['failed'] as $inc => $reason) {
                $table->addRow([$inc, $reason]);
            }
            $table->render();
            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @param array<int, array{line: int|string, column: string, message: string}> $rows
     */
    private function renderRows(OutputInterface $output, string $title, array $rows): void
    {
        if (empty($rows)) {
            return;
        }
        $output->writeln('<comment>' . $title . ':</comment>');
        $table = new Table($output);
        $table->setHeaders(['line', 'column', 'message']);
        foreach ($rows as $row) {
            $table->addRow([$row['line'], $row['column'], $row['message']]);
        }
        $table->render();
    }
}
