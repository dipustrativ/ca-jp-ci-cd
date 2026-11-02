<?php
declare(strict_types=1);

namespace IXPF\Syncer\Console\Commands;

use IXPF\Syncer\Helper\Helpers;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Services\PhotoStudio\ProcessGoogleBucketImages;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DownloadGoogleBucketImages extends Command
{
    public function __construct(
        private readonly State $state,
        protected readonly PhotoStudioLogger $logger,
        protected ProcessGoogleBucketImages $processGoogleBucketImages,
        protected Helpers $helpers,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('gcs:images:download')
            ->setDescription('Download images from GCS bucket in parallel')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of new images to download in one run.', 250)
            ->addOption('concurrency', 'c', InputOption::VALUE_OPTIONAL, 'Parallel downloads at once', 20);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($this->helpers->getEnablePhotoStudioPreviousFunctionality()) {
                $output->writeln('<error>Previous Photo Studio functionality is enabled in config. Please disable it to use the new queue runner.</error>');
                return Command::FAILURE;
            }

            set_time_limit(0);
            $scriptStart = microtime(true);
            // Apply options
            $concurrency = (int) $input->getOption('concurrency');
            if ($concurrency < 1) {
                $concurrency = 20;
            }
            $downloadLimit = (int) $input->getOption('limit');
            if ($downloadLimit < 1) {
                $downloadLimit = 250;
            }

            $output->writeln('<info>╔══════════════════════════════╗</info>');
            $output->writeln('<info>║   GCS Image Downloader       ║</info>');
            $output->writeln('<info>╚══════════════════════════════╝</info>');

            $this->state->setAreaCode('frontend');
            $succeeded = $this->processGoogleBucketImages->process($concurrency, $output, $downloadLimit);
            $totalTime = $this->processGoogleBucketImages->elapsed($scriptStart);
            $output->writeln('<info>╔══════════════════════════════╗</info>');
            $output->writeln('<info>║         Summary              ║</info>');
            $output->writeln('<info>╠══════════════════════════════╣</info>');
            $output->writeln("<info>║</info> Total time:   <info>$totalTime</info>");
            $output->writeln('<info>╚══════════════════════════════╝</info>');

            return $succeeded ? Command::SUCCESS : Command::FAILURE;

        } catch (\Exception $exception) {
            $this->logger->writeDaily('Error in GCS image download: ' . $exception->getMessage());
            $output->writeln('<error>Error: ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }


    }
}
