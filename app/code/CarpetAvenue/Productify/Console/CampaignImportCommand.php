<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Console;

use CarpetAvenue\Productify\Helper\CampaignHelper;
use Exception;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CampaignImportCommand extends Command
{
    public function __construct(
        protected CampaignHelper $campaignHelper,
        protected State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('productify:campaign:import')
            ->setDescription('Import dynamic campaign data from Google Sheet and update callout widgets.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode('adminhtml');
        } catch (Exception $e) {
            // Area code already set
        }

        $output->writeln('<info>Starting dynamic campaign import...</info>');

        try {
            if (!$this->campaignHelper->isDynamicCampaignImportEnabled()) {
                $output->writeln('<comment>Dynamic campaign import is disabled in configuration. Skipping.</comment>');
                return Cli::RETURN_SUCCESS;
            }

            $sheetId = $this->campaignHelper->getGoogleSheetId();
            if (empty($sheetId)) {
                $output->writeln('<error>Google Sheet ID is not configured. Please set it in Admin > Stores > Configuration.</error>');
                return Cli::RETURN_FAILURE;
            }

            $gridId = $this->campaignHelper->getGoogleSheetGridId();
            if ($gridId === null) {
                $output->writeln('<error>Google Sheet Grid ID is not configured. Please set it in Admin > Stores > Configuration.</error>');
                return Cli::RETURN_FAILURE;
            }

            $output->writeln('<info>Fetching campaign data from Google Sheet...</info>');
            $startTime = microtime(true);

            $this->campaignHelper->importDynamicCampaign();

            $executionTime = round(microtime(true) - $startTime, 2);
            $output->writeln('<info>Campaign import completed successfully.</info>');
            $output->writeln("<info>Execution time: {$executionTime} seconds</info>");
        } catch (Exception $e) {
            $output->writeln('<error>Campaign import failed: ' . $e->getMessage() . '</error>');
            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }
}
