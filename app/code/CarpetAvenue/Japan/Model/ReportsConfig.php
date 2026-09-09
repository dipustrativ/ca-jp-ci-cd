<?php

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model;

use CarpetAvenue\Japan\Api\ReportsConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class ReportsConfig implements ReportsConfigInterface
{
    /** @var ScopeConfigInterface */
    private $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @inheritDoc
     */
    public function royaltyPercent(): float
    {
        return (float) $this->scopeConfig->getValue('carpet_reports/jp/royalty_percent');
    }

    /**
     * @inheritDoc
     */
    public function costMultiplier(): float
    {
        return (float) $this->scopeConfig->getValue('carpet_reports/jp/cost_multiplier');
    }

    /**
     * @inheritDoc
     */
    public function costBase(): float
    {
        return (float) $this->scopeConfig->getValue('carpet_reports/jp/cost_base');
    }

    /**
     * @inheritDoc
     */
    public function storeId(): int
    {
        return (int) $this->scopeConfig->getValue('carpet_reports/jp/store_id');
    }
}
