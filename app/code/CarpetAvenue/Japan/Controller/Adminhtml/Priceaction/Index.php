<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Grid over the shared Japan price-action log — every rule, one place.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Controller\Adminhtml\Priceaction;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
// Must be the framework factory, never Magento\Backend\Model\View\Result\PageFactory.
// In adminhtml this one is configured with
// instanceName = Magento\Backend\Model\View\Result\Page
// (Magento_Backend/etc/adminhtml/di.xml:93), so it still returns the backend
// page — and its create() calls addDefaultHandle(), which loads the `default`
// layout handle that declares the `menu` block. The generated backend factory
// only instantiates the class, so setActiveMenu() then fatals on
// "Call to a member function setActive() on false".
// phpstan flags setActiveMenu() here because it types the return as the
// framework page; that is a false positive — see CarpetAvenue_B2B for the same.
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'CarpetAvenue_Japan::price_action_log';

    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu(self::ADMIN_RESOURCE);
        $page->getConfig()->getTitle()->prepend(__('Price Action Log'));

        return $page;
    }
}
