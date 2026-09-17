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
 * Queues GA4 remove_from_cart. Same reasoning as AddToCart — the minicart removes items
 * from cached pages, so this cannot be rendered server-side.
 */
class RemoveFromCart implements ObserverInterface
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

        if (!$item instanceof Item || $item->getParentItem()) {
            return;
        }

        $this->section->queue($this->dataLayer->getCartEventPayload('remove_from_cart', $item));
    }
}
