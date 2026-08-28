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
 * Orphan Cache Images Cleanup Service.
 *
 * An "orphan" cache image is a file under pub/media/catalog/product/cache/
 * whose source original path is NOT present in the Magento DB (gallery or role attributes).
 *
 * Execution order (live):
 *   STEP 1  Build DB image path set (2 queries: gallery + role attrs)
 *   STEP 2  Scan cache directory, identify orphan files
 *   STEP 3  Copy orphans to staging (abort on any failure)
 *   STEP 4  [--zip] Create ZIP
 *   STEP 5  [--zip --bucket] Upload ZIP to GCS
 *   STEP X  [--bucket] Upload raw staging files to GCS via PHP SDK (ABORT if fails)
 *   STEP X  Delete orphan cache files (only if staging + GCS confirmed)
 *
 * Options array keys:
 *   dry_run      bool
 *   limit        int    0 = unlimited
 *   enable_zip   bool
 *   bucket       string
 *   credentials  string
 *   staging_dir  string
 */
class CleanupOrphanCacheService
{
    private const GCS_RAW_ROOT = 'raw_backup/pub/media/catalog/product/cache';
    private const GCS_ZIP_ROOT = 'zip_backup';

    private static array $EXCLUDED_NAMES = ['.htaccess', '.DS_Store', 'placeholder', 'watermark', 'Thumbs.db'];
    private static array $IMAGE_EXTS     = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff', 'tif'];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DirectoryList      $directoryList,
        private readonly GcsService         $gcsService,
    ) {
    }

    public function execute(array $options, OutputInterface $output): int
    {
        $isDryRun   = (bool) $options['dry_run'];
        $limit      = max(0, (int) $options['limit']);
        $enableZip  = (bool) $options['enable_zip'];
        $gcsBucket  = (string) $options['bucket'];
        $gcsKeyFile = (string) $options['credentials'];
        $mode       = $isDryRun ? 'DRY-RUN' : 'LIVE';
        $limitLabel = $limit > 0 ? (string) $limit : 'unlimited';

        $bp             = $this->directoryList->getRoot();
        $cacheDir       = $bp . '/pub/media/catalog/product/cache';
        $logDir         = $bp . '/var/log/carpetavenue/orphan_cache_cleanup';
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
        if (!is_dir($cacheDir)) {
            $log("ERROR: Cache directory not found: $cacheDir");
            $releaseLock();
            return 1;
        }

        $log('════════════════════════════════════════════════════');
        $log('  Magento 2 — Orphan Cache Images Cleanup');
        $log('  SAFE   — Only cache files. Originals NOT touched.');
        $log('  BACKUP — Stage -> [ZIP -> GCS] -> Delete.');
        $log('════════════════════════════════════════════════════');
        $log("  Mode       : $mode");
        $log("  Limit      : $limitLabel");
        $log("  ZIP backup : " . ($enableZip ? 'ENABLED' : 'DISABLED (pass --zip to enable)'));
        $log("  Cache dir  : $cacheDir");
        if (!$isDryRun) {
            $log("  Staging    : $stagingDir");
        }
        $log('════════════════════════════════════════════════════');

        // ── DB ────────────────────────────────────────────────────────────────
        $connection = $this->resourceConnection->getConnection();
        $tbl        = fn(string $name): string => $this->resourceConnection->getTableName($name);

        // ── Role attribute IDs ────────────────────────────────────────────────
        $roleAttrIds = $this->getRoleAttributeIds($connection, $tbl);

        // ══════════════════════════════════════════════════════════════════════
        // STEP 1 — Build DB image path set (2 queries)
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log('STEP 1: Building DB-referenced image path set...');
        $dbBuildStart = microtime(true);

        $galleryPaths = $connection->fetchCol(
            $connection->select()
                ->from($tbl('catalog_product_entity_media_gallery'), 'value')
                ->where('value IS NOT NULL')
                ->where("value != ''")
        );

        $rolePaths = [];
        if (!empty($roleAttrIds)) {
            $rolePaths = $connection->fetchCol(
                $connection->select()
                    ->from($tbl('catalog_product_entity_varchar'), 'value')
                    ->where('attribute_id IN (?)', $roleAttrIds)
                    ->where('value IS NOT NULL')
                    ->where("value != ''")
                    ->where("value != 'no_selection'")
            );
        }

        $dbImagePaths = [];
        foreach (array_merge($galleryPaths, $rolePaths) as $path) {
            $dbImagePaths['/' . ltrim($path, '/')] = true;
        }

        $log('  DB image set built: ' . count($dbImagePaths) . ' unique paths in '
            . round(microtime(true) - $dbBuildStart, 2) . 's '
            . '(gallery: ' . count($galleryPaths) . ' | role attrs: ' . count($rolePaths) . ')');

        // ══════════════════════════════════════════════════════════════════════
        // STEP 2 — Scan cache directory, collect orphans
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log('STEP 2: Scanning cache directory for orphan files...');
        $scanStart = microtime(true);

        $orphans      = [];
        $scannedTotal = 0;
        $keptTotal    = 0;
        $limitReached = false;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $scannedTotal++;
            $absPath  = $file->getPathname();
            $filename = $file->getFilename();

            if ($this->isExcluded($filename) || !$this->isImageFile($filename)) {
                $keptTotal++;
                continue;
            }

            $origRelPath = $this->extractOriginalRelPath($absPath, $cacheDir);
            if ($origRelPath === null) {
                $keptTotal++;
                continue;
            }

            if (isset($dbImagePaths[$origRelPath])) {
                $keptTotal++;
                continue;
            }

            $relPath           = ltrim(str_replace($cacheDir, '', $absPath), '/');
            $orphans[$absPath] = [
                'size'    => $file->getSize(),
                'relPath' => $relPath,
                'orig'    => $origRelPath,
            ];

            if ($limit > 0 && count($orphans) >= $limit) {
                $limitReached = true;
                break;
            }

            if ($scannedTotal % 50000 === 0) {
                $log('  ... scanned ' . $scannedTotal . ' | orphans found: ' . count($orphans));
            }
        }

        $scanElapsed  = round(microtime(true) - $scanStart, 2);
        $totalOrphans = count($orphans);
        $totalBytes   = (int) array_sum(array_column($orphans, 'size'));

        $log("  Scanned: $scannedTotal | Kept: $keptTotal | Orphans: $totalOrphans | Size: " . ($fmt)($totalBytes) . " | Time: {$scanElapsed}s");
        if ($limitReached) {
            $log("  Limit of $limitLabel reached — scan stopped early.");
        }

        // ── Dry-run ───────────────────────────────────────────────────────────
        if ($isDryRun) {
            $log('');
            $log('════════════════════════════════════════════════════');
            $log('  DRY-RUN REPORT');
            $log('════════════════════════════════════════════════════');
            foreach ($orphans as $absPath => $info) {
                $mtime = file_exists($absPath) ? date('Y-m-d H:i:s', filemtime($absPath)) : 'N/A';
                $log("  [DRY] ORPHAN  {$info['relPath']} (" . ($fmt)($info['size']) . ") | orig={$info['orig']} | modified=$mtime");
            }
            $log('');
            $log("  Would delete     : $totalOrphans orphan cache file(s)");
            $log("  Space reclaimable: " . ($fmt)($totalBytes));
            $log('  DRY-RUN — no files deleted.');
            $releaseLock();
            return 0;
        }

        if ($totalOrphans === 0) {
            $log('  No orphan cache files found. Nothing to do.');
            $releaseLock();
            return 0;
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP 3 — Copy orphans to staging
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log("STEP 3: Copying $totalOrphans orphan files to staging...");

        if (!is_dir($stagingDir) && !mkdir($stagingDir, 0750, true)) {
            $log("ERROR: Cannot create staging directory: $stagingDir — ABORTING.");
            $releaseLock();
            return 1;
        }

        $moveConfirmed = [];
        $moveCopied    = 0;
        $moveSkipped   = 0;
        $moveFailed    = [];

        foreach ($orphans as $absPath => $info) {
            $stagingAbsPath = $stagingDir . '/' . $info['relPath'];
            $stagingFileDir = dirname($stagingAbsPath);

            // Resume-safe: accept already-staged file only if sizes match
            if (file_exists($stagingAbsPath)) {
                if (filesize($stagingAbsPath) === $info['size']) {
                    $moveConfirmed[$absPath] = $stagingAbsPath;
                    $moveSkipped++;
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
                if ($moveCopied <= 50 || $moveCopied % 2000 === 0) {
                    $log("  STAGED  {$info['relPath']} (" . ($fmt)($info['size']) . ')');
                }
            } else {
                $reason = error_get_last()['message'] ?? 'copy() failed';
                $moveFailed[$absPath] = $reason;
                $log("  ERROR  Cannot copy to staging: {$info['relPath']} — $reason");
            }
        }

        $confirmedCount = count($moveConfirmed);
        $log("  Staging summary: copied=$moveCopied | skipped=$moveSkipped | failed=" . count($moveFailed) . " | confirmed=$confirmedCount");

        if (count($moveFailed) > 0) {
            $log('ERROR: Staging failures — ABORTING (no files deleted).');
            $releaseLock();
            return 1;
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP 4 — [--zip] ZIP + GCS upload
        // ══════════════════════════════════════════════════════════════════════
        if ($enableZip) {
            $log('');
            $log("STEP 4: Creating local ZIP backup of $confirmedCount files...");

            $zipFile    = "$logDir/orphan_cache_{$datePart}.zip";
            $zipArchive = new \ZipArchive();
            if ($zipArchive->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                $log("ERROR: Cannot open ZIP: $zipFile — ABORTING.");
                $releaseLock();
                return 1;
            }

            $zipCount  = 0;
            $zipErrors = 0;

            foreach ($moveConfirmed as $absPath => $stagingAbsPath) {
                $info    = $orphans[$absPath];
                $zipPath = 'pub/media/catalog/product/cache/' . $info['relPath'];

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
                $log("ERROR: $zipErrors file(s) could not be added to ZIP — ABORTING.");
                $releaseLock();
                return 1;
            }

            $zipSize = (int) (file_exists($zipFile) ? filesize($zipFile) : 0);
            $log("  ZIP ready: $zipCount files | " . ($fmt)($zipSize) . " | $zipFile");

            if ($gcsBucket !== '') {
                $log('');
                $log('  Uploading ZIP to GCS...');
                $zipObjectName = self::GCS_ZIP_ROOT . "/orphan_cache_{$datePart}.zip";
                $this->gcsService->uploadZip($zipFile, $zipSize, $gcsBucket, $zipObjectName, $gcsKeyFile, $log);
            }
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP X — Upload raw staging files to GCS via PHP SDK (BEFORE delete)
        // ══════════════════════════════════════════════════════════════════════
        if ($gcsBucket !== '') {
            $rawStep = 4 + ($enableZip ? ($gcsBucket !== '' ? 2 : 1) : 0);
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
        // STEP X — Delete orphan cache files (confirmed in staging + GCS)
        // ══════════════════════════════════════════════════════════════════════
        $deleteStep = 4
            + ($enableZip ? ($gcsBucket !== '' ? 2 : 1) : 0)
            + ($gcsBucket !== '' ? 1 : 0);
        $log('');
        $log("STEP $deleteStep: Deleting $confirmedCount orphan cache files...");

        $totalDeleted = 0;
        $totalErrors  = 0;
        $deleteI      = 0;
        $logEvery     = max(1, (int) ($confirmedCount / 20)); // ~20 progress lines

        foreach ($moveConfirmed as $absPath => $stagingAbsPath) {
            $deleteI++;
            $info = $orphans[$absPath];

            if (!file_exists($stagingAbsPath)) {
                $pct = (int) round($deleteI / $confirmedCount * 100);
                $log("  ERROR  [$deleteI/$confirmedCount ($pct%)] Staging missing, skip: {$info['relPath']}");
                $totalErrors++;
                continue;
            }
            if (filesize($stagingAbsPath) !== $info['size']) {
                $pct = (int) round($deleteI / $confirmedCount * 100);
                $log("  ERROR  [$deleteI/$confirmedCount ($pct%)] Size mismatch in staging, skip: {$info['relPath']}");
                $totalErrors++;
                continue;
            }

            if (@unlink($absPath)) {
                $totalDeleted++;
                if ($deleteI % $logEvery === 0 || $deleteI === $confirmedCount) {
                    $pct = (int) round($deleteI / $confirmedCount * 100);
                    $log("  DELETED  [$deleteI/$confirmedCount ($pct%)] {$info['relPath']} (" . ($fmt)($info['size']) . ')');
                }
            } else {
                $pct    = (int) round($deleteI / $confirmedCount * 100);
                $reason = error_get_last()['message'] ?? 'unlink failed';
                $log("  ERROR  [$deleteI/$confirmedCount ($pct%)] Cannot delete: {$info['relPath']} — $reason");
                $totalErrors++;
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $log('');
        $log('════════════════════════════════════════════════════');
        $log("  FINAL SUMMARY ($mode)");
        $log('════════════════════════════════════════════════════');
        $log("  Cache files scanned : $scannedTotal");
        $log("  Orphans found       : $totalOrphans");
        $log("  Limit               : $limitLabel" . ($limitReached ? ' (REACHED)' : ''));
        $log("  Staged (copied)     : $moveCopied");
        $log("  Staged (already)    : $moveSkipped");
        $log("  Deleted             : $totalDeleted file(s)");
        $log("  Space freed         : " . ($fmt)($totalBytes));
        $log("  Errors              : $totalErrors");

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

    private function getRoleAttributeIds($connection, callable $tbl): array
    {
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from(['ea' => $tbl('eav_attribute')], ['ea.attribute_code', 'ea.attribute_id'])
                ->join(['eet' => $tbl('eav_entity_type')], 'ea.entity_type_id = eet.entity_type_id', [])
                ->where('eet.entity_type_code = ?', 'catalog_product')
                ->where('ea.attribute_code IN (?)', ['image', 'small_image', 'thumbnail', 'swatch_image'])
        );
        return array_filter(array_values(array_map('intval', $rows)));
    }

    private function isExcluded(string $filename): bool
    {
        foreach (self::$EXCLUDED_NAMES as $ex) {
            if (strcasecmp($filename, $ex) === 0) {
                return true;
            }
        }
        return false;
    }

    private function isImageFile(string $filename): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, self::$IMAGE_EXTS, true);
    }

    /**
     * Cache path structure: <cacheDir>/<hash>/<X>/<Y>/<filename>
     * Original relative path: /<X>/<Y>/<filename>
     * Returns null if the path does not match the expected structure.
     */
    private function extractOriginalRelPath(string $absPath, string $cacheDir): ?string
    {
        $afterCache = ltrim(str_replace($cacheDir, '', $absPath), '/');
        $parts      = explode('/', $afterCache);

        // Minimum structure: <hash>/<X>/<Y>/<filename> = 4 segments
        if (count($parts) < 4) {
            return null;
        }

        // Drop the hash directory (first segment)
        array_shift($parts);
        return '/' . implode('/', $parts);
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
