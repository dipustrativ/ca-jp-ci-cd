<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * Seeds Japanese consumption tax (消費税) so quotes and orders actually carry a
 * tax amount. Without this the install has no JP rate at all — only the two
 * sample US rates Magento ships — so `tax_amount` stays 0 and the checkout's
 * "ご注文合計（税抜）" row (grand_total - tax_amount, see
 * Magento_Tax/js/view/checkout/summary/grand-total.js) prints the incl-tax figure.
 *
 * Rate is 10% standard, the reduced 8% band covers food/newspapers only and never
 * applies to rugs. Catalog prices on this store are entered 税込, which matches
 * the legacy store (imported orders: subtotal 691818.18, subtotal_incl_tax 761000
 * = exactly /1.1), so `price_includes_tax` and `shipping_includes_tax` are both
 * switched on — otherwise adding the rate would inflate every grand total by 10%
 * instead of splitting the existing price.
 *
 * 送料 is taxable in Japan, so `shipping_tax_class` is pointed at the taxable
 * product class instead of Magento's default "None".
 *
 * The rate alone is not enough. With `price_includes_tax` on, Magento works out
 * how much tax is baked into a catalog price from the *store* rate, and
 * Magento\Tax\Model\Calculation::getRateOriginRequest() takes that from
 * `shipping/origin/*` — still Magento's untouched sample default of US / CA /
 * 90034 on this install, which matches no rule, returns 0%, and makes the
 * collector treat 21000 as a net price and add 10% on top. So this patch also:
 *
 *   - sets `general/country/default` to JP (correct for a JP-only store; also
 *     the default country in address forms), and
 *   - turns on `carpetavenue_vat/tax_calc/store_country_origin`, the switch
 *     {@see \CarpetAvenue\VAT\Model\Tax\Calculation} reads to take the origin
 *     rate from that store country instead of the shipping origin.
 *
 * `shipping/origin/*` is deliberately left alone — it is the physical warehouse
 * address and drives carrier rating, not something to guess at from here.
 *
 * The rule deliberately covers the "Retail Customer" class only; the Tax-Free
 * class created by {@see SetupTaxClasses} must stay outside it.
 *
 * Idempotent: reuses an existing rate/rule with the same code and only writes a
 * config value that differs from the current one, so an admin override survives.
 *
 * Display settings (incl-tax everywhere, 税込 suffix) live in
 * CarpetAvenue\Japan\Setup\Patch\Data\ConfigureJapanTaxDisplay — this patch owns
 * the calculation side only.
 *
 * @package CarpetAvenue_VAT
 * @author  Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\VAT\Setup\Patch\Data;

use CarpetAvenue\VAT\Model\Config as VatConfig;
use Magento\Directory\Helper\Data as DirectoryData;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Tax\Api\Data\TaxRateInterface;
use Magento\Tax\Api\Data\TaxRuleInterface;
use Magento\Tax\Api\Data\TaxRateInterfaceFactory;
use Magento\Tax\Api\Data\TaxRuleInterfaceFactory;
use Magento\Tax\Api\TaxRateRepositoryInterface;
use Magento\Tax\Api\TaxRuleRepositoryInterface;
use Magento\Tax\Model\ClassModel;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Tax\Model\ResourceModel\Calculation\Rate\CollectionFactory as RateCollectionFactory;
use Magento\Tax\Model\ResourceModel\Calculation\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\Tax\Model\ResourceModel\TaxClass\CollectionFactory as TaxClassCollectionFactory;

class SetupJapanConsumptionTax implements DataPatchInterface
{
    /** Standard consumption tax rate. The 8% reduced band does not apply to rugs. */
    private const RATE_PERCENT = 10.0;

    private const RATE_CODE = 'JP-*-*-Rate 1';

    private const RULE_CODE = 'JP Consumption Tax';

    /** Product tax classes the rule applies to, by name. */
    private const PRODUCT_CLASS_NAMES = ['Taxable Goods', 'Standard'];

    /** Shipping is taxable in Japan — this class is used for 送料. */
    private const SHIPPING_CLASS_NAME = 'Taxable Goods';

    /** Tax-Free Customer is deliberately excluded. */
    private const CUSTOMER_CLASS_NAMES = ['Retail Customer'];

