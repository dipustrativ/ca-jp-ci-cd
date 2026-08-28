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
 * Disabled Product Image Resize Service.
 *
 * Resizes original images for disabled products in-place.
 * Originals are backed up to staging BEFORE any resize.
 *
 * Execution order (live, no ZIP, no GCS):
 *   STEP 1  Fetch target products from DB
 *   STEP 2  Batch-fetch gallery + role images → collect unique originals
 *   STEP 3  Filter: skip already-small, non-images, missing
 *   STEP 4  Copy originals to staging (abort on any failure)
 *   STEP 5  Resize each image in-place (per-file non-fatal)
 *
 * With --bucket: raw GCS upload (PHP SDK) is inserted BEFORE resize. Abort if upload fails.
 * With --zip:    ZIP creation + ZIP upload inserted before raw upload step.
 *
 * Options array keys:
 *   dry_run          bool
 *   id_from          int
 *   id_to            int
 *   limit            int    0 = unlimited
 *   max_width        int
 *   max_height       int
 *   min_width        int
 *   min_height       int
 *   min_size         int    bytes
 *   quality          int    1–100
 *   driver           string 'auto'|'gd'|'imagick'
 *   enable_zip       bool
 *   bucket           string
 *   credentials      string
 *   staging_dir      string
 *   non_unique       bool   Skip unique filter (default: only attribute_id 270 = 1)
 *   skip_roles       bool
 *   sold_before      string 'YYYY-MM-DD' — only products last sold before this date (empty = no filter)
 */
class ResizeDisabledService
{
    private const GCS_RAW_ROOT = 'raw_backup/pub/media/catalog/product';
    private const GCS_ZIP_ROOT = 'zip_backup';

    public function __construct(
        private readonly ResourceConnection      $resourceConnection,
        private readonly DirectoryList           $directoryList,
        private readonly GcsService              $gcsService,
        private readonly ImageResizeLogRepository $logRepository,
    ) {
    }

