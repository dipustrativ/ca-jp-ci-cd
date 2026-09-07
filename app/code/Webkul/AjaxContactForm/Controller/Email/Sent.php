<?php
namespace Webkul\AjaxContactForm\Controller\Email;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Webkul\AjaxContactForm\Helper\Email;
use Webkul\AjaxContactForm\Logger\Logger;

class Sent extends Action
{
    protected PageFactory $_resultPageFactory;
    protected JsonFactory $_resultJson;
    protected ScopeConfigInterface $scopeConfig;
    private Logger $logger;

    // reCAPTCHA v3 config paths
    private const XML_PATH_SECRET_KEY = 'recaptcha_frontend/type_recaptcha_v3/private_key';
    private const XML_PATH_ENABLED_RECAPTCHA_BACKEND = 'recaptcha_frontend/type_for/backend_verification_enabled';
    private const XML_PATH_ENABLED_RECAPTCHA_BACKEND_FOR_CONTACT_FORM = 'recaptcha_frontend/type_for/backend_verification_contact_enabled';

    protected array $response = array(
        'success' => false,
        'error' => '',
        'message' => ''
    );

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        JsonFactory $resultJson,
        ScopeConfigInterface $scopeConfig,
        Logger $logger
    ) {
        $this->_resultPageFactory = $resultPageFactory;
        $this->_resultJson = $resultJson;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        parent::__construct($context);
    }

    public function execute(): Json|ResultInterface|ResponseInterface
    {
        $helper = $this->_objectManager->get(Email::class);
        $resultJson = $this->_resultJson->create();

        if ($this->getRequest()->isPost()) {
            try {

                $data = $this->getRequest()->getParams();
                $emailList = explode(',', $data['email']);
                $name = $data['name'];
                $emailto = $data['emailto'];
                $_area = $data['area'];

                $this->logger->info("Contact form request initiated");
                $this->logger->info('Contact form Data', [
                    'params'  => $this->getRequest()->getParams()
                ]);

                foreach ($emailList as $email) {
                    if ($_area == "order_catalogue") {
                        $this->logger->info("Form Type : Catalogue");
                        $backendValidationEnabled = $this->scopeConfig->getValue(self::XML_PATH_ENABLED_RECAPTCHA_BACKEND, ScopeInterface::SCOPE_WEBSITES);
                        if ($backendValidationEnabled) {
                            $this->logger->info("Step : Backend Validation Enabled");
                            $captchaResult = $this->validateRecaptchaV3($this->getRequest()->getParam('g-recaptcha-response'));
                            if ($captchaResult !== true) {
                                $this->logger->error("Step : Backend Validation Failed");
                                if (is_string($captchaResult)) {
                                    $this->logger->error("Error : ".$captchaResult);
                                }
                                $this->response['error'] = $captchaResult;
                                $this->response['message'] = "Captcha verification failed. Please try again.";
                                return $resultJson->setData($this->response);
                            } else {
                                $this->logger->info("Step : Backend Validation Successfully Done");
                            }
                        }
                        if ($email != '') {
                            $this->logger->info("Step : Email found stating sending email");
                            $helper->orderCatalogueMail(
                                $emailto, $name, $email, __("NEW CATALOGUE 2025"),
                                $data['surname'], $data['phone'], $data['zip'], $data['address']
                            );
                        } else {
                            $this->logger->error("Step : No email found");
                        }
                    } elseif ($_area == "contact_popup") {
                        $backendValidationEnabled = $this->scopeConfig->getValue(self::XML_PATH_ENABLED_RECAPTCHA_BACKEND_FOR_CONTACT_FORM, ScopeInterface::SCOPE_WEBSITES);
                        if ($backendValidationEnabled) {
                            $captchaResult = $this->validateRecaptchaV3($this->getRequest()->getParam('g-recaptcha-response'));
                            if ($captchaResult !== true) {
                                $this->response['error'] = $captchaResult;
                                $this->response['message'] = "Captcha verification failed. Please try again.";
                                return $resultJson->setData($this->response);
                            }
                        }
                    }

                    if ($_area != "order_catalogue" && $email != '') {
                        $helper->contactusMail(
                            $emailto, $name, $email, __("I want to be called"),
                            $data['message'], $data['phone'], $data['fromstore']
                        );
                    }
                }
                $this->response['success'] = true;
                return $resultJson->setData($this->response);

            } catch (\Exception $e) {
                $this->response['error'] = $e->getMessage();
                $this->response['message'] = "Captcha verification failed. Please try again.";
                return $resultJson->setData($this->response);
            } catch (GuzzleException) {
                return $resultJson->setData(['error' => __('Captcha verification error. Please try again.')]);
            }
        }
        return $resultJson->setData(['error' => __('Invalid request')]);
    }

    /**
     * Validate reCAPTCHA v3 for "order_catalogue" area
     * @param string $token
     * @return true|string  true if valid, error message if invalid
     * @throws GuzzleException
     */
    private function validateRecaptchaV3(string $token): bool|string
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
                $googleResponse = json_decode($response->getBody(), true);
                $this->response['response'] = json_encode($googleResponse);
                $this->response['request'] = json_encode($postData);
                if (empty($googleResponse['success'])) {
                    return __('Captcha verification failed. Please try again.');
                }
                if (isset($googleResponse['score']) && $googleResponse['score'] < 0.5) {
                    return __('Captcha score too low. Please try again.');
                }
            } catch (\Exception $e) {
                $this->response['message'] = $e->getMessage();
                return __('Captcha verification error. Please try again.');
            }
        } else {
            return false;
        }
        return true;
    }

}
