<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Typed, single-source-of-truth reader for every CarpetAvenue_VAT config path.
 * Every feature is switch-gated: callers check the relevant *Enabled()/flag first
 * and no-op when it returns false.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    /** VIES */
    public const XML_VIES_ENABLED     = 'carpetavenue_vat/vies/enabled';
    public const XML_VIES_PROVIDER    = 'carpetavenue_vat/vies/provider';
    public const XML_VIES_TEST_MODE   = 'carpetavenue_vat/vies/test_mode';
    public const XML_VIES_API_ID      = 'carpetavenue_vat/vies/viesapi_id';
    public const XML_VIES_API_KEY     = 'carpetavenue_vat/vies/viesapi_key';

    /** Shipping VAT */
    public const XML_SHIP_NOT_TAXFREE = 'carpetavenue_vat/shipping_vat/shipping_not_taxfree';
    public const XML_SHIP_TARGET_GRP  = 'carpetavenue_vat/shipping_vat/target_group';

    /** Custom tax calculation */
    public const XML_STORE_COUNTRY_ORIGIN = 'carpetavenue_vat/tax_calc/store_country_origin';
    public const XML_DISABLE_CBT_GROUPS   = 'carpetavenue_vat/tax_calc/disable_cbt_groups';
    public const XML_EXCLUDING_TAX_CLASS  = 'carpetavenue_vat/tax_calc/excluding_tax_class';

    /** EUR store pricing */
    public const XML_NULL_PRICING_EUR = 'carpetavenue_vat/eur_pricing/null_pricing_in_eur_stores';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    private function flag(string $path, $store = null): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $store);
    }

    private function value(string $path, $store = null): ?string
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $store);
    }

    /* ------------------------------------------------------------------ VIES */

    public function viesEnabled($store = null): bool
    {
        return $this->flag(self::XML_VIES_ENABLED, $store);
    }

    public function viesProvider($store = null): string
    {
        return (string)($this->value(self::XML_VIES_PROVIDER, $store) ?? 'eu_soap');
    }

    public function viesTestMode($store = null): bool
    {
        return $this->flag(self::XML_VIES_TEST_MODE, $store);
    }

    public function viesApiId($store = null): string
    {
        return (string)($this->value(self::XML_VIES_API_ID, $store) ?? '');
    }

    public function viesApiKey($store = null): string
    {
        return (string)($this->value(self::XML_VIES_API_KEY, $store) ?? '');
    }

    /* --------------------------------------------------------- Shipping VAT */

    public function shippingNotTaxfree($store = null): bool
    {
        return $this->flag(self::XML_SHIP_NOT_TAXFREE, $store);
    }

    public function shippingTargetGroup($store = null): ?int
    {
        $val = $this->value(self::XML_SHIP_TARGET_GRP, $store);
        return ($val === null || $val === '') ? null : (int)$val;
    }

    /* --------------------------------------------------- Custom tax calc */

    public function storeCountryOrigin($store = null): bool
    {
        return $this->flag(self::XML_STORE_COUNTRY_ORIGIN, $store);
    }

    /**
     * @return int[] customer group ids that should get net (CBT-disabled) prices
     */
    public function disableCbtGroups($store = null): array
    {
        $raw = $this->value(self::XML_DISABLE_CBT_GROUPS, $store);
        if ($raw === null || $raw === '') {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn ($v) => $v === '' ? null : (int)$v,
            explode(',', $raw)
        ), static fn ($v) => $v !== null));
    }

    public function excludingTaxClass($store = null): ?int
    {
        $val = $this->value(self::XML_EXCLUDING_TAX_CLASS, $store);
        return ($val === null || $val === '') ? null : (int)$val;
    }

    /* --------------------------------------------------- EUR store pricing */

    public function nullPricingInEurStores($store = null): bool
    {
        return $this->flag(self::XML_NULL_PRICING_EUR, $store);
    }
}