    public function execute(array $options, OutputInterface $output): int
    {
        $isDryRun       = (bool) $options['dry_run'];
        $idFrom         = max(1, (int) $options['id_from']);
        $idTo           = max(1, (int) $options['id_to']);
        $limit          = max(0, (int) $options['limit']);
        $maxWidth       = max(1, (int) $options['max_width']);
        $maxHeight      = max(1, (int) $options['max_height']);
        $minWidth       = max(0, (int) ($options['min_width']  ?? 0));
        $minHeight      = max(0, (int) ($options['min_height'] ?? 0));
        $minSize        = max(0, (int) ($options['min_size']   ?? 0));
        $quality        = max(1, min(100, (int) $options['quality']));
        $enableZip      = (bool) $options['enable_zip'];
        $gcsBucket      = (string) $options['bucket'];
        $gcsKeyFile     = (string) $options['credentials'];
        $nonUnique      = (bool) ($options['non_unique'] ?? false);
        $skipRoles      = (bool) ($options['skip_roles']      ?? false);
        $requalify      = (bool) ($options['requalify'] ?? false);
        $soldBefore     = trim((string) ($options['sold_before'] ?? ''));
        $mode       = $isDryRun ? 'DRY-RUN' : 'LIVE';
        $limitLabel = $limit > 0 ? (string) $limit : 'unlimited';

        $bp           = $this->directoryList->getRoot();
        $mediaBaseDir = $bp . '/pub/media/catalog/product';
        $logDir       = $bp . '/var/log/carpetavenue/disabled_product_image_resize';
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

        // ── Detect driver ─────────────────────────────────────────────────────
        $imageDriver = $this->detectDriver($options['driver'] ?? 'auto');
        if ($imageDriver === 'none') {
            $output->writeln('<error>ERROR: Neither PHP GD nor Imagick extension is available.</error>');
            return 1;
        }

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
        if (!$isDryRun && $enableZip && !class_exists('ZipArchive')) {
            $log('ERROR: PHP ZipArchive extension is not available (required by --zip).');
            $releaseLock();
            return 1;
        }
        if (!is_dir($mediaBaseDir)) {
            $log("ERROR: Media directory does not exist: $mediaBaseDir");
            $releaseLock();
            return 1;
        }

        // ── Header ────────────────────────────────────────────────────────────
        $scope = 'disabled products only';
        $scope .= $nonUnique ? '' : ' [unique=Yes only]';
        $log('════════════════════════════════════════════════════');
        $log('  Magento 2 — Disabled Product Original Image Resize');
        $log('  BACKUP  — Originals copied to staging BEFORE resize.');
        $log('  IN-PLACE — Resized files REPLACE the originals.');
        $log('════════════════════════════════════════════════════');
        $log("  Mode           : $mode");
        $log("  Scope          : $scope");
        $log("  Driver         : $imageDriver");
        $log("  Max dimensions : {$maxWidth}×{$maxHeight} px");
        $log("  Quality        : $quality%");
        $log("  Re-quantify    : " . ($requalify ? 'YES — re-save within-dim images at same quality' : 'NO — within-dim images skipped'));
        $log("  Skip roles     : " . ($skipRoles ? 'YES' : 'NO'));
        $log("  Last sold before: " . ($soldBefore !== '' ? $soldBefore : '(no filter)'));
        $log("  ZIP backup     : " . ($enableZip ? 'ENABLED' : 'DISABLED'));
        $log("  Limit (images) : $limitLabel");
        $log("  Product ID     : $idFrom – $idTo");
        $log(!$isDryRun ? "  Staging dir    : $stagingDir" : '  Backup         : N/A (dry-run)');
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
        // STEP 1 — Fetch target products
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log("STEP 1: Fetching disabled products (entity_id $idFrom – $idTo)...");

        $productSelect = $connection->select()
            ->from(['cpe' => $tbl('catalog_product_entity')], ['entity_id', 'sku'])
            ->where('cpe.entity_id >= ?', $idFrom)
            ->where('cpe.entity_id <= ?', $idTo);

        $productSelect->join(
            ['cpei' => $tbl('catalog_product_entity_int')],
            'cpe.entity_id = cpei.entity_id'
            . ' AND cpei.attribute_id = ' . $attrStatus
            . ' AND cpei.store_id = 0',
            []
        )->where('cpei.value = ?', 2);

        if (!$nonUnique) {
            $productSelect->join(
                ['cpei_unique' => $tbl('catalog_product_entity_int')],
                'cpe.entity_id = cpei_unique.entity_id'
                . ' AND cpei_unique.attribute_id = 270'
                . ' AND cpei_unique.store_id = 0',
                []
            )->where('cpei_unique.value = ?', 1);
        }

        if ($soldBefore !== '') {
            $soldBeforeDateTime = $soldBefore . ' 00:00:00';
            $lastSaleSubquery   = $connection->select()
                ->from($tbl('sales_order_item'), ['sku', 'last_sold_at' => new \Zend_Db_Expr('MAX(created_at)')])
                ->group('sku');
            $productSelect->join(
                ['last_sale' => $lastSaleSubquery],
                'cpe.sku = last_sale.sku',
                ['last_sold_at']
            )->where('last_sale.last_sold_at < ?', $soldBeforeDateTime);
        }

        $products      = $connection->fetchAll($productSelect);
        $totalProducts = count($products);
        $log("  Found $totalProducts product(s)" . ($soldBefore !== '' ? " (last sold before $soldBefore)" : ''));

        if (empty($products)) {
            $log('  Nothing to do.');
            $releaseLock();
            return 0;
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP 2 — Batch-fetch gallery + role images → collect unique originals
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log('STEP 2: Batch-fetching gallery and role images from DB...');

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
                $roleByProduct[(int) $row['entity_id']][] = $row['value'];
            }
        }

        $log('  Images fetched: ' . count($galleryByProduct) . ' product(s) with gallery entries');

