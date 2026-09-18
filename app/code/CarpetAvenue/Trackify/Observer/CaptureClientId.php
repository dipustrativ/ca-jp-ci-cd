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

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\Cookie\CookieReaderInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Stores the GA4 client id on the order at placement.
 *
 * This exists entirely for the server-side refund. GA4's Measurement Protocol will not
 * attribute an event without a client_id, and a refund is raised days later in the admin
 * or from the ERP, where there is no browser and no cookie — so the id has to be taken now
 * and carried on the order.
 *
 * Bound to sales_model_service_quote_submit_before, which hands over the order *before* it
 * is saved, so setting the value here persists with the order's own insert — no second
 * write, and nothing to clean up if the order placement then fails.
 *
 * Deliberately NOT gated on the Measurement Protocol toggle: the id has to be captured
 * before anyone knows they will want it, otherwise switching the refund feature on would
 * only work for orders placed afterwards.
 */
class CaptureClientId implements ObserverInterface
{
    /**
     * Google's first-party analytics cookie.
     */
    private const GA_COOKIE = '_ga';

    public function __construct(
        private readonly CookieReaderInterface $cookieReader,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');

            if (!$order instanceof Order || $order->getData('trackify_client_id')) {
                return;
            }

            $clientId = $this->getClientId();

            if ($clientId !== '') {
                $order->setData('trackify_client_id', $clientId);
            }
        } catch (Throwable $e) {
            // An order must never fail to place because of analytics.
            $this->logger->debug('Trackify: could not capture GA client id — ' . $e->getMessage());
        }
    }

    /**
     * Client id out of the _ga cookie.
     *
     * The cookie reads `GA1.<domain depth>.<client id>`, e.g. GA1.1.1234567890.1699999999
     * — and the client id is itself dotted, so it is everything from the third segment on.
     * The domain-depth digit varies by host, which is why this counts from the front
     * rather than assuming a fixed number of parts.
     *
     * Empty when the customer blocked cookies or declined analytics consent, and an empty
     * id simply means no refund is reported for that order — which is the correct
     * behaviour, not a failure.
     *
     * @return string
     */
    private function getClientId(): string
    {
        $raw = trim((string) $this->cookieReader->getCookie(self::GA_COOKIE, ''));

        if ($raw === '') {
            return '';
        }

        $parts = explode('.', $raw);

        if (count($parts) < 4) {
            return '';
        }

        return implode('.', array_slice($parts, 2));
    }
}
