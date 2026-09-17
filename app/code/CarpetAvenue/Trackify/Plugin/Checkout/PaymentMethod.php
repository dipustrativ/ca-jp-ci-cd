<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Marketing
 * @package   CarpetAvenue_Trackify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Trackify\Plugin\Checkout;

use CarpetAvenue\Trackify\CustomerData\Trackify;
use CarpetAvenue\Trackify\Helper\TagManagerHelper;
use CarpetAvenue\Trackify\ViewModel\DataLayer;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\PaymentMethodManagement;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues GA4 add_payment_info when a payment method is selected.
 *
 * Bound to the concrete PaymentMethodManagement because everything funnels through it:
 * the storefront's selected-payment-method REST call, GuestPaymentMethodManagement
 * (which delegates here after resolving the masked id), and — importantly —
 * PaymentInformationManagement::savePaymentInformation(), which calls set() again at
 * place-order time with the SAME method.
 *
 * That last one is why this uses queueOnce() keyed on the method code: firing on every
 * call would report a second add_payment_info for every order. Keying on the code still
 * lets a customer who genuinely switches methods produce a second, correct event.
 *
 * Declared in the global etc/di.xml — these calls run in the webapi_rest area.
 */
class PaymentMethod
{
    public function __construct(
        private readonly Trackify $section,
        private readonly TagManagerHelper $helper,
        private readonly DataLayer $dataLayer,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param PaymentMethodManagement $subject
     * @param int $result Payment id
     * @param int $cartId
     * @return int
     */
    public function afterSet(PaymentMethodManagement $subject, $result, $cartId)
    {
        if (!$this->helper->isGoogleTagManagerEnabled()) {
            return $result;
        }

        try {
            $quote = $this->cartRepository->get((int) $cartId);

            // CartRepository is typed to CartInterface, which declares none of the quote
            // methods used below; the concrete is always Quote.
            if (!$quote instanceof Quote) {
                return $result;
            }

            $payment = $quote->getPayment();
            $code = (string) $payment->getMethod();

            if ($code === '') {
                return $result;
            }

            $payload = $this->dataLayer->getCheckoutStepPayload(
                'add_payment_info',
                $quote,
                ['payment_type' => $this->getPaymentTitle($payment, $code)]
            );

            $this->section->queueOnce('add_payment_info', $code, $payload);
        } catch (Throwable $e) {
            $this->logger->debug('Trackify: skipped add_payment_info — ' . $e->getMessage());
        }

        return $result;
    }

    /**
     * Human-readable method title, falling back to the code.
     *
     * getMethodInstance() throws when the method is not resolvable (a disabled or removed
     * payment method still sitting on an old quote), which must not cost us the event.
     *
     * @param mixed $payment
     * @param string $code
     * @return string
     */
    private function getPaymentTitle($payment, string $code): string
    {
        try {
            return (string) ($payment->getMethodInstance()->getTitle() ?: $code);
        } catch (Throwable) {
            return $code;
        }
    }
}
