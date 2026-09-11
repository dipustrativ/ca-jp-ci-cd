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

namespace CarpetAvenue\ExportImport\Controller\Adminhtml\Document\Import;

use CarpetAvenue\ExportImport\Model\Config;
use CarpetAvenue\ExportImport\Model\Document\Pool;
use CarpetAvenue\ExportImport\Model\Order\CsvReader;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Filesystem;

/**
 * Step 2 of a document import: re-read the previously validated file and apply
 * it. The file path comes from the admin session (set by Validate), never from
 * user input, so only a file that passed the manual check for THIS document type
 * can be imported.
 */
class Submit extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_ExportImport::import';

    public function __construct(
        Action\Context $context,
        private readonly Config $config,
        private readonly Pool $pool,
        private readonly Filesystem $filesystem,
        private readonly CsvReader $csvReader,
        private readonly DataPersistorInterface $dataPersistor,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $type = (string)$this->getRequest()->getParam('type');
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('exportimport/document_import/index', ['type' => $type]);

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Order Export / Import is disabled in configuration.'));
            return $redirect;
        }
        if (!$this->pool->has($type)) {
            $this->messageManager->addErrorMessage(__('Unknown document type "%1".', $type));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('admin/dashboard');
        }

        $relativePath = (string)$this->dataPersistor->get(Validate::sessionFileKey($type));
        if ($relativePath === '') {
            $this->messageManager->addErrorMessage(__('No validated file found. Upload and validate a CSV first.'));
            return $redirect;
        }

        try {
            $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
            $parsed = $this->csvReader->read($directory->getAbsolutePath($relativePath));
            $result = $this->pool->getImporter($type)->import($parsed);

            if (!$result['success']) {
                $validation = $result['validation'];
                $this->dataPersistor->set(Validate::sessionReportKey($type), [
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

            $this->dataPersistor->set(Validate::sessionReportKey($type), [
                'phase' => 'imported',
                'summary' => $result['validation']->getSummary(),
                'warnings' => $result['validation']->getWarnings(),
                'created' => $result['created'],
                'updated' => $result['updated'],
                'relinked' => $result['relinked'],
                'orders_synced' => $result['orders_synced'],
                'failed' => $result['failed'],
            ]);
            $this->dataPersistor->clear(Validate::sessionFileKey($type));

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

            if (!empty($result['orders_synced'])) {
                // Which figures were recomputed depends on the document type:
                // the invoiced / paid / refunded family for invoices and credit
                // memos, the per-item shipped quantities for shipments.
                $this->messageManager->addNoticeMessage(__(
                    'Recomputed the order figures derived from %1s on %2 order(s).',
                    $this->pool->getType($type)->getLabel(),
                    count($result['orders_synced'])
                ));
            }

            // Re-linking only happens on the update path, and only for items
            // that had no order item — worth calling out, it is easy to miss.
            $relinkedCount = array_sum($result['relinked']);
            if ($relinkedCount > 0) {
                $this->messageManager->addNoticeMessage(__(
                    '%1 document item(s) across %2 document(s) were linked to an order item that could not be '
                    . 'matched when they were first imported.',
                    $relinkedCount,
                    count($result['relinked'])
                ));
            }
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Import failed: %1', $e->getMessage()));
        }

        return $redirect;
    }
}
