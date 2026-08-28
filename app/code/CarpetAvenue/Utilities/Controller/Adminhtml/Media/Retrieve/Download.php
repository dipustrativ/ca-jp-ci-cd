<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Controller\Adminhtml\Media\Retrieve;

use CarpetAvenue\Utilities\Helper\Config;
use CarpetAvenue\Utilities\Service\Media\GcsService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http\FileFactory;
use Psr\Log\LoggerInterface;

class Download extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Utilities::media_cleanup';

    private const MAX_SKUS    = 20;
    private const SKU_PATTERN = '/^[a-zA-Z0-9_\-\.]+$/';

    public function __construct(
        Context $context,
        private readonly Config             $config,
        private readonly ResourceConnection $resourceConnection,
        private readonly DirectoryList      $directoryList,
        private readonly FileFactory        $fileFactory,
        private readonly LoggerInterface    $logger,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $rawSkus    = trim((string) $this->getRequest()->getPost('skus', ''));
        $gcsBucket  = trim((string) $this->getRequest()->getPost('bucket', $this->config->getGcsBucket()));
        $gcsKeyFile = trim((string) $this->getRequest()->getPost('credentials', $this->config->getGcsCredentials()));

        // ── Validate SKUs ─────────────────────────────────────────────────────
        $skuList = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $rawSkus))
        )));

        if (empty($skuList)) {
            $this->messageManager->addErrorMessage(__('Please enter at least one SKU.'));
            return $this->_redirect('*/*/index');
        }

        if (count($skuList) > self::MAX_SKUS) {
            $this->messageManager->addErrorMessage(
                __('Maximum %1 SKUs allowed. You entered %2.', self::MAX_SKUS, count($skuList))
            );
            return $this->_redirect('*/*/index');
        }

        $invalidSkus = array_filter($skuList, fn($s) => strlen($s) > 255 || !preg_match(self::SKU_PATTERN, $s));
        if (!empty($invalidSkus)) {
            $this->messageManager->addErrorMessage(
                __('Invalid SKU(s): %1. Only letters, digits, hyphens (-), underscores (_), and dots (.) are allowed.',
                    implode(', ', $invalidSkus))
            );
            return $this->_redirect('*/*/index');
        }

        if ($gcsBucket === '') {
            $this->messageManager->addErrorMessage(__('GCS Bucket is required.'));
            return $this->_redirect('*/*/index');
        }

        if ($gcsKeyFile !== '' && !is_readable($gcsKeyFile)) {
            $this->messageManager->addErrorMessage(__('Credentials file is not readable: %1', $gcsKeyFile));
            return $this->_redirect('*/*/index');
        }

        if (!class_exists(\Google\Cloud\Storage\StorageClient::class)) {
            $this->messageManager->addErrorMessage(__('google/cloud-storage SDK not found. Run: composer require google/cloud-storage'));
            return $this->_redirect('*/*/index');
        }

        // ── Query DB ─────────────────────────────────────────────────────────
        $connection = $this->resourceConnection->getConnection();
        $tbl        = $this->resourceConnection->getTableName('carpetavenue_image_resize_log');

        $select = $connection->select()
            ->from($tbl, ['id', 'sku', 'local_path', 'bucket_remote_path'])
            ->where('sku IN (?)', $skuList)
            ->where('bucket_remote_path IS NOT NULL')
            ->where("bucket_remote_path != ''")
            ->order('sku ASC')
            ->order('id ASC');

        $rows = $connection->fetchAll($select);

        if (empty($rows)) {
            $this->messageManager->addWarningMessage(__('No images found in DB with GCS backup for the given SKU(s).'));
            return $this->_redirect('*/*/index');
        }

        // ── Connect to GCS ────────────────────────────────────────────────────
        try {
            $storageOpts = ['suppressKeyFileNotice' => true];
            if ($gcsKeyFile !== '') {
                $storageOpts['keyFilePath'] = $gcsKeyFile;
            }
            $storageClient = new \Google\Cloud\Storage\StorageClient($storageOpts);
            $bucket        = $storageClient->bucket($gcsBucket);
            if (!$bucket->exists()) {
                throw new \RuntimeException("Bucket not accessible: $gcsBucket");
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('GCS connection failed: %1', $e->getMessage()));
            $this->logger->error('CarpetAvenue Retrieve: GCS connection failed — ' . $e->getMessage());
            return $this->_redirect('*/*/index');
        }

        // ── Download to temp dir ──────────────────────────────────────────────
        $varDir  = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        $baseDir = $varDir . '/carpetavenue/retrieve';
        if (!is_dir($baseDir) && !mkdir($baseDir, 0750, true)) {
            $this->messageManager->addErrorMessage(__('Cannot create retrieve directory.'));
            return $this->_redirect('*/*/index');
        }

        $tempDir = $baseDir . '/tmp_' . date('Ymd_His') . '_' . getmypid();
        if (!mkdir($tempDir, 0750, true)) {
            $this->messageManager->addErrorMessage(__('Cannot create temporary directory.'));
            return $this->_redirect('*/*/index');
        }

        $downloaded = 0;
        $errors     = 0;
        $seenNames  = [];

        foreach ($rows as $row) {
            $remotePath = (string) $row['bucket_remote_path'];
            if (!preg_match('#^gs://[^/]+/(.+)$#', $remotePath, $m)) {
                $this->logger->warning("CarpetAvenue Retrieve: cannot parse GCS path: $remotePath");
                $errors++;
                continue;
            }
            $objectName = $m[1];

            // Collision-safe filename: sku__basename
            $baseName = basename($row['local_path']);
            $key      = $row['sku'] . '__' . $baseName;
            if (isset($seenNames[$key])) {
                $seenNames[$key]++;
                $ext  = pathinfo($baseName, PATHINFO_EXTENSION);
                $stem = pathinfo($baseName, PATHINFO_FILENAME);
                $key  = $row['sku'] . '__' . $stem . '_' . $seenNames[$row['sku'] . '__' . $baseName] . '.' . $ext;
            } else {
                $seenNames[$key] = 1;
            }
            $localFile = $tempDir . '/' . $key;

            try {
                $gcsObject = $bucket->object($objectName);
                if (!$gcsObject->exists()) {
                    $this->logger->warning("CarpetAvenue Retrieve: GCS object not found: gs://$gcsBucket/$objectName");
                    $errors++;
                    continue;
                }
                $gcsObject->downloadToFile($localFile);
                $downloaded++;
            } catch (\Throwable $e) {
                $this->logger->error("CarpetAvenue Retrieve: download failed for $objectName — " . $e->getMessage());
                $errors++;
            }
        }

        if ($downloaded === 0) {
            $this->removeDirectory($tempDir);
            $this->messageManager->addErrorMessage(__('No files could be downloaded from GCS.'));
            return $this->_redirect('*/*/index');
        }

        // ── Create ZIP ────────────────────────────────────────────────────────
        $skuSlug = preg_replace('/[^a-zA-Z0-9_\-]/', '_', implode('-', array_slice($skuList, 0, 5)));
        $zipName = 'originals_' . $skuSlug . '_' . date('Ymd_His') . '.zip';
        $zipPath = $baseDir . '/' . $zipName;
        $zipRel  = 'carpetavenue/retrieve/' . $zipName;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->removeDirectory($tempDir);
            $this->messageManager->addErrorMessage(__('Cannot create ZIP archive.'));
            return $this->_redirect('*/*/index');
        }

        foreach (new \DirectoryIterator($tempDir) as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getRealPath(), $file->getFilename());
            }
        }
        $zip->close();
        $this->removeDirectory($tempDir);

        $zipSize = file_exists($zipPath) ? (int) filesize($zipPath) : 0;
        $this->logger->info(sprintf(
            'CarpetAvenue Retrieve: ZIP created — %s | SKUs: %s | files: %d | errors: %d | size: %s',
            $zipName, implode(',', $skuList), $downloaded, $errors, GcsService::fmtBytes($zipSize)
        ));

        // ── Stream ZIP as download ────────────────────────────────────────────
        try {
            return $this->fileFactory->create(
                $zipName,
                ['type' => 'filename', 'value' => $zipRel],
                DirectoryList::VAR_DIR,
                'application/zip',
                $zipSize
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Failed to serve ZIP: %1', $e->getMessage()));
            return $this->_redirect('*/*/index');
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
