<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Console\Command\Media;

use CarpetAvenue\Utilities\Helper\Config;
use CarpetAvenue\Utilities\Service\Media\RestoreFromGcsService;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:media:restore:originals
 *
 * Downloads original images back from GCS to their local_path.
 * Only restores rows with status=converted and a bucket_remote_path in the DB.
 * Default mode is DRY-RUN. Pass --live to actually download files.
 *
 * After restoring, flush Magento cache:
 *   bin/magento cache:flush
 */
class RestoreFromGcsCommand extends Command
{
    public function __construct(
        private readonly Config                $config,
        private readonly RestoreFromGcsService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:media:restore:originals')
             ->setDescription('[DRY-RUN by default] Restore original images from GCS to local disk. Pass --live to download for real.')
             ->addOption('live',        null, InputOption::VALUE_NONE,     'Actually download and restore files (default: dry-run only)')
             ->addOption('bucket',      null, InputOption::VALUE_REQUIRED, 'GCS bucket name without gs:// (overrides config)')
             ->addOption('credentials', null, InputOption::VALUE_REQUIRED, 'Path to GCP service-account JSON key file (overrides config)')
             ->addOption('limit',       null, InputOption::VALUE_REQUIRED, 'Max files to restore. 0 = unlimited')
             ->addOption('product-id',  null, InputOption::VALUE_REQUIRED, 'Restore only images for this product entity_id')
             ->addOption('force',       null, InputOption::VALUE_NONE,     '(No-op, kept for backwards compatibility — --live is sufficient confirmation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDryRun = !$input->getOption('live');

        $options = [
            'dry_run'     => $isDryRun,
            'bucket'      => $input->getOption('bucket')      ?? $this->config->getGcsBucket(),
            'credentials' => $input->getOption('credentials') ?? $this->config->getGcsCredentials(),
            'limit'       => max(0, (int) ($input->getOption('limit') ?? 0)),
            'product_id'  => max(0, (int) ($input->getOption('product-id') ?? 0)),
        ];

        return $this->service->execute($options, $output) === 0
            ? Cli::RETURN_SUCCESS
            : Cli::RETURN_FAILURE;
    }
}
