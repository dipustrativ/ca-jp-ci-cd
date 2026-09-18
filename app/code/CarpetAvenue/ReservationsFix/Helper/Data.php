<?php

declare(strict_types=1);

namespace CarpetAvenue\ReservationsFix\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('catalog/reservationsfix/enabled', ScopeInterface::SCOPE_STORE);
    }
    public function getEnabledStockId(): int
    {
        return (int)$this->scopeConfig->getValue('catalog/reservationsfix/enabled_stock', ScopeInterface::SCOPE_STORE);
    }
    public function getCalculatePreciseQtyToReserve(): int
    {
        return (int)$this->scopeConfig->getValue('catalog/reservationsfix/calculate_precise_qty_to_reserve', ScopeInterface::SCOPE_STORE);
    }
    public function getCalculatePreciseQtyToMove(): int
    {
        return (int)$this->scopeConfig->getValue('catalog/reservationsfix/calculate_precise_qty_to_move', ScopeInterface::SCOPE_STORE);
    }
    public function getFromSource(): string
    {
        return $this->scopeConfig->getValue('catalog/reservationsfix/from_source', ScopeInterface::SCOPE_STORE);
    }
    public function getToSource(): string
    {
        return $this->scopeConfig->getValue('catalog/reservationsfix/to_source', ScopeInterface::SCOPE_STORE);
    }
    public function isWebsiteRemoveEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('catalog/reservationsfix/website_remove_enable', ScopeInterface::SCOPE_STORE);
    }
    public function isAutoFixEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('catalog/reservationsfix/auto_fix_enabled', ScopeInterface::SCOPE_STORE);
    }
    public function getAutoFixOrderStatus(): string
    {
        return $this->scopeConfig->getValue('catalog/reservationsfix/auto_fix_order_status', ScopeInterface::SCOPE_STORE);
    }
    public function getAutoFixAction(): string
    {
        return $this->scopeConfig->getValue('catalog/reservationsfix/auto_fix_action', ScopeInterface::SCOPE_STORE);
    }
    /*public function cliIncludeCustomReservations(): bool
    {
        return $this->scopeConfig->isSetFlag('catalog/reservationsfix/cli_include_custom_reservations', ScopeInterface::SCOPE_STORE);
    }*/
}
