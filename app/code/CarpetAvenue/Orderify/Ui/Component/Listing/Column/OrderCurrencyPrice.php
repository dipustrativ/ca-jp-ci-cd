<?php

declare(strict_types=1);

namespace CarpetAvenue\Orderify\Ui\Component\Listing\Column;

use CarpetAvenue\Orderify\Model\SalesGrid\OrderCurrencyAmounts;
use Magento\Directory\Model\Currency;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Invoice / credit memo grid money column rendered in the currency the customer
 * was charged in (order_currency_code, JPY) instead of the EUR base currency.
 *
 * The stock Magento\Sales\Ui\Component\Listing\Column\Price formats with
 * base_currency_code and several grid fields hold base_* amounts, so the grids
 * showed € figures. The displayed amount is read from the source document:
 *
 *   sourceTable  sales_invoice | sales_creditmemo
 *   sourceField  column on that table (or order_grand_total) holding the
 *                order-currency amount; defaults to the grid column name
 *
 * Only the rendering changes: filtering and sorting still use the grid value.
 */
class OrderCurrencyPrice extends Column
{
    /** @var array<string,Currency> */
    private array $currencies = [];

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param OrderCurrencyAmounts $amounts
     * @param CurrencyFactory $currencyFactory
     * @param StoreManagerInterface $storeManager
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly OrderCurrencyAmounts $amounts,
        private readonly CurrencyFactory $currencyFactory,
        private readonly StoreManagerInterface $storeManager,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritdoc
     */
    public function prepareDataSource(array $dataSource)
    {
        if (empty($dataSource['data']['items'])) {
            return $dataSource;
        }

        $field = $this->getData('name');
        $sourceField = $this->getData('sourceField') ?: $field;
        $documents = $this->amounts->get(
            (string)$this->getData('sourceTable'),
            array_column($dataSource['data']['items'], 'entity_id')
        );

        foreach ($dataSource['data']['items'] as &$item) {
            $document = $documents[(int)($item['entity_id'] ?? 0)] ?? null;
            $amount = $document[$sourceField] ?? null;

            if ($amount === null) {
                continue;
            }
            $currencyCode = $document['order_currency_code']
                ?: $this->storeManager->getStore($item['store_id'] ?? null)->getDefaultCurrencyCode();
            $item[$field] = $this->getCurrency((string)$currencyCode)->format($amount, [], false);
        }

        return $dataSource;
    }

    /**
     * @param string $code
     * @return Currency
     */
    private function getCurrency(string $code): Currency
    {
        return $this->currencies[$code] ??= $this->currencyFactory->create()->load($code);
    }
}
