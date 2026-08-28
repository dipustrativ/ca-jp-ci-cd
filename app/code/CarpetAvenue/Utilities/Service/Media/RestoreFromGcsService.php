<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Service\Media;

use CarpetAvenue\Utilities\Model\ImageResizeLog;
use CarpetAvenue\Utilities\Model\ImageResizeLogRepository;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Restore original images from GCS to local disk.
 *
 * Queries DB for rows with status=converted and a bucket_remote_path.
 * Downloads each file from GCS using the PHP SDK.
 * Marks row as restored in DB after each successful download.
 *
 * Execution order:
 *   STEP 1  Query DB for restorable rows (status=converted, bucket_remote_path set)
 *   STEP 2  For each file: download from GCS → write to local_path → markRestored()
 */
class RestoreFromGcsService
{
    public function __construct(
        private readonly ResourceConnection      $resourceConnection,
        private readonly DirectoryList           $directoryList,
        private readonly ImageResizeLogRepository $logRepository,
    ) {
    }

    public function execute(array $options, OutputInterface $output): int
    {
        $isDryRun   = (bool) $options['dry_run'];
        $gcsBucket  = (string) $options['bucket'];
        $gcsKeyFile = (string) $options['credentials'];
        $limit      = max(0, (int) $options['limit']);
        $productId  = (int) ($options['product_id'] ?? 0);
        $mode       = $isDryRun ? 'DRY-RUN' : 'LIVE';
        $limitLabel = $limit > 0 ? (string) $limit : 'unlimited';

        $bp      = $this->directoryList->getRoot();
        $logDir  = $bp . '/var/log/carpetavenue/restore_from_gcs';

        // ── Lock ──────────────────────────────────────────────────────────────
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }
        $lockFile = $logDir . '/.lock';
        $lockFp   = fopen($lockFile, 'w');
        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            $output->writeln('<error>ERROR: Another restore instance is already running.</error>');
            return 1;
        }
        fwrite($lockFp, (string) getmypid());

        $releaseLock = static function () use ($lockFp, $lockFile): void {
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            @unlink($lockFile);
        };

        // ── Log setup ─────────────────────────────────────────────────────────
        $datePart = date('Y-m-d_H-i-s');
        $logFile  = $logDir . '/' . ($isDryRun ? 'dry' : 'live') . "_$datePart.log";

        $log = static function (string $msg) use ($logFile, $output): void {
            $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
            file_put_contents($logFile, $line, FILE_APPEND);
            $output->write($line);
        };

        // ── Pre-flight ────────────────────────────────────────────────────────
        if (!$isDryRun) {
            if (!class_exists(\Google\Cloud\Storage\StorageClient::class)) {
                $log('ERROR: google/cloud-storage SDK not found. Run: composer require google/cloud-storage');
                $releaseLock();
                return 1;
            }
            if ($gcsBucket === '') {
                $log('ERROR: --bucket is required for restore.');
                $releaseLock();
                return 1;
            }
            if ($gcsKeyFile !== '' && !is_readable($gcsKeyFile)) {
                $log("ERROR: Credentials file not readable: $gcsKeyFile");
                $releaseLock();
                return 1;
            }
        }

        // ── Header ────────────────────────────────────────────────────────────
        $log('════════════════════════════════════════════════════');
        $log('  CarpetAvenue — Restore Original Images from GCS');
        $log('  Downloads staged originals back to local_path.');
        $log('════════════════════════════════════════════════════');
        $log("  Mode       : $mode");
        $log("  Bucket     : " . ($gcsBucket ?: '(not set)'));
        $log("  Limit      : $limitLabel");
        $log("  Product ID : " . ($productId > 0 ? (string) $productId : 'all'));
        $log('════════════════════════════════════════════════════');

        // ══════════════════════════════════════════════════════════════════════
        // STEP 1 — Query DB for restorable rows
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log('STEP 1: Querying DB for restorable rows (status=converted, bucket_remote_path set)...');

        $connection = $this->resourceConnection->getConnection();
        $tbl        = $this->resourceConnection->getTableName('carpetavenue_image_resize_log');

        $select = $connection->select()
            ->from($tbl, ['id', 'product_id', 'sku', 'local_path', 'db_path', 'bucket_remote_path', 'before_size'])
            ->where('status = ?', ImageResizeLog::STATUS_CONVERTED)
            ->where('bucket_remote_path IS NOT NULL')
            ->where("bucket_remote_path != ''");

        if ($productId > 0) {
            $select->where('product_id = ?', $productId);
        }
        if ($limit > 0) {
            $select->limit($limit);
        }

        $rows = $connection->fetchAll($select);
        $total = count($rows);
        $log("  Found $total restorable row(s).");

        if ($total === 0) {
            $log('  Nothing to restore.');
            $releaseLock();
            return 0;
        }

        // ── Dry-run report ────────────────────────────────────────────────────
        if ($isDryRun) {
            $log('');
            $log('════════════════════════════════════════════════════');
            $log('  DRY-RUN REPORT');
            $log('════════════════════════════════════════════════════');
            foreach ($rows as $row) {
                $log("  [DRY] RESTORE  SKU={$row['sku']} | {$row['local_path']} ← {$row['bucket_remote_path']}");
            }
            $log('');
            $log("  Would restore: $total file(s)");
            $log('  DRY-RUN — no files downloaded.');
            $releaseLock();
            return 0;
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP 2 — Connect to GCS
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log('STEP 2: Connecting to GCS and restoring files...');

        try {
            $storageOpts = ['suppressKeyFileNotice' => true];
            if ($gcsKeyFile !== '') {
                $storageOpts['keyFilePath'] = $gcsKeyFile;
                $log("  Auth: service-account key — $gcsKeyFile");
            } else {
                $log('  Auth: Application Default Credentials (ADC)');
            }
            $storageClient = new \Google\Cloud\Storage\StorageClient($storageOpts);
            $bucket        = $storageClient->bucket($gcsBucket);
            if (!$bucket->exists()) {
                throw new \RuntimeException("Bucket does not exist or is not accessible: $gcsBucket");
            }
            $log("  Bucket confirmed: gs://$gcsBucket/");
        } catch (\Throwable $e) {
            $log('ERROR: GCS connection failed — ' . $e->getMessage());
            $releaseLock();
            return 1;
        }

        // ── Restore loop ──────────────────────────────────────────────────────
        $totalRestored = 0;
        $totalErrors   = 0;
        $totalSkipped  = 0;
        $i             = 0;

        foreach ($rows as $row) {
            $i++;
            $pct  = (int) round($i / $total * 100);
            $pfx  = "$i/$total ($pct%)";

            $localPath        = $row['local_path'];
            $bucketRemotePath = $row['bucket_remote_path'];

            // Parse GCS object name from bucket_remote_path: gs://bucket/object
            if (!preg_match('#^gs://[^/]+/(.+)$#', $bucketRemotePath, $m)) {
                $log("  ERROR  [$pfx] Cannot parse GCS path: $bucketRemotePath");
                $totalErrors++;
                continue;
            }
            $gcsObjectName = $m[1];

            // Ensure local parent directory exists
            $localDir = dirname($localPath);
            if (!is_dir($localDir) && !mkdir($localDir, 0750, true)) {
                $log("  ERROR  [$pfx] Cannot create directory: $localDir");
                $totalErrors++;
                continue;
            }

            // Skip if local file already exists and matches expected size
            $expectedSize = (int) ($row['before_size'] ?? 0);
            if (file_exists($localPath) && $expectedSize > 0 && filesize($localPath) === $expectedSize) {
                $log("  SKIP   [$pfx] Already restored, size matches: $localPath");
                $totalSkipped++;
                continue;
            }

            try {
                $gcsObject = $bucket->object($gcsObjectName);
                if (!$gcsObject->exists()) {
                    $log("  ERROR  [$pfx] GCS object not found: gs://$gcsBucket/$gcsObjectName");
                    $totalErrors++;
                    continue;
                }

                $gcsObject->downloadToFile($localPath);

                // Verify download
                if (!file_exists($localPath)) {
                    throw new \RuntimeException("File not found after download: $localPath");
                }

                $this->logRepository->markRestored($localPath);
                $totalRestored++;
                $log("  RESTORED  [$pfx] SKU={$row['sku']} | $localPath");
            } catch (\Throwable $e) {
                $log("  ERROR  [$pfx] Restore failed: $localPath — " . $e->getMessage());
                $totalErrors++;
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $log('');
        $log('════════════════════════════════════════════════════');
        $log("  FINAL SUMMARY ($mode)");
        $log('════════════════════════════════════════════════════');
        $log("  Rows found     : $total");
        $log("  Restored       : $totalRestored");
        $log("  Skipped        : $totalSkipped");
        $log("  Errors         : $totalErrors");
        $log('');
        if ($totalRestored > 0) {
            $log('  IMPORTANT: Flush Magento cache after restore:');
            $log('    bin/magento cache:flush');
        }

        $releaseLock();
        return $totalErrors > 0 ? 1 : 0;
    }
}
