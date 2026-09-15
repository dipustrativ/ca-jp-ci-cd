<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * One product, the scanner row it came from, and what the rule decided about
 * it. Pairs the two so the Runner does not have to carry them side by side.
 *
 * Also builds the audit record, so the Runner never has to reach into the
 * scanner row itself.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\SpecialPriceGuard;

use CarpetAvenue\Japan\Model\PriceAction\Entry;

class Outcome
{
    /**
     * @param array<string, mixed> $row Scanner row: sku, cost, price, owner, special_price
     */
    public function __construct(
        public readonly int $productId,
        public readonly array $row,
        public readonly Decision $decision
    ) {
    }

    public function writesCatalog(): bool
    {
        return $this->decision->writesCatalog();
    }

    /**
     * The audit record for the shared price-action log.
     */
    public function toEntry(int $storeId): Entry
    {
        return new Entry(
            ruleCode: Decision::RULE_CODE,
            productId: $this->productId,
            attributeCode: Decision::ATTRIBUTE_CODE,
            storeId: $storeId,
            action: $this->decision->action,
            reason: $this->decision->reason,
            sku: $this->text('sku'),
            oldValue: $this->number('special_price'),
            newValue: $this->decision->newValue,
            thresholdValue: $this->decision->threshold,
            basisValue: $this->number('cost'),
            price: $this->number('price'),
            owner: $this->text('owner')
        );
    }

    private function number(string $column): ?float
    {
        $value = $this->row[$column] ?? null;

        return $value === null || $value === '' ? null : (float)$value;
    }

    private function text(string $column): ?string
    {
        $value = $this->row[$column] ?? null;

        return $value === null ? null : (string)$value;
    }
}
