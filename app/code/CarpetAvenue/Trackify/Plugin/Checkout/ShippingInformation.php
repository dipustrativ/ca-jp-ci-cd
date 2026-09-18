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
use Magento\Checkout\Api\Data\PaymentDetailsInterface;
use Magento\Checkout\Model\ShippingInformationManagement;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues GA4 add_shipping_info when a shipping method is chosen.
 *
 * Bound to the CONCRETE ShippingInformationManagement rather than to a controller, because
 * three different callers reach it and only this one point is common to all of them:
 * Mageplaza OSC (CheckoutManagement::saveCheckoutInformation), the stock checkout, and
 * guests — GuestShippingInformationManagement resolves the masked cart id and then
 * delegates straight here, so guests need no separate binding.
 *
 * Declared in the GLOBAL etc/di.xml, not etc/frontend: every one of those callers arrives
 * over the REST API, which runs in the webapi_rest area. A frontend-only plugin would
 * never execute.
 *
 * OSC re-saves the address on practically every field change, so this must not report a
 * step each time — queueOnce() keys on the shipping method and only emits when it
 * actually changes.
 */
class ShippingInformation
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
     * @param ShippingInformationManagement $subject
     * @param PaymentDetailsInterface $result
     * @param int $cartId
     * @return PaymentDetailsInterface
     */
    public function afterSaveAddressInformation(
        ShippingInformationManagement $subject,
        $result,
        $cartId
    ) {
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

            $address = $quote->getShippingAddress();
            $method = (string) $address->getShippingMethod();

            // Virtual carts have no shipping step at all.
            if ($method === '') {
                return $result;
            }

            $payload = $this->dataLayer->getCheckoutStepPayload(
                'add_shipping_info',
                $quote,
                ['shipping_tier' => (string) ($address->getShippingDescription() ?: $method)]
            );

            $this->section->queueOnce('add_shipping_info', $method, $payload);
        } catch (Throwable $e) {
            // Analytics must never be the reason a checkout step fails.
            $this->logger->debug('Trackify: skipped add_shipping_info — ' . $e->getMessage());
        }

        return $result;
    }
}
