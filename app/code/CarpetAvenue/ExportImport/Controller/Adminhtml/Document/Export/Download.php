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

namespace CarpetAvenue\ExportImport\Controller\Adminhtml\Document\Export;

use CarpetAvenue\ExportImport\Model\Config;
use CarpetAvenue\ExportImport\Model\Document\Pool;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Builds the invoice / credit-memo CSV and streams it back.
 */
class Download extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_ExportImport::export';

    public function __construct(
        Action\Context $context,
        private readonly Config $config,
        private readonly Pool $pool,
        private readonly FileFactory $fileFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|ResponseInterface
    {
        $type = (string)$this->getRequest()->getParam('type');
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('exportimport/document_export/index', ['type' => $type]);

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Order Export / Import is disabled in configuration.'));
            return $redirect;
        }
        if (!$this->pool->has($type)) {
            $this->messageManager->addErrorMessage(__('Unknown document type "%1".', $type));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('admin/dashboard');
        }

        $filters = [
            'store_id' => (int)$this->getRequest()->getParam('store_id') ?: null,
            'state' => array_filter((array)$this->getRequest()->getParam('state', [])),
            'from' => trim((string)$this->getRequest()->getParam('from')) ?: null,
            'to' => trim((string)$this->getRequest()->getParam('to')) ?: null,
            // Free-text field: one order increment id per line or comma-separated.
            'order_increment_id' => $this->parseOrderIds(
                (string)$this->getRequest()->getParam('order_increment_id')
            ),
        ];

        try {
            $result = $this->pool->getExporter($type)->exportToFile($filters);

            if ($result['documents'] === 0) {
                $this->messageManager->addWarningMessage(__('No documents matched the selected filters.'));
                return $redirect;
            }

            return $this->fileFactory->create(
                basename($result['relative_path']),
                [
                    'type' => 'filename',
                    'value' => $result['relative_path'],
                    'rm' => false,
                ],
                DirectoryList::VAR_DIR,
                'text/csv'
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Export failed: %1', $e->getMessage()));
            return $redirect;
        }
    }

    /** @return string[] */
    private function parseOrderIds(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: [])));
    }
}
