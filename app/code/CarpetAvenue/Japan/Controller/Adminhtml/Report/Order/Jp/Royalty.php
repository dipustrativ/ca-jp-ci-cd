<?php

declare(strict_types=1);

namespace CarpetAvenue\Japan\Controller\Adminhtml\Report\Order\Jp;

use Magento\Framework\App\Action\HttpGetActionInterface as HttpGetActionInterface;
use Magento\Reports\Controller\Adminhtml\Report\Product;

class Royalty extends Product implements HttpGetActionInterface
{
    /**
     * Authorization level of a basic admin session
     *
     * @see _isAllowed()
     */
    const ADMIN_RESOURCE = 'Magento_Reports::jp_royalty';

    /**
     * Sold Products Report Action
     *
     * @return void
     */
    public function execute()
    {
        $this->_initAction()->_setActiveMenu(
            'CarpetAvenue_Japan::report_custom_order_jp_royalty'
        )->_addBreadcrumb(
            __('JP Order Royalty'),
            __('JP Order Royalty')
        );
        $this->_view->getPage()->getConfig()->getTitle()->prepend(__('JP Order Royalty'));
        $this->_view->renderLayout();
    }
}