<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Handles the reseller application form submission: validates, persists to
 * carpetavenue_b2b_reseller_application and notifies the admin by email.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Controller\Index;

use CarpetAvenue\B2B\Model\Config;
use CarpetAvenue\B2B\Model\Email\ResellerApplicationSender;
use CarpetAvenue\B2B\Model\ResellerApplicationFactory;
use CarpetAvenue\B2B\Model\ResourceModel\ResellerApplication as ResellerApplicationResource;
use CarpetAvenue\B2B\Model\Source\ApplicationStatus;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Message\ManagerInterface;
use Magento\ReCaptchaUi\Model\CaptchaResponseResolverInterface;
use Magento\ReCaptchaUi\Model\IsCaptchaEnabledInterface;
use Magento\ReCaptchaUi\Model\ValidationConfigResolverInterface;
use Magento\ReCaptchaValidationApi\Api\ValidatorInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Post implements HttpPostActionInterface
{
    private const REQUIRED_FIELDS = ['name', 'email', 'company_name', 'business_type', 'sales_channels', 'message'];

    /** reCAPTCHA form key — matches recaptcha_frontend/type_for/<key> + the layout block. */
    private const CAPTCHA_KEY = 'carpetavenue_b2b_reseller';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly ResellerApplicationFactory $applicationFactory,
        private readonly ResellerApplicationResource $applicationResource,
        private readonly ResellerApplicationSender $sender,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly LoggerInterface $logger,
        private readonly IsCaptchaEnabledInterface $isCaptchaEnabled,
        private readonly CaptchaResponseResolverInterface $captchaResponseResolver,
        private readonly ValidationConfigResolverInterface $validationConfigResolver,
        private readonly ValidatorInterface $captchaValidator,
        private readonly Config $config
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $redirect->setPath('become-a-carpient-reseller');

        $data = (array)$this->request->getPostValue();

        if (!$this->passesCaptcha()) {
            $this->messageManager->addErrorMessage(
                __('reCAPTCHA verification failed. Please try again.')
            );
            $this->keepData($data);
            return $redirect;
        }

        $error = $this->validate($data);
        if ($error !== null) {
            $this->messageManager->addErrorMessage($error);
            $this->keepData($data);
            return $redirect;
        }

        if ($this->applicationResource->existsByEmail(trim((string)$data['email']))) {
            $this->messageManager->addErrorMessage(
                __('You have already submitted a reseller application with this email address. Our team will be in touch shortly.')
            );
            return $redirect;
        }

        try {
            $storeId = (int)$this->storeManager->getStore()->getId();

            $application = $this->applicationFactory->create();
            $application->addData([
                'name' => trim((string)$data['name']),
                'email' => trim((string)$data['email']),
                'company_name' => trim((string)$data['company_name']),
                'homepage' => trim((string)($data['homepage'] ?? '')),
                'business_type' => (string)$data['business_type'],
                'sales_channels' => (string)$data['sales_channels'],
                'message' => trim((string)$data['message']),
                'status' => ApplicationStatus::STATUS_NEW,
                'store_id' => $storeId,
                'customer_id' => $this->customerSession->isLoggedIn()
                    ? (int)$this->customerSession->getCustomerId()
                    : null,
            ]);
            $this->applicationResource->save($application);

            $this->sender->send($application, $storeId);

            $this->messageManager->addSuccessMessage(
                __('Thank you! Your reseller application has been received. Our team will be in touch shortly.')
            );
        } catch (\Exception $e) {
            $this->logger->error('[CarpetAvenue_B2B] reseller application error: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(
                __('Something went wrong while submitting your application. Please try again.')
            );
            $this->keepData($data);
        }

        return $redirect;
    }

    /**
     * True when reCAPTCHA is disabled for this form, or the submitted token is valid.
     * Uses the storefront reCAPTCHA keys/threshold configured under Security → Google reCAPTCHA.
     */
    private function passesCaptcha(): bool
    {
        // Master switch (default Yes). Off → no verification.
        if (!$this->config->resellerRecaptchaEnabled()) {
            return true;
        }

        // reCAPTCHA type/keys not configured natively → nothing to verify against.
        if (!$this->isCaptchaEnabled->isCaptchaEnabledFor(self::CAPTCHA_KEY)) {
            return true;
        }

        try {
            $token = $this->captchaResponseResolver->resolve($this->request);
        } catch (InputException $e) {
            return false;
        }

        $config = $this->validationConfigResolver->get(self::CAPTCHA_KEY);

        return $this->captchaValidator->isValid($token, $config)->isValid();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validate(array $data): ?\Magento\Framework\Phrase
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (trim((string)($data[$field] ?? '')) === '') {
                return __('Please fill in all required fields.');
            }
        }

        if (!\filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return __('Please enter a valid email address.');
        }

        return null;
    }

    /**
     * Retain submitted values so the form can be re-populated after a redirect.
     *
     * @param array<string, mixed> $data
     */
    private function keepData(array $data): void
    {
        unset($data['form_key']);
        $this->customerSession->setResellerFormData($data);
    }
}
