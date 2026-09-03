<?php

namespace Evalent\CheckoutModsJP\Plugin\Quote;

use Magento\Checkout\Api\Data\ShippingInformationInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\QuoteRepository;
use Evalent\CheckoutModsJP\Api\Data\CheckoutConfigsJpInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * class SaveToQuote
 */
class SaveToQuote implements CheckoutConfigsJpInterface
{
    /**
     * @var \Magento\Quote\Model\QuoteRepository
     */
    protected $quoteRepository;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * SaveToQuote constructor
     *
     * @param \Magento\Quote\Model\QuoteRepository $quoteRepository
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        QuoteRepository       $quoteRepository,
        StoreManagerInterface $storeManager
    )
    {
        $this->quoteRepository = $quoteRepository;
        $this->_storeManager = $storeManager;
    }

    /**
     * @param \Magento\Checkout\Model\ShippingInformationManagement $subject
     * @param $cartId
     * @param \Magento\Checkout\Api\Data\ShippingInformationInterface $addressInformation
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function beforeSaveAddressInformation(
        ShippingInformationManagement $subject,
                                      $cartId,
        ShippingInformationInterface  $addressInformation
    )
    {
        $storeCode = $this->_storeManager->getStore()->getCode();
        if ($storeCode !== $this::STORE_CODE_JP) {
            return;
        }

        $extensionAttributes = $addressInformation->getShippingAddress()->getExtensionAttributes();
        if (!$extensionAttributes->getShippingFirstnameJp()) {
            return;
        }

        try {
            $quote = $this->quoteRepository->getActive($cartId);
        } catch (NoSuchEntityException $e) {
        }

        $quote->setShippingFirstnameJp($extensionAttributes->getShippingFirstnameJp());
        $quote->setShippingLastnameJp($extensionAttributes->getShippingLastnameJp());
        $quote->setBillingFirstnameJp($extensionAttributes->getBillingFirstnameJp() ?
            $extensionAttributes->getBillingFirstnameJp() :
            $extensionAttributes->getShippingFirstnameJp());
        $quote->setBillingLastnameJp($extensionAttributes->getBillingLastnameJp() ?
            $extensionAttributes->getBillingLastnameJp() :
            $extensionAttributes->getShippingLastnameJp());
    }
}
