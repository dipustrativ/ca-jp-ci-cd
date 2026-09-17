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

namespace CarpetAvenue\Trackify\CustomerData;

use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Session\SessionManager;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

/**
 * The only genuinely per-customer tracking data.
 *
 * Everything else renders into the page, but visitor properties are needed on
 * full-page-cached pages and add_to_cart / remove_from_cart fire from them, so both have
 * to come down this channel instead. Nothing here may ever be rendered into page markup —
 * that is exactly the FPC leak the verification step greps for.
 */
class Trackify implements SectionSourceInterface
{
    /**
     * Queue key in the PHP session. Events are drained as they are read so the session
     * does not grow; the client additionally filters by id, because customer-data is
     * replayed out of the browser's localStorage on every page load.
     */
    private const QUEUE_KEY = 'trackify_event_queue';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly SessionManager $session,
        private readonly OrderCollectionFactory $orderCollectionFactory
    ) {
    }

    /**
     * @return array
     */
    public function getSectionData(): array
    {
        return [
            'visitor' => $this->getVisitorData(),
            'events' => $this->drainQueue(),
        ];
    }

    /**
     * Append a GA4 payload for the next customer-data fetch to pick up.
     *
     * @param array $payload
     * @return void
     */
    public function queue(array $payload): void
    {
        $queue = (array) $this->session->getData(self::QUEUE_KEY);
        $queue[] = [
            // Monotonic per-session id; the client keeps a high-water mark so a replayed
            // section never re-pushes an event it has already sent.
            'id' => (int) round(microtime(true) * 1000),
            'payload' => $payload,
        ];
        // SessionManager defines getData() but forwards setData() to its storage via
        // __call, so static analysis cannot see it. It is the framework's own idiom.
        /** @phpstan-ignore-next-line */
        $this->session->setData(self::QUEUE_KEY, $queue);
    }

    /**
     * @return array
     */
    private function drainQueue(): array
    {
        // getData($key, true) reads and clears in one call.
        return array_values((array) $this->session->getData(self::QUEUE_KEY, true));
    }

    /**
     * @return array
     */
    private function getVisitorData(): array
    {
        if (!$this->customerSession->isLoggedIn()) {
            return [
                'visitorLoginState' => 'Logged out',
                'visitorType' => 'NOT LOGGED IN',
                'visitorExistingCustomer' => 'No',
                'visitorLifetimeValue' => 0,
            ];
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $orders = $this->orderCollectionFactory->create()
            ->addFieldToFilter('customer_id', $customerId)
            ->addFieldToFilter('state', ['nin' => ['canceled', 'new']]);

        $lifetimeValue = 0.0;

        foreach ($orders as $order) {
            $lifetimeValue += (float) $order->getGrandTotal();
        }

        return [
            'visitorLoginState' => 'Logged in',
            'visitorType' => 'customer',
            'visitorExistingCustomer' => $orders->getSize() > 0 ? 'Yes' : 'No',
            'visitorLifetimeValue' => round($lifetimeValue, 2),
            'visitorId' => $customerId,
        ];
    }
}
