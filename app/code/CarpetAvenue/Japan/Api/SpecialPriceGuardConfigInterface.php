<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Config accessor for the special-price guard (japan/special_price_guard/*).
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Api;

interface SpecialPriceGuardConfigInterface
{
    /**
     * Master switch. Off means neither the cron nor the CLI does anything.
     */
    public function isEnabled(): bool;

    /**
     * Whether the scheduled run is allowed. The CLI ignores this.
     */
    public function isCronEnabled(): bool;

    /**
     * Cost multiplier in the floor formula.
     */
    public function costMultiplier(): float;

    /**
     * Flat shipping/handling amount added on top of the multiplied cost, in base currency.
     */
    public function shippingBase(): float;

    /**
     * Tax percentage applied to the shipping amount (5 = +5%).
     */
    public function taxPercent(): float;

    /**
     * Owner attribute values that are never clamped. Empty means "apply to all".
     *
     * @return string[]
     */
    public function excludedOwners(): array;

    /**
     * Products read per scan batch.
     */
    public function batchSize(): int;

    /**
     * Abort threshold: a run touching more than this percentage of the catalog
     * is treated as a misconfiguration and refuses to write. 0 disables the cap.
     */
    public function maxAffectedPercent(): float;

    /**
     * Days of log history to keep. 0 keeps everything.
     */
    public function logRetentionDays(): int;

    /**
     * Store view the guard reads and writes. 0 = global/default scope.
     */
    public function storeId(): int;
}
