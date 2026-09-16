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
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote\Item;

/**
 * Queues GA4 add_to_cart. Add to cart happens from a full-page-cached page, so the event
 * cannot be rendered into the markup — it goes out through the customer-data section.
 */
class AddToCart implements ObserverInterface
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

        $item = $observer->getEvent()->getData('quote_item');

        if (!$item instanceof Item) {
            return;
        }

        // Configurable/bundle children carry no meaningful price of their own.
        $item = $item->getParentItem() ?: $item;

        $this->section->queue($this->dataLayer->getCartEventPayload('add_to_cart', $item));
    }
}
