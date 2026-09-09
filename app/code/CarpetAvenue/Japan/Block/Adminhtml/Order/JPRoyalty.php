<?php

declare(strict_types=1);

namespace CarpetAvenue\Japan\Block\Adminhtml\Order;

/**
 * Backend Report Sold Product Content Block
 *
 * @api
 * @author     Magento Core Team <core@magentocommerce.com>
 * @since 100.0.2
 */
class JPRoyalty extends \Magento\Backend\Block\Widget\Grid\Container
{
    /**
     * @var string
     */
    protected $_blockGroup = 'Magento_Reports';

    /**
     * Initialize container block settings
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_blockGroup = 'Magento_Reports';
        $this->_controller = 'adminhtml_custom_order_jp_royalty';
        $this->_headerText = __('JP Order Royalty');
        parent::_construct();
        $this->buttonList->remove('add');
    }
}