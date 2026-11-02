<?php

declare(strict_types=1);

namespace IXPF\Syncer\Console\Commands;

use IXPF\Syncer\Helper\Helpers;
use IXPF\Syncer\Helper\Logger\PhotoStudioLogger;
use IXPF\Syncer\Services\PhotoStudio\DirectGallerySync;
use IXPF\Syncer\Services\PhotoStudio\ProcessImages;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PhotoStudioQueueRun extends Command
{
    public function __construct(
        private readonly State               $state,
        protected readonly PhotoStudioLogger  $logger,
        protected ProcessImages              $processImages,
        protected DirectGallerySync         $directGallerySync,
        protected Helpers                   $helpers,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('photo-studio:queue:run')
            ->setDescription('Process pending Photo Studio image queue batches and sync them to Magento product galleries.')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of queue batches to process in one run.', 20);
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

            $limit = (int) $input->getOption('limit');
            if ($limit < 1) {
                $limit = 20;
            }

            $output->writeln('<info>╔══════════════════════════════════════╗</info>');
            $output->writeln('<info>║     Photo Studio Queue Runner        ║</info>');
            $output->writeln('<info>╚══════════════════════════════════════╝</info>');

            $this->state->setAreaCode('frontend');

            if ($this->helpers->isRawDbSaveMethod()) {
                $output->writeln('<comment>Using RAW DB save method (faster, no ORM events)</comment>');
                $succeeded = $this->directGallerySync->process($limit, $output);
            } else {
                $output->writeln('<comment>Using ORM save method (product->save, fires events)</comment>');
                $succeeded = $this->processImages->process($limit, $output);
            }

            $elapsed = $this->formatElapsed(microtime(true) - $scriptStart);

            $output->writeln('<info>╔══════════════════════════════════════╗</info>');
            $output->writeln('<info>║              Summary                 ║</info>');
            $output->writeln('<info>╠══════════════════════════════════════╣</info>');
            $output->writeln("<info>║</info> Limit:        <info>$limit</info>");
            $output->writeln("<info>║</info> Total time:   <info>$elapsed</info>");
            $output->writeln('<info>╚══════════════════════════════════════╝</info>');

            return $succeeded ? Command::SUCCESS : Command::FAILURE;

        } catch (\Exception $e) {
            $this->logger->writeDaily('PhotoStudioQueueRun: Fatal error — ' . $e->getMessage(), 'error');
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function formatElapsed(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 2) . 's';
        }

        $mins = floor($seconds / 60);
        $secs = round(fmod($seconds, 60), 2);

        return "{$mins}m {$secs}s";
    }
}
