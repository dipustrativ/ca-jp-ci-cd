<?php

declare(strict_types=1);

namespace Evalent\ProductContactForm\Controller\Product;

use Evalent\ProductContactForm\Helper\Data;
use Evalent\ProductContactForm\Model\MailInterface;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Captcha\Helper\Data as CaptchaData;
use Magento\Captcha\Observer\CaptchaStringResolver;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use function strpos;

/**
 * Class Post
 * @package Evalent\ProductContactForm\Controller\Product
 */
class Post extends Action
{
    const XML_PATH_ENABLED_RECAPTCHA_BACKEND_CATALOG_FORM = 'recaptcha_frontend/type_for/backend_verification_catalog_form_enabled';
    const XML_PATH_SECRET_KEY = 'recaptcha_frontend/type_recaptcha_v3/private_key';

    const PRODUCT_FORM_CONTACT_CAPTCHA_ID = 'user_productcontactform';

    /**
     * @var PageFactory
     */
    protected $resultPageFactory;

    /**
     * @var \Magento\Contact\Model\MailInterface
     */
    private $mail;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var DataPersistorInterface
     */
    private $dataPersistor;

    /**
     * @var CaptchaData $captchaHelper
     */
    private $captchaHelper;

    /**
     * @var CaptchaStringResolver
     */
    private CaptchaStringResolver $captchaStringResolver;

    /**
     * @var \Evalent\ProductContactForm\Helper\Data
     */
    private $dataHelper;

    private $scopeConfig;

    /**
     * Constructor
     *
     * @param LoggerInterface $logger
     * @param MailInterface $mail
     * @param DataPersistorInterface $dataPersistor
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param CaptchaData $captchaHelper
     * @param CaptchaStringResolver $captchaStringResolver
     * @param Data $dataHelper
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        LoggerInterface $logger,
        MailInterface $mail,
        DataPersistorInterface $dataPersistor,
        Context $context,
        PageFactory $resultPageFactory,
        CaptchaData $captchaHelper,
        CaptchaStringResolver $captchaStringResolver,
        Data $dataHelper,
        ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->mail = $mail;
        $this->logger = $logger;
        $this->dataPersistor = $dataPersistor;
        $this->captchaHelper = $captchaHelper;
        $this->captchaStringResolver = $captchaStringResolver;
        $this->dataHelper = $dataHelper;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Execute view action
     *
     * @return ResultInterface
     * @throws GuzzleException
     */
    public function execute()
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create();
        if (!$this->getRequest()->isPost()) {
            return $redirect->setPath($this->_redirect->getRefererUrl());
        }
        try {
            $params = $this->validatedParams();
            $variables = $this->getVariables();

            //-- check backend validation
            $backendValidationEnabled = $this->scopeConfig->getValue(self::XML_PATH_ENABLED_RECAPTCHA_BACKEND_CATALOG_FORM, ScopeInterface::SCOPE_WEBSITES);
            if ($backendValidationEnabled) {
                $captchaResult = $this->validateRecaptchaV3($this->getRequest()->getParam('g-recaptcha-response'));
                if ($captchaResult !== true) {
                    throw new LocalizedException(__("Captcha verification failed. Please try again."));
                }
            }

            $this->sendEmail(array_merge($params, $variables));
            $this->messageManager->addSuccessMessage(
                __('Thanks for contacting us with your comments and questions. We\'ll respond to you very soon.')
            );
            $this->dataPersistor->clear('contact_us');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->dataPersistor->set('contact_us', $this->getRequest()->getParams());
        } catch (Exception $e) {
            $this->logger->critical($e);
            $this->messageManager->addErrorMessage(
                __('An error occurred while processing your form. Please try again later.')
            );
            $this->dataPersistor->set('contact_us', $this->getRequest()->getParams());
        }
        return $redirect->setPath($this->_redirect->getRefererUrl());
    }

    /**
     * @param array $post Post data from contact form
     * @return void
     */
    private function sendEmail($post)
    {
        $this->mail->send(
            $post['email'],
            ['data' => new DataObject($post)]
        );
    }

    /**
     * @return array
     * @throws Exception
     */
    private function validatedParams(): array
    {
        $request = $this->getRequest();
        if (trim($request->getParam('name')) === '') {
            throw new LocalizedException(__('Enter the Name and try again.'));
        }
        if (trim($request->getParam('comment')) === '') {
            throw new LocalizedException(__('Enter the comment and try again.'));
        }
        if (false === strpos($request->getParam('email'), '@')) {
            throw new LocalizedException(__('The email address is invalid. Verify the email address and try again.'));
        }
        if (trim($request->getParam('hideit')) !== '') {
            throw new Exception();
        }

        if($this->dataHelper->isRecaptchaEnabled()) {
            $this->validateCaptcha();
        }

        return $request->getParams();
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    private function validateCaptcha()
    {
        $captcha = $this->captchaHelper->getCaptcha(self::PRODUCT_FORM_CONTACT_CAPTCHA_ID);
        if ($captcha->isRequired()) {
            if (!$captcha->isCorrect(
                $this->captchaStringResolver->resolve(
                    $this->getRequest(),
                    self::PRODUCT_FORM_CONTACT_CAPTCHA_ID)
            )) {
                throw new LocalizedException(__('Incorrect CAPTCHA.'));
            }
        }
    }

    /**
     * @return array
     */
    private function getVariables(): array
    {
        $requestParams =  $this->getRequest()->getParams();
        $params = [];
        if (isset($requestParams['product_id']) && $requestParams['product_id'] != '') {
            $params['product_id'] = $requestParams['product_id'];
        }
        if (isset($requestParams['product_sku']) && $requestParams['product_sku'] != '') {
            $params['product_sku'] = $requestParams['product_sku'];
        }
        if (isset($requestParams['product_name']) && $requestParams['product_name'] != '') {
            $params['product_name'] = $requestParams['product_name'];
        }
        $params['request_url'] = $this->_redirect->getRefererUrl();
        return $params;
    }


    private function validateRecaptchaV3(string $token)
    {
        if (empty($token)) {
            return __('Captcha token missing, please reload the page.');
        }

        $secret = $this->scopeConfig->getValue(self::XML_PATH_SECRET_KEY, ScopeInterface::SCOPE_WEBSITES);
        if (empty($secret)) {
            return __('Captcha secret key not set.');
        }

        $postData = [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        // Use Guzzle if available
        if (class_exists('\GuzzleHttp\Client')) {
            try {
                $client = new Client();
                $response = $client->post('https://www.google.com/recaptcha/api/siteverify', [
                    'form_params' => $postData,
                ]);

                $googleResponse = json_decode($response->getBody()->getContents(), true);
                if (empty($googleResponse['success'])) {
                    return __('Captcha verification failed. Please try again.');
                }
                if (isset($googleResponse['score']) && $googleResponse['score'] < 0.5) {
                    return __('Captcha score too low. Please try again.');
                }
            } catch (\Exception $e) {
                return __('Captcha verification error. Please try again.');
            }
        } else {
            return false;
        }
        return true;
    }
}
