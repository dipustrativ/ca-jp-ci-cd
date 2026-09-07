<?php

declare(strict_types=1);

namespace Evalent\ProductContactForm\Helper;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Helper\View as CustomerViewHelper;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{

    const IS_ENABLED_XML_PATH = 'catalog/contact_form/enabled';
    const FROM_XML_PATH = 'catalog/contact_form/sender_email_identity';
    const RECEIVERS_XML_PATH = 'catalog/contact_form/receiving_email';
    const CC_EMAIL_XML_PATH = 'catalog/contact_form/cc_email';
    const EMAIL_TEMPLATE_XML_PATH = 'catalog/contact_form/email_template';
    const IS_RECAPTCHA_ENABLED_XML_PATH = 'catalog/contact_form/recaptcha_enabled';

    const IS_GRECAPTCHA_ENABLED_XML_PATH = 'recaptcha_frontend/type_for/productcontactform';

    /**
     * @var \Magento\Framework\App\Request\DataPersistorInterface
     */
    private $dataPersistor;


    /**
     * @var array
     */
    private $postData = null;

    /**
     * @var \Magento\Customer\Model\Session
     */
    private $customerSession;

    /**
     * @var \Magento\Customer\Helper\View
     */
    private $customerViewHelper;

    /**
     * Data constructor.
     *
     * @param \Magento\Framework\App\Request\DataPersistorInterface $dataPersistor
     * @param \Magento\Customer\Model\Session                       $customerSession
     * @param \Magento\Customer\Helper\View                         $customerViewHelper
     * @param \Magento\Framework\App\Helper\Context                 $context
     */
    public function __construct(
        DataPersistorInterface $dataPersistor,
        \Magento\Customer\Model\Session $customerSession,
        CustomerViewHelper $customerViewHelper,
        Context $context
    ) {
        parent::__construct($context);
        $this->dataPersistor = $dataPersistor;
        $this->customerSession = $customerSession;
        $this->customerViewHelper = $customerViewHelper;
    }

    /**
     * @param string $scope
     * @param null   $storeId
     *
     * @return bool
     */
    public function isEnabled($scope = ScopeInterface::SCOPE_STORE, $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::IS_ENABLED_XML_PATH, $scope, $storeId);
    }

    /**
     * @param string $scope
     * @param null   $storeId
     *
     * @return array
     */
    public function getReceivers($scope = ScopeInterface::SCOPE_STORE, $storeId = null): array
    {
        $receiversString = $this->scopeConfig->getValue(self::RECEIVERS_XML_PATH, $scope, $storeId);
        if ($receiversString =='') {
            return [];
        }
        $receivers = explode(',', str_replace(' ', '', $receiversString));
        return $receivers;
    }

    /**
     * @param string $scope
     * @param null   $storeId
     *
     * @return array
     */
    public function getCcReceivers($scope = ScopeInterface::SCOPE_STORE, $storeId = null): array
    {
        $receiversString = $this->scopeConfig->getValue(self::CC_EMAIL_XML_PATH, $scope, $storeId);
        if ($receiversString =='') {
            return [];
        }
        $receivers = explode(',', str_replace(' ', '', $receiversString));
        return $receivers;
    }

    /**
     * @param string $scope
     * @param null   $storeId
     *
     * @return string
     */
    public function getTemplate($scope = ScopeInterface::SCOPE_STORE, $storeId = null): string
    {
        return $this->scopeConfig->getValue(self::EMAIL_TEMPLATE_XML_PATH, $scope, $storeId);
    }

    /**
     * @param string $scope
     * @param null   $storeId
     *
     * @return string
     */
    public function getFrom($scope = ScopeInterface::SCOPE_STORE, $storeId = null): string
    {
        return $this->scopeConfig->getValue(self::FROM_XML_PATH, $scope, $storeId);
    }

    /**
     * @param string $scope
     * @param null   $storeId
     *
     * @return bool
     */
    public function isRecaptchaEnabled($scope = ScopeInterface::SCOPE_STORE, $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::IS_RECAPTCHA_ENABLED_XML_PATH, $scope, $storeId);
    }

    public function isGRecaptchaEnabled($scope = ScopeInterface::SCOPE_STORE, $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::IS_GRECAPTCHA_ENABLED_XML_PATH, $scope, $storeId);
    }

    /**
     * Get value from POST by key
     *
     * @param string $key
     * @return string
     */
    public function getPostValue($key)
    {
        if (null === $this->postData) {
            $this->postData = (array) $this->dataPersistor->get('product_contact_form');
            $this->dataPersistor->clear('product_contact_form');
        }

        if (isset($this->postData[$key])) {
            return (string) $this->postData[$key];
        }

        return '';
    }

    /**
     * Get user name
     *
     * @return string
     */
    public function getUserName()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return '';
        }
        /**
         * @var \Magento\Customer\Api\Data\CustomerInterface $customer
         */
        $customer = $this->customerSession->getCustomerDataObject();

        return trim($this->customerViewHelper->getCustomerName($customer));
    }

    /**
     * Get user email
     *
     * @return string
     */
    public function getUserEmail()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return '';
        }
        /**
         * @var CustomerInterface $customer
         */
        $customer = $this->customerSession->getCustomerDataObject();

        return $customer->getEmail();
    }


}
