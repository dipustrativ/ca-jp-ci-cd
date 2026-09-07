<?php
/**
 * Webkul software.
 * @category Webkul
 * @package Webkul_AjaxContactForm
 * @author Webkul
 * @copyright Copyright (c) Webkul Software Private Limited (https://webkul.com)
 * @license https://store.webkul.com/license.html
 */
namespace Webkul\AjaxContactForm\Block\Widget;

use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;
use Magento\Widget\Block\BlockInterface;

class Contactus extends Template implements BlockInterface
{
    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;

    protected $httpContext;

    protected $_storeManager;


    private const XML_PATH_PUBLIC_KEY = 'recaptcha_frontend/type_recaptcha_v3/public_key';
    private const XML_PATH_SECRET_KEY = 'recaptcha_frontend/type_recaptcha_v3/private_key';
    private const XML_PATH_POSITION = 'recaptcha_frontend/type_recaptcha_v3/position';
    private const XML_PATH_THEME = 'recaptcha_frontend/type_recaptcha_v3/theme';
    private const XML_PATH_LANGUAGE_CODE = 'recaptcha_frontend/type_recaptcha_v3/lang';

    /**
     * @var \Magento\Catalog\Block\Product\Context
     * @var \Magento\Customer\Model\Session
     * @var array
     */
    public function __construct(
        \Magento\Catalog\Block\Product\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\App\Http\Context $httpContext,
        \Magento\Framework\Encryption\EncryptorInterface $encryptor,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        array $data = []
    ) {
          $this->setTemplate('widget/contactus.phtml');
          $this->_customerSession = $customerSession;
          $this->httpContext = $httpContext;
          $this->_encryptor = $encryptor;
          $this->_storeManager = $storeManager;
          parent::__construct($context, $data);
    }
    //This function will be used to get the css/js file.
    public function getAssetUrl($asset)
    {
        return $this->_assetRepo->createAsset($asset)->getUrl();
    }
    public function getApiKey()
    {
        return $this->_encryptor->decrypt($this->_scopeConfig->getValue('ajaxcontactform/general/appendChild'));
    }

    public function getRecaptchaLang()
    {
        return $this->_scopeConfig->getValue(self::XML_PATH_LANGUAGE_CODE, ScopeInterface::SCOPE_STORE) ?: 'en';
    }

    public function getRecaptchaType()
    {
        return $this->_scopeConfig->getValue('recaptcha_frontend/type_for/ordercatalogue', ScopeInterface::SCOPE_WEBSITE);
    }

    public function getRecaptchaSiteKey()
    {
        $type = $this->getRecaptchaType();
        if ($type === 'recaptcha_v3') {
            return $this->_scopeConfig->getValue(self::XML_PATH_PUBLIC_KEY, ScopeInterface::SCOPE_WEBSITE) ?: '';
        }
        return '';
    }

    public function getApiVersion()
    {
        return $this->_scopeConfig->getValue('ajaxcontactform/general/map_status');
    }
    public function getApiCoordinate()
    {
        return $this->_scopeConfig->getValue('ajaxcontactform/general/map_coordinates');
    }

    public function isShowEmail()
    {
        return $this->_scopeConfig->getValue('ajaxcontactform/general/showemail');
    }

    /**
     * getCustomerEmail
     * @return string
     */
    public function getCustomerEmail()
    {
        $customerEmail = $this->httpContext->getValue('customer_email');
        return $customerEmail;
    }

    /**
     * getCustomerName
     * @return string
     */
    public function getCustomerName()
    {
        $customerName = $this->httpContext->getValue('customer_name');
        return $customerName;
    }

    /**
     * Get Store code
     *
     * @return string
     */
    public function getStoreCode()
    {
        return $this->_storeManager->getStore()->getCode();
    }

    protected function _prepareLayout()
    {
        parent::_prepareLayout();

        $this->addChild(
            'recaptcha',
            'Magento\ReCaptchaUi\Block\ReCaptcha',
            [
                'recaptcha_for' => 'callmepopup',
                'template' => 'Magento_ReCaptchaFrontendUi::recaptcha.phtml',
                'jsLayout' => [
                    'components' => [
                        'recaptcha' => [
                            'component' => 'Magento_ReCaptchaFrontendUi/js/reCaptcha',
                            'reCaptchaId' => 'recaptcha-popup-callme'
                        ]
                    ]
                ]
            ]
        );

        return $this;
    }

}
