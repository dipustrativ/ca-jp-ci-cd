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
use CarpetAvenue\Utilities\Service\Media\RetrieveOriginalsService;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:media:retrieve:originals
 *
 * Downloads GCS-backed originals for up to 20 SKUs and packages them into a local ZIP.
 * Only retrieves images that have a bucket_remote_path recorded in carpetavenue_image_resize_log.
 *
 * Usage:
 *   bin/magento carpetavenue:media:retrieve:originals --sku=SKU1,SKU2 --bucket=my-bucket
 */
class RetrieveOriginalsCommand extends Command
{
    public function __construct(
        private readonly Config                  $config,
        private readonly RetrieveOriginalsService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:media:retrieve:originals')
             ->setDescription('Download GCS-backed original images by SKU and package into a ZIP (max 20 SKUs).')
             ->addOption('sku',         null, InputOption::VALUE_REQUIRED, 'Comma-separated SKU list (max 20). Only letters, digits, hyphens, underscores, dots allowed.')
             ->addOption('bucket',      null, InputOption::VALUE_REQUIRED, 'GCS bucket name without gs:// (overrides config)')
             ->addOption('credentials', null, InputOption::VALUE_REQUIRED, 'Path to GCP service-account JSON key file (overrides config)')
             ->addOption('output-dir',  null, InputOption::VALUE_REQUIRED, 'Directory to save the ZIP (default: var/carpetavenue/retrieve)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [
            'sku'        => $input->getOption('sku')        ?? '',
            'bucket'     => $input->getOption('bucket')     ?? $this->config->getGcsBucket(),
            'credentials'=> $input->getOption('credentials') ?? $this->config->getGcsCredentials(),
            'output_dir' => $input->getOption('output-dir') ?? '',
        ];

        return $this->service->execute($options, $output) === 0
            ? Cli::RETURN_SUCCESS
            : Cli::RETURN_FAILURE;
    }
}
