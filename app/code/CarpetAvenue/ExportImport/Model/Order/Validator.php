<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * This source file is subject to the Carpet Avenue GmbH license
 *
 * @category  Order
 * @package   CarpetAvenue_ExportImport
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\ExportImport\Model\Order;

use CarpetAvenue\Core\Logger\Logger;
use CarpetAvenue\ExportImport\Model\Source\OrderStatus;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Validates a parsed order CSV BEFORE any data is written — the "manual check"
 * gate. Verifies header completeness, per-row required fields, store-code and
 * status-code validity, email format, numeric types, and product existence, and
 * builds the create/update plan per order.
 *
 * Errors block the import; warnings (e.g. an unknown SKU) do not.
 */
class Validator
{
    /** @var array<string, bool> sku => exists (per-run cache) */
    private array $skuCache = [];

    /** @var array<string, string> store code => configured base currency (per-run cache) */
    private array $baseCurrencyCache = [];

    public function __construct(
        private readonly Schema $schema,
        private readonly ValidationResultFactory $resultFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly OrderStatus $orderStatus,
        private readonly StatusResolver $statusResolver,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array{header: string[], rows: array<int, array{line: int, data: array<string, string>}>} $parsed
     */
    public function validate(array $parsed): ValidationResult
    {
        $this->skuCache = [];
        $result = $this->resultFactory->create();

        $header = $parsed['header'] ?? [];
        $rows = $parsed['rows'] ?? [];

        // 1. Header must carry every required column — otherwise nothing can proceed.
        $missing = array_diff($this->schema->getRequiredColumns(), $header);
        if (!empty($missing)) {
            foreach ($missing as $column) {
                $result->addError(1, $column, (string)__('Required column is missing from the CSV header.'));
            }
            return $result;
        }

        if (empty($rows)) {
            $result->addError(1, 'file', (string)__('The CSV contains no data rows.'));
            return $result;
        }

        $storeCodes = $this->validStoreCodes();
        $validStatuses = $this->orderStatus->getValidStatusCodes();
        $existing = $this->existingIncrementIds($rows);
        $numericColumns = array_intersect($this->schema->getNumericColumns(), $header);
        $statusChecked = [];

        foreach ($rows as $row) {
            $line = $row['line'];
            $data = $row['data'];

            $incrementId = $data['increment_id'] ?? '';
            if ($incrementId === '') {
                $result->addError($line, 'increment_id', (string)__('increment_id is required.'));
                continue;
            }

            // Needed below: several checks only matter when the row will actually
            // write the value they guard, which the create path does and the
            // update path does not.
            $mode = isset($existing[$incrementId]) ? 'update' : 'create';

            $storeCode = $data['store_code'] ?? '';
            if ($storeCode === '') {
                $result->addError($line, 'store_code', (string)__('store_code is required.'));
            } elseif (!isset($storeCodes[$storeCode])) {
                $result->addError($line, 'store_code', (string)__('Unknown store code "%1".', $storeCode));
            }

            // Status and state are both optional: an empty one is derived from
            // the other (see StatusResolver), and a row with neither still
            // imports. Only a status this instance does not know is an error —
            // the order would carry a code no admin screen can label.
            $status = $data['order_status'] ?? '';
            if ($status !== '' && !isset($validStatuses[$status])) {
                $result->addError($line, 'order_status', (string)__('Unknown order status "%1".', $status));
            } elseif (!isset($statusChecked[$incrementId])) {
                // The importer reads status/state from an order's first row only,
                // so warn once per order, not once per item row.
                $statusChecked[$incrementId] = true;
                $this->warnOnDerivedStatus($result, $line, $status, (string)($data['order_state'] ?? ''), $mode);
            }

            // A CSV whose base currency differs from the store's is imported verbatim,
            // NOT converted — Magento keeps base_currency_code and base_to_order_rate
            // per order, so the order stays internally consistent and the admin renders
            // it in its own currency. Only a warning, because store-level reporting sums
            // base amounts across orders and will mix currencies.
            //
            // What must not happen is the rate going missing: an order left with a NULL
            // base_to_order_rate has no rate of its own, so a base amount gets converted
            // at the store's live rate instead — that is how a ¥231,818 unit price came
            // out as ¥39,410,000 (×170, then rounded up by the JPY ceiling rule).
            $csvBaseCurrency = $data['base_currency_code'] ?? '';
            if ($csvBaseCurrency !== '' && $storeCode !== '' && isset($storeCodes[$storeCode])) {
                $storeBaseCurrency = $this->storeBaseCurrency($storeCode);
                if ($storeBaseCurrency !== '' && $csvBaseCurrency !== $storeBaseCurrency) {
                    $result->addWarning(
                        $line,
                        'base_currency_code',
                        (string)__(
                            'The CSV order is in base currency "%1" but store "%2" has base currency "%3". '
                            . 'The order is imported as-is, keeping its own currency and rate, so it displays '
                            . 'correctly on its own. Store-level sales reports total base amounts across '
                            . 'orders, so this one will mix "%1" into "%3" figures.',
                            $csvBaseCurrency,
                            $storeCode,
                            $storeBaseCurrency
                        )
                    );

                    // Without its own rate the order has nothing to convert with, and the
                    // store's live rate is used instead — the actual cause of wrong prices.
                    //
                    // Create only. Importer::updateOrder() writes status/state and the
                    // item product link, never a base amount or a rate, so a missing rate
                    // cannot be introduced by an update. Erroring there would block the
                    // whole file — one legacy order with a NULL rate would stop every
                    // other order in the CSV from being updated.
                    if ($mode === 'create' && (float)($data['base_to_order_rate'] ?? 0) <= 0.0) {
                        $result->addError(
                            $line,
                            'base_to_order_rate',
                            (string)__(
                                'base_to_order_rate is empty or zero on a "%1" order being imported into a '
                                . '"%2" store. The order would carry no rate of its own and its base amounts '
                                . 'would be converted at the store\'s live rate, producing wrong prices. Set '
                                . 'base_to_order_rate (use 1 when the order currency equals "%1").',
                                $csvBaseCurrency,
                                $storeBaseCurrency
                            )
                        );
                    }
                }
            }

            // A zero conversion rate cannot be right, and silently breaks any
            // later base<->order conversion.
            foreach (['base_to_order_rate', 'base_to_global_rate'] as $rateColumn) {
                $rate = $data[$rateColumn] ?? '';
                if ($rate !== '' && is_numeric($rate) && (float)$rate <= 0.0) {
                    $result->addWarning(
                        $line,
                        $rateColumn,
                        (string)__('"%1" is %2 — the order carries no usable currency rate.', $rateColumn, $rate)
                    );
                }
            }

            $email = $data['customer_email'] ?? '';
            if ($email === '') {
                $result->addError($line, 'customer_email', (string)__('customer_email is required.'));
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result->addError($line, 'customer_email', (string)__('Invalid email "%1".', $email));
            }

            $sku = $data['item_sku'] ?? '';
            if ($sku === '') {
                $result->addError($line, 'item_sku', (string)__('item_sku is required.'));
            } elseif (!$this->skuExists($sku)) {
                $result->addWarning(
                    $line,
                    'item_sku',
                    (string)__('SKU "%1" not found in catalog — item will be imported without a product link.', $sku)
                );
            }

            $qty = $data['item_qty_ordered'] ?? '';
            if ($qty === '') {
                $result->addError($line, 'item_qty_ordered', (string)__('item_qty_ordered is required.'));
            } elseif (!is_numeric($qty)) {
                $result->addError($line, 'item_qty_ordered', (string)__('item_qty_ordered must be numeric.'));
            }

            foreach ($numericColumns as $column) {
                $value = $data[$column] ?? '';
                if ($value !== '' && !is_numeric($value)) {
                    $result->addError($line, $column, (string)__('"%1" must be numeric, got "%2".', $column, $value));
                }
            }

            // JSON-packed columns: malformed JSON is a warning, not a hard error
            // (the row still imports, just without that packed data).
            foreach ($this->schema->getJsonColumns() as $jsonColumn) {
                $value = $data[$jsonColumn] ?? '';
                if ($value !== '' && json_decode($value, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                    $result->addWarning($line, $jsonColumn, (string)__('"%1" is not valid JSON — it will be ignored on import.', $jsonColumn));
                }
            }

            $result->registerOrder($incrementId, $mode, $line);
        }

        $summary = $result->getSummary();
        $this->logger->info(sprintf(
            'Validated CSV: %d order(s) [%d create / %d update], %d error(s), %d warning(s).',
            $summary['orders'],
            $summary['creates'],
            $summary['updates'],
            $summary['errors'],
            $summary['warnings']
        ));

        return $result;
    }

    /**
     * Tell the user what an empty order_status / order_state turns into, since
     * the CSV no longer says it.
     */
    private function warnOnDerivedStatus(
        ValidationResult $result,
        int $line,
        string $status,
        string $state,
        string $mode
    ): void {
        if ($status !== '' && $state !== '') {
            return;
        }

        $resolved = $this->statusResolver->resolve($status, $state);

        if ($status === '' && $state === '') {
            $message = $mode === 'create'
                ? __(
                    'order_status and order_state are empty — the order is created as state "%1", status "%2".',
                    Importer::DEFAULT_STATE,
                    Importer::DEFAULT_STATUS
                )
                : __('order_status and order_state are empty — the order keeps its current status and state.');
            $result->addWarning($line, 'order_status', (string)$message);
            return;
        }

        if ($status === '') {
            $message = $resolved['status'] !== null
                ? __('order_status is empty — set to "%1", the default status of state "%2".', $resolved['status'], $state)
                : __(
                    'order_status is empty and state "%1" has no default status — %2.',
                    $state,
                    $mode === 'create'
                        ? __('status "%1" is used', Importer::DEFAULT_STATUS)
                        : __('the current status is kept')
                );
            $result->addWarning($line, 'order_status', (string)$message);
            return;
        }

        $message = $resolved['state'] !== null
            ? __('order_state is empty — set to "%1", the state status "%2" belongs to.', $resolved['state'], $status)
            : __(
                'order_state is empty and status "%1" is not assigned to any state — %2.',
                $status,
                $mode === 'create'
                    ? __('state "%1" is used', Importer::DEFAULT_STATE)
                    : __('the current state is kept')
            );
        $result->addWarning($line, 'order_state', (string)$message);
    }

    /**
     * The configured base currency of one store view, '' when it cannot be resolved.
     */
    private function storeBaseCurrency(string $storeCode): string
    {
        if (!array_key_exists($storeCode, $this->baseCurrencyCache)) {
            $this->baseCurrencyCache[$storeCode] = (string)$this->scopeConfig->getValue(
                'currency/options/base',
                ScopeInterface::SCOPE_STORE,
                $storeCode
            );
        }
        return $this->baseCurrencyCache[$storeCode];
    }

    /** @return array<string, bool> */
    private function validStoreCodes(): array
    {
        $codes = [];
        foreach ($this->storeManager->getStores(true) as $store) {
            $codes[(string)$store->getCode()] = true;
        }
        return $codes;
    }

    /**
     * @param array<int, array{line: int, data: array<string, string>}> $rows
     * @return array<string, bool>
     */
    private function existingIncrementIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $inc = $row['data']['increment_id'] ?? '';
            if ($inc !== '') {
                $ids[$inc] = true;
            }
        }
        if (empty($ids)) {
            return [];
        }

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', ['in' => array_keys($ids)]);
        $found = [];
        foreach ($collection->getColumnValues('increment_id') as $inc) {
            $found[(string)$inc] = true;
        }
        return $found;
    }

    private function skuExists(string $sku): bool
    {
        if (isset($this->skuCache[$sku])) {
            return $this->skuCache[$sku];
        }
        try {
            $this->productRepository->get($sku);
            return $this->skuCache[$sku] = true;
        } catch (NoSuchEntityException $e) {
            return $this->skuCache[$sku] = false;
        }
    }
}
