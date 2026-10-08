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

use CarpetAvenue\FeedExport\Api\Data\ProfileInterface;
use CarpetAvenue\FeedExport\Api\FeedExporterInterface;
use CarpetAvenue\FeedExport\Model\ResourceModel\Profile\CollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:feedexport:run 4 6 9
 * bin/magento carpetavenue:feedexport:run --all
 */
class RunCommand extends Command
{
    private const ARG_PROFILE_IDS = 'profile_ids';
    private const OPT_ALL = 'all';

    public function __construct(
        private readonly FeedExporterInterface $feedExporter,
        private readonly CollectionFactory $collectionFactory,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:feedexport:run')
            ->setDescription('Generate product feed(s) for the given profile IDs')
            ->addArgument(self::ARG_PROFILE_IDS, InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Profile IDs')
            ->addOption(self::OPT_ALL, null, InputOption::VALUE_NONE, 'Run every enabled profile');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->getAreaCode();
        } catch (LocalizedException $e) {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        }

        $profileIds = array_map('intval', (array)$input->getArgument(self::ARG_PROFILE_IDS));
        if ($input->getOption(self::OPT_ALL)) {
            $profileIds = array_map('intval', $this->collectionFactory->create()
                ->addFieldToFilter(ProfileInterface::ENABLED, 1)
                ->getAllIds());
        }
        if (!$profileIds) {
            $output->writeln('<error>Pass profile IDs or --all.</error>');
            return Command::INVALID;
        }

        $failed = 0;
        foreach ($profileIds as $profileId) {
            try {
                $result = $this->feedExporter->export($profileId, FeedExporterInterface::TRIGGER_CLI);
                $output->writeln(sprintf(
                    '<info>Profile %d: %d product(s) in %.2fs</info>',
                    $profileId,
                    $result->getRecordCount(),
                    $result->getDuration()
                ));
                foreach ($result->getFiles() as $file) {
                    $output->writeln('  ' . $file);
                }
            } catch (\Exception $e) {
                $failed++;
                $output->writeln(sprintf('<error>Profile %d: %s</error>', $profileId, $e->getMessage()));
            }
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
