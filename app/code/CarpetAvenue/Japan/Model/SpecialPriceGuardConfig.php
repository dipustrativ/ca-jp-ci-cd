<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Reads japan/special_price_guard/*.
 *
 * Deliberately separate from carpet_reports/jp/* even though both describe a
 * "cost * multiplier + base" figure: that group's cost_base is 60 and the JP
 * royalty report depends on it. Changing one must not move the other.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model;

use CarpetAvenue\Japan\Api\SpecialPriceGuardConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class SpecialPriceGuardConfig implements SpecialPriceGuardConfigInterface
{
    private const XML_PATH = 'japan/special_price_guard/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH . 'enabled');
    }

    /**
     * @inheritDoc
     */
    public function isCronEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH . 'cron_enabled');
    }

    /**
     * @inheritDoc
     */
    public function costMultiplier(): float
    {
        return (float)$this->scopeConfig->getValue(self::XML_PATH . 'cost_multiplier');
    }

    /**
     * @inheritDoc
     */
    public function shippingBase(): float
    {
        return (float)$this->scopeConfig->getValue(self::XML_PATH . 'shipping_base');
    }

    /**
     * @inheritDoc
     */
    public function taxPercent(): float
    {
        return (float)$this->scopeConfig->getValue(self::XML_PATH . 'tax_percent');
    }

    /**
     * @inheritDoc
     */
    public function excludedOwners(): array
    {
        $raw = (string)$this->scopeConfig->getValue(self::XML_PATH . 'excluded_owners');
        if (trim($raw) === '') {
            return [];
        }

        $owners = array_map('trim', explode(',', $raw));

        return array_values(array_filter($owners, static fn (string $owner): bool => $owner !== ''));
    }

    /**
     * @inheritDoc
     */
    public function batchSize(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_PATH . 'batch_size'));
    }

    /**
     * @inheritDoc
     */
    public function maxAffectedPercent(): float
    {
        return (float)$this->scopeConfig->getValue(self::XML_PATH . 'max_affected_percent');
    }

    /**
     * @inheritDoc
     */
    public function logRetentionDays(): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::XML_PATH . 'log_retention_days'));
    }

    /**
     * @inheritDoc
     */
    public function storeId(): int
    {
        return max(0, (int)$this->scopeConfig->getValue(self::XML_PATH . 'store_id'));
    }
}
