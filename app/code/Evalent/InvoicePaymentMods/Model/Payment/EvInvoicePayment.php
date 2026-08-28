<?php


namespace Evalent\InvoicePaymentMods\Model\Payment;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Quote\Api\Data\CartInterface;

class EvInvoicePayment extends AbstractMethod
{

    protected $_code = 'evinvoicepayment';
    protected $_isOffline = true;
    protected $_isInitializeNeeded = true;
    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canCapturePartial = true;
    protected $_canRefundInvoicePartial = true;

    protected $_orderCollectionFactory;
    protected $_checkoutSession;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        ?\Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        ?\Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    )
    {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->_orderCollectionFactory = $orderCollectionFactory;
        $this->_checkoutSession = $checkoutSession;
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     * @return AbstractMethod|void
     */
    public function initialize($paymentAction, $stateObject)
    {
        $state = $this->getConfigData('order_status');
        $stateObject->setState($state);
        $stateObject->setStatus($state);
        $stateObject->setIsNotified(false);
    }

    public function canUseCheckout()
    {
        return parent::canUseCheckout();
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        $_isAdmin = \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE == 'adminhtml';
        if ($quote === null) {
            return false;
        }

        if ($this->getConfigData('allowspecific_customer')) {
            if (!$this->isCustomerGroupAllowed($quote)) {
                return false;
            }
        }

        if ($this->getConfigData('allowspecific')) {
            if (!$this->isCountryAllowed($quote->getBillingAddress()->getCountryId())) {
                return false;
            }
        }

        return true;
    }


    /**
     * @param $quote
     * @return bool
     */
    protected function isCustomerGroupAllowed($quote)
    {
        $allowedCustomerGroupIds = explode(',', $this->getConfigData('allowcustomergroup'));
        return in_array($quote->getCustomerGroupId(), $allowedCustomerGroupIds) !== false;
    }

    /**
     * @param $countryId
     * @return bool
     */
    protected function isCountryAllowed($countryId)
    {
        return strpos($this->getConfigData('specificcountry'), $countryId) !== false;
    }
}
