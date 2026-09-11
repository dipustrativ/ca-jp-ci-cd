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
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;

/**
 * The Export Invoices / Export Credit Memos page. One controller for both: the
 * document type comes from the `type` request parameter, which the menu entries
 * supply.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_ExportImport::export';

    public function __construct(
        Action\Context $context,
        private readonly Config $config,
        private readonly Pool $pool,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Order Export / Import is disabled in configuration.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('admin/dashboard');
        }

        $type = (string)$this->getRequest()->getParam('type');
        if (!$this->pool->has($type)) {
            $this->messageManager->addErrorMessage(__('Unknown document type "%1".', $type));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('admin/dashboard');
        }

        /** @var Page $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $result->setActiveMenu('CarpetAvenue_ExportImport::document_export_' . $type);
        $result->getConfig()->getTitle()->prepend(
            __('Export %1s', $this->pool->getType($type)->getLabel())
        );
        return $result;
    }
}