        $uniqueImages = [];
        foreach ($products as $product) {
            $productId = (int) $product['entity_id'];
            $sku       = $product['sku'];

            $gallery = $galleryByProduct[$productId] ?? [];
            $roles   = $roleByProduct[$productId]   ?? [];

            $toProcess = $skipRoles
                ? $this->getGalleryOnlyImages($gallery, $roles)
                : array_unique(array_merge($gallery, $roles));

            $roleSet = array_flip($roles);
            foreach ($toProcess as $imgDbPath) {
                $absPath = $mediaBaseDir . '/' . ltrim($imgDbPath, '/');
                if (!isset($uniqueImages[$absPath])) {
                    $uniqueImages[$absPath] = [
                        'sku'       => $sku,
                        'productId' => $productId,
                        'dbPath'    => $imgDbPath,
                        'imageType' => isset($roleSet[$imgDbPath]) ? 'main' : 'gallery',
                    ];
                }
            }
        }

        $log('  Unique original images collected: ' . count($uniqueImages));

        // ══════════════════════════════════════════════════════════════════════
        // STEP 3 — Filter: skip already-small, non-images, missing
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log('STEP 3: Filtering (skip already-small, unsupported types, missing files)...');

        // Batch-fetch existing log rows to skip already-converted images
        $existingLogRows = $this->logRepository->getExistingByPaths(array_keys($uniqueImages));

        $toResize          = [];
        $skippedFiles      = [];
        $skippedSmall      = 0;
        $skippedMissing    = 0;
        $skippedType       = 0;
        $skippedConverted  = 0;
        $limitReached      = false;

        foreach ($uniqueImages as $absPath => $meta) {
            if ($limit > 0 && count($toResize) >= $limit) {
                $limitReached = true;
                break;
            }

            // Skip images already successfully converted in a previous run
            // Do NOT add to $skippedFiles — upsert loop would overwrite STATUS_CONVERTED with STATUS_SKIPPED
            if (isset($existingLogRows[$absPath])
                && $existingLogRows[$absPath]['status'] === ImageResizeLog::STATUS_CONVERTED
            ) {
                $skippedConverted++;
                continue;
            }

            if (!file_exists($absPath)) {
                $skippedMissing++;
                $skippedFiles[$absPath] = array_merge($meta, ['skip_reason' => 'File not found', 'size' => null, 'width' => null, 'height' => null]);
                continue;
            }

            $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                $skippedType++;
                $skippedFiles[$absPath] = array_merge($meta, ['skip_reason' => "Unsupported type: $ext", 'size' => (int) filesize($absPath), 'width' => null, 'height' => null]);
                continue;
            }

            $fileSize = filesize($absPath);
            if ($minSize > 0 && $fileSize < $minSize) {
                $skippedSmall++;
                $skippedFiles[$absPath] = array_merge($meta, ['skip_reason' => "File size {$fileSize}B below minimum {$minSize}B", 'size' => (int) $fileSize, 'width' => null, 'height' => null]);
                continue;
            }

            [$imgW, $imgH] = @getimagesize($absPath) ?: [0, 0];

            if ($minWidth > 0 && $imgW < $minWidth) {
                $skippedSmall++;
                $skippedFiles[$absPath] = array_merge($meta, ['skip_reason' => "Width {$imgW}px below minimum {$minWidth}px", 'size' => (int) $fileSize, 'width' => $imgW, 'height' => $imgH]);
                continue;
            }
            if ($minHeight > 0 && $imgH < $minHeight) {
                $skippedSmall++;
                $skippedFiles[$absPath] = array_merge($meta, ['skip_reason' => "Height {$imgH}px below minimum {$minHeight}px", 'size' => (int) $fileSize, 'width' => $imgW, 'height' => $imgH]);
                continue;
            }
            if ($imgW <= $maxWidth && $imgH <= $maxHeight) {
                if ($requalify) {
                    // Re-save at configured quality without changing dimensions
                    $relPath = ltrim(str_replace($mediaBaseDir, '', $absPath), '/');
                    $toResize[$absPath] = array_merge($meta, [
                        'size'         => (int) $fileSize,
                        'relPath'      => $relPath,
                        'width'        => $imgW,
                        'height'       => $imgH,
                        'quality_only' => true,
                    ]);
                    continue;
                }
                $skippedSmall++;
                $skippedFiles[$absPath] = array_merge($meta, ['skip_reason' => "Already within {$maxWidth}×{$maxHeight}px ({$imgW}×{$imgH}px)", 'size' => (int) $fileSize, 'width' => $imgW, 'height' => $imgH]);
                continue;
            }

