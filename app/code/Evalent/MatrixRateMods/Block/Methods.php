<?php

namespace Evalent\MatrixRateMods\Block;

use Evalent\MatrixRateMods\Helper\Data;
use Magento\Framework\View\Element\Template;
use Magento\Sales\Api\Data\OrderInterface;

class Methods extends Template
{
    /**
     * @var OrderInterface
     */
    private $_order;

    /**
     * @var Data
     */
    private $_helper;

    /**
     * Methods constructor.
     * @param Template\Context $context
     * @param Data $helper
     * @param array $data
     */
    public function __construct(Template\Context $context, Data $helper, array $data = [])
    {
        parent::__construct($context, $data);
        $this->_helper = $helper;
    }

    /**
     * @param OrderInterface $order
     */
    public function setOrder(OrderInterface $order)
    {
        $this->_order = $order;
    }

    /**
     * @return OrderInterface
     */
    public function getOrder()
    {
        return $this->_order;
    }

    /**
     * @return array
     */
    public function getCurrentMethod(): array
    {
        return [$this->_order->getShippingMethod() => $this->_order->getShippingDescription()];
    }

    /**
     * @return array
     */
    public function getAvailableMethods(): array
    {
        $methods = $this->_helper->getMethods();
        $current = $this->getCurrentMethod();
        foreach ($current as $key => $title) {
            $methods[strval($key)] = $title;
        }
        return $methods;
    }

    /**
     * @return string
     */
    public function getUpdateUrl()
    {
        return $this->_urlBuilder->getUrl('shippingmods/update/index');
    }

    /**
     * @return string
     */
    public function _toHtml(): string
    {
        $this->setTemplate('Evalent_MatrixRateMods::methods.phtml');
        return parent::_toHtml();
    }
}
