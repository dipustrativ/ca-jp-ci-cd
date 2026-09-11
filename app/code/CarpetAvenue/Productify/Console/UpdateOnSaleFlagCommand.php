<?php
/**
 * Copyright © CarpetAvenue. All rights reserved.
 */
declare(strict_types=1);

namespace CarpetAvenue\Productify\Console;

use CarpetAvenue\Productify\Model\OnSale\FlagUpdater;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recomputes the am_on_sale flag on demand, rather than waiting for the hourly
 * cron. Also the way to verify the flag against the price index.
 */
class UpdateOnSaleFlagCommand extends Command
{
    public function __construct(
        private readonly FlagUpdater $flagUpdater,
        private readonly State $state,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('productify:onsale:update')
            ->setDescription('Recompute the am_on_sale product flag from the price index');

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
        } catch (\Exception $e) {
            // Area already set — fine.
        }

        try {
            $result = $this->flagUpdater->execute();
        } catch (\Exception $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>%d products on sale.</info>', $result['on_sale']));
        $output->writeln(sprintf('<info>%d flag values changed.</info>', $result['changed']));

        if ($result['changed'] > 0) {
            $output->writeln('<comment>A catalog search reindex is scheduled for the changed products.</comment>');
        }

        return Command::SUCCESS;
    }
}
