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

namespace CarpetAvenue\ExportImport\Model\Document;

use CarpetAvenue\Core\Logger\Logger;
use CarpetAvenue\ExportImport\Model\Order\ValidationResult;
use CarpetAvenue\ExportImport\Model\Order\ValidationResultFactory;
use CarpetAvenue\ExportImport\Model\ResourceModel\DocumentExtraData;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Validates a parsed invoice / credit-memo / shipment CSV BEFORE any data is
 * written — the "manual check" gate, and the document counterpart of
 * {@see \CarpetAvenue\ExportImport\Model\Order\Validator}.
 *
 * Errors block the import; warnings (an item SKU the order does not have, say)
 * do not.
 *
 * The one hard difference from the order validator: **a missing order is an
 * error, not a warning.** An order can be imported with an unlinked item and
 * still be a complete record; an invoice with no order is not a record of
 * anything, and Magento has nowhere to put it.
 */
class Validator
{
    /** @var array<string, string> store code => configured base currency (per-run cache) */
    private array $baseCurrencyCache = [];

    public function __construct(
        private readonly TypeInterface $type,
        private readonly Schema $schema,
        private readonly ValidationResultFactory $resultFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly DocumentExtraData $extraData,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array{header: string[], rows: array<int, array{line: int, data: array<string, string>}>} $parsed
     */
    public function validate(array $parsed): ValidationResult
    {
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
        // Two batched lookups for the whole file: which orders exist, and what
        // item SKUs they have.
        $orderIds = $this->extraData->resolveOrderIds(
            array_map(static fn ($row) => (string)($row['data']['order_increment_id'] ?? ''), $rows)
        );
        $orderItemSkus = $this->extraData->getOrderItemSkus(array_values($orderIds));
        $existing = $this->existingIncrementIds($rows);
        $numericColumns = array_intersect($this->schema->getNumericColumns(), $header);
        $label = $this->type->getLabel();

        foreach ($rows as $row) {
            $line = $row['line'];
            $data = $row['data'];

            $incrementId = $data['increment_id'] ?? '';
            if ($incrementId === '') {
                $result->addError($line, 'increment_id', (string)__('increment_id is required.'));
                continue;
            }

            // Several checks below only matter when the row will actually write
            // the value they guard, which the create path does and the update
            // path does not.
            $mode = isset($existing[$incrementId]) ? 'update' : 'create';

            $orderIncrementId = $data['order_increment_id'] ?? '';
            if ($orderIncrementId === '') {
                $result->addError($line, 'order_increment_id', (string)__('order_increment_id is required.'));
            } elseif (!isset($orderIds[$orderIncrementId])) {
                $result->addError(
                    $line,
                    'order_increment_id',
                    (string)__(
                        'Order "%1" does not exist on this instance. The %2 cannot be imported without its '
                        . 'order — import the orders first.',
                        $orderIncrementId,
                        $label
                    )
                );
            }

            $storeCode = $data['store_code'] ?? '';
            if ($storeCode === '') {
                $result->addError($line, 'store_code', (string)__('store_code is required.'));
            } elseif (!isset($storeCodes[$storeCode])) {
                $result->addError($line, 'store_code', (string)__('Unknown store code "%1".', $storeCode));
            }

            // A shipment holds no amount and no currency, so there is nothing to
            // reconcile against the store's base currency.
            if ($this->type->hasCurrencyColumns()) {
                $this->validateCurrency($result, $line, $data, $storeCode, $storeCodes, $mode);
            }

            $sku = $data['item_sku'] ?? '';
            // A type that allows item-less documents (an adjustment-only credit
            // memo) exports them as one row with every item column empty. That row
            // is valid; the importer skips it when building items.
            $itemlessRow = $sku === '' && ($data['item_qty'] ?? '') === ''
                && $this->type->allowsItemlessDocument();
            if ($itemlessRow) {
                if ($this->isZeroOrEmpty($data['adjustment_positive'] ?? '')
                    && $this->isZeroOrEmpty($data['adjustment_negative'] ?? '')
                    && $this->isZeroOrEmpty($data['grand_total'] ?? '')
                ) {
                    $result->addWarning(
                        $line,
                        'item_sku',
                        (string)__(
                            'The %1 has no items and no adjustment or total — it will be imported but '
                            . 'records no amount.',
                            $label
                        )
                    );
                }
            } elseif ($sku === '') {
                $result->addError($line, 'item_sku', (string)__('item_sku is required.'));
            } elseif ($orderIncrementId !== '' && isset($orderIds[$orderIncrementId])) {
                $skusOfOrder = $orderItemSkus[$orderIds[$orderIncrementId]] ?? [];
                if (!isset($skusOfOrder[$sku])) {
                    $result->addWarning(
                        $line,
                        'item_sku',
                        (string)__(
                            'Order "%1" has no item with SKU "%2" — the %3 item will be imported without a link '
                            . 'to an order item, so it will not count towards that item\'s invoiced/refunded '
                            . 'quantities.',
                            $orderIncrementId,
                            $sku,
                            $label
                        )
                    );
                }
            }

            // Emptiness only — `item_qty` is a `:num` column, so the loop below
            // already reports a non-numeric value, and checking it twice here
            // would put the same row in the error table twice.
            if (!$itemlessRow && ($data['item_qty'] ?? '') === '') {
                $result->addError($line, 'item_qty', (string)__('item_qty is required.'));
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
                    $result->addWarning(
                        $line,
                        $jsonColumn,
                        (string)__('"%1" is not valid JSON — it will be ignored on import.', $jsonColumn)
                    );
                }
            }

            $result->registerOrder($incrementId, $mode, $line);
        }

        $summary = $result->getSummary();
        $this->logger->info(sprintf(
            'Validated %s CSV: %d document(s) [%d create / %d update], %d error(s), %d warning(s).',
            $this->type->getCode(),
            $summary['orders'],
            $summary['creates'],
            $summary['updates'],
            $summary['errors'],
            $summary['warnings']
        ));

        return $result;
    }

