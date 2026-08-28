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
use CarpetAvenue\Utilities\Service\Media\CleanupDisabledCacheService;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:media:cleanup:disabled-cache
 *
 * Deletes cache images for disabled products (non-role gallery images only).
 * Originals are NEVER touched. Backup -> Stage -> [ZIP -> GCS] -> Delete.
 *
 * CLI options override admin config values.
 * Default mode is DRY-RUN. Pass --live to actually delete files.
 */
class CleanupDisabledCacheCommand extends Command
{
    public function __construct(
        private readonly Config                      $config,
        private readonly CleanupDisabledCacheService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:media:cleanup:disabled-cache')
             ->setDescription('[DRY-RUN by default] Delete cache images for disabled products. Backup first, then delete. Pass --live to run for real.')
             ->addOption('live',        null, InputOption::VALUE_NONE, 'Actually delete files (default: dry-run only)')
             ->addOption('id-from',     null, InputOption::VALUE_REQUIRED, 'Process products with entity_id >= N (overrides config)')
             ->addOption('id-to',       null, InputOption::VALUE_REQUIRED, 'Process products with entity_id <= N (overrides config)')
             ->addOption('limit',       null, InputOption::VALUE_REQUIRED, 'Max cache files to process. 0 = unlimited (overrides config)')
             ->addOption('zip',         null, InputOption::VALUE_NONE,     'Enable ZIP creation and auto-upload to GCS (overrides config)')
             ->addOption('bucket',      null, InputOption::VALUE_REQUIRED, 'GCS bucket name without gs:// (overrides config)')
             ->addOption('credentials', null, InputOption::VALUE_REQUIRED, 'Path to GCP service-account JSON key file (overrides config)')
             ->addOption('staging-dir', null, InputOption::VALUE_REQUIRED, 'Custom staging directory path (overrides config)')
             ->addOption('force',       null, InputOption::VALUE_NONE,     '(No-op, kept for backwards compatibility — --live is sufficient confirmation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // ── Build options: CLI wins over admin config ──────────────────────────
        $isDryRun = !$input->getOption('live');

        $options = [
            'dry_run'     => $isDryRun,
            'id_from'     => $input->getOption('id-from')     ?? $this->config->getDisabledCacheIdFrom(),
            'id_to'       => $input->getOption('id-to')       ?? $this->config->getDisabledCacheIdTo(),
            'limit'       => $input->getOption('limit')       ?? $this->config->getDisabledCacheLimit(),
            'enable_zip'  => $input->getOption('zip')         || $this->config->isDisabledCacheZipEnabled(),
            'bucket'      => $input->getOption('bucket')      ?? $this->config->getGcsBucket(),
            'credentials' => $input->getOption('credentials') ?? $this->config->getGcsCredentials(),
            'staging_dir' => $input->getOption('staging-dir') ?? $this->config->getDisabledCacheStagingDir(),
        ];

        // ── Check master + operation enabled (soft check, CLI can still override) ─
        if (!$isDryRun && !$this->config->isMasterEnabled()) {
            $output->writeln('<comment>WARNING: Master Media Cleanup is disabled in admin config. Running anyway (CLI override).</comment>');
        }

        return $this->service->execute($options, $output) === 0
            ? Cli::RETURN_SUCCESS
            : Cli::RETURN_FAILURE;
    }
}
