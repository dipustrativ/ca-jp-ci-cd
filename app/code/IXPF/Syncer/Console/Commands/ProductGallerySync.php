<?php

namespace IXPF\Syncer\Console\Commands;

use Exception;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Helper\PhotoStudio;
use IXPF\Syncer\Model\ResourceModel\IxpfPhotoStudioQueue;
use Magento\Framework\App\State;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProductGallerySync extends Command
{
    public function __construct(
        private readonly State $state,
        protected readonly PhotoStudioLogger $logger,
        protected PhotoStudio $photoStudioHelper,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('ixpf:photo:studio')
            ->setDescription('Ixpf Photo studio image insert and webhook sync.')
            ->addOption(
                'status',
                's',
                InputOption::VALUE_OPTIONAL,
                'Process status parameter from terminal user',
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode('frontend');

            $status = $input->getOption('status');
            if ($status !== null) {
                $this->logger->writeDaily('Process status received: ' . $status);
                $output->writeln('<info>Process status: ' . $status . '</info>');
            } else {
                $status = 'pending';
            }

            if (in_array($status, [IxpfPhotoStudioQueue::STATUS_PENDING, IxpfPhotoStudioQueue::STATUS_MAGENTO_FAILED])) {
                $this->photoStudioHelper->runQueue($status);
            }

            if (in_array($status, [IxpfPhotoStudioQueue::STATUS_READY_FOR_WEBHOOK, IxpfPhotoStudioQueue::STATUS_WEBHOOK_FAILED])) {
                $this->photoStudioHelper->webhookDispatch($status);
            }

        } catch (Exception $exception) {
            $this->logger->writeDaily('Error in ProductGallerySync command: ' . $exception->getMessage());
            return Cli::RETURN_FAILURE;
        }

        return Cli::RETURN_SUCCESS;
    }
}