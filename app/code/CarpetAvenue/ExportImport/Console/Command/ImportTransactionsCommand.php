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
use CarpetAvenue\ExportImport\Model\Transaction\Importer;
use CarpetAvenue\ExportImport\Model\Transaction\Validator;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: validate and import a payment-transaction CSV. Validation always runs
 * first (the manual check); --validate-only stops before writing anything.
 *
 *   bin/magento exportimport:transactions:import --file=var/import/transactions.csv
 *   bin/magento exportimport:transactions:import --file=var/import/txns.csv --validate-only
 *
 * A row with a validation error is skipped and the rest are imported; every
 * skipped and failed row is listed once the run is done. --strict restores
 * all-or-nothing: any validation error and nothing is written.
 *
 * The orders the transactions belong to must already exist, with their payment
 * records — import orders first.
 */
class ImportTransactionsCommand extends Command
{
    private const OPT_FILE = 'file';
    private const OPT_VALIDATE_ONLY = 'validate-only';
    private const OPT_STRICT = 'strict';

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
        $this->setName('exportimport:transactions:import')
            ->setDescription(
                'Validate and import a payment-transaction CSV (create/update by order increment id + txn_id).'
            )
            ->addOption(self::OPT_FILE, null, InputOption::VALUE_REQUIRED, 'Path to the CSV file.')
            ->addOption(
                self::OPT_VALIDATE_ONLY,
                null,
                InputOption::VALUE_NONE,
                'Only validate; do not write any data.'
            )
            ->addOption(
                self::OPT_STRICT,
                null,
                InputOption::VALUE_NONE,
                'Import nothing if any row fails validation (default: skip the invalid rows).'
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
            '<info>Validation: %d transaction(s) [%d create / %d update], %d error(s), %d warning(s).</info>',
            $summary['orders'],
            $summary['creates'],
            $summary['updates'],
            $summary['errors'],
            $summary['warnings']
        ));

        $this->renderRows($output, 'Warnings', $validation->getWarnings());

        // A file-level error (missing header column, no data rows) is reported on
        // line 1; there is no valid row to import around it.
        $fileLevelError = array_filter($validation->getErrors(), static fn ($e) => (int)$e['line'] <= 1) !== [];
        $strict = (bool)$input->getOption(self::OPT_STRICT) || $fileLevelError;
        if ($input->getOption(self::OPT_VALIDATE_ONLY) || ($strict && !$validation->isValid())) {
            $this->renderRows($output, 'Errors', $validation->getErrors());
            if (!$validation->isValid()) {
                $output->writeln('<error>Validation failed — nothing imported. Fix the CSV and retry.</error>');
                return Cli::RETURN_FAILURE;
            }
            $output->writeln('<comment>--validate-only set: no data written.</comment>');
            return Cli::RETURN_SUCCESS;
        }

        if (!$validation->isValid()) {
            $output->writeln(sprintf(
                '<comment>%d validation error(s): those rows are skipped, the rest are imported. '
                . 'Full list below once the import is done.</comment>',
                count($validation->getErrors())
            ));
        }

        $result = $this->importer->import($parsed, true);
        $output->writeln(sprintf(
            '<info>Import: %d created, %d updated, %d skipped (validation), %d failed.</info>',
            count($result['created']),
            count($result['updated']),
            count($result['skipped']),
            count($result['failed'])
        ));

        if ($result['parents_linked'] > 0) {
            $output->writeln(sprintf(
                '<info>Resolved %d parent transaction link(s).</info>',
                $result['parents_linked']
            ));
        }

        // Error list last, after everything that was imported.
        if (!empty($result['skipped']) || !empty($result['failed'])) {
            $output->writeln('<comment>Not imported:</comment>');
            $table = new Table($output);
            $table->setHeaders(['order | txn_id', 'stage', 'reason']);
            foreach ($result['skipped'] as $key => $reasons) {
                $table->addRow([$key, 'validation', implode("\n", $reasons)]);
            }
            foreach ($result['failed'] as $key => $reason) {
                $table->addRow([$key, 'import', $reason]);
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
