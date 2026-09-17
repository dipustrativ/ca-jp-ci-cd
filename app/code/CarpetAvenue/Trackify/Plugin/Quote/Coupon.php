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

namespace CarpetAvenue\Trackify\Plugin\Quote;

use CarpetAvenue\Trackify\CustomerData\Trackify;
use CarpetAvenue\Trackify\Helper\TagManagerHelper;
use CarpetAvenue\Trackify\ViewModel\DataLayer;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\CouponManagement;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues GA4 select_promotion when a coupon is applied from the CHECKOUT.
 *
 * A coupon is the only promotion this storefront actually exposes, so applying one is
 * what select_promotion reports. The cart page does not come through here — it posts to
 * checkout/cart/couponPost and sets the code straight on the quote — so that path has its
 * own observer (Observer\ApplyCouponCart). Both build the same payload through
 * DataLayer::getPromotionPayload(), differing only in creative_slot.
 *
 * CouponManagement::set() throws on an invalid or unusable code, so an after-plugin only
 * runs when the coupon really landed on the quote; no success check is needed here.
 *
 * Declared in the global etc/di.xml — the coupon REST route runs in webapi_rest.
 */
class Coupon
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
     * @param CouponManagement $subject
     * @param bool $result
     * @param int $cartId
     * @return bool
     */
    public function afterSet(CouponManagement $subject, $result, $cartId)
    {
        if (!$this->helper->isGoogleTagManagerEnabled()) {
            return $result;
        }

        try {
            $quote = $this->cartRepository->getActive((int) $cartId);

            // CartRepository is typed to CartInterface, which declares none of the quote
            // methods used below; the concrete is always Quote.
            if (!$quote instanceof Quote) {
                return $result;
            }

            $coupon = trim((string) $quote->getCouponCode());

            if ($coupon === '') {
                return $result;
            }

            $this->section->queueOnce(
                'select_promotion',
                $coupon,
                $this->dataLayer->getPromotionPayload('select_promotion', $quote, 'checkout')
            );
        } catch (Throwable $e) {
            $this->logger->debug('Trackify: skipped select_promotion — ' . $e->getMessage());
        }

        return $result;
    }
}
