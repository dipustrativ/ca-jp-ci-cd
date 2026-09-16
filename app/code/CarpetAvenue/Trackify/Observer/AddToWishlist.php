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
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Queues GA4 add_to_wishlist.
 *
 * Bound to BOTH `wishlist_add_product` (stock, logged-in customers) and
 * `guestwishlist_add_product` (BelVG_GuestWishlist, guests) — see etc/frontend/events.xml.
 * Guests are the majority of wishlist adds on this storefront, so hooking only the stock
 * event would silently miss most of them. Both dispatch the same
 * ['wishlist', 'product', 'item'] payload, so one observer serves both.
 */
class AddToWishlist implements ObserverInterface
{
    public function __construct(
        private readonly Trackify $section,
        private readonly TagManagerHelper $helper,
        private readonly DataLayer $dataLayer
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

        $product = $observer->getEvent()->getData('product');

        if (!$product instanceof Product) {
            return;
        }

        // Stock and guest wishlist items are different classes but both are DataObjects.
        $item = $observer->getEvent()->getData('item');
        $qty = $item instanceof DataObject ? (int) ($item->getData('qty') ?: 1) : 1;

        $this->section->queue(
            $this->dataLayer->getProductEventPayload('add_to_wishlist', $product, $qty ?: 1)
        );
    }
}
