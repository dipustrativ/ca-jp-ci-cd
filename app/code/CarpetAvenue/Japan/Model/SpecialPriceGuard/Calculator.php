<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * The rule:
 *
 *     minimum = cost * multiplier + shipping * (1 + tax/100)
 *     special_price below the minimum  →  raise it to the minimum
 *     otherwise                        →  leave it alone
 *
 * The rule keeps no memory of what a price used to be, so it only ever moves
 * prices up. If the minimum later drops, the product keeps its raised price
 * until the ERP sends a new special_price.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\SpecialPriceGuard;

use CarpetAvenue\Japan\Api\SpecialPriceGuardConfigInterface;
use CarpetAvenue\Japan\Model\PriceAction\Entry;

class Calculator
{
    /** Half a cent — EAV money columns carry 6 decimal places. */
    private const EPSILON = 0.00005;

    /** Decimal places carried through the intermediate steps. */
    private const SCALE = 10;

    public function __construct(
        private readonly SpecialPriceGuardConfigInterface $config
    ) {
    }

    /**
     * Decide what happens to one product.
     *
     * Owners on the exclusion list never get here — the Scanner filters them
     * out in SQL.
     *
     * @param array $row Scanner row: entity_id, sku, cost, price, owner, special_price
     */
    public function decide(array $row): Decision
    {
        $live = $this->toFloat($row['special_price']);
        $cost = $this->toFloat($row['cost']);

        // No special price means there is nothing to enforce a minimum on. The
        // rule never creates a discount, it only corrects one.
        if ($live === null) {
            return Decision::skip(Entry::REASON_NO_SPECIAL_PRICE);
        }

        // Without a cost there is no minimum to compare against.
        if ($cost === null || $cost <= 0.0) {
            return Decision::skip(Entry::REASON_NO_COST);
        }

        $minimum = $this->minimumFor($cost);
        $regularPrice = $this->toFloat($row['price']);

        // The minimum is above what the product normally sells for, so no
        // discount can ever satisfy it — the product is priced below cost.
        // Raising special_price here achieves nothing: Magento charges
        // min(price, special_price), so the customer pays the regular price
        // either way. Clearing it leaves honest data and surfaces the real
        // problem instead of burying it under a price nobody ever pays.
        if ($regularPrice !== null && $minimum > $regularPrice + self::EPSILON) {
            return Decision::clear($minimum);
        }

        return $live < $minimum - self::EPSILON
            ? Decision::raise($minimum)
            : Decision::skip(Entry::REASON_IN_RANGE, $minimum);
    }

    /**
     * Lowest price a product may be sold at, in base currency (EUR).
     *
     * Public because it is the formula itself — worth testing directly, and
     * worth being able to ask for without making a decision.
     */
    public function minimumFor(float $cost): float
    {
        $goods = $this->multiply($cost, $this->config->costMultiplier());
        $shipping = $this->multiply($this->config->shippingBase(), $this->taxMultiplier());

        return $this->roundToCents($this->add($goods, $shipping));
    }

    /**
     * Tax percentage as a multiplier: 5 → 1.05.
     */
    private function taxMultiplier(): float
    {
        return 1 + $this->config->taxPercent() / 100;
    }

    /*
     * ── Exact decimal arithmetic ──────────────────────────────────────────
     *
     * multiply / add / round, done on decimal strings instead of floats. They
     * exist because plain float arithmetic gets this wrong in a way that
     * matters: `133.19 * 1.5` evaluates to 199.78499999999996, so the half-cent
     * tie at 304.785 rounds *down* to 304.78 — a cent below the minimum, the
     * one direction a price floor must never go. It affected 12 products when
     * this was written with floats.
     *
     * Values move between them as strings so no step can round-trip through a
     * float and pick the error back up.
     */

    private function multiply(float $a, float $b): string
    {
        return bcmul($this->decimal($a), $this->decimal($b), self::SCALE);
    }

    private function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::SCALE);
    }

    /**
     * Round half up to 2 decimals. bcadd truncates at the scale it is given, so
     * adding half a cent first carries a tie upwards instead of away.
     */
    private function roundToCents(string $value): float
    {
        $halfCent = $value[0] === '-' ? '-0.005' : '0.005';

        return (float)bcadd($value, $halfCent, 2);
    }

    /**
     * Float to a decimal string, leaving its binary representation error behind.
     * Six places is well inside the precision of every value this handles, and
     * matches the catalog's decimal(20,6) money columns.
     */
    private function decimal(float $value): string
    {
        return sprintf('%.6F', $value);
    }

    private function toFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float)$value;
    }
}
