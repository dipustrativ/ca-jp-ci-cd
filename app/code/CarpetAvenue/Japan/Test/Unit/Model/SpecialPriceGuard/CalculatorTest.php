<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Test\Unit\Model\SpecialPriceGuard;

use CarpetAvenue\Japan\Api\SpecialPriceGuardConfigInterface;
use CarpetAvenue\Japan\Model\PriceAction\Entry;
use CarpetAvenue\Japan\Model\SpecialPriceGuard\Calculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalculatorTest extends TestCase
{
    /**
     * Base store defaults: round(cost * 1.5 + 100 * 1.05, 2), so cost 200 → 405.
     */
    private function calculator(): Calculator
    {
        $config = $this->createMock(SpecialPriceGuardConfigInterface::class);
        $config->method('costMultiplier')->willReturn(1.5);
        $config->method('shippingBase')->willReturn(100.0);
        $config->method('taxPercent')->willReturn(5.0);

        return new Calculator($config);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'entity_id' => 42,
            'sku' => 'VAN1003',
            'cost' => '200.0000',
            'price' => '900.0000',
            'owner' => 'Vani',
            'special_price' => '300.0000',
        ];
    }

    public function testTheFormulaMatchesTheBaseStore(): void
    {
        // 200 * 1.5 + 100 * 1.05 = 405
        $this->assertSame(405.0, $this->calculator()->minimumFor(200.0));
        // 149.01 * 1.5 + 105 = 328.515
        $this->assertSame(328.52, $this->calculator()->minimumFor(149.01));
        // cost 0 still carries the shipping floor
        $this->assertSame(105.0, $this->calculator()->minimumFor(0.0));
    }

    /**
     * Half-cent ties must round up, the way MySQL's decimal maths does. Plain
     * float arithmetic rounds these down by a cent, putting the price under the
     * very minimum this exists to enforce.
     */
    #[DataProvider('halfCentTies')]
    public function testHalfCentTiesRoundUpNotDown(float $cost, float $expected): void
    {
        $this->assertSame($expected, $this->calculator()->minimumFor($cost));
    }

    /**
     * @return array<string, array{0: float, 1: float}>
     */
    public static function halfCentTies(): array
    {
        return [
            '133.19 → 304.785' => [133.19, 304.79],
            '29.93 → 149.895' => [29.93, 149.90],
            '629.17 → 1048.755' => [629.17, 1048.76],
            '949.67 → 1529.505' => [949.67, 1529.51],
        ];
    }

    public function testRaisesAPriceBelowTheFloor(): void
    {
        $decision = $this->calculator()->decide($this->row());

        $this->assertSame(Entry::ACTION_RAISE, $decision->action);
        $this->assertSame(Entry::REASON_BELOW_MIN, $decision->reason);
        $this->assertSame(405.0, $decision->newValue);
        $this->assertSame(405.0, $decision->threshold);
        $this->assertTrue($decision->writesCatalog());
        $this->assertTrue($decision->isLoggable());
    }

    public function testLeavesAPriceAboveTheFloorAlone(): void
    {
        $decision = $this->calculator()->decide($this->row(['special_price' => '800.0000']));

        $this->assertSame(Entry::ACTION_SKIP, $decision->action);
        $this->assertSame(Entry::REASON_IN_RANGE, $decision->reason);
        $this->assertNull($decision->newValue);
        $this->assertFalse($decision->writesCatalog());
        // Skips are never logged, or every run would write a row per product.
        $this->assertFalse($decision->isLoggable());
    }

    /**
     * A price sitting exactly on the floor is already correct — raising it again
     * would rewrite the same value every run and churn the price indexer.
     */
    public function testAPriceExactlyOnTheFloorIsLeftAlone(): void
    {
        $decision = $this->calculator()->decide($this->row(['special_price' => '405.0000']));

        $this->assertSame(Entry::ACTION_SKIP, $decision->action);
        $this->assertFalse($decision->writesCatalog());
    }

    /**
     * When the minimum exceeds what the product normally sells for, no discount
     * can satisfy it. Raising would be pointless — Magento charges
     * min(price, special_price), so the customer pays the regular price either
     * way — so the special price is removed instead.
     */
    public function testClearsTheDiscountWhenTheMinimumBeatsTheRegularPrice(): void
    {
        // cost 200 → minimum 405, but the product only sells for 111.
        $decision = $this->calculator()->decide($this->row(['price' => '111.0000']));

        $this->assertSame(Entry::ACTION_CLEAR, $decision->action);
        $this->assertSame(Entry::REASON_ABOVE_REGULAR_PRICE, $decision->reason);
        // null is the whole point: the special price is removed, not set.
        $this->assertNull($decision->newValue);
        $this->assertSame(405.0, $decision->threshold);
        $this->assertTrue($decision->writesCatalog());
        $this->assertTrue($decision->isLoggable());
    }

    /**
     * Clearing wins over raising: a product that is both below the minimum and
     * priced under it must not be given a special price nobody can use.
     */
    public function testClearTakesPrecedenceOverRaise(): void
    {
        $decision = $this->calculator()->decide($this->row([
            'price' => '111.0000',
            'special_price' => '50.0000',
        ]));

        $this->assertSame(Entry::ACTION_CLEAR, $decision->action);
    }

    /**
     * A minimum at or below the regular price is a normal, usable discount
     * floor — raise, do not clear.
     */
    public function testDoesNotClearWhenTheMinimumFitsUnderTheRegularPrice(): void
    {
        $decision = $this->calculator()->decide($this->row(['price' => '900.0000']));

        $this->assertSame(Entry::ACTION_RAISE, $decision->action);
        $this->assertSame(405.0, $decision->newValue);
    }

    public function testProductsWithNoSpecialPriceNeverGetOne(): void
    {
        $decision = $this->calculator()->decide($this->row(['special_price' => null]));

        $this->assertSame(Entry::ACTION_SKIP, $decision->action);
        $this->assertSame(Entry::REASON_NO_SPECIAL_PRICE, $decision->reason);
        $this->assertFalse($decision->writesCatalog());
    }

    /**
     * No cost means no floor can be computed, so there is nothing to enforce.
     */
    public function testSkipsWhenCostIsMissingOrZero(): void
    {
        foreach ([null, '0.0000'] as $cost) {
            $decision = $this->calculator()->decide($this->row(['cost' => $cost]));

            $this->assertSame(Entry::ACTION_SKIP, $decision->action);
            $this->assertSame(Entry::REASON_NO_COST, $decision->reason);
        }
    }

    /**
     * The rule keeps no memory, so a second run over an already-raised price is
     * a no-op rather than a second write.
     */
    public function testASecondRunOverARaisedPriceDoesNothing(): void
    {
        $calculator = $this->calculator();

        $first = $calculator->decide($this->row());
        $this->assertSame(Entry::ACTION_RAISE, $first->action);

        $second = $calculator->decide($this->row(['special_price' => (string)$first->newValue]));
        $this->assertSame(Entry::ACTION_SKIP, $second->action);
    }

    /**
     * A failed catalog write must not be logged as a successful one. MariaDB
     * clamps out-of-range decimals instead of raising, so a genuine write
     * failure cannot be forced from the database side — this pins the mapping.
     */
    public function testAFailedWriteIsRecordedAsAnError(): void
    {
        $entry = new Entry(
            ruleCode: Entry::RULE_SPECIAL_PRICE_GUARD,
            productId: 42,
            attributeCode: 'special_price',
            storeId: 0,
            action: Entry::ACTION_RAISE,
            reason: Entry::REASON_BELOW_MIN,
            oldValue: 300.0,
            newValue: 405.0
        );

        $failed = $entry->failed('SQLSTATE[22003]: out of range');

        $this->assertSame(Entry::ACTION_ERROR, $failed->action);
        $this->assertSame(Entry::REASON_ERROR, $failed->reason);
        // Nothing was written, so there is no new value to claim.
        $this->assertNull($failed->newValue);
        // The value it *would* have written survives in the message.
        $this->assertStringContainsString('405.00', (string)$failed->message);
        $this->assertStringContainsString('out of range', (string)$failed->message);
        $this->assertSame(300.0, $failed->oldValue);
    }
}
