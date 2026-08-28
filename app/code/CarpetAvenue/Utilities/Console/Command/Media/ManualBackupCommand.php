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
use CarpetAvenue\Utilities\Service\Media\ManualBackupService;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento carpetavenue:media:manual-backup
 *
 * Parses a CSV of relative image paths, stages each file found under
 * pub/media/catalog/product/, uploads the staging directory to GCS,
 * and writes a result CSV to var/log/carpetavenue/manual_backup_delete/.
 *
 * No row limit — suitable for large CSVs. Progress is printed to console.
 *
 * Example:
 *   bin/magento carpetavenue:media:manual-backup \
 *     --csv=/var/www/html/images_to_backup.csv \
 *     --bucket=my-gcs-bucket \
 *     --gcs-prefix=manual_backup/2024-01
 */
class ManualBackupCommand extends Command
{
    public function __construct(
        private readonly Config              $config,
        private readonly ManualBackupService $service,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('carpetavenue:media:manual-backup')
             ->setDescription('Back up product images listed in a CSV to GCS. No row limit. Outputs progress to console.')
             ->addOption('csv',         null, InputOption::VALUE_REQUIRED, 'Absolute path to input CSV file (must have "image_path" column)')
             ->addOption('bucket',      null, InputOption::VALUE_REQUIRED, 'GCS bucket name without gs:// (overrides config)')
             ->addOption('credentials', null, InputOption::VALUE_REQUIRED, 'Path to GCP service-account JSON key file (overrides config; empty = ADC)')
             ->addOption('gcs-prefix',  null, InputOption::VALUE_REQUIRED, 'GCS object path prefix inside the bucket (default: manual_backup)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $csvPath    = trim((string) ($input->getOption('csv')         ?? ''));
        $gcsBucket  = trim((string) ($input->getOption('bucket')      ?? $this->config->getGcsBucket()));
        $gcsKeyFile = trim((string) ($input->getOption('credentials') ?? $this->config->getGcsCredentials()));
        $gcsPrefix  = trim((string) ($input->getOption('gcs-prefix')  ?? 'manual_backup'));

        if ($csvPath === '') {
            $output->writeln('<error>--csv option is required. Provide the absolute path to a CSV file.</error>');
            return Cli::RETURN_FAILURE;
        }

        if (!is_readable($csvPath)) {
            $output->writeln("<error>CSV file not readable: $csvPath</error>");
            return Cli::RETURN_FAILURE;
        }

        $output->writeln("<info>Parsing CSV: $csvPath</info>");

        $parsed = $this->service->parseAndValidateCsv($csvPath);

        if ($parsed['error'] !== null) {
            $output->writeln('<error>CSV error: ' . $parsed['error'] . '</error>');
            return Cli::RETURN_FAILURE;
        }

        $rows  = $parsed['rows'];
        $total = count($rows);
        $output->writeln("<info>Rows parsed: $total</info>");

        if ($gcsBucket === '') {
            $output->writeln('<comment>No GCS bucket configured — files will be staged locally only.</comment>');
        } else {
            $prefix = $gcsPrefix !== '' ? $gcsPrefix : 'manual_backup';
            $output->writeln("<info>Target: gs://$gcsBucket/$prefix/</info>");
        }

        $log = static function (string $msg) use ($output): void {
            $output->writeln($msg);
        };

        $resultCsvPath = $this->service->execute(
            $rows,
            [
                'bucket'      => $gcsBucket,
                'credentials' => $gcsKeyFile,
                'gcs_prefix'  => $gcsPrefix,
            ],
            $log
        );

        if ($resultCsvPath === null) {
            $output->writeln('<error>Backup failed with a fatal error. Check the log files in var/log/carpetavenue/manual_backup_delete/</error>');
            return Cli::RETURN_FAILURE;
        }

        $output->writeln("<info>Result CSV: $resultCsvPath</info>");

        return Cli::RETURN_SUCCESS;
    }
}
