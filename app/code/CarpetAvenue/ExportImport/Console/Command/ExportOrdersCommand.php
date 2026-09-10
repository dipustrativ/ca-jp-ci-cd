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
use CarpetAvenue\ExportImport\Model\Order\Exporter;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: export store-wise orders to CSV.
 *
 *   bin/magento exportimport:orders:export
 *   bin/magento exportimport:orders:export --store=jp --status=processing,complete --from=2026-01-01 --to=2026-03-31
 */
class ExportOrdersCommand extends Command
{
    private const OPT_STORE = 'store';
    private const OPT_STATUS = 'status';
    private const OPT_FROM = 'from';
    private const OPT_TO = 'to';
    private const OPT_OUTPUT = 'output';

    public function __construct(
        private readonly State $state,
        private readonly Config $config,
        private readonly Exporter $exporter,
        private readonly StoreManagerInterface $storeManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('exportimport:orders:export')
            ->setDescription('Export store-wise orders to a CSV file under var/export.')
            ->addOption(self::OPT_STORE, null, InputOption::VALUE_REQUIRED, 'Store view id or code.')
            ->addOption(self::OPT_STATUS, null, InputOption::VALUE_REQUIRED, 'Comma-separated order status codes.')
            ->addOption(self::OPT_FROM, null, InputOption::VALUE_REQUIRED, 'created_at from (Y-m-d).')
            ->addOption(self::OPT_TO, null, InputOption::VALUE_REQUIRED, 'created_at to (Y-m-d).')
            ->addOption(self::OPT_OUTPUT, null, InputOption::VALUE_REQUIRED, 'Output file name (default: timestamped).');
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

        try {
            $filters = [
                'store_id' => $this->resolveStoreId($input->getOption(self::OPT_STORE)),
                'status' => $this->parseStatus($input->getOption(self::OPT_STATUS)),
                'from' => $input->getOption(self::OPT_FROM) ?: null,
                'to' => $input->getOption(self::OPT_TO) ?: null,
            ];

            $fileName = $input->getOption(self::OPT_OUTPUT) ?: null;
            $result = $this->exporter->exportToFile($filters, $fileName);

            $output->writeln(sprintf(
                '<info>Exported %d order(s), %d row(s).</info>',
                $result['orders'],
                $result['rows']
            ));
            $output->writeln('<comment>File: ' . $result['absolute_path'] . '</comment>');
            return Cli::RETURN_SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Export failed: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }

    private function resolveStoreId(?string $store): ?int
    {
        if ($store === null || $store === '') {
            return null;
        }
        if (ctype_digit($store)) {
            return (int)$store;
        }
        return (int)$this->storeManager->getStore($store)->getId();
    }

    /** @return string[] */
    private function parseStatus(?string $status): array
    {
        if ($status === null || $status === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $status))));
    }
}
