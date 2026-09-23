<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Product
 * @package   CarpetAvenue_Productify
 * @copyright Copyright (c) carpetavenue.com (https://carpetavenue.com)
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Productify\Plugin\Currency;

use Magento\Catalog\Ui\Component\Listing\Columns\Price;
use Magento\Framework\Locale\CurrencyInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Labels admin product-grid prices with the base currency instead of the
 * display currency.
 *
 * The grid column holds raw base-currency values (this store's base is EUR),
 * but Magento 2.4.9's Price::prepareDataSource formats them through
 * PriceCurrency::format(), whose getCurrency() resolves to the store's
 * *current* currency (JPY here). format() never converts, so €2,630 rendered
 * as "￥2,630.00" — right number, wrong symbol. Pre-2.4.9 cores used
 * $store->getBaseCurrencyCode() at this point; this restores that.
 *
 * Applies to every grid built on Catalog's Price column: product_listing,
 * related/upsell/crosssell, bundle, grouped, custom-options — all of which
 * carry base-currency values.
 */
class AdminGridBaseCurrency
{
    /**
     * @param CurrencyInterface $localeCurrency
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly CurrencyInterface $localeCurrency,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Format the column with the store's base currency.
     *
     * Wraps rather than follows the original: an after-plugin would only see
     * the already-formatted string, with the raw amount gone.
     *
     * @param Price $subject
     * @param callable $proceed
     * @param array $dataSource
     * @return array
     */
    public function aroundPrepareDataSource(Price $subject, callable $proceed, array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $proceed($dataSource);
        }

        $store = $this->storeManager->getStore(
            $subject->getContext()->getFilterParam('store_id', Store::DEFAULT_STORE_ID)
        );
        $currency = $this->localeCurrency->getCurrency($store->getBaseCurrencyCode());
        $fieldName = $subject->getData('name');

        foreach ($dataSource['data']['items'] as &$item) {
            if (isset($item[$fieldName])) {
                $item[$fieldName] = $currency->toCurrency(sprintf('%F', $item[$fieldName]));
            }
        }

        return $dataSource;
    }
}
