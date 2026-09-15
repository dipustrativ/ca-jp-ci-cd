<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * What the rule decided for one product.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\SpecialPriceGuard;

use CarpetAvenue\Japan\Model\PriceAction\Entry;

class Decision
{
    /** This rule's identity in the shared price-action log. */
    public const RULE_CODE = Entry::RULE_SPECIAL_PRICE_GUARD;
    public const ATTRIBUTE_CODE = 'special_price';

    public function __construct(
        public readonly string $action,
        public readonly string $reason,
        public readonly ?float $newValue = null,
        public readonly ?float $threshold = null
    ) {
    }

    /**
     * Push the price up to the minimum.
     */
    public static function raise(float $floor): self
    {
        return new self(Entry::ACTION_RAISE, Entry::REASON_BELOW_MIN, $floor, $floor);
    }

    /**
     * Remove the special price entirely. Used when no discount can satisfy the
     * minimum, so any value left there would be meaningless.
     */
    public static function clear(float $minimum): self
    {
        return new self(Entry::ACTION_CLEAR, Entry::REASON_ABOVE_REGULAR_PRICE, null, $minimum);
    }

    /**
     * Leave the price alone.
     */
    public static function skip(string $reason, ?float $floor = null): self
    {
        return new self(Entry::ACTION_SKIP, $reason, null, $floor);
    }

    public function writesCatalog(): bool
    {
        return $this->action === Entry::ACTION_RAISE || $this->action === Entry::ACTION_CLEAR;
    }

    /**
     * Plain skips are noise — logging them would add a row per product per run.
     */
    public function isLoggable(): bool
    {
        return $this->writesCatalog() || $this->action === Entry::ACTION_ERROR;
    }
}
