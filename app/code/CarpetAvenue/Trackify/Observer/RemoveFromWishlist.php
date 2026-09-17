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
use Magento\Catalog\Model\Product\Configuration\Item\ItemInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues GA4 remove_from_wishlist.
 *
 * Unlike adding, **no wishlist controller dispatches a remove event** — stock
 * Index\Remove, BelVG's Index\Remove and BelVG's Index\RemoveProduct (twice) all simply
 * call $item->delete(). Hooking any one of those would leave the others untracked, so
 * this listens at the single point they all route through: the model's own delete event,
 * bound for both item classes (`wishlist_item` / `guest_wishlist_item` prefixes).
 *
 * `_delete_commit_after` rather than `_delete_after`, so a rolled-back delete never
 * reports a removal that did not happen.
 */
class RemoveFromWishlist implements ObserverInterface
{
    public function __construct(
        private readonly Trackify $section,
        private readonly TagManagerHelper $helper,
        private readonly DataLayer $dataLayer,
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

        $item = $observer->getEvent()->getData('item');

        // Both the stock and the guest wishlist item implement ItemInterface (getProduct)
        // and extend DataObject (qty lives in the row data, not on the interface).
        if (!$item instanceof ItemInterface || !$item instanceof DataObject) {
            return;
        }

        try {
            // getProduct() throws when the catalog product is gone, which is exactly when
            // a customer is most likely to be clearing a stale wishlist row. Analytics
            // must never be the reason a delete fails.
            $product = $item->getProduct();
            $qty = (int) ($item->getData('qty') ?: 1);

            $this->section->queue(
                $this->dataLayer->getProductEventPayload('remove_from_wishlist', $product, $qty ?: 1)
            );
        } catch (Throwable $e) {
            $this->logger->debug('Trackify: skipped remove_from_wishlist — ' . $e->getMessage());
        }
    }
}
