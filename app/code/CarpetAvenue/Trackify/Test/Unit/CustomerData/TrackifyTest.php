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

namespace CarpetAvenue\Trackify\Test\Unit\CustomerData;

use CarpetAvenue\Trackify\CustomerData\Trackify;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Guards the de-duplication in queueOnce().
 *
 * If this breaks, nothing errors and nothing looks wrong on the page — GA4 just quietly
 * records two add_payment_info events for every order, because
 * PaymentInformationManagement::savePaymentInformation() calls
 * PaymentMethodManagement::set() a second time at place-order with the same method.
 */
class TrackifyTest extends TestCase
{
    private Trackify $section;

    protected function setUp(): void
    {
        $this->section = new Trackify(
            $this->createStub(CustomerSession::class),
            new SessionStub(),
            $this->createStub(OrderCollectionFactory::class)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function drain(): array
    {
        return $this->section->getSectionData()['events'];
    }

    public function testQueueOnceEmitsTheFirstTime(): void
    {
        $this->section->queueOnce('add_payment_info', 'adyen_cc', ['event' => 'add_payment_info']);

        $events = $this->drain();
        $this->assertCount(1, $events);
        $this->assertSame('add_payment_info', $events[0]['payload']['event']);
    }

    public function testQueueOnceSuppressesTheSameValueTwice(): void
    {
        // savePaymentInformation() sets the same method again at place-order time.
        $this->section->queueOnce('add_payment_info', 'adyen_cc', ['event' => 'add_payment_info']);
        $this->section->queueOnce('add_payment_info', 'adyen_cc', ['event' => 'add_payment_info']);

        $this->assertCount(1, $this->drain(), 'The repeat call must not produce a second event.');
    }

    public function testQueueOnceEmitsAgainWhenTheValueChanges(): void
    {
        // A customer who genuinely switches payment method must produce a second event.
        $this->section->queueOnce('add_payment_info', 'adyen_cc', ['event' => 'add_payment_info']);
        $this->section->queueOnce('add_payment_info', 'banktransfer', ['event' => 'add_payment_info']);

        $this->assertCount(2, $this->drain());
    }

    public function testQueueOnceKeepsEventTypesIndependent(): void
    {
        // OSC re-saves the address constantly; that must not block the payment step.
        $this->section->queueOnce('add_shipping_info', 'flatrate_flatrate', ['event' => 'add_shipping_info']);
        $this->section->queueOnce('add_shipping_info', 'flatrate_flatrate', ['event' => 'add_shipping_info']);
        $this->section->queueOnce('add_payment_info', 'adyen_cc', ['event' => 'add_payment_info']);

        $events = $this->drain();
        $this->assertCount(2, $events);
        $this->assertSame('add_shipping_info', $events[0]['payload']['event']);
        $this->assertSame('add_payment_info', $events[1]['payload']['event']);
    }

    public function testQueueOnceIgnoresAnEmptyPayload(): void
    {
        // getCheckoutStepPayload() returns [] for an empty quote; that must not arm the
        // guard, or the real event would be suppressed when it finally arrives.
        $this->section->queueOnce('add_shipping_info', 'flatrate_flatrate', []);
        $this->section->queueOnce('add_shipping_info', 'flatrate_flatrate', ['event' => 'add_shipping_info']);

        $this->assertCount(1, $this->drain());
    }

    public function testDrainClearsTheQueue(): void
    {
        $this->section->queueOnce('add_payment_info', 'adyen_cc', ['event' => 'add_payment_info']);

        $this->assertCount(1, $this->drain());
        $this->assertCount(0, $this->drain(), 'Events must be drained, not replayed.');
    }
}