            $relPath = ltrim(str_replace($mediaBaseDir, '', $absPath), '/');
            $toResize[$absPath] = array_merge($meta, [
                'size'         => (int) $fileSize,
                'relPath'      => $relPath,
                'width'        => $imgW,
                'height'       => $imgH,
                'quality_only' => false,
            ]);
        }

        $totalToResize = count($toResize);
        $totalBytes    = (int) array_sum(array_column($toResize, 'size'));
        $log("  To resize: $totalToResize | Skipped: converted=$skippedConverted small/type/missing=" . ($skippedSmall + $skippedType + $skippedMissing)
            . " ($skippedSmall small, $skippedType unsupported type, $skippedMissing missing, $skippedConverted already converted)");
        $log("  Total size of originals to backup: " . ($fmt)($totalBytes));

        // ── Dry-run report ────────────────────────────────────────────────────
        if ($isDryRun) {
            $log('');
            $log('════════════════════════════════════════════════════');
            $log('  DRY-RUN REPORT');
            $log('════════════════════════════════════════════════════');
            foreach ($toResize as $absPath => $info) {
                $label = ($info['quality_only'] ?? false) ? 'REQUALI' : 'RESIZE ';
                $log("  [DRY] $label  SKU={$info['sku']} | {$info['relPath']} | {$info['width']}×{$info['height']}px | " . ($fmt)($info['size']));
            }
            $log('');
            $log("  Would resize     : $totalToResize image(s)");
            $log("  Backup size      : " . ($fmt)($totalBytes) . " (staging only — originals resized in-place)");
            $log('  DRY-RUN — no files modified.');
            $releaseLock();
            return 0;
        }

        if ($totalToResize === 0) {
            $log('  No images need resizing.');
            $releaseLock();
            return 0;
        }

        // ── Log skipped images to DB ──────────────────────────────────────────
        foreach ($skippedFiles as $absPath => $skippedInfo) {
            $this->logRepository->upsert([
                'product_id'    => $skippedInfo['productId'],
                'sku'           => $skippedInfo['sku'],
                'image_type'    => $skippedInfo['imageType'],
                'local_path'    => $absPath,
                'db_path'       => $skippedInfo['dbPath'],
                'before_size'   => $skippedInfo['size'],
                'before_width'  => $skippedInfo['width'],
                'before_height' => $skippedInfo['height'],
                'status'        => ImageResizeLog::STATUS_SKIPPED,
                'skip_reason'   => $skippedInfo['skip_reason'],
            ]);
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP 4 — Copy originals to staging
        // ══════════════════════════════════════════════════════════════════════
        $log('');
        $log("STEP 4: Copying $totalToResize original files to staging...");

        if (!is_dir($stagingDir) && !mkdir($stagingDir, 0750, true)) {
            $log("ERROR: Cannot create staging directory: $stagingDir — ABORTING.");
            $releaseLock();
            return 1;
        }

        $moveConfirmed = [];
        $moveCopied    = 0;
        $moveSkipped   = 0;
        $moveFailed    = [];

        foreach ($toResize as $absPath => $info) {
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
                if ($moveCopied <= 50 || $moveCopied % 1000 === 0) {
                    $log("  STAGED  SKU={$info['sku']} | {$info['relPath']} (" . ($fmt)($info['size']) . ')');
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
            $log('ERROR: Staging failures — ABORTING (no files modified).');
            $releaseLock();
            return 1;
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP 5 — [--zip] ZIP + GCS upload
        // ══════════════════════════════════════════════════════════════════════
        if ($enableZip) {
            $log('');
            $log("STEP 5: Creating local ZIP backup of $confirmedCount original files...");

            $zipFile    = "$logDir/resize_backup_id-{$idFrom}-{$idTo}.zip";
            $zipArchive = new \ZipArchive();
            if ($zipArchive->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                $log("ERROR: Cannot open ZIP: $zipFile — ABORTING.");
                $releaseLock();
                return 1;
            }

            $zipCount  = 0;
            $zipErrors = 0;

            foreach ($moveConfirmed as $absPath => $stagingAbsPath) {
                $info    = $toResize[$absPath];
                $zipPath = 'pub/media/catalog/product/' . $info['relPath'];

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
                $log('  STEP 6: Uploading ZIP to GCS...');
                $zipObjectName = self::GCS_ZIP_ROOT . "/resize_backup_id-{$idFrom}-{$idTo}.zip";
                $this->gcsService->uploadZip($zipFile, $zipSize, $gcsBucket, $zipObjectName, $gcsKeyFile, $log);
            }
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP X — Upload raw staging files to GCS via PHP SDK (BEFORE resize)
        // ══════════════════════════════════════════════════════════════════════
        if ($gcsBucket !== '') {
            $rawStep = 5 + ($enableZip ? ($gcsBucket !== '' ? 2 : 1) : 0);
            $log('');
            $log('════════════════════════════════════════════════════');
            $log("  STEP $rawStep: Uploading raw staging files to GCS via PHP SDK...");
            $log('════════════════════════════════════════════════════');
            $uploadFileList = [];
            foreach ($moveConfirmed as $stagingAbsPath) {
                $relPath          = ltrim(str_replace($stagingDir, '', $stagingAbsPath), '/');
                $uploadFileList[] = [$stagingAbsPath, $relPath, (int) filesize($stagingAbsPath)];
            }
            $rawOk = $this->gcsService->uploadRawFiles($stagingDir, $gcsBucket, self::GCS_RAW_ROOT, $gcsKeyFile, $log, 8, $uploadFileList);
            if (!$rawOk) {
                $log('ERROR: GCS raw upload FAILED — ABORTING (no files modified). Staging files kept safely.');
                $releaseLock();
                return 1;
            }
            $log('  GCS raw upload confirmed. Proceeding with resize.');
        }

        // ══════════════════════════════════════════════════════════════════════
        // STEP X — Resize images in-place (originals confirmed in staging + GCS)
        // ══════════════════════════════════════════════════════════════════════
        $resizeStep = 5
            + ($enableZip ? ($gcsBucket !== '' ? 2 : 1) : 0)
            + ($gcsBucket !== '' ? 1 : 0);
        $log('');
        $log("STEP $resizeStep: Resizing $confirmedCount original images in-place...");

        $totalResized    = 0;
        $totalRequali    = 0;
        $totalErrors     = 0;
        $totalSavedBytes = 0;
        $resizeI         = 0;

        foreach ($moveConfirmed as $absPath => $stagingAbsPath) {
            $resizeI++;
            $pct  = (int) round($resizeI / $confirmedCount * 100);
            $pfx  = "$resizeI/$confirmedCount ($pct%)";
            $info = $toResize[$absPath];

            // Safety: only resize if staging backup exists and size matches
            if (!file_exists($stagingAbsPath) || filesize($stagingAbsPath) !== $info['size']) {
                $log("  ERROR  [$pfx] Staging missing or size mismatch, skip: {$info['relPath']}");
                $totalErrors++;
                continue;
            }

            $isQualityOnly = (bool) ($info['quality_only'] ?? false);
            $result        = $isQualityOnly
                ? $this->requantizeImage($absPath, $quality, $imageDriver)
                : $this->resizeImage($absPath, $maxWidth, $maxHeight, $quality, $imageDriver);
            if ($result['success']) {
                $newSize          = file_exists($absPath) ? (int) filesize($absPath) : 0;
                [$newW, $newH]    = @getimagesize($absPath) ?: [0, 0];
                $saved            = $info['size'] - $newSize;
                $totalSavedBytes += $saved;
                if ($isQualityOnly) {
                    $totalRequali++;
                    $log("  REQUALI  [$pfx] SKU={$info['sku']} | {$info['relPath']} | {$info['width']}×{$info['height']}px"
                        . ' | ' . ($fmt)($info['size']) . ' → ' . ($fmt)($newSize) . ' | saved: ' . ($fmt)($saved));
                } else {
                    $totalResized++;
                    $log("  RESIZED  [$pfx] SKU={$info['sku']} | {$info['relPath']} | {$info['width']}×{$info['height']}px"
                        . ' | ' . ($fmt)($info['size']) . ' → ' . ($fmt)($newSize) . ' | saved: ' . ($fmt)($saved));
                }
                $this->logRepository->upsert([
                    'product_id'         => $info['productId'],
                    'sku'                => $info['sku'],
                    'image_type'         => $info['imageType'],
                    'local_path'         => $absPath,
                    'db_path'            => $info['dbPath'],
                    'bucket_remote_path' => $gcsBucket !== '' ? "gs://{$gcsBucket}/" . self::GCS_RAW_ROOT . '/' . $info['relPath'] : null,
                    'before_size'        => $info['size'],
                    'before_width'       => $info['width'],
                    'before_height'      => $info['height'],
                    'after_size'         => $newSize,
                    'after_width'        => $newW,
                    'after_height'       => $newH,
                    'status'             => ImageResizeLog::STATUS_CONVERTED,
                    'skip_reason'        => null,
                ]);
            } else {
                $log("  ERROR  [$pfx] Resize failed: {$info['relPath']} — " . $result['error']);
                $totalErrors++;
                $this->logRepository->upsert([
                    'product_id'    => $info['productId'],
                    'sku'           => $info['sku'],
                    'image_type'    => $info['imageType'],
                    'local_path'    => $absPath,
                    'db_path'       => $info['dbPath'],
                    'before_size'   => $info['size'],
                    'before_width'  => $info['width'],
                    'before_height' => $info['height'],
                    'status'        => ImageResizeLog::STATUS_ERROR,
                    'skip_reason'   => $result['error'],
                ]);
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $log('');
        $log('════════════════════════════════════════════════════');
        $log("  FINAL SUMMARY ($mode)");
        $log('════════════════════════════════════════════════════');
        $log("  Products          : $totalProducts");
        $log("  Images processed  : $totalToResize");
        $log("  Resized           : $totalResized");
        $log("  Re-quantized      : $totalRequali");
        $log("  Space saved       : " . ($fmt)($totalSavedBytes));
        $log("  Errors            : $totalErrors");
        $log('');
        $log('  IMPORTANT: Flush cache storage:');
        $log('    bin/magento cache:flush');

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

    private function detectDriver(string $preferred): string
    {
        if ($preferred === 'imagick' && extension_loaded('imagick')) {
            return 'imagick';
        }
        if ($preferred === 'gd' && extension_loaded('gd')) {
            return 'gd';
        }
        return extension_loaded('imagick') ? 'imagick'
             : (extension_loaded('gd')    ? 'gd' : 'none');
    }

    private function getGalleryOnlyImages(array $gallery, array $roles): array
    {
        $roleSet = array_flip($roles);
        return array_values(array_filter($gallery, fn($p) => !isset($roleSet[$p])));
    }

    /**
     * Resize image in-place using GD or Imagick.
     * Returns ['success' => bool, 'error' => string].
     */
    private function resizeImage(string $absPath, int $maxWidth, int $maxHeight, int $quality, string $driver): array
    {
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        return $driver === 'imagick'
            ? $this->resizeWithImagick($absPath, $maxWidth, $maxHeight, $quality, $ext)
            : $this->resizeWithGd($absPath, $maxWidth, $maxHeight, $quality, $ext);
    }

    private function resizeWithImagick(string $absPath, int $maxW, int $maxH, int $quality, string $ext): array
    {
        try {
            $imagick = new \Imagick($absPath);
            $imagick->setFirstIterator();
            do {
                $imagick->thumbnailImage($maxW, $maxH, true);
            } while ($imagick->nextImage());
            $imagick->setFirstIterator();

            if (in_array($ext, ['jpg', 'jpeg'], true)) {
                $imagick->setImageCompressionQuality($quality);
                $imagick->setCompression(\Imagick::COMPRESSION_JPEG);
            } elseif ($ext === 'png') {
                $imagick->setImageCompressionQuality($quality);
            }

            $imagick->writeImages($absPath, true);
            $imagick->clear();
            return ['success' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function resizeWithGd(string $absPath, int $maxW, int $maxH, int $quality, string $ext): array
    {
        try {
            $src = match ($ext) {
                'jpg', 'jpeg' => @imagecreatefromjpeg($absPath),
                'png'         => @imagecreatefrompng($absPath),
                default       => false,
            };
            if ($src === false) {
                return ['success' => false, 'error' => 'Cannot load image with GD'];
            }

            $origW = imagesx($src);
            $origH = imagesy($src);
            $ratio = min($maxW / $origW, $maxH / $origH);
            $newW  = (int) round($origW * $ratio);
            $newH  = (int) round($origH * $ratio);
            $dst   = imagecreatetruecolor($newW, $newH);

            // Preserve transparency for PNG
            if ($ext === 'png') {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
            }

            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);

            $ok = match ($ext) {
                'jpg', 'jpeg' => imagejpeg($dst, $absPath, $quality),
                'png'         => imagepng($dst, $absPath, (int) round(9 - ($quality / 100) * 9)),
                default       => false,
            };

            unset($src, $dst);

            return $ok
                ? ['success' => true, 'error' => '']
                : ['success' => false, 'error' => 'GD save failed'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Re-save image at a lower quality without changing dimensions.
     * Used when an image is within max dimensions but exceeds the force-quality-size threshold.
     */
    private function requantizeImage(string $absPath, int $quality, string $driver): array
    {
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        return $driver === 'imagick'
            ? $this->requantizeWithImagick($absPath, $quality, $ext)
            : $this->requantizeWithGd($absPath, $quality, $ext);
    }

    private function requantizeWithImagick(string $absPath, int $quality, string $ext): array
    {
        try {
            $imagick = new \Imagick($absPath);
            if (in_array($ext, ['jpg', 'jpeg'], true)) {
                $imagick->setImageCompressionQuality($quality);
                $imagick->setCompression(\Imagick::COMPRESSION_JPEG);
            } elseif ($ext === 'png') {
                $imagick->setImageCompressionQuality($quality);
            }
            $imagick->writeImages($absPath, true);
            $imagick->clear();
            return ['success' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function requantizeWithGd(string $absPath, int $quality, string $ext): array
    {
        try {
            $src = match ($ext) {
                'jpg', 'jpeg' => @imagecreatefromjpeg($absPath),
                'png'         => @imagecreatefrompng($absPath),
                default       => false,
            };
            if ($src === false) {
                return ['success' => false, 'error' => 'Cannot load image with GD'];
            }
            $ok = match ($ext) {
                'jpg', 'jpeg' => imagejpeg($src, $absPath, $quality),
                'png'         => imagepng($src, $absPath, (int) round(9 - ($quality / 100) * 9)),
                default       => false,
            };
            unset($src);
            return $ok
                ? ['success' => true, 'error' => '']
                : ['success' => false, 'error' => 'GD save failed'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
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
