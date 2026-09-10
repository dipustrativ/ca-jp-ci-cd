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

namespace CarpetAvenue\ExportImport\Controller\Adminhtml\Export;

use CarpetAvenue\ExportImport\Model\Config;
use CarpetAvenue\ExportImport\Model\Order\Exporter;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class Download extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_ExportImport::export';

    public function __construct(
        Action\Context $context,
        private readonly Config $config,
        private readonly Exporter $exporter,
        private readonly FileFactory $fileFactory,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface|ResponseInterface
    {
        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Order Export / Import is disabled in configuration.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('exportimport/export/index');
        }

        $filters = [
            'store_id' => (int)$this->getRequest()->getParam('store_id') ?: null,
            'status' => array_filter((array)$this->getRequest()->getParam('status', [])),
            'from' => trim((string)$this->getRequest()->getParam('from')) ?: null,
            'to' => trim((string)$this->getRequest()->getParam('to')) ?: null,
        ];

        try {
            $result = $this->exporter->exportToFile($filters);

            if ($result['orders'] === 0) {
                $this->messageManager->addWarningMessage(__('No orders matched the selected filters.'));
                return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
                    ->setPath('exportimport/export/index');
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
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
                ->setPath('exportimport/export/index');
        }
    }
}
