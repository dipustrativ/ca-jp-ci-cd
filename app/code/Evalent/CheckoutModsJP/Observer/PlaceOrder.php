<?php

namespace Evalent\CheckoutModsJP\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\QuoteRepository;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Evalent\CheckoutModsJP\Api\Data\CheckoutConfigsJpInterface;

/**
 * class PlaceOrder
 */
class PlaceOrder implements ObserverInterface, CheckoutConfigsJpInterface
{
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * @var \Magento\Quote\Model\QuoteRepository
     */
    protected $quoteRepository;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * PlaceOrder constructor
     *
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Quote\Model\QuoteRepository $quoteRepository
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        LoggerInterface $logger,
        QuoteRepository $quoteRepository,
        StoreManagerInterface $storeManager
    ) {
        $this->_logger = $logger;
        $this->quoteRepository = $quoteRepository;
        $this->_storeManager = $storeManager;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute(Observer $observer)
    {
        $storeCode = $this->_storeManager->getStore()->getCode();
        if ($storeCode !== $this::STORE_CODE_JP) {
            return;
        }
        $order = $observer->getOrder();
        $quoteId = $order->getQuoteId();
        $quote  = $this->quoteRepository->get($quoteId);
        $order->setShippingFirstnameJp($quote->getShippingFirstnameJp());
        $order->setShippingLastnameJp($quote->getShippingLastnameJp());
        $order->setBillingFirstnameJp($quote->getBillingFirstnameJp() ?
            $quote->getBillingFirstnameJp() :
            $quote->getShippingFirstnameJp());
        $order->setBillingLastnameJp($quote->getBillingLastnameJp() ?
            $quote->getBillingLastnameJp() :
            $quote->getShippingLastnameJp());
        $order->save();
    }
}
