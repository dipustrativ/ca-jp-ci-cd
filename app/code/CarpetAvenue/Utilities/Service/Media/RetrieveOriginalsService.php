<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Service\Media;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retrieve original images from GCS by SKU, package into a local ZIP.
 *
 * Queries carpetavenue_image_resize_log for rows matching the requested SKUs
 * that have a bucket_remote_path set.
 * Downloads matching files from GCS and creates a ZIP for local retrieval.
 *
 * Options array keys:
 *   sku          string  Comma-separated SKUs (1–20, validated)
 *   bucket       string  GCS bucket name (required)
 *   credentials  string  Path to GCP service-account JSON key
 *   output_dir   string  Where to save the ZIP (default: var/carpetavenue/retrieve)
 */
class RetrieveOriginalsService
{
    private const MAX_SKUS = 20;

    // Allowed SKU characters: letters, digits, hyphen, underscore, dot
    private const SKU_PATTERN = '/^[a-zA-Z0-9_\-\.]+$/';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DirectoryList      $directoryList,
    ) {
    }

    public function execute(array $options, OutputInterface $output): int
    {
        $rawSkus    = trim((string) ($options['sku']        ?? ''));
        $gcsBucket  = trim((string) ($options['bucket']     ?? ''));
        $gcsKeyFile = trim((string) ($options['credentials'] ?? ''));
        $outputDir  = trim((string) ($options['output_dir'] ?? ''));

        // ── Validate SKU input ────────────────────────────────────────────────
        if ($rawSkus === '') {
            $output->writeln('<error>ERROR: --sku is required. Provide 1–' . self::MAX_SKUS . ' comma-separated SKUs.</error>');
            return 1;
        }

        $skuList = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $rawSkus))
        )));

        if (count($skuList) === 0) {
            $output->writeln('<error>ERROR: No valid SKUs found in input.</error>');
            return 1;
        }

        if (count($skuList) > self::MAX_SKUS) {
            $output->writeln('<error>ERROR: Maximum ' . self::MAX_SKUS . ' SKUs allowed. Got ' . count($skuList) . '.</error>');
            return 1;
        }

        $invalidSkus = [];
        foreach ($skuList as $sku) {
            if (strlen($sku) > 255 || !preg_match(self::SKU_PATTERN, $sku)) {
                $invalidSkus[] = $sku;
            }
        }
        if (!empty($invalidSkus)) {
            $output->writeln('<error>ERROR: Invalid SKU(s): ' . implode(', ', $invalidSkus)
                . '. Only letters, digits, hyphens (-), underscores (_), and dots (.) are allowed.</error>');
            return 1;
        }

        // ── Validate bucket ───────────────────────────────────────────────────
        if ($gcsBucket === '') {
            $output->writeln('<error>ERROR: --bucket is required.</error>');
            return 1;
        }

        if ($gcsKeyFile !== '' && !is_readable($gcsKeyFile)) {
            $output->writeln("<error>ERROR: Credentials file not readable: $gcsKeyFile</error>");
            return 1;
        }

        if (!class_exists(\Google\Cloud\Storage\StorageClient::class)) {
            $output->writeln('<error>ERROR: google/cloud-storage SDK not found. Run: composer require google/cloud-storage</error>');
            return 1;
        }

        // ── Output directory ──────────────────────────────────────────────────
        if ($outputDir === '') {
            $outputDir = $this->directoryList->getPath(DirectoryList::VAR_DIR) . '/carpetavenue/retrieve';
        }
        if (!is_dir($outputDir) && !mkdir($outputDir, 0750, true)) {
            $output->writeln("<error>ERROR: Cannot create output directory: $outputDir</error>");
            return 1;
        }

        // ── Query DB ─────────────────────────────────────────────────────────
        $output->writeln('');
        $output->writeln('════════════════════════════════════════════════════');
        $output->writeln('  CarpetAvenue — Retrieve Original Images from GCS');
        $output->writeln('════════════════════════════════════════════════════');
        $output->writeln('  SKUs       : ' . implode(', ', $skuList));
        $output->writeln("  Bucket     : gs://$gcsBucket/");
        $output->writeln("  Output dir : $outputDir");
        $output->writeln('════════════════════════════════════════════════════');
        $output->writeln('');
        $output->writeln('STEP 1: Querying DB for images with GCS backup for given SKU(s)...');

        $connection = $this->resourceConnection->getConnection();
        $tbl        = $this->resourceConnection->getTableName('carpetavenue_image_resize_log');

        // Parameterized — safe against SQL injection
        $select = $connection->select()
            ->from($tbl, ['id', 'sku', 'local_path', 'bucket_remote_path', 'status', 'before_size'])
            ->where('sku IN (?)', $skuList)
            ->where('bucket_remote_path IS NOT NULL')
            ->where("bucket_remote_path != ''")
            ->order('sku ASC')
            ->order('id ASC');

        $rows  = $connection->fetchAll($select);
        $total = count($rows);
        $output->writeln("  Found $total image row(s) in DB.");

        if ($total === 0) {
            $output->writeln('<comment>  No images found in DB with GCS backup for the given SKU(s).</comment>');
            return 0;
        }

        // Warn about SKUs with no DB entries
        $foundSkus   = array_unique(array_column($rows, 'sku'));
        $missingSkus = array_diff($skuList, $foundSkus);
        if (!empty($missingSkus)) {
            $output->writeln('<comment>  WARNING: No DB records found for: ' . implode(', ', $missingSkus) . '</comment>');
        }

        // ── Connect to GCS ────────────────────────────────────────────────────
        $output->writeln('');
        $output->writeln('STEP 2: Connecting to GCS...');

        try {
            $storageOpts = ['suppressKeyFileNotice' => true];
            if ($gcsKeyFile !== '') {
                $storageOpts['keyFilePath'] = $gcsKeyFile;
                $output->writeln("  Auth: service-account key — $gcsKeyFile");
            } else {
                $output->writeln('  Auth: Application Default Credentials (ADC)');
            }
            $storageClient = new \Google\Cloud\Storage\StorageClient($storageOpts);
            $bucket        = $storageClient->bucket($gcsBucket);
            if (!$bucket->exists()) {
                throw new \RuntimeException("Bucket does not exist or is not accessible: $gcsBucket");
            }
            $output->writeln("  Bucket confirmed: gs://$gcsBucket/");
        } catch (\Throwable $e) {
            $output->writeln('<error>ERROR: GCS connection failed — ' . $e->getMessage() . '</error>');
            return 1;
        }

        // ── Download files into temp dir ──────────────────────────────────────
        $output->writeln('');
        $output->writeln("STEP 3: Downloading $total file(s) from GCS...");

        $tempDir = $outputDir . '/tmp_retrieve_' . date('Ymd_His') . '_' . getmypid();
        if (!mkdir($tempDir, 0750, true)) {
            $output->writeln("<error>ERROR: Cannot create temp directory: $tempDir</error>");
            return 1;
        }

        $downloaded = 0;
        $errors     = 0;
        $seenNames  = [];

        foreach ($rows as $row) {
            $remotePath = (string) $row['bucket_remote_path'];

            // Parse object name from gs://bucket/object-name
            if (!preg_match('#^gs://[^/]+/(.+)$#', $remotePath, $m)) {
                $output->writeln("<comment>  SKIP  Cannot parse GCS path: $remotePath</comment>");
                $errors++;
                continue;
            }
            $objectName = $m[1];

            // Build local filename: sku__original-basename.ext (collision-safe)
            $baseName  = basename($row['local_path']);
            $safeKey   = $row['sku'] . '__' . $baseName;
            if (isset($seenNames[$safeKey])) {
                $seenNames[$safeKey]++;
                $ext      = pathinfo($baseName, PATHINFO_EXTENSION);
                $stem     = pathinfo($baseName, PATHINFO_FILENAME);
                $safeKey  = $row['sku'] . '__' . $stem . '_' . $seenNames[$row['sku'] . '__' . $baseName] . '.' . $ext;
            } else {
                $seenNames[$safeKey] = 1;
            }
            $localFile = $tempDir . '/' . $safeKey;

            try {
                $gcsObject = $bucket->object($objectName);
                if (!$gcsObject->exists()) {
                    $output->writeln("<comment>  SKIP  GCS object not found: gs://$gcsBucket/$objectName</comment>");
                    $errors++;
                    continue;
                }
                $gcsObject->downloadToFile($localFile);
                $downloaded++;
                $fileSize = file_exists($localFile) ? filesize($localFile) : 0;
                $output->writeln("  OK  SKU={$row['sku']} | $safeKey | " . GcsService::fmtBytes((int) $fileSize));
            } catch (\Throwable $e) {
                $output->writeln('<error>  ERROR  SKU=' . $row['sku'] . " | $objectName — " . $e->getMessage() . '</error>');
                $errors++;
            }
        }

        if ($downloaded === 0) {
            $this->removeDirectory($tempDir);
            $output->writeln('<error>ERROR: No files downloaded successfully.</error>');
            return 1;
        }

        // ── Create ZIP ────────────────────────────────────────────────────────
        $output->writeln('');
        $output->writeln("STEP 4: Creating ZIP archive...");

        $skuSlug = implode('-', array_slice($skuList, 0, 5));
        // Replace unsafe filesystem chars in the slug
        $skuSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $skuSlug);
        $zipName = 'originals_' . $skuSlug . '_' . date('Ymd_His') . '.zip';
        $zipPath = $outputDir . '/' . $zipName;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $output->writeln("<error>ERROR: Cannot create ZIP: $zipPath</error>");
            $this->removeDirectory($tempDir);
            return 1;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getRealPath(), $file->getFilename());
            }
        }
        $zip->close();

        $this->removeDirectory($tempDir);

        $zipSize = file_exists($zipPath) ? (int) filesize($zipPath) : 0;

        // ── Summary ───────────────────────────────────────────────────────────
        $output->writeln('');
        $output->writeln('════════════════════════════════════════════════════');
        $output->writeln('  RETRIEVE SUMMARY');
        $output->writeln('════════════════════════════════════════════════════');
        $output->writeln("  SKUs requested : " . count($skuList));
        $output->writeln("  Files in DB    : $total");
        $output->writeln("  Downloaded     : $downloaded");
        $output->writeln("  Errors/skipped : $errors");
        $output->writeln("  ZIP size       : " . GcsService::fmtBytes($zipSize));
        $output->writeln("  ZIP location   : $zipPath");
        $output->writeln('════════════════════════════════════════════════════');
        $output->writeln('');

        return $errors > 0 ? 1 : 0;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }
}
