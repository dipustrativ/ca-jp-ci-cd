<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Authoritative purchase block. Throws before an order is placed for an
 * unapproved customer, covering the default checkout, Mageplaza OSC and any
 * REST/GraphQL path that reaches QuoteManagement::placeOrder. Gated by config.
 *
 * @package CarpetAvenue_B2B
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\B2B\Plugin\Checkout;

use CarpetAvenue\B2B\Model\ApprovalChecker;
use CarpetAvenue\B2B\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;

class PlaceOrderGuard
{
    public function __construct(
        private readonly ApprovalChecker $approvalChecker,
        private readonly Config $config,
        private readonly CartRepositoryInterface $cartRepository
    ) {
    }

    /**
     * @param int $cartId
     * @param \Magento\Quote\Api\Data\PaymentInterface|null $paymentMethod
     * @throws LocalizedException
     */
    public function beforePlaceOrder(QuoteManagement $subject, $cartId, $paymentMethod = null): void
    {
        if (!$this->approvalChecker->isGateActive()) {
            return;
        }

        try {
            $quote = $this->cartRepository->get((int)$cartId);
        } catch (\Exception $e) {
            return; // let core handle a bad cart id
        }

        $customerId = (int)$quote->getCustomer()->getId();
        if ($customerId <= 0) {
            return; // guests are not gated
        }

        if (!$this->approvalChecker->isApprovedById($customerId)) {
            throw new LocalizedException(
                $this->config->pendingMessage()
                    ? __($this->config->pendingMessage())
                    : __('Your account is pending approval and cannot place orders yet.')
            );
        }
    }
}
