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
use CarpetAvenue\ExportImport\Model\Document\Pool;
use CarpetAvenue\ExportImport\Model\Order\CsvReader;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: validate and import an invoice, credit-memo or shipment CSV. Validation
 * always runs first (the manual check); --validate-only stops before writing
 * anything.
 *
 *   bin/magento exportimport:documents:import --type=invoice --file=var/import/invoices.csv
 *   bin/magento exportimport:documents:import --type=creditmemo --file=var/import/cms.csv --validate-only
 *   bin/magento exportimport:documents:import --type=shipment --file=var/import/shipments.csv
 *
 * One command per type is registered too (virtualTypes in etc/di.xml passing
 * $fixedType), so --type can be left off:
 *
 *   bin/magento exportimport:invoices:import --file=var/import/invoices.csv
 *   bin/magento exportimport:creditmemos:import --file=var/import/cms.csv
 *   bin/magento exportimport:shipments:import --file=var/import/shipments.csv
 *
 * A document with a validation error is skipped and the rest are imported; every
 * skipped and failed document is listed once the run is done. --strict restores
 * all-or-nothing: any validation error and nothing is written.
 *
 * The orders the documents belong to must already exist — import orders first.
 */
class ImportDocumentsCommand extends Command
{
    private const OPT_TYPE = 'type';
    private const OPT_FILE = 'file';
    private const OPT_VALIDATE_ONLY = 'validate-only';
    private const OPT_STRICT = 'strict';

    /**
     * @param string|null $fixedType document type this command is bound to; null = take it from --type
     * @param string|null $commandName name for a type-bound command
     */
    public function __construct(
        private readonly State $state,
        private readonly Config $config,
        private readonly CsvReader $csvReader,
        private readonly Pool $pool,
        private readonly ?string $fixedType = null,
        private readonly ?string $commandName = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        if ($this->fixedType !== null) {
            $this->setName($this->commandName ?? 'exportimport:' . $this->fixedType . 's:import')
                ->setDescription(sprintf(
                    'Validate and import %s CSV (create/update by increment_id).',
                    match ($this->fixedType) {
                        'invoice' => 'an invoice',
                        'creditmemo' => 'a credit-memo',
                        default => 'a ' . $this->fixedType,
                    }
                ));
        } else {
            $this->setName('exportimport:documents:import')
                ->setDescription(
                    'Validate and import an invoice, credit-memo or shipment CSV (create/update by increment_id).'
                )
                ->addOption(
                    self::OPT_TYPE,
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Document type: invoice, creditmemo or shipment.'
                );
        }

        $this->addOption(self::OPT_FILE, null, InputOption::VALUE_REQUIRED, 'Path to the CSV file.')
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
                'Import nothing if any row fails validation (default: skip the invalid documents).'
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

        $type = $this->fixedType ?? (string)$input->getOption(self::OPT_TYPE);
        if (!$this->pool->has($type)) {
            $output->writeln(sprintf(
                '<error>--type is required and must be one of: %s.</error>',
                implode(', ', $this->pool->getCodes())
            ));
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
        $validation = $this->pool->getValidator($type)->validate($parsed);
        $summary = $validation->getSummary();
        $output->writeln(sprintf(
            '<info>Validation: %d %s(s) [%d create / %d update], %d error(s), %d warning(s).</info>',
            $summary['orders'],
            $type,
            $summary['creates'],
            $summary['updates'],
            $summary['errors'],
            $summary['warnings']
        ));

        $this->renderRows($output, 'Warnings', $validation->getWarnings());

        // A file-level error (missing header column, no data rows) is reported on
        // line 1; there is no valid document to import around it.
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
                '<comment>%d validation error(s): the %s(s) they belong to are skipped, the rest are imported. '
                . 'Full list below once the import is done.</comment>',
                count($validation->getErrors()),
                $type
            ));
        }

        $result = $this->pool->getImporter($type)->import($parsed, true);

        $output->writeln(sprintf(
            '<info>Import: %d created, %d updated, %d skipped (validation), %d failed.</info>',
            count($result['created']),
            count($result['updated']),
            count($result['skipped']),
            count($result['failed'])
        ));

        if (!empty($result['orders_synced'])) {
            $output->writeln(sprintf(
                '<info>Recomputed invoiced/paid/refunded totals on %d order(s): %s</info>',
                count($result['orders_synced']),
                implode(', ', $result['orders_synced'])
            ));
        }

        if (!empty($result['relinked'])) {
            $output->writeln(sprintf(
                '<info>Re-linked %d item(s) to an order item that could not be matched at first import:</info>',
                array_sum($result['relinked'])
            ));
            $table = new Table($output);
            $table->setHeaders([$type, 'items linked']);
            foreach ($result['relinked'] as $inc => $count) {
                $table->addRow([$inc, $count]);
            }
            $table->render();
        }

        // Error list last, after everything that was imported.
        if (!empty($result['skipped']) || !empty($result['failed'])) {
            $output->writeln('<comment>Not imported:</comment>');
            $table = new Table($output);
            $table->setHeaders([$type, 'stage', 'reason']);
            foreach ($result['skipped'] as $inc => $reasons) {
                $table->addRow([$inc, 'validation', implode("\n", $reasons)]);
            }
            foreach ($result['failed'] as $inc => $reason) {
                $table->addRow([$inc, 'import', $reason]);
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
