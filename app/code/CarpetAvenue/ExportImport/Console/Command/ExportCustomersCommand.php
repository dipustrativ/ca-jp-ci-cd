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

use CarpetAvenue\ExportImport\Model\Config;
use CarpetAvenue\ExportImport\Model\Customer\Exporter;
use CarpetAvenue\ExportImport\Model\ResourceModel\CustomerExtraData;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI: export customers to CSV.
 *
 *   bin/magento exportimport:customers:export
 *   bin/magento exportimport:customers:export --website=base --group=General,Wholesale
 *   bin/magento exportimport:customers:export --from=2026-01-01 --email=a@b.com,c@d.com
 *
 * The file carries password hashes and personal data — see the README before
 * moving it anywhere.
 */
class ExportCustomersCommand extends Command
{
    private const OPT_WEBSITE = 'website';
    private const OPT_GROUP = 'group';
    private const OPT_FROM = 'from';
    private const OPT_TO = 'to';
    private const OPT_EMAIL = 'email';
    private const OPT_OUTPUT = 'output';

    public function __construct(
        private readonly State $state,
        private readonly Config $config,
        private readonly Exporter $exporter,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerExtraData $extraData,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('exportimport:customers:export')
            ->setDescription('Export customers to a CSV file under var/export.')
            ->addOption(self::OPT_WEBSITE, null, InputOption::VALUE_REQUIRED, 'Website id or code.')
            ->addOption(
                self::OPT_GROUP,
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated customer group codes (or ids).'
            )
            ->addOption(self::OPT_FROM, null, InputOption::VALUE_REQUIRED, 'created_at from (Y-m-d).')
            ->addOption(self::OPT_TO, null, InputOption::VALUE_REQUIRED, 'created_at to (Y-m-d).')
            ->addOption(
                self::OPT_EMAIL,
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated customer emails to restrict to.'
            )
            ->addOption(
                self::OPT_OUTPUT,
                null,
                InputOption::VALUE_REQUIRED,
                'Output file name (default: timestamped).'
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

        try {
            $filters = [
                'website_id' => $this->resolveWebsiteId($input->getOption(self::OPT_WEBSITE)),
                'group_id' => $this->resolveGroupIds($this->parseList($input->getOption(self::OPT_GROUP))),
                'from' => $input->getOption(self::OPT_FROM) ?: null,
                'to' => $input->getOption(self::OPT_TO) ?: null,
                'email' => $this->parseList($input->getOption(self::OPT_EMAIL)),
            ];

            $result = $this->exporter->exportToFile(
                $filters,
                $input->getOption(self::OPT_OUTPUT) ?: null
            );

            $output->writeln(sprintf('<info>Exported %d customer(s).</info>', $result['customers']));
            $output->writeln('<comment>File: ' . $result['absolute_path'] . '</comment>');
            return Cli::RETURN_SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>Export failed: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }
    }

    private function resolveWebsiteId(?string $website): ?int
    {
        if ($website === null || $website === '') {
            return null;
        }
        if (ctype_digit($website)) {
            return (int)$website;
        }
        return (int)$this->storeManager->getWebsite($website)->getId();
    }

    /**
     * Group codes (the CSV's own currency) or ids, both accepted.
     *
     * @param string[] $groups
     * @return int[]
     */
    private function resolveGroupIds(array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        $byCode = $this->extraData->getGroupIdsByCode();
        $ids = [];
        foreach ($groups as $group) {
            if (ctype_digit($group)) {
                $ids[] = (int)$group;
                continue;
            }
            $id = $byCode[strtolower($group)] ?? null;
            if ($id === null) {
                throw new \InvalidArgumentException(sprintf('Unknown customer group "%s".', $group));
            }
            $ids[] = $id;
        }
        return $ids;
    }

    /** @return string[] */
    private function parseList(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
