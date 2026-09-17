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

namespace CarpetAvenue\Trackify\Observer;

use CarpetAvenue\Trackify\CustomerData\Trackify;
use CarpetAvenue\Trackify\Helper\TagManagerHelper;
use CarpetAvenue\Trackify\ViewModel\DataLayer;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues GA4 select_promotion for a coupon applied on the CART page.
 *
 * The cart page does not use CouponManagement: Magento\Checkout\Controller\Cart\CouponPost
 * sets the code directly on the quote and collects totals, so the checkout plugin
 * (Plugin\Quote\Coupon) never sees it. Hence a second entry point — but both hand off to
 * the same DataLayer::getPromotionPayload(), so the payload shape cannot drift between
 * the two.
 *
 * Bound to controller_action_postdispatch_checkout_cart_couponPost rather than to the
 * controller class, so no plugin on a core controller is needed. The controller reports
 * failure through the message manager and still returns normally, so success is
 * determined the only reliable way — by reading the code back off the quote afterwards.
 * That also naturally ignores coupon REMOVAL, where the code comes back empty.
 */
class ApplyCouponCart implements ObserverInterface
{
    public function __construct(
        private readonly Trackify $section,
        private readonly TagManagerHelper $helper,
        private readonly DataLayer $dataLayer,
        private readonly CheckoutSession $checkoutSession,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->helper->isGoogleTagManagerEnabled()) {
            return;
        }

        try {
            $quote = $this->checkoutSession->getQuote();
            $coupon = trim((string) $quote->getCouponCode());

            // Empty means the coupon was removed, or was rejected and never applied.
            if ($coupon === '') {
                return;
            }

            $this->section->queueOnce(
                'select_promotion',
                $coupon,
                $this->dataLayer->getPromotionPayload('select_promotion', $quote, 'cart')
            );
        } catch (Throwable $e) {
            $this->logger->debug('Trackify: skipped select_promotion — ' . $e->getMessage());
        }
    }
}
