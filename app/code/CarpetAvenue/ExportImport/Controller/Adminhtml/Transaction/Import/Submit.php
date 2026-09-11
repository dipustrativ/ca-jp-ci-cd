<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Order
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Controller\Adminhtml\Transaction\Import;

use CarpetAvenue\ExportImport\Model\Config;
use CarpetAvenue\ExportImport\Model\Order\CsvReader;
use CarpetAvenue\ExportImport\Model\Transaction\Importer;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem;

/**
 * Step 2 of a transaction import: re-read the previously validated file and
 * apply it. The file path comes from the admin session (set by Validate), never
 * from user input, so only a file that passed the manual check can be imported.
 */
class Submit extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_ExportImport::import';

    public function __construct(
        Action\Context $context,
        private readonly Config $config,
        private readonly Importer $importer,
        private readonly Filesystem $filesystem,
        private readonly CsvReader $csvReader,
        private readonly DataPersistorInterface $dataPersistor,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('exportimport/transaction_import/index');

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Order Export / Import is disabled in configuration.'));
            return $redirect;
        }

        $relativePath = (string)$this->dataPersistor->get(Validate::SESSION_FILE_KEY);
        if ($relativePath === '') {
            $this->messageManager->addErrorMessage(__('No validated file found. Upload and validate a CSV first.'));
            return $redirect;
        }

        try {
            $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $parsed = $this->csvReader->read($directory->getAbsolutePath($relativePath));
            $result = $this->importer->import($parsed);

            if (!$result['success']) {
                $validation = $result['validation'];
                $this->dataPersistor->set(Validate::SESSION_REPORT_KEY, [
                    'phase' => 'validated',
                    'summary' => $validation->getSummary(),
                    'errors' => $validation->getErrors(),
                    'warnings' => $validation->getWarnings(),
                ]);
                $this->messageManager->addErrorMessage(__(
                    'Import aborted — the file no longer passes validation (%1 error(s)).',
                    $validation->getSummary()['errors']
                ));
                return $redirect;
            }

            $this->dataPersistor->set(Validate::SESSION_REPORT_KEY, [
                'phase' => 'imported',
                'summary' => $result['validation']->getSummary(),
                'warnings' => $result['validation']->getWarnings(),
                'created' => $result['created'],
                'updated' => $result['updated'],
                'parents_linked' => $result['parents_linked'],
                'failed' => $result['failed'],
            ]);
            $this->dataPersistor->clear(Validate::SESSION_FILE_KEY);

            $failedCount = count($result['failed']);
            $message = __(
                'Import finished: %1 created, %2 updated, %3 failed.',
                count($result['created']),
                count($result['updated']),
                $failedCount
            );
            if ($failedCount > 0) {
                $this->messageManager->addWarningMessage($message);
            } else {
                $this->messageManager->addSuccessMessage($message);
            }

            if ($result['parents_linked'] > 0) {
                $this->messageManager->addNoticeMessage(__(
                    'Linked %1 transaction(s) to their parent transaction.',
                    $result['parents_linked']
                ));
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Import failed: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
