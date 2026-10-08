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
use CarpetAvenue\FeedExport\Model\ResourceModel\Profile\CollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Command
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:feedexport:list')
            ->setDescription('List feed export profiles');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $table = new Table($output);
        $table->setHeaders(['ID', 'Name', 'Enabled', 'Store', 'Cron', 'Destination', 'Last run (UTC)']);
        /** @var ProfileInterface $profile */
        foreach ($this->collectionFactory->create()->setOrder(ProfileInterface::PROFILE_ID, 'ASC') as $profile) {
            $table->addRow([
                $profile->getProfileId(),
                $profile->getName(),
                $profile->isEnabled() ? 'yes' : 'no',
                $profile->getStoreId(),
                $profile->isCronEnabled() ? implode('; ', $profile->getCronExpressions()) : '-',
                $profile->getDestinationPath(),
                $profile->getLastExecution() ?? '-',
            ]);
        }
        $table->render();
        return Command::SUCCESS;
    }
}
