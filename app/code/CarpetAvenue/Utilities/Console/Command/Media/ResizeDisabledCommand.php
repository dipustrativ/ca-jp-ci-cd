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
use CarpetAvenue\Utilities\Service\Media\ResizeDisabledService;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:media:resize:disabled
 *
 * Resizes original images for disabled products in-place.
 * Originals are backed up to staging BEFORE any resize.
 * Default mode is DRY-RUN. Pass --live to actually resize files.
 *
 * After running in live mode, regenerate Magento cache:
 *   bin/magento cache:flush
 */
class ResizeDisabledCommand extends Command
{
    public function __construct(
        private readonly Config               $config,
        private readonly ResizeDisabledService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:media:resize:disabled')
             ->setDescription('[DRY-RUN by default] Resize original images for disabled products in-place. Backup first. Pass --live to run for real.')
             ->addOption('live',            null, InputOption::VALUE_NONE,     'Actually resize files (default: dry-run only)')
             ->addOption('id-from',         null, InputOption::VALUE_REQUIRED, 'Process products with entity_id >= N (overrides config)')
             ->addOption('id-to',           null, InputOption::VALUE_REQUIRED, 'Process products with entity_id <= N (overrides config)')
             ->addOption('limit',           null, InputOption::VALUE_REQUIRED, 'Max images to process. 0 = unlimited (overrides config)')
             ->addOption('max-width',       null, InputOption::VALUE_REQUIRED, 'Target max-width in pixels (overrides config, default: 1200)')
             ->addOption('max-height',      null, InputOption::VALUE_REQUIRED, 'Target max-height in pixels (overrides config, default: 1200)')
             ->addOption('min-width',       null, InputOption::VALUE_REQUIRED, 'Skip images whose width is below this (px)')
             ->addOption('min-height',      null, InputOption::VALUE_REQUIRED, 'Skip images whose height is below this (px)')
             ->addOption('min-size',        null, InputOption::VALUE_REQUIRED, 'Skip images whose file size is below this (bytes, e.g. 500KB, 1MB)')
             ->addOption('quality',         null, InputOption::VALUE_REQUIRED, 'JPEG/WebP quality 1-100 (overrides config, default: 82)')
             ->addOption('driver',          null, InputOption::VALUE_REQUIRED, 'Image driver: auto|gd|imagick (overrides config)')
             ->addOption('zip',             null, InputOption::VALUE_NONE,     'Enable ZIP backup before resize (overrides config)')
             ->addOption('bucket',          null, InputOption::VALUE_REQUIRED, 'GCS bucket name without gs:// (overrides config)')
             ->addOption('credentials',     null, InputOption::VALUE_REQUIRED, 'Path to GCP service-account JSON key file (overrides config)')
             ->addOption('staging-dir',     null, InputOption::VALUE_REQUIRED, 'Custom staging directory path (overrides config)')
             ->addOption('skip-roles',  null, InputOption::VALUE_NONE, 'Skip role images (image, small_image, thumbnail, swatch_image)')
             ->addOption('requalify',   null, InputOption::VALUE_NONE, 'Re-save images that are already within max dimensions at configured JPEG quality (overrides config)')
             ->addOption('non-unique',  null, InputOption::VALUE_NONE,     'Also process non-unique products (default: unique products only, attribute_id 270 = 1)')
             ->addOption('sold-before', null, InputOption::VALUE_REQUIRED, 'Only process products last sold before this date (YYYY-MM-DD, e.g. 2026-01-01). Products with no sales history are excluded. Leave empty to skip filter (overrides config)')
             ->addOption('force',       null, InputOption::VALUE_NONE,     '(No-op, kept for backwards compatibility — --live is sufficient confirmation)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isDryRun = !$input->getOption('live');

        $options = [
            'dry_run'         => $isDryRun,
            'id_from'         => $input->getOption('id-from')     ?? $this->config->getDisabledResizeIdFrom(),
            'id_to'           => $input->getOption('id-to')       ?? $this->config->getDisabledResizeIdTo(),
            'limit'           => $input->getOption('limit')       ?? $this->config->getDisabledResizeLimit(),
            'max_width'       => $input->getOption('max-width')   ?? $this->config->getDisabledResizeMaxWidth(),
            'max_height'      => $input->getOption('max-height')  ?? $this->config->getDisabledResizeMaxHeight(),
            'min_width'       => $input->getOption('min-width')   ?? 0,
            'min_height'      => $input->getOption('min-height')  ?? 0,
            'min_size'        => $this->parseBytes((string) ($input->getOption('min-size') ?? '0')),
            'quality'         => $input->getOption('quality')     ?? $this->config->getDisabledResizeQuality(),
            'driver'          => $input->getOption('driver')      ?? $this->config->getDisabledResizeDriver(),
            'enable_zip'      => $input->getOption('zip')         || $this->config->isDisabledResizeZipEnabled(),
            'bucket'          => $input->getOption('bucket')      ?? $this->config->getGcsBucket(),
            'credentials'     => $input->getOption('credentials') ?? $this->config->getGcsCredentials(),
            'staging_dir'     => $input->getOption('staging-dir') ?? $this->config->getDisabledResizeStagingDir(),
            'non_unique'      => (bool) $input->getOption('non-unique'),
            'skip_roles'      => (bool) $input->getOption('skip-roles'),
            'requalify'       => $input->getOption('requalify') || $this->config->isDisabledResizeRequalifySmall(),
            'sold_before'     => $input->getOption('sold-before') ?? $this->config->getDisabledResizeSoldBefore(),
        ];

        return $this->service->execute($options, $output) === 0
            ? Cli::RETURN_SUCCESS
            : Cli::RETURN_FAILURE;
    }

    private function parseBytes(string $value): int
    {
        $value = trim($value);
        if (preg_match('/^(\d+(?:\.\d+)?)\s*(KB|MB|GB|TB)?$/i', $value, $m)) {
            $num  = (float) $m[1];
            $unit = strtoupper($m[2] ?? '');
            return (int) match ($unit) {
                'KB'    => $num * 1024,
                'MB'    => $num * 1024 * 1024,
                'GB'    => $num * 1024 * 1024 * 1024,
                'TB'    => $num * 1024 * 1024 * 1024 * 1024,
                default => $num,
            };
        }
        return max(0, (int) $value);
    }
}
