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
 * Disabled Product Cache Images Cleanup Service.
 *
 * Execution order (live, no ZIP, no GCS):
 *   STEP 1  Fetch disabled products from DB
 *   STEP 2  Scan cache directory once → build filename index
 *   STEP 3  Batch-fetch gallery + role images → collect candidate files
 *   STEP 4  Copy files to staging (abort on any failure)
 *   STEP 5  Delete cache files (only if staging copy confirmed + size matches)
 *
 * With --bucket: raw GCS upload (PHP SDK) is inserted BEFORE delete. Abort if upload fails.
 * With --zip:    ZIP creation + ZIP upload inserted before raw upload step.
 *
 * Options array keys (built by CleanupDisabledCacheCommand):
 *   dry_run      bool
 *   id_from      int
 *   id_to        int
 *   limit        int     0 = unlimited
 *   enable_zip   bool
 *   bucket       string
 *   credentials  string
 *   staging_dir  string
 */
class CleanupDisabledCacheService
{
    private const GCS_RAW_ROOT = 'raw_backup/pub/media/catalog/product/cache';
    private const GCS_ZIP_ROOT = 'zip_backup';

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DirectoryList      $directoryList,
        private readonly GcsService         $gcsService,
    ) {
    }

    public function execute(array $options, OutputInterface $output): int
    {
        $isDryRun   = (bool) $options['dry_run'];
        $idFrom     = max(1, (int) $options['id_from']);
        $idTo       = max(1, (int) $options['id_to']);
        $limit      = max(0, (int) $options['limit']);
        $enableZip  = (bool) $options['enable_zip'];
        $gcsBucket  = (string) $options['bucket'];
        $gcsKeyFile = (string) $options['credentials'];
        $mode       = $isDryRun ? 'DRY-RUN' : 'LIVE';
        $limitLabel = $limit > 0 ? (string) $limit : 'unlimited';

        $bp             = $this->directoryList->getRoot();
        $cacheDir       = $bp . '/pub/media/catalog/product/cache';
        $logDir         = $bp . '/var/log/carpetavenue/disabled_product_cache_cleanup';
        $defaultStaging = $logDir . '/staging';

        $customStagingDir = $options['staging_dir'] ?? '';
        if ($customStagingDir !== '') {
            if (!str_starts_with($customStagingDir, '/') || str_contains($customStagingDir, "\0")) {
                $output->writeln('<error>ERROR: staging-dir must be an absolute path and must not contain null bytes.</error>');
                return 1;
            }
            $stagingBaseDir = rtrim($customStagingDir, '/');
        } else {
            $stagingBaseDir = $defaultStaging;
        }
        $stagingDir = $stagingBaseDir . '/' . self::GCS_RAW_ROOT;

        // ── Lock ──────────────────────────────────────────────────────────────
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }
        $lockFile = $logDir . '/.lock';
        $lockFp   = fopen($lockFile, 'w');
        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            $output->writeln('<error>ERROR: Another instance is already running.</error>');
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

        $fmt = [GcsService::class, 'fmtBytes'];

        // ── Pre-flight ────────────────────────────────────────────────────────
        if (!$isDryRun) {
            if ($enableZip && !class_exists('ZipArchive')) {
                $log('ERROR: PHP ZipArchive extension is not available (required by --zip).');
                $releaseLock();
                return 1;
            }
            if ($gcsKeyFile !== '' && !is_readable($gcsKeyFile)) {
                $log("ERROR: Credentials file not readable: $gcsKeyFile");
                $releaseLock();
                return 1;
            }
        }
        if (!is_dir($cacheDir)) {
            $log("ERROR: Cache directory does not exist: $cacheDir");
            $releaseLock();
            return 1;
        }

        // ── Header ────────────────────────────────────────────────────────────
        $log('════════════════════════════════════════════════════');
        $log('  Magento 2 — Disabled Product CACHE Image Cleanup');
        $log('  SAFE   — Only cache files. Originals NOT touched.');
        $log('  BACKUP — Stage -> [ZIP -> GCS] -> Delete.');
        $log('════════════════════════════════════════════════════');
        $log("  Mode           : $mode");
        $log("  ZIP backup     : " . ($enableZip ? 'ENABLED' : 'DISABLED (pass --zip to enable)'));
        $log("  Limit (files)  : $limitLabel");
        $log("  Product ID     : $idFrom - $idTo");
        if (!$isDryRun) {
            $log("  Staging dir    : $stagingDir");
            if ($gcsBucket !== '') {
                $log("  GCS bucket     : $gcsBucket");
                if ($enableZip) {
                    $log("  GCS ZIP path   : gs://$gcsBucket/" . self::GCS_ZIP_ROOT . "/backup_id-{$idFrom}-{$idTo}.zip");
                }
            }
        } else {
            $log('  Backup         : N/A (dry-run)');
        }
        $log('════════════════════════════════════════════════════');

        // ── DB ────────────────────────────────────────────────────────────────
        $connection = $this->resourceConnection->getConnection();
        $tbl        = fn(string $name): string => $this->resourceConnection->getTableName($name);

        // ── EAV attribute IDs ─────────────────────────────────────────────────
        $attrRows = $connection->fetchPairs(
            $connection->select()
                ->from(['ea' => $tbl('eav_attribute')], ['ea.attribute_code', 'ea.attribute_id'])
                ->join(['eet' => $tbl('eav_entity_type')], 'ea.entity_type_id = eet.entity_type_id', [])
                ->where('eet.entity_type_code = ?', 'catalog_product')
                ->where('ea.attribute_code IN (?)', ['status', 'image', 'small_image', 'thumbnail', 'swatch_image'])
        );
        $attrStatus  = (int) ($attrRows['status'] ?? 0);
        $roleAttrIds = array_filter([
            (int) ($attrRows['image']        ?? 0),
            (int) ($attrRows['small_image']  ?? 0),
            (int) ($attrRows['thumbnail']    ?? 0),
            (int) ($attrRows['swatch_image'] ?? 0),
        ]);

        // ══════════════════════════════════════════════════════════════════════
        // STEP 1 — Fetch disabled products
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log("STEP 1: Fetching disabled products (entity_id $idFrom – $idTo)...");

        $products = $connection->fetchAll(
            $connection->select()
                ->from(['cpe' => $tbl('catalog_product_entity')], ['entity_id', 'sku'])
                ->join(
                    ['cpei' => $tbl('catalog_product_entity_int')],
                    'cpe.entity_id = cpei.entity_id'
                    . ' AND cpei.attribute_id = ' . $attrStatus
                    . ' AND cpei.store_id = 0',
                    []
                )
                ->where('cpei.value = ?', 2)
                ->where('cpe.entity_id >= ?', $idFrom)
                ->where('cpe.entity_id <= ?', $idTo)
        );

        $totalProducts = count($products);
        $log("  Found $totalProducts disabled products");

        if (empty($products)) {
            $log('  Nothing to do.');
            $releaseLock();
            return 0;
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP 2 — Scan cache directory once → build filename index
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log('STEP 2: Scanning cache directory once to build index...');
        $scanStart    = microtime(true);
        $cacheIndex   = [];
        $scannedTotal = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $cacheIndex[$file->getFilename()][] = [
                    'abs'  => $file->getPathname(),
                    'size' => $file->getSize(),
                ];
                $scannedTotal++;
                if ($scannedTotal % 100000 === 0) {
                    $log('  ... indexed ' . $scannedTotal . ' files (' . round(microtime(true) - $scanStart, 1) . 's)...');
                }
            }
        } catch (\Exception $e) {
            $log('WARNING: Error scanning cache dir: ' . $e->getMessage());
        }

        $log('  Indexed ' . $scannedTotal . ' cache files (' . count($cacheIndex) . ' unique basenames) in '
            . round(microtime(true) - $scanStart, 2) . 's');

        // ══════════════════════════════════════════════════════════════════════
        // STEP 3 — Batch-fetch gallery + role images → collect candidate files
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log('STEP 3: Batch-fetching gallery + role images and collecting cache candidates...');

        // Two queries total regardless of product count
        $allProductIds = array_column($products, 'entity_id');

        $galleryRows = $connection->fetchAll(
            $connection->select()
                ->from(['mg' => $tbl('catalog_product_entity_media_gallery')], ['mg.value'])
                ->join(
                    ['mgvte' => $tbl('catalog_product_entity_media_gallery_value_to_entity')],
                    'mg.value_id = mgvte.value_id',
                    ['entity_id']
                )
                ->where('mgvte.entity_id IN (?)', $allProductIds)
                ->where('mg.value IS NOT NULL')
                ->where("mg.value != ''")
        );
        $galleryByProduct = [];
        foreach ($galleryRows as $row) {
            $galleryByProduct[(int) $row['entity_id']][] = $row['value'];
        }

        $roleByProduct = [];
        if (!empty($roleAttrIds)) {
            $roleRows = $connection->fetchAll(
                $connection->select()
                    ->from($tbl('catalog_product_entity_varchar'), ['entity_id', 'value'])
                    ->where('entity_id IN (?)', $allProductIds)
                    ->where('attribute_id IN (?)', $roleAttrIds)
                    ->where('value IS NOT NULL')
                    ->where("value != ''")
                    ->where("value != 'no_selection'")
            );
            foreach ($roleRows as $row) {
                $roleByProduct[(int) $row['entity_id']][] = basename($row['value']);
            }
        }

        $log('  Gallery data fetched: ' . count($galleryByProduct) . ' product(s) with images');

        $filesToDelete = [];
        $limitReached  = false;
        $skuMap        = array_column($products, 'sku', 'entity_id');

        foreach ($products as $product) {
            $productId = (int) $product['entity_id'];
            $sku       = $product['sku'];

            if ($limit > 0 && count($filesToDelete) >= $limit) {
                $limitReached = true;
                break;
            }

            $gallery = $galleryByProduct[$productId] ?? [];
            if (empty($gallery)) {
                continue;
            }

            $protectedBasenames = array_unique($roleByProduct[$productId] ?? []);
            $cacheFiles         = $this->findCacheFiles($gallery, $cacheIndex, $cacheDir);

            foreach ($cacheFiles as $absPath => $info) {
                if ($limit > 0 && count($filesToDelete) >= $limit) {
                    $limitReached = true;
                    break;
                }
                if (in_array(basename($info['orig']), $protectedBasenames, true)) {
                    continue;
                }
                $relPath = ltrim(str_replace($cacheDir, '', $absPath), '/');
                $filesToDelete[$absPath] = [
                    'size'      => $info['size'],
                    'orig'      => $info['orig'],
                    'sku'       => $sku,
                    'productId' => $productId,
                    'relPath'   => $relPath,
                ];
            }
            if ($limitReached) {
                break;
            }
        }

        $totalToDelete = count($filesToDelete);
        $totalBytes    = (int) array_sum(array_column($filesToDelete, 'size'));

        $log('  Files to ' . ($isDryRun ? 'report' : 'process') . ": $totalToDelete | Size: " . ($fmt)($totalBytes));
        if ($limitReached) {
            $log("  Limit of $limitLabel applied — collection stopped early.");
        }

        // ── Dry-run report ────────────────────────────────────────────────────
        if ($isDryRun) {
            $log('');
            $log('════════════════════════════════════════════════════');
            $log('  DRY-RUN REPORT');
            $log('════════════════════════════════════════════════════');
            foreach ($filesToDelete as $absPath => $info) {
                $mtime = file_exists($absPath) ? date('Y-m-d H:i:s', filemtime($absPath)) : 'N/A';
                $log("  [DRY] DEL  SKU={$info['sku']} | {$info['relPath']} (" . ($fmt)($info['size']) . ") | orig={$info['orig']} | modified=$mtime");
            }
            $this->printSummary($log, $mode, $totalProducts, $idFrom, $idTo, $scannedTotal,
                $limitLabel, $limitReached, true, $totalToDelete, $totalBytes, $fmt);
            $log('  DRY-RUN — no files deleted.');
            $log('  Run with --live (optionally --limit=N) for a safe first live run.');
            $releaseLock();
            return 0;
        }

        if ($totalToDelete === 0) {
            $log('  Nothing to backup or delete.');
            $this->printSummary($log, $mode, $totalProducts, $idFrom, $idTo, $scannedTotal,
                $limitLabel, $limitReached, false, 0, 0, $fmt);
            $releaseLock();
            return 0;
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP 4 — Copy files to staging
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log("STEP 4: Copying $totalToDelete files to staging directory...");
        $log("  Staging dir: $stagingDir");

        if (!is_dir($stagingDir) && !mkdir($stagingDir, 0750, true)) {
            $log("ERROR: Cannot create staging directory: $stagingDir — ABORTING.");
            $releaseLock();
            return 1;
        }

        $freeBytes = disk_free_space($stagingDir);
        if ($freeBytes !== false && $freeBytes < $totalBytes + 1073741824) {
            $log('  WARNING: Low disk space! Need ~' . ($fmt)($totalBytes) . ' | Available: ' . ($fmt)($freeBytes));
        }

        $moveConfirmed = [];
        $moveSkipped   = 0;
        $moveCopied    = 0;
        $moveFailed    = [];
        $moveCount     = 0;

        foreach ($filesToDelete as $absPath => $info) {
            $moveCount++;
            $stagingAbsPath = $stagingDir . '/' . $info['relPath'];
            $stagingFileDir = dirname($stagingAbsPath);

            // Resume-safe: accept already-staged file only if sizes match
            if (file_exists($stagingAbsPath)) {
                if (filesize($stagingAbsPath) === $info['size']) {
                    $moveConfirmed[$absPath] = $stagingAbsPath;
                    $moveSkipped++;
                    if ($moveSkipped <= 50 || $moveSkipped % 1000 === 0) {
                        $log("  SKIP (already staged)  {$info['relPath']}");
                    }
                    continue;
                }
                // Stale or partial staging copy — remove and re-copy
                @unlink($stagingAbsPath);
                $log("  WARN  Removed stale staging file (size mismatch): {$info['relPath']}");
            }

            if (!is_dir($stagingFileDir) && !mkdir($stagingFileDir, 0750, true)) {
                $reason = error_get_last()['message'] ?? 'mkdir failed';
                $moveFailed[$absPath] = $reason;
                $log("  ERROR  Cannot create staging dir: $stagingFileDir — $reason");
                continue;
            }

            if (@copy($absPath, $stagingAbsPath)) {
                $moveConfirmed[$absPath] = $stagingAbsPath;
                $moveCopied++;
                if ($moveCopied <= 50 || $moveCopied % 1000 === 0) {
                    $log("  STAGED  SKU={$info['sku']} | {$info['relPath']} (" . ($fmt)($info['size']) . ')');
                }
            } else {
                $reason = error_get_last()['message'] ?? 'copy() failed';
                $moveFailed[$absPath] = $reason;
                $log("  ERROR  Cannot copy to staging: {$info['relPath']} — $reason");
            }

            if ($moveCount % 5000 === 0) {
                $log("  ... progress: $moveCount/$totalToDelete | staged=$moveCopied skipped=$moveSkipped failed=" . count($moveFailed));
            }
        }

        $confirmedCount = count($moveConfirmed);
        $failedCount    = count($moveFailed);

        $log('');
        $log("  Staging summary: copied=$moveCopied | skipped(already staged)=$moveSkipped | failed=$failedCount | confirmed=$confirmedCount");

        if ($failedCount > 0) {
            $log("ERROR: $failedCount file(s) failed to stage — ABORTING (no files deleted).");
            $releaseLock();
            return 1;
        }
        if ($confirmedCount === 0) {
            $log('  Nothing staged — nothing to delete.');
            $releaseLock();
            return 0;
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP 5 — [--zip] Create local ZIP
        // ══════════════════════════════════════════════════════════════════════
        $zipFile = '';
        $zipSize = 0;

        if ($enableZip) {
            $log('');
            $log("STEP 5: Creating local ZIP backup of $confirmedCount confirmed files...");

            $zipFile     = "$logDir/backup_id-{$idFrom}-{$idTo}.zip";
            $zipIsNew    = !file_exists($zipFile);
            $zipArchive  = new \ZipArchive();
            $zipOpenFlag = $zipIsNew ? (\ZipArchive::CREATE | \ZipArchive::OVERWRITE) : \ZipArchive::CREATE;

            if ($zipArchive->open($zipFile, $zipOpenFlag) !== true) {
                $log("ERROR: Cannot open ZIP: $zipFile — ABORTING (no files deleted).");
                $releaseLock();
                return 1;
            }

            $zipCount  = 0;
            $zipErrors = 0;

            foreach ($moveConfirmed as $absPath => $stagingAbsPath) {
                $info    = $filesToDelete[$absPath];
                $zipPath = 'pub/media/catalog/product/cache/' . $info['relPath'];

                if ($zipArchive->locateName($zipPath) !== false) {
                    $zipCount++;
                    continue; // already added (resume)
                }

                if (!is_readable($stagingAbsPath)) {
                    $log("  WARN  Cannot read staging file for ZIP: {$info['relPath']}");
                    $zipErrors++;
                    continue;
                }
                if (!$zipArchive->addFile($stagingAbsPath, $zipPath)) {
                    $log("  WARN  Cannot add to ZIP: {$info['relPath']}");
                    $zipErrors++;
                    continue;
                }
                $zipCount++;

                // Flush every 5000 entries to avoid memory exhaustion
                if ($zipCount % 5000 === 0) {
                    $zipArchive->close();
                    if ($zipArchive->open($zipFile, \ZipArchive::CREATE) !== true) {
                        $log('ERROR: Cannot reopen ZIP after flush — ABORTING.');
                        $releaseLock();
                        return 1;
                    }
                    $log("  ZIP flushed ($zipCount files)");
                }
            }

            $zipArchive->close();

            if ($zipErrors > 0) {
                $log("ERROR: $zipErrors file(s) could not be added to ZIP — ABORTING (no files deleted).");
                $releaseLock();
                return 1;
            }

            $zipSize = (int) (file_exists($zipFile) ? filesize($zipFile) : 0);
            $log('  ZIP ready: ' . $zipCount . ' files | ' . ($fmt)($zipSize) . " | $zipFile");

            // ── STEP 6: Upload ZIP to GCS ─────────────────────────────────────
            if ($gcsBucket !== '') {
                $log('');
                $log('════════════════════════════════════════════════════');
                $log('  STEP 6: Uploading ZIP to GCS via PHP SDK...');
                $log('════════════════════════════════════════════════════');

                $zipObjectName = self::GCS_ZIP_ROOT . "/backup_id-{$idFrom}-{$idTo}.zip";
                $this->gcsService->uploadZip($zipFile, $zipSize, $gcsBucket, $zipObjectName, $gcsKeyFile, $log);
            } else {
                $log('');
                $log('  STEP 6: SKIPPED — no --bucket specified. Local ZIP kept: ' . $zipFile);
            }
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP X — Upload raw staging files to GCS via PHP SDK (BEFORE delete)
        // ══════════════════════════════════════════════════════════════════════
        if ($gcsBucket !== '') {
            $rawStep = 5 + ($enableZip ? ($gcsBucket !== '' ? 2 : 1) : 0);
            $log('');
            $log('════════════════════════════════════════════════════');
            $log("  STEP $rawStep: Uploading raw staging files to GCS via PHP SDK...");
            $log('════════════════════════════════════════════════════');
            $rawOk = $this->gcsService->uploadRawFiles($stagingDir, $gcsBucket, self::GCS_RAW_ROOT, $gcsKeyFile, $log);
            if (!$rawOk) {
                $log('ERROR: GCS raw upload FAILED — ABORTING (no files deleted). Staging files kept safely.');
                $releaseLock();
                return 1;
            }
            $log('  GCS raw upload confirmed. Proceeding with delete.');
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP X — Delete original cache files (confirmed in staging + GCS)
        // ══════════════════════════════════════════════════════════════════════
        $deleteStep = 5
            + ($enableZip ? ($gcsBucket !== '' ? 2 : 1) : 0)
            + ($gcsBucket !== '' ? 1 : 0);
        $log('');
        $log('════════════════════════════════════════════════════');
        $log("  STEP $deleteStep: Deleting $confirmedCount original cache files (each confirmed in staging)...");
        $log('════════════════════════════════════════════════════');

        $totalDeleted = 0;
        $totalErrors  = 0;
        $deleteI      = 0;

        foreach ($moveConfirmed as $absPath => $stagingAbsPath) {
            $deleteI++;
            $pct     = (int) round($deleteI / $confirmedCount * 100);
            $pfx     = "$deleteI/$confirmedCount ($pct%)";
            $info    = $filesToDelete[$absPath];
            $sizeStr = ($fmt)($info['size']);
            $mtime   = file_exists($absPath) ? date('Y-m-d H:i:s', filemtime($absPath)) : 'N/A';

            if (!file_exists($stagingAbsPath)) {
                $log("  ERROR  [$pfx] Staging missing, skip: {$info['relPath']}");
                $totalErrors++;
                continue;
            }
            if (filesize($stagingAbsPath) !== $info['size']) {
                $log("  ERROR  [$pfx] Staging size mismatch, skip: {$info['relPath']}");
                $totalErrors++;
                continue;
            }

            if (@unlink($absPath)) {
                $log("  DELETED  [$pfx] SKU={$info['sku']} | {$info['relPath']} ($sizeStr) | modified=$mtime");
                $totalDeleted++;
            } else {
                $reason = error_get_last()['message'] ?? 'unlink failed';
                $log("  ERROR  [$pfx] Cannot delete: SKU={$info['sku']} | {$info['relPath']} — $reason");
                $totalErrors++;
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $this->printSummary($log, $mode, $totalProducts, $idFrom, $idTo, $scannedTotal,
            $limitLabel, $limitReached, false, $totalToDelete, $totalBytes, $fmt,
            $totalDeleted, $totalErrors, $moveCopied, $moveSkipped);

        // ── Staging cleanup ───────────────────────────────────────────────────
        if (!$isDryRun && $gcsBucket !== '' && $totalErrors === 0) {
            $log('');
            $log('  Cleaning up local staging (originals safely on GCS)...');
            $this->removeDirectory($stagingDir);
            $log("  Staging removed: $stagingDir");
        } elseif (!$isDryRun && $gcsBucket === '') {
            $log('');
            $log("  Staging kept (no GCS bucket configured): $stagingDir");
        } elseif (!$isDryRun && $totalErrors > 0) {
            $log('');
            $log("  Staging kept (errors encountered — review before cleanup): $stagingDir");
        }

        $releaseLock();
        return $totalErrors > 0 ? 1 : 0;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Find all cache file entries for the given gallery image paths using the pre-built index.
     * Matches by basename first (O(1) lookup), then verifies the original path is embedded
     * in the cache file's absolute path.
     */
    private function findCacheFiles(array $imagePaths, array &$cacheIndex, string $cacheDir): array
    {
        $found = [];
        foreach ($imagePaths as $imgPath) {
            $basename = basename($imgPath);
            if (!isset($cacheIndex[$basename])) {
                continue;
            }
            foreach ($cacheIndex[$basename] as $entry) {
                // Verify the original path segment is actually embedded in the cache path
                if (str_contains($entry['abs'], $imgPath)) {
                    $found[$entry['abs']] = ['size' => $entry['size'], 'orig' => $imgPath];
                }
            }
        }
        return $found;
    }

    private function printSummary(
        callable $log,
        string   $mode,
        int      $totalProducts,
        int      $idFrom,
        int      $idTo,
        int      $scannedTotal,
        string   $limitLabel,
        bool     $limitReached,
        bool     $isDryRun,
        int      $totalToDelete,
        int      $totalBytes,
        callable $fmt,
        int      $totalDeleted = 0,
        int      $totalErrors  = 0,
        int      $moveCopied   = 0,
        int      $moveSkipped  = 0,
    ): void {
        $log('');
        $log('════════════════════════════════════════════════════');
        $log("  FINAL SUMMARY ($mode)");
        $log('════════════════════════════════════════════════════');
        $log("  Disabled products   : $totalProducts (entity_id $idFrom – $idTo)");
        $log("  Cache files indexed : $scannedTotal");
        $log("  Limit               : $limitLabel" . ($limitReached ? ' (REACHED)' : ''));
        if ($isDryRun) {
            $log("  Would delete        : $totalToDelete file(s)");
            $log("  Space reclaimable   : " . ($fmt)($totalBytes));
        } else {
            $log("  Files collected     : $totalToDelete");
            $log("  Staged (copied)     : $moveCopied");
            $log("  Staged (already)    : $moveSkipped");
            $log("  Deleted             : $totalDeleted file(s)");
            $log("  Space freed         : " . ($fmt)($totalBytes));
            $log("  Errors              : $totalErrors");
        }
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