    private function isZeroOrEmpty(string $value): bool
    {
        return $value === '' || (is_numeric($value) && abs((float)$value) < 0.0001);
    }

    /**
     * The same currency reasoning as the order CSV: a document whose base
     * currency differs from the store's is imported verbatim, keeping its own
     * currency and rate, so it displays correctly on its own — but a MISSING
     * rate means the document has nothing to convert with and Magento falls
     * back to the store's live rate, which is how a ¥231,818 line came out as
     * ¥39,410,000.
     *
     * Create only: the update path writes no amount and no rate, so it cannot
     * introduce a missing rate. Erroring there would let one legacy document
     * block every other row in the file.
     *
     * @param array<string, string> $data
     * @param array<string, bool> $storeCodes
     */
    private function validateCurrency(
        ValidationResult $result,
        int $line,
        array $data,
        string $storeCode,
        array $storeCodes,
        string $mode
    ): void {
        $csvBaseCurrency = $data['base_currency_code'] ?? '';
        if ($csvBaseCurrency === '' || $storeCode === '' || !isset($storeCodes[$storeCode])) {
            return;
        }

        $storeBaseCurrency = $this->storeBaseCurrency($storeCode);
        if ($storeBaseCurrency === '' || $csvBaseCurrency === $storeBaseCurrency) {
            return;
        }

        $result->addWarning(
            $line,
            'base_currency_code',
            (string)__(
                'The CSV %1 is in base currency "%2" but store "%3" has base currency "%4". It is imported '
                . 'as-is, keeping its own currency and rate, so it displays correctly on its own. Store-level '
                . 'sales reports total base amounts across documents, so this one will mix "%2" into "%4" figures.',
                $this->type->getLabel(),
                $csvBaseCurrency,
                $storeCode,
                $storeBaseCurrency
            )
        );

        if ($mode === 'create' && (float)($data['base_to_order_rate'] ?? 0) <= 0.0) {
            $result->addError(
                $line,
                'base_to_order_rate',
                (string)__(
                    'base_to_order_rate is empty or zero on a "%1" %2 being imported into a "%3" store. The '
                    . 'document would carry no rate of its own and its base amounts would be converted at the '
                    . 'store\'s live rate, producing wrong figures. Set base_to_order_rate (use 1 when the '
                    . 'document currency equals "%1").',
                    $csvBaseCurrency,
                    $this->type->getLabel(),
                    $storeBaseCurrency
                )
            );
        }
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
     * Which of the CSV's document increment ids already exist here.
     *
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

        $collection = $this->type->createCollection();
        $collection->addFieldToFilter('increment_id', ['in' => array_keys($ids)]);
        $found = [];
        foreach ($collection->getColumnValues('increment_id') as $inc) {
            $found[(string)$inc] = true;
        }
        return $found;
    }
}
