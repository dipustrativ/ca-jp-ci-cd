<?php
/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @package   CarpetAvenue_Utilities
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Utilities\Controller\Adminhtml\Media\ManualBackup;

use CarpetAvenue\Utilities\Service\Media\ManualBackupService;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;

class DownloadResult extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Utilities::media_cleanup';

    public function __construct(
        Context $context,
        private readonly DirectoryList $directoryList,
        private readonly FileFactory   $fileFactory,
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        $bp             = $this->directoryList->getRoot();
        $logDir         = $bp . '/var/log/carpetavenue/' . ManualBackupService::LOG_DIR;
        $pointerFile    = $logDir . '/latest_result.txt';

        if (!file_exists($pointerFile)) {
            $this->messageManager->addErrorMessage(__('No result CSV found. Run a backup first.'));
            return $this->_redirect('*/*/index');
        }

        $resultCsvPath = trim((string) file_get_contents($pointerFile));

        if ($resultCsvPath === '' || !file_exists($resultCsvPath)) {
            $this->messageManager->addErrorMessage(__('Result CSV file no longer exists: %1', $resultCsvPath));
            return $this->_redirect('*/*/index');
        }

        $fileName = basename($resultCsvPath);
        $fileSize = (int) filesize($resultCsvPath);

        // Path relative to var/ for FileFactory
        $varDir   = $this->directoryList->getPath(DirectoryList::VAR_DIR);
        $relPath  = ltrim(str_replace($varDir, '', $resultCsvPath), '/');

        try {
            return $this->fileFactory->create(
                $fileName,
                ['type' => 'filename', 'value' => $relPath],
                DirectoryList::VAR_DIR,
                'text/csv',
                $fileSize
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Failed to serve CSV: %1', $e->getMessage()));
            return $this->_redirect('*/*/index');
        }
    }
}
