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

    /**
     * Last value reported per event type, so a step that the checkout re-saves does not
     * report itself twice. See queueOnce().
     */
    private const GUARD_KEY = 'trackify_event_guard';

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
     * Queue a payload only when the thing it describes has actually changed.
     *
     * The mid-funnel checkout steps are re-saved constantly and would otherwise report a
     * GA4 event every time. Mageplaza OSC calls saveAddressInformation() on practically
     * every field change, and PaymentInformationManagement::savePaymentInformation()
     * calls PaymentMethodManagement::set() a second time at place-order with the same
     * method — so add_payment_info would double-count on every single order.
     *
     * Keying on the value rather than suppressing outright keeps the events that SHOULD
     * repeat: a customer who switches from one shipping method or payment method to
     * another gets a second, correct event.
     *
     * @param string $key Event name, e.g. 'add_shipping_info'.
     * @param string $value The thing that must change for a re-report, e.g. the method code.
     * @param array $payload
     * @return void
     */
    public function queueOnce(string $key, string $value, array $payload): void
    {
        if ($payload === []) {
            return;
        }

        $guard = (array) $this->session->getData(self::GUARD_KEY);

        if (($guard[$key] ?? null) === $value) {
            return;
        }

        $guard[$key] = $value;
        /** @phpstan-ignore-next-line */
        $this->session->setData(self::GUARD_KEY, $guard);

        $this->queue($payload);
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
