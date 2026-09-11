<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Customer
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Controller\Adminhtml\Customer\Export;

use CarpetAvenue\ExportImport\Model\Config;
use CarpetAvenue\ExportImport\Model\Customer\Exporter;
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Builds the Customer CSV and streams it back.
 */
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
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)
            ->setPath('exportimport/customer_export/index');

        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Order Export / Import is disabled in configuration.'));
            return $redirect;
        }

        $filters = [
            'website_id' => (int)$this->getRequest()->getParam('website_id') ?: null,
            'group_id' => array_filter((array)$this->getRequest()->getParam('group_id', [])),
            'from' => trim((string)$this->getRequest()->getParam('from')) ?: null,
            'to' => trim((string)$this->getRequest()->getParam('to')) ?: null,
            // Free-text field: one email per line or comma-separated.
            'email' => $this->parseEmails((string)$this->getRequest()->getParam('email')),
        ];

        try {
            $result = $this->exporter->exportToFile($filters);

            if ($result['customers'] === 0) {
                $this->messageManager->addWarningMessage(__('Nothing matched the selected filters.'));
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
    private function parseEmails(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: [])));
    }
}
