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
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;

/**
 * The Import Transactions page.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_ExportImport::import';

    public function __construct(
        Action\Context $context,
        private readonly Config $config,
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->isEnabled()) {
            $this->messageManager->addErrorMessage(__('Order Export / Import is disabled in configuration.'));
            return $this->resultFactory->create(ResultFactory::TYPE_REDIRECT)->setPath('admin/dashboard');
        }

        /** @var Page $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $result->setActiveMenu('CarpetAvenue_ExportImport::transaction_import');
        $result->getConfig()->getTitle()->prepend(__('Import Transactions'));
        return $result;
    }
}
