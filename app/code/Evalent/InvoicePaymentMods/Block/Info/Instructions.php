<?php

declare(strict_types=1);

namespace Evalent\InvoicePaymentMods\Block\Info;

use Evalent\InvoicePaymentMods\Helper\Data;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class Instructions extends \Magento\Payment\Block\Info\Instructions
{

    /**
     * @var \Evalent\InvoicePaymentMods\Helper\Data
     */
    private $helper;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    public function __construct(
        OrderRepositoryInterface $orderRepository,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        Data $helper,
        Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->helper = $helper;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->orderRepository = $orderRepository;
    }

    public function getInstructions()
    {
        $fullActionName = $this->getRequest()->getFullActionName();
        $orderId = $this->getRequest()->getParam('order_id');
        if (!$orderId) {
            $info = $this->getInfo();
            $order = $info->getOrder();
            if ($order) {
                $orderId = $order->getId();
            }
        }
        $payed = false;
        $storeId = $this->storeManager->getStore()->getId();
        if ($orderId) {
            $order = $this->orderRepository->get($orderId);
            $payed  = $order->getBaseTotalDue() == 0;
            $storeId = $order->getStoreId();
        }
        if ($payed || strpos($fullActionName, "shipment") || strpos($fullActionName, "receipt") || strpos($fullActionName, 'capture') || strpos($fullActionName, 'invoice_email')) {
            $this->_instructions = trim($this->scopeConfig->getValue("payment/evinvoicepayment/instructions_payment_information_shipment", ScopeInterface::SCOPE_STORE, $storeId));
        } elseif ($payed || strpos($fullActionName, "reminder")) {
            $this->_instructions = trim($this->scopeConfig->getValue("payment/evinvoicepayment/instructions_payment_information_for_reminder", ScopeInterface::SCOPE_STORE, $storeId) ?? "");
        }
        else {
            return parent::getInstructions();
        }

        return $this->_instructions;
    }
}
