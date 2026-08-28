<?php

namespace IXPF\Syncer\Console\Commands;

use IXPF\Syncer\Cron\Purchase;
use IXPF\Syncer\Services\Purchase\Purchase as PurchaseService;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use IXPF\Syncer\Helper\Logger\PurchaseLogger;
use Magento\Framework\App\State;

class PurchaseCommand extends Command
{
    public function __construct(
        private readonly PurchaseService $purchaseService,
        private readonly PurchaseLogger $purchaseLogger,
        private readonly State $state,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('ixpf:syncer:purchase')
            ->setDescription('Ixpf purchase product create.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode('frontend');

            $this->purchaseService->run("Terminal");
        } catch (\Exception $exception) {
            $this->purchaseLogger->writeExceptionDaily($exception);
            return Cli::RETURN_FAILURE;
        }
        return Cli::RETURN_SUCCESS;
    }
}