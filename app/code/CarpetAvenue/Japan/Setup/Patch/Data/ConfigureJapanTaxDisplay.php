<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Seeds the tax DISPLAY settings the JP store needs. Japan quotes consumption
 * tax inclusive (総額表示), so every customer- and admin-facing price must render
 * incl-tax; only the grand-total flags are yes/no switches.
 *
 * Config written at the DEFAULT scope only, so an admin can still override per
 * website/store view.
 *
 *   tax/display/type              = 2   catalog prices incl. tax
 *   tax/display/shipping          = 2   catalog shipping incl. tax
 *   tax/display/vat_text          = 税込  suffix after a price (CarpetAvenue_Productify field)
 *   tax/cart_display/price        = 2   cart/checkout prices incl. tax
 *   tax/cart_display/subtotal     = 2
 *   tax/cart_display/shipping     = 2
 *   tax/cart_display/grandtotal   = 1   yes — include tax in grand total
 *   tax/sales_display/price       = 2   order/invoice/credit-memo prices incl. tax
 *   tax/sales_display/subtotal    = 2
 *   tax/sales_display/shipping    = 2
 *   tax/sales_display/grandtotal  = 1   yes
 *   tax/defaults/country          = JP  default tax-calculation destination
 *
 * `tax/sales_display/price` and `tax/sales_display/subtotal` are also the
 * standing workaround for docs/Issues/ISSUE-006 (the admin order-item Price
 * column double-converts the currency rate under *Excluding Tax*) — do not flip
 * them back to 1 without reading that file.
 *
 * `tax/defaults/country` is stored as the uppercase `directory_country.country_id`
 * (`JP`), not the lowercase locale/store code — a lowercase value silently fails
 * the country lookup in Magento\Tax\Model\Calculation::getRateRequest().
 *
 * @package CarpetAvenue_Japan
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Tax\Model\Config as TaxConfig;

class ConfigureJapanTaxDisplay implements DataPatchInterface
{
    /**
     * config path => value, default scope.
     *
     * @var array<string, string>
     */
    private const VALUES = [
        TaxConfig::CONFIG_XML_PATH_PRICE_DISPLAY_TYPE => '2',
        TaxConfig::CONFIG_XML_PATH_DISPLAY_SHIPPING   => '2',
        'tax/display/vat_text'                        => '税込',
        TaxConfig::XML_PATH_DISPLAY_CART_PRICE        => '2',
        TaxConfig::XML_PATH_DISPLAY_CART_SUBTOTAL     => '2',
        TaxConfig::XML_PATH_DISPLAY_CART_SHIPPING     => '2',
        TaxConfig::XML_PATH_DISPLAY_CART_GRANDTOTAL   => '1',
        TaxConfig::XML_PATH_DISPLAY_SALES_PRICE       => '2',
        TaxConfig::XML_PATH_DISPLAY_SALES_SUBTOTAL    => '2',
        TaxConfig::XML_PATH_DISPLAY_SALES_SHIPPING    => '2',
        TaxConfig::XML_PATH_DISPLAY_SALES_GRANDTOTAL  => '1',
        TaxConfig::CONFIG_XML_PATH_DEFAULT_COUNTRY    => 'JP',
    ];

    /**
     * @param WriterInterface $configWriter
     * @param ReinitableConfigInterface $reinitableConfig
     */
    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly ReinitableConfigInterface $reinitableConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        foreach (self::VALUES as $path => $value) {
            $this->configWriter->save($path, $value);
        }

        // The writer bypasses the already-loaded config cache; without this the
        // rest of this setup run (and any later patch reading tax config) still
        // sees the pre-patch values.
        $this->reinitableConfig->reinit();

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
