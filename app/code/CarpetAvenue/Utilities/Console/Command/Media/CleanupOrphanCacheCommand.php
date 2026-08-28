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
use CarpetAvenue\Utilities\Service\Media\CleanupOrphanCacheService;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:media:cleanup:orphan-cache
 *
 * Deletes cache images whose source original is not referenced in the DB.
 * Safe — only cache files. Originals are NEVER touched.
 * Default mode is DRY-RUN. Pass --live to actually delete files.
 */
class CleanupOrphanCacheCommand extends Command
{
    public function __construct(
        private readonly Config                    $config,
        private readonly CleanupOrphanCacheService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:media:cleanup:orphan-cache')
             ->setDescription('[DRY-RUN by default] Delete orphan cache images (no DB reference). Backup first, then delete. Pass --live to run for real.')
             ->addOption('live',        null, InputOption::VALUE_NONE,     'Actually delete files (default: dry-run only)')
             ->addOption('limit',       null, InputOption::VALUE_REQUIRED, 'Max orphan cache files to process. 0 = unlimited (overrides config)')
             ->addOption('zip',         null, InputOption::VALUE_NONE,     'Enable ZIP backup before deletion (overrides config)')
             ->addOption('bucket',      null, InputOption::VALUE_REQUIRED, 'GCS bucket name without gs:// (overrides config)')
             ->addOption('credentials', null, InputOption::VALUE_REQUIRED, 'Path to GCP service-account JSON key file (overrides config)')
             ->addOption('staging-dir', null, InputOption::VALUE_REQUIRED, 'Custom staging directory path')
             ->addOption('force',       null, InputOption::VALUE_NONE,     '(No-op, kept for backwards compatibility — --live is sufficient confirmation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDryRun = !$input->getOption('live');

        $options = [
            'dry_run'     => $isDryRun,
            'limit'       => $input->getOption('limit')       ?? $this->config->getOrphanCacheLimit(),
            'enable_zip'  => $input->getOption('zip')         || $this->config->isOrphanCacheZipEnabled(),
            'bucket'      => $input->getOption('bucket')      ?? $this->config->getGcsBucket(),
            'credentials' => $input->getOption('credentials') ?? $this->config->getGcsCredentials(),
            'staging_dir' => $input->getOption('staging-dir') ?? '',
        ];

        return $this->service->execute($options, $output) === 0
            ? Cli::RETURN_SUCCESS
            : Cli::RETURN_FAILURE;
    }
}
