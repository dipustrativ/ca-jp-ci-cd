<?php

namespace CarpetAvenue\Productify\Console;

use Symfony\Component\Console\Command\Command;
use CarpetAvenue\Productify\Cron\ProcessQueue;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\App\State;

class ProductifyQueueRun extends Command
{
    public function __construct(
        protected ProcessQueue $queue,
        protected State $state,
        ?string                 $name = null
    )
    {
        parent::__construct($name);
    }

    protected function configure()
    {
        $this->setName('productify:queue:run')
            ->setDescription('Run the Productify Product Indexer Queue Process manually');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode('crontab');
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            // Area code was already set, ignore
        }

        // run(), not execute(): it prints each outcome and draws the build progress.
        $this->queue->run($output);
        return Cli::RETURN_SUCCESS;
    }
}