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

namespace CarpetAvenue\Trackify\Test\Unit\ViewModel;

use CarpetAvenue\Trackify\Helper\Data;
use CarpetAvenue\Trackify\ViewModel\DataLayer;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\SalesRule\Model\RuleFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers the GA4 refund payload, which is a money path sent server-side with no browser
 * and no GTM preview to eyeball — if it is wrong, it is wrong silently and in the revenue
 * figures.
 *
 * The case that matters most is testRefundWithoutItemsIsNotReported(): GA4 reads a refund
 * carrying no items as a reversal of the ENTIRE transaction, so a shipping-only credit
 * memo must not be sent at all.
 */
class DataLayerRefundTest extends TestCase
{
    private DataLayer $dataLayer;

    protected function setUp(): void
    {
        $this->dataLayer = new DataLayer(
            $this->createStub(Data::class),
            $this->createStub(PriceCurrencyInterface::class),
            $this->createStub(RuleFactory::class)
        );
    }

    /**
     * @param float $qty
     * @param bool $hasParent
     * @return CreditmemoItem
     */
    private function item(string $sku, float $qty, float $priceInclTax, bool $hasParent = false): CreditmemoItem
    {
        $orderItem = $this->createStub(OrderItem::class);
        $orderItem->method('getParentItem')->willReturn($hasParent ? $this->createStub(OrderItem::class) : null);

        $item = $this->createStub(CreditmemoItem::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getName')->willReturn('Name of ' . $sku);
        $item->method('getQty')->willReturn($qty);
        $item->method('getPriceInclTax')->willReturn($priceInclTax);
        $item->method('getOrderItem')->willReturn($orderItem);

        return $item;
    }

    /**
     * @param CreditmemoItem[] $items
     */
    private function creditmemo(array $items, float $grandTotal, ?string $coupon = null): Creditmemo
    {
        $order = $this->createStub(Order::class);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getOrderCurrencyCode')->willReturn('JPY');
        $order->method('getCouponCode')->willReturn($coupon);

        $creditmemo = $this->createStub(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getAllItems')->willReturn($items);
        $creditmemo->method('getGrandTotal')->willReturn($grandTotal);

        return $creditmemo;
    }

    public function testPartialRefundCarriesItemsAndOrderCurrency(): void
    {
        $event = $this->dataLayer->getRefundEvent(
            $this->creditmemo([$this->item('VAN211932', 1.0, 21000.0)], 21000.0)
        );

        $this->assertSame('refund', $event['name']);
        // Order currency, never the EUR base the amounts are stored against.
        $this->assertSame('JPY', $event['params']['currency']);
        $this->assertSame(21000.0, $event['params']['value']);
        $this->assertSame('000000123', $event['params']['transaction_id']);
        $this->assertCount(1, $event['params']['items']);
        $this->assertSame('VAN211932', $event['params']['items'][0]['item_id']);
        $this->assertSame(1, $event['params']['items'][0]['quantity']);
    }

    public function testValueIsTheCreditMemoTotalNotTheSumOfItems(): void
    {
        // Refunded shipping and tax live in the credit memo total and nowhere else, so
        // summing the item lines would under-report every refund that returns postage.
        $event = $this->dataLayer->getRefundEvent(
            $this->creditmemo([$this->item('VAN211932', 1.0, 21000.0)], 21850.0)
        );

        $this->assertSame(21850.0, $event['params']['value']);
    }

    public function testUnrefundedLinesAreDropped(): void
    {
        // A credit memo carries a qty=0 line for every order item left untouched.
        $event = $this->dataLayer->getRefundEvent(
            $this->creditmemo(
                [$this->item('REFUNDED', 2.0, 1000.0), $this->item('UNTOUCHED', 0.0, 5000.0)],
                2000.0
            )
        );

        $this->assertCount(1, $event['params']['items']);
        $this->assertSame('REFUNDED', $event['params']['items'][0]['item_id']);
    }

    public function testConfigurableChildrenAreDropped(): void
    {
        // Children ride along with their parent and carry no meaningful price.
        $event = $this->dataLayer->getRefundEvent(
            $this->creditmemo(
                [$this->item('PARENT', 1.0, 21000.0), $this->item('CHILD', 1.0, 0.0, true)],
                21000.0
            )
        );

        $this->assertCount(1, $event['params']['items']);
        $this->assertSame('PARENT', $event['params']['items'][0]['item_id']);
    }

    public function testRefundWithoutItemsIsNotReported(): void
    {
        // A shipping-only or adjustment-only credit memo. GA4 reads an items-less refund
        // as a full-transaction reversal, so sending this would reverse the entire order
        // instead of the few hundred yen actually returned.
        $this->assertSame(
            [],
            $this->dataLayer->getRefundEvent($this->creditmemo([$this->item('X', 0.0, 0.0)], 772.73))
        );
    }

    public function testCouponIsIncludedWhenTheOrderHadOne(): void
    {
        $event = $this->dataLayer->getRefundEvent(
            $this->creditmemo([$this->item('VAN211932', 1.0, 21000.0)], 21000.0, 'FINAL10')
        );

        $this->assertSame('FINAL10', $event['params']['coupon']);
    }
}
