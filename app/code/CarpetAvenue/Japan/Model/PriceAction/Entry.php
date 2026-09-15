<?php

/**
 * Copyright © Carpet Avenue GmbH. All rights reserved.
 *
 * One audit record for the shared Japan price-action log. Any rule that writes
 * a price attribute — special price guard today, a `price` or `pricing` rule
 * tomorrow — hands these to PriceActionRecorderInterface rather than touching
 * the table itself.
 *
 * @category  Carpet Avenue
 * @package   CarpetAvenue_Japan
 * @author    Strativ Magento Team
 */

declare(strict_types=1);

namespace CarpetAvenue\Japan\Model\PriceAction;

class Entry
{
    /** Rule codes. One per price rule; keeps rows apart in the shared tables. */
    public const RULE_SPECIAL_PRICE_GUARD = 'special_price_guard';

    /** Actions. Shared vocabulary across every rule. */
    public const ACTION_RAISE = 'raise';
    public const ACTION_CLEAR = 'clear';
    public const ACTION_SKIP = 'skip';
    public const ACTION_ERROR = 'error';

    /** Reasons. Rules may add their own codes; these are the shared ones. */
    public const REASON_BELOW_MIN = 'below_min';
    public const REASON_NO_SPECIAL_PRICE = 'no_special_price';
    public const REASON_ABOVE_REGULAR_PRICE = 'above_regular_price';
    public const REASON_NO_COST = 'no_cost';
    public const REASON_IN_RANGE = 'in_range';
    public const REASON_ERROR = 'error';

    /**
     * @param string      $ruleCode       Which rule acted
     * @param int         $productId      Catalog product entity id
     * @param string      $attributeCode  Attribute written: special_price | price | pricing
     * @param int         $storeId        Store view written to
     * @param string      $action         One of the ACTION_* constants
     * @param string      $reason         Reason code
     * @param string|null $sku            Product SKU
     * @param float|null  $oldValue       Attribute value before the action
     * @param float|null  $newValue       Attribute value after the action
     * @param float|null  $thresholdValue Limit the rule enforced
     * @param float|null  $basisValue     Figure the threshold came from, e.g. cost
     * @param float|null  $price          Regular price at decision time
     * @param string|null $owner          Owner attribute at decision time
     * @param string|null $message        Free-text detail
     */
    public function __construct(
        public readonly string $ruleCode,
        public readonly int $productId,
        public readonly string $attributeCode,
        public readonly int $storeId,
        public readonly string $action,
        public readonly string $reason,
        public readonly ?string $sku = null,
        public readonly ?float $oldValue = null,
        public readonly ?float $newValue = null,
        public readonly ?float $thresholdValue = null,
        public readonly ?float $basisValue = null,
        public readonly ?float $price = null,
        public readonly ?string $owner = null,
        public readonly ?string $message = null
    ) {
    }

    /**
     * Same action, but the catalog write failed. The log must say so — a failed
     * run must not look identical to a successful one. The value that would
     * have been written survives in the message.
     */
    public function failed(string $error): self
    {
        return new self(
            ruleCode: $this->ruleCode,
            productId: $this->productId,
            attributeCode: $this->attributeCode,
            storeId: $this->storeId,
            action: self::ACTION_ERROR,
            reason: self::REASON_ERROR,
            sku: $this->sku,
            oldValue: $this->oldValue,
            // Deliberately null: nothing was written.
            newValue: null,
            thresholdValue: $this->thresholdValue,
            basisValue: $this->basisValue,
            price: $this->price,
            owner: $this->owner,
            message: sprintf(
                'write failed (%s → %s): %s',
                $this->action,
                $this->newValue === null ? 'none' : number_format($this->newValue, 2, '.', ''),
                $error
            )
        );
    }

    /**
     * Row shape for carpetavenue_japan_price_action_log.
     *
     * @return array<string, mixed>
     */
    public function toRow(string $runId, bool $dryRun): array
    {
        return [
            'rule_code' => $this->ruleCode,
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'store_id' => $this->storeId,
            'attribute_code' => $this->attributeCode,
            'old_value' => $this->oldValue,
            'new_value' => $this->newValue,
            'threshold_value' => $this->thresholdValue,
            'basis_value' => $this->basisValue,
            'price' => $this->price,
            'owner' => $this->owner,
            'action' => $this->action,
            'reason' => $this->reason,
            'message' => $this->message,
            'run_id' => $runId,
            'dry_run' => $dryRun ? 1 : 0,
        ];
    }
}