    /**
     * @param TaxRateRepositoryInterface $rateRepository
     * @param TaxRateInterfaceFactory $rateFactory
     * @param TaxRuleRepositoryInterface $ruleRepository
     * @param TaxRuleInterfaceFactory $ruleFactory
     * @param RateCollectionFactory $rateCollectionFactory
     * @param RuleCollectionFactory $ruleCollectionFactory
     * @param TaxClassCollectionFactory $taxClassCollectionFactory
     * @param WriterInterface $configWriter
     * @param ScopeConfigInterface $scopeConfig
     * @param ReinitableConfigInterface $reinitableConfig
     */
    public function __construct(
        private readonly TaxRateRepositoryInterface $rateRepository,
        private readonly TaxRateInterfaceFactory $rateFactory,
        private readonly TaxRuleRepositoryInterface $ruleRepository,
        private readonly TaxRuleInterfaceFactory $ruleFactory,
        private readonly RateCollectionFactory $rateCollectionFactory,
        private readonly RuleCollectionFactory $ruleCollectionFactory,
        private readonly TaxClassCollectionFactory $taxClassCollectionFactory,
        private readonly WriterInterface $configWriter,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ReinitableConfigInterface $reinitableConfig
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $rateId = $this->ensureRate();

        $productClassIds  = $this->resolveClassIds(self::PRODUCT_CLASS_NAMES, ClassModel::TAX_CLASS_TYPE_PRODUCT);
        $customerClassIds = $this->resolveClassIds(self::CUSTOMER_CLASS_NAMES, ClassModel::TAX_CLASS_TYPE_CUSTOMER);

        // A rule with no class on either side silently taxes nothing, so skip it
        // rather than leaving a dead rule behind.
        if ($productClassIds && $customerClassIds) {
            $this->ensureRule($rateId, $productClassIds, $customerClassIds);
        }

        $this->writeCalculationConfig($productClassIds);

        // The writer bypasses the already-loaded config cache; without this the
        // rest of this setup run still sees the pre-patch values.
        $this->reinitableConfig->reinit();

        return $this;
    }

    /**
     * Create the JP 10% rate unless one with the same code already exists.
     *
     * @return int tax_calculation_rate_id
     */
    private function ensureRate(): int
    {
        $existing = $this->rateCollectionFactory->create()
            ->addFieldToFilter('code', self::RATE_CODE)
            ->setPageSize(1)
            ->getFirstItem();

        if ($existing->getData('tax_calculation_rate_id')) {
            return (int)$existing->getData('tax_calculation_rate_id');
        }

        /** @var TaxRateInterface $rate */
        $rate = $this->rateFactory->create();
        $rate->setCode(self::RATE_CODE);
        $rate->setTaxCountryId('JP');
        $rate->setTaxRegionId(0);
        $rate->setTaxPostcode('*');
        $rate->setRate(self::RATE_PERCENT);

        return (int)$this->rateRepository->save($rate)->getId();
    }

    /**
     * Bind the rate to the taxable product classes and the retail customer class.
     *
     * @param int $rateId
     * @param int[] $productClassIds
     * @param int[] $customerClassIds
     * @return void
     */
    private function ensureRule(int $rateId, array $productClassIds, array $customerClassIds): void
    {
        $existing = $this->ruleCollectionFactory->create()
            ->addFieldToFilter('code', self::RULE_CODE)
            ->setPageSize(1)
            ->getFirstItem();

        if ($existing->getData('tax_calculation_rule_id')) {
            return;
        }

        /** @var TaxRuleInterface $rule */
        $rule = $this->ruleFactory->create();
        $rule->setCode(self::RULE_CODE);
        $rule->setTaxRateIds([$rateId]);
        $rule->setProductTaxClassIds($productClassIds);
        $rule->setCustomerTaxClassIds($customerClassIds);
        $rule->setPriority(0);
        $rule->setPosition(0);
        $rule->setCalculateSubtotal(false);

        $this->ruleRepository->save($rule);
    }

    /**
     * Turns the calculation side on: prices and shipping are stored 税込,
     * shipping itself is taxable, and the origin rate comes from the JP store
     * country rather than the unconfigured US shipping origin.
     *
     * @param int[] $productClassIds
     * @return void
     */
    private function writeCalculationConfig(array $productClassIds): void
    {
        $values = [
            TaxConfig::CONFIG_XML_PATH_PRICE_INCLUDES_TAX    => '1',
            TaxConfig::CONFIG_XML_PATH_SHIPPING_INCLUDES_TAX => '1',
            DirectoryData::XML_PATH_DEFAULT_COUNTRY          => 'JP',
            VatConfig::XML_STORE_COUNTRY_ORIGIN              => '1',
        ];

        $shippingClassId = $this->resolveClassIds(
            [self::SHIPPING_CLASS_NAME],
            ClassModel::TAX_CLASS_TYPE_PRODUCT
        );

        if ($shippingClassId && in_array($shippingClassId[0], $productClassIds, true)) {
            $values[TaxConfig::CONFIG_XML_PATH_SHIPPING_TAX_CLASS] = (string)$shippingClassId[0];
        }

        foreach ($values as $path => $value) {
            if ((string)$this->scopeConfig->getValue($path) === $value) {
                continue;
            }

            $this->configWriter->save($path, $value);
        }
    }

    /**
     * Look tax classes up by name so the patch never hardcodes generated ids.
     *
     * @param string[] $names
     * @param string $type
     * @return int[]
     */
    private function resolveClassIds(array $names, string $type): array
    {
        $collection = $this->taxClassCollectionFactory->create()
            ->addFieldToFilter('class_name', ['in' => $names])
            ->addFieldToFilter('class_type', $type);

        $ids = [];
        foreach ($collection as $taxClass) {
            $ids[] = (int)$taxClass->getData('class_id');
        }

        return $ids;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [SetupTaxClasses::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
