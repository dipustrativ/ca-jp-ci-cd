<?php
/**
 * Webkul contactus js.
 * @category Webkul
 * @package Webkul_AjaxContactForm
 * @author Webkul
 * @copyright Copyright (c) Webkul Software Private Limited (https://webkul.com)
 * @license https://store.webkul.com/license.html
 */
namespace Webkul\AjaxContactForm\Helper;

use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Webkul\AjaxContactForm\Logger\Logger;

class Email extends \Magento\Framework\App\Helper\AbstractHelper
{
    const XML_PATH_CONTACTUS_MAIL = 'ajaxcontactform_general_contactus_email';
    const XML_PATH_CATALOGUE_MAIL = 'ajaxcontactform_general_catalogue_email';

    /**
     * @var \Magento\Framework\Translate\Inline\StateInterface
     */
    private $inlineTranslation;

    /**
     * @var \Magento\Framework\Mail\Template\TransportBuilder
     */
    private $transportBuilder;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private $_storeManager;

    protected \Magento\Framework\App\Config\ScopeConfigInterface $_scopeConfig;

    protected $_template;
    private Logger $logger;

    /**
     * @param \Magento\Framework\App\Helper\Context  $context,
     * @param StateInterface                          $inlineTranslation,
     * @param TransportBuilder                       $transportBuilder,
     * @param StoreManagerInterface                  $storeManager,
     * @param CustomerRepositoryInterface            $customer,
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        StateInterface $inlineTranslation,
        TransportBuilder $transportBuilder,
        StoreManagerInterface $storeManager,
        CustomerRepositoryInterface $customer,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        Logger $logger
    ) {

        parent::__construct($context);
        $this->inlineTranslation = $inlineTranslation;
        $this->transportBuilder = $transportBuilder;
        $this->_storeManager = $storeManager;
        $this->_scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * [generateTemplate description].
     *
     * @param Mixed $emailTemplateVariables
     * @param Mixed $senderInfo
     * @param Mixed $receiverInfo
     */
    public function generateTemplate($emailTemplateVariables, $senderInfo, $receiverInfo)
    {
        $template = $this->transportBuilder
                ->setTemplateIdentifier($this->_template)
                ->setTemplateOptions(
                    [
                        'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                        'store' => $this->_storeManager->getStore()->getId(),
                    ]
                )
                ->setTemplateVars($emailTemplateVariables)
                ->setFrom($senderInfo)
                ->addTo($receiverInfo['email'], $receiverInfo['name']);
        return $this;
    }

    /*
     * sent the mail to admin
     */
    public function contactusMail($emailto, $name, $email, $subject, $message, $phone, $fromstore)
    {
        try {
            $senderInfo = [
                'name' => $name,
                'email' => $email
            ];
            $receiverInfo = ['name' => $name, 'email' => $emailto];
            $emailTempVariables = ['message' => $message,'subject' => $subject,'name' => $name,'phone' => $phone,'email' => $email,'fromstore' => $fromstore];
            $this->_template = $this->getTemplateId(self::XML_PATH_CONTACTUS_MAIL);
            $this->inlineTranslation->suspend();
            $this->generateTemplate($emailTempVariables, $senderInfo, $receiverInfo);
            $transport = $this->transportBuilder->getTransport();
            $transport->sendMessage();
            $this->inlineTranslation->resume();
        } catch (\Exception $e) {
            $this->logger->info($e->getMessage());
            $this->logger->info($e->getFile().":".$e->getLine());
            $this->logger->info($e->getTraceAsString());
        }
    }

    public function orderCatalogueMail($emailto, $name, $email, $subject, $surname, $phone, $zip, $address)
    {
        try {
            $senderInfo = [
                'name' => $name,
                'email' => $email
            ];
            $receiverInfo = ['name' => $name, 'email' => $emailto];
            $emailTempVariables = [
                'subject' => $subject,
                'name' => $name,
                'surname' => $surname,
                'phone' => $phone,
                'email' => $email,
                'zip' => $zip,
                'address' => $address,
                'fromstore' => "JP"
            ];
            $this->_template = $this->getTemplateId2(self::XML_PATH_CATALOGUE_MAIL);
            $this->inlineTranslation->suspend();
            $this->generateTemplate($emailTempVariables, $senderInfo, $receiverInfo);
            $transport = $this->transportBuilder->getTransport();
            $transport->sendMessage();
            $this->inlineTranslation->resume();
            $this->logger->info("Step : Email sent to customer successfully...");
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $this->logger->error($e->getFile().":".$e->getLine());
            $this->logger->error($e->getTraceAsString());
        }
    }

    /**
     * Return template id.
     *
     * @return mixed
     */
    public function getTemplateId($xmlPath)
    {
        return $this->getConfigValue($xmlPath, $this->_storeManager->getStore()->getId());
    }

    public function getTemplateId2($xmlPath)
    {
        return $this->getConfigValue2($xmlPath, $this->_storeManager->getStore()->getId());
    }

    protected function getConfigValue($path, $storeId)
    {
        return $this->_scopeConfig->getValue(
            "ajaxcontactform/general/contactus_email",
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    protected function getConfigValue2($path, $storeId)
    {
        return $this->_scopeConfig->getValue(
            "ajaxcontactform/general/catalogue_email",
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
}
