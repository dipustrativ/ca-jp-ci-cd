<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Controller\Adminhtml\Media\ManualBackup;

use CarpetAvenue\Utilities\Helper\Config;
use CarpetAvenue\Utilities\Service\Media\ManualBackupService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;

class Process extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Utilities::media_cleanup';

    private const MAX_UI_ROWS = 50;

    public function __construct(
        Context $context,
        private readonly Config              $config,
        private readonly ManualBackupService $manualBackupService,
        private readonly DirectoryList       $directoryList,
        private readonly LoggerInterface     $logger,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        // Bucket and prefix always from config — not from form POST
        $gcsBucket  = $this->config->getGcsBucket();
        $gcsKeyFile = $this->config->getGcsCredentials();
        $gcsPrefix  = $this->config->getManualBackupGcsPrefix();

        // ── Handle uploaded CSV ───────────────────────────────────────────────
        $uploadedFiles = $this->getRequest()->getFiles('csv_file');
        if (empty($uploadedFiles) || ($uploadedFiles['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->messageManager->addErrorMessage(__('Please upload a CSV file.'));
            return $this->_redirect('*/*/index');
        }

        $tmpPath = (string) ($uploadedFiles['tmp_name'] ?? '');

        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            $this->messageManager->addErrorMessage(__('Invalid file upload.'));
            return $this->_redirect('*/*/index');
        }

        // ── Save input CSV as ui_input_{timestamp}.csv ────────────────────────
        $bp       = $this->directoryList->getRoot();
        $logDir   = $bp . '/var/log/carpetavenue/' . ManualBackupService::LOG_DIR;
        if (!is_dir($logDir) && !mkdir($logDir, 0750, true)) {
            $this->messageManager->addErrorMessage(__('Cannot create log directory.'));
            return $this->_redirect('*/*/index');
        }

        $timestamp    = date('Ymd_His');
        $savedCsvPath = $logDir . '/ui_input_' . $timestamp . '.csv';
        if (!move_uploaded_file($tmpPath, $savedCsvPath)) {
            $this->messageManager->addErrorMessage(__('Cannot save uploaded CSV file.'));
            return $this->_redirect('*/*/index');
        }

        // ── Parse & validate ─────────────────────────────────────────────────
        $parsed = $this->manualBackupService->parseAndValidateCsv($savedCsvPath);
        if ($parsed['error'] !== null) {
            $this->messageManager->addErrorMessage(__('CSV error: %1', $parsed['error']));
            return $this->_redirect('*/*/index');
        }

        $rows = $parsed['rows'];

        // ── Enforce UI row limit ──────────────────────────────────────────────
        $validRows = array_filter($rows, fn($r) => !isset($r['_error']));
        if (count($validRows) > self::MAX_UI_ROWS) {
            $this->messageManager->addErrorMessage(__(
                'CSV contains %1 valid paths, which exceeds the UI limit of %2. Use the CLI for large batches: bin/magento carpetavenue:media:manual-backup --csv=%3',
                count($validRows),
                self::MAX_UI_ROWS,
                $savedCsvPath
            ));
            return $this->_redirect('*/*/index');
        }

        // ── Execute backup ────────────────────────────────────────────────────
        $logs = [];
        $log  = static function (string $msg) use (&$logs): void {
            $logs[] = $msg;
        };

        $resultCsvPath = $this->manualBackupService->execute(
            $rows,
            [
                'bucket'      => $gcsBucket,
                'credentials' => $gcsKeyFile,
                'gcs_prefix'  => $gcsPrefix,
            ],
            $log
        );

        // Write run log
        $logFilePath = $logDir . '/run_' . $timestamp . '.log';
        file_put_contents($logFilePath, implode("\n", $logs));

        $this->logger->info('CarpetAvenue ManualBackup: run completed — ' . implode(' | ', array_slice($logs, -3)));

        if ($resultCsvPath === null) {
            $this->messageManager->addErrorMessage(__(
                'Backup process encountered a fatal error. Check log: %1',
                $logFilePath
            ));
            return $this->_redirect('*/*/index');
        }

        // Count backed-up rows — result CSV col[1] = bucket_full_path (non-empty = backed up)
        $backedCount = 0;
        $totalCount  = count($rows);
        if (is_readable($resultCsvPath)) {
            $fh = fopen($resultCsvPath, 'r');
            fgetcsv($fh, 0, ',', '"', '\\'); // skip header
            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                if (isset($row[1]) && $row[1] !== '') {
                    $backedCount++;
                }
            }
            fclose($fh);
        }

        $this->messageManager->addSuccessMessage(__(
            'Backup complete. Backed up: %1 / %2 images. Download result from config panel or the button below.',
            $backedCount,
            $totalCount
        ));

        return $this->_redirect('*/*/index');
    }
}
