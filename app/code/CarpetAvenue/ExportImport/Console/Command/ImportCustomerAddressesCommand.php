<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Customer
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Console\Command;

use CarpetAvenue\ExportImport\Model\CustomerAddress\Importer;
use CarpetAvenue\ExportImport\Model\CustomerAddress\Validator;
use CarpetAvenue\ExportImport\Model\Config;
use CarpetAvenue\ExportImport\Model\Order\CsvReader;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: validate and import a customer-address CSV. Validation always runs first
 * (the manual check); --validate-only stops before writing anything.
 *
 *   bin/magento exportimport:customer-addresses:import --file=var/import/addresses.csv --validate-only
 *   bin/magento exportimport:customer-addresses:import --file=var/import/addresses.csv
 *
 * The customers must already exist — import them first. An address the customer
 * already has is recognised by its own field values and is never duplicated.
 */
class ImportCustomerAddressesCommand extends Command
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
        $this->setName('exportimport:customer-addresses:import')
            ->setDescription(
                'Validate and import a customer-address CSV (matched against the customer\'s existing '
                . 'addresses by their field values).'
            )
            ->addOption(self::OPT_FILE, null, InputOption::VALUE_REQUIRED, 'Path to the CSV file.')
            ->addOption(
                self::OPT_VALIDATE_ONLY,
                null,
                InputOption::VALUE_NONE,
                'Only validate; do not write any data.'
            );
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
            '<info>Validation: %d address(es) [%d new / %d already present], %d error(s), %d warning(s).</info>',
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
            '<info>Import: %d created, %d already present, %d failed.</info>',
            count($result['created']),
            count($result['skipped']),
            count($result['failed'])
        ));

        if ($result['defaults_set'] > 0) {
            $output->writeln(sprintf(
                '<info>Set %d default billing / shipping pointer(s).</info>',
                $result['defaults_set']
            ));
        }

        if ($result['grid_reindexed'] > 0) {
            $output->writeln(sprintf(
                '<info>Refreshed the admin customer grid for %d customer(s).</info>',
                $result['grid_reindexed']
            ));
        }

        if (!empty($result['failed'])) {
            $table = new Table($output);
            $table->setHeaders(['customer | line', 'reason']);
            foreach ($result['failed'] as $key => $reason) {
                $table->addRow([$key, $reason]);
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
