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

/**
 * Single source of truth for the order CSV column layout.
 *
 * Both the exporter (writes these columns, in this order) and the importer /
 * validator (reads and checks against them) depend on this class, so the two
 * halves can never drift out of sync.
 *
 * Row granularity: ONE ROW PER ORDER ITEM. Order-level columns are repeated on
 * every line belonging to the same order (keyed by `increment_id`).
 *
 * ## How the maps work
 *
 * Each map is an **ordered** `csv column => "source:field[:num]"` list. Order of
 * declaration is the order of the CSV header, so a column is declared exactly
 * once — its position, its origin and its type all come from the same line.
 *
 *   source  `order`    a column of `sales_order`
 *           `payment`  a column of `sales_order_payment`
 *           `billing`  a column of the billing `sales_order_address`
 *           `shipping` a column of the shipping `sales_order_address`
 *           `item`     a column of `sales_order_item`
 *           `computed` neither — built by the exporter (and consumed, or not,
 *                      by the importer). Declared as plain `computed`.
 *   `:num`  validated as numeric on import and written as a float.
 *
 * ## Scope: which DB columns are here and which are not
 *
 * Every *business* column of `sales_order`, `sales_order_item`,
 * `sales_order_payment` and `sales_order_address` is present. Three groups are
 * deliberately absent:
 *
 *  1. **Internal ids and relations** — `entity_id`, `store_id`, `customer_id`,
 *     `quote_id`, `*_address_id`, `parent_item_id`, `product_id`, `relation_*`,
 *     `protect_code`, `edit_increment`. The CSV addresses products by SKU and
 *     stores by code so a file moves between instances; carrying source ids
 *     would defeat that.
 *  2. **Lifecycle aggregates** — the `*_invoiced` / `*_refunded` / `*_canceled`
 *     family plus `total_paid` / `total_due`. Magento maintains these as
 *     invoices, shipments and credit memos are created. Those documents are not
 *     exported, so the aggregates could not be reconstructed from this file;
 *     exporting them would only invite the two to disagree.
 *  3. **Cardholder data** — `cc_number_enc`, `cc_owner`, `cc_exp_month`,
 *     `cc_exp_year`, the `cc_ss_*` switch/solo fields, `echeck_routing_number`,
 *     `echeck_account_name` and the `cc_debug_*` gateway blobs. A plaintext CSV
 *     is the wrong place for them. Non-sensitive gateway state (`cc_type`,
 *     `cc_last_4`, statuses, transaction ids) is exported.
 */
class Schema
{
    /**
     * Order-level columns (repeated on every item row of the same order).
     */
    public const ORDER_COLUMN_MAP = [
        // --- identity -------------------------------------------------------
        'increment_id' => 'order:increment_id',
        'store_code' => 'computed',
        'store_name' => 'order:store_name',
        'order_status' => 'order:status',
        'order_state' => 'order:state',
        'created_at' => 'order:created_at',
        'updated_at' => 'order:updated_at',
        // "Placed from IP" in the admin order view.
        'remote_ip' => 'order:remote_ip',
        'x_forwarded_for' => 'order:x_forwarded_for',
        'is_virtual' => 'order:is_virtual:num',
        'original_increment_id' => 'order:original_increment_id',
        'ext_order_id' => 'order:ext_order_id',
        'ext_customer_id' => 'order:ext_customer_id',
        'hold_before_state' => 'order:hold_before_state',
        'hold_before_status' => 'order:hold_before_status',

        // --- customer -------------------------------------------------------
        'customer_email' => 'order:customer_email',
        'customer_prefix' => 'order:customer_prefix',
        'customer_firstname' => 'order:customer_firstname',
        'customer_middlename' => 'order:customer_middlename',
        'customer_lastname' => 'order:customer_lastname',
        'customer_suffix' => 'order:customer_suffix',
        'customer_is_guest' => 'order:customer_is_guest:num',
        'customer_group_id' => 'order:customer_group_id:num',
        'customer_dob' => 'order:customer_dob',
        'customer_gender' => 'order:customer_gender:num',
        'customer_taxvat' => 'order:customer_taxvat',
        'customer_note' => 'order:customer_note',
        'customer_note_notify' => 'order:customer_note_notify:num',

        // --- currency -------------------------------------------------------
        // The rates are what tie the order-currency and base-currency amounts
        // together (JPY order / EUR base at base_to_order_rate). Without them
        // the two sets of totals cannot be reconciled.
        'order_currency_code' => 'order:order_currency_code',
        'base_currency_code' => 'order:base_currency_code',
        'global_currency_code' => 'order:global_currency_code',
        'store_currency_code' => 'order:store_currency_code',
        'base_to_order_rate' => 'order:base_to_order_rate:num',
        'base_to_global_rate' => 'order:base_to_global_rate:num',
        'store_to_base_rate' => 'order:store_to_base_rate:num',
        'store_to_order_rate' => 'order:store_to_order_rate:num',
        'store_to_eur_rate' => 'order:store_to_eur_rate:num',

        // --- totals ---------------------------------------------------------
        'grand_total' => 'order:grand_total:num',
        'base_grand_total' => 'order:base_grand_total:num',
        'subtotal' => 'order:subtotal:num',
        'base_subtotal' => 'order:base_subtotal:num',
        // Tax-inclusive totals. This is a tax-inclusive (JP) store, so these are
        // the figures the admin order view actually renders.
        'subtotal_incl_tax' => 'order:subtotal_incl_tax:num',
        'base_subtotal_incl_tax' => 'order:base_subtotal_incl_tax:num',
        'tax_amount' => 'order:tax_amount:num',
        'base_tax_amount' => 'order:base_tax_amount:num',
        'shipping_amount' => 'order:shipping_amount:num',
        'base_shipping_amount' => 'order:base_shipping_amount:num',
        'shipping_incl_tax' => 'order:shipping_incl_tax:num',
        'base_shipping_incl_tax' => 'order:base_shipping_incl_tax:num',
        'shipping_tax_amount' => 'order:shipping_tax_amount:num',
        'base_shipping_tax_amount' => 'order:base_shipping_tax_amount:num',
        'discount_amount' => 'order:discount_amount:num',
        'base_discount_amount' => 'order:base_discount_amount:num',
        'shipping_discount_amount' => 'order:shipping_discount_amount:num',
        'base_shipping_discount_amount' => 'order:base_shipping_discount_amount:num',
        'discount_tax_compensation_amount' => 'order:discount_tax_compensation_amount:num',
        'base_discount_tax_compensation_amount' => 'order:base_discount_tax_compensation_amount:num',
        'shipping_discount_tax_compensation_amount' => 'order:shipping_discount_tax_compensation_amount:num',
        'base_shipping_discount_tax_compensation_amnt' => 'order:base_shipping_discount_tax_compensation_amnt:num',
        'adjustment_positive' => 'order:adjustment_positive:num',
        'base_adjustment_positive' => 'order:base_adjustment_positive:num',
        'adjustment_negative' => 'order:adjustment_negative:num',
        'base_adjustment_negative' => 'order:base_adjustment_negative:num',
        'coupon_code' => 'order:coupon_code',
        'coupon_rule_name' => 'order:coupon_rule_name',
        'applied_rule_ids' => 'order:applied_rule_ids',
        'discount_description' => 'order:discount_description',
        'total_qty_ordered' => 'order:total_qty_ordered:num',
        'base_total_qty_ordered' => 'order:base_total_qty_ordered:num',
        'total_item_count' => 'order:total_item_count:num',
        'weight' => 'order:weight:num',

        // --- shipping / fulfilment flags ------------------------------------
        'shipping_method' => 'order:shipping_method',
        'shipping_description' => 'order:shipping_description',
        'can_ship_partially' => 'order:can_ship_partially:num',
        'can_ship_partially_item' => 'order:can_ship_partially_item:num',
        'forced_shipment_with_invoice' => 'order:forced_shipment_with_invoice:num',
        'email_sent' => 'order:email_sent:num',
        'send_email' => 'order:send_email:num',
        'paypal_ipn_customer_notified' => 'order:paypal_ipn_customer_notified:num',

        // --- payment ---------------------------------------------------------
        // NOTE: these two are columns of `sales_order`, not `sales_order_payment`
        // — hence the `order_` prefix, so they cannot be confused with the
        // `payment_*` block below.
        'order_payment_authorization_amount' => 'order:payment_authorization_amount:num',
        'order_payment_auth_expiration' => 'order:payment_auth_expiration:num',
        'payment_method' => 'payment:method',
        'payment_last_trans_id' => 'payment:last_trans_id',
        'payment_cc_trans_id' => 'payment:cc_trans_id',
        'payment_cc_type' => 'payment:cc_type',
        'payment_cc_last_4' => 'payment:cc_last_4',
        'payment_cc_status' => 'payment:cc_status',
        'payment_cc_status_description' => 'payment:cc_status_description',
        'payment_cc_approval' => 'payment:cc_approval',
        'payment_cc_avs_status' => 'payment:cc_avs_status',
        'payment_cc_cid_status' => 'payment:cc_cid_status',
        'payment_cc_secure_verify' => 'payment:cc_secure_verify',
        'payment_protection_eligibility' => 'payment:protection_eligibility',
        'payment_account_status' => 'payment:account_status',
        'payment_address_status' => 'payment:address_status',
        'payment_anet_trans_method' => 'payment:anet_trans_method',
        'payment_adyen_psp_reference' => 'payment:adyen_psp_reference',
        'payment_echeck_type' => 'payment:echeck_type',
        'payment_echeck_bank_name' => 'payment:echeck_bank_name',
        'payment_echeck_account_type' => 'payment:echeck_account_type',
        'payment_po_number' => 'payment:po_number',
        'payment_amount_ordered' => 'payment:amount_ordered:num',
        'payment_base_amount_ordered' => 'payment:base_amount_ordered:num',
        'payment_amount_authorized' => 'payment:amount_authorized:num',
        'payment_base_amount_authorized' => 'payment:base_amount_authorized:num',
        'payment_amount_paid' => 'payment:amount_paid:num',
        'payment_base_amount_paid' => 'payment:base_amount_paid:num',
        'payment_base_amount_paid_online' => 'payment:base_amount_paid_online:num',
        'payment_amount_refunded' => 'payment:amount_refunded:num',
        'payment_base_amount_refunded' => 'payment:base_amount_refunded:num',
        'payment_base_amount_refunded_online' => 'payment:base_amount_refunded_online:num',
        'payment_amount_canceled' => 'payment:amount_canceled:num',
        'payment_base_amount_canceled' => 'payment:base_amount_canceled:num',
        'payment_shipping_amount' => 'payment:shipping_amount:num',
        'payment_base_shipping_amount' => 'payment:base_shipping_amount:num',
        'payment_shipping_captured' => 'payment:shipping_captured:num',
        'payment_base_shipping_captured' => 'payment:base_shipping_captured:num',
        'payment_shipping_refunded' => 'payment:shipping_refunded:num',
        'payment_base_shipping_refunded' => 'payment:base_shipping_refunded:num',
        'payment_additional_information' => 'computed',
        'payment_additional_data' => 'payment:additional_data',

        // --- billing address -------------------------------------------------
        'billing_prefix' => 'billing:prefix',
        'billing_firstname' => 'billing:firstname',
        'billing_middlename' => 'billing:middlename',
        'billing_lastname' => 'billing:lastname',
        'billing_suffix' => 'billing:suffix',
        // These two are `sales_order` columns (a store-specific second name pair),
        // not address columns.
        'billing_firstname_jp' => 'order:billing_firstname_jp',
        'billing_lastname_jp' => 'order:billing_lastname_jp',
        'billing_company' => 'billing:company',
        'billing_street' => 'computed',
        'billing_city' => 'billing:city',
        'billing_region' => 'billing:region',
        'billing_region_id' => 'billing:region_id:num',
        'billing_postcode' => 'billing:postcode',
        'billing_country_id' => 'billing:country_id',
        'billing_telephone' => 'billing:telephone',
        'billing_fax' => 'billing:fax',
        'billing_vat_id' => 'billing:vat_id',
        'billing_vat_is_valid' => 'billing:vat_is_valid:num',
        'billing_mposc_field_1' => 'billing:mposc_field_1',
        'billing_mposc_field_2' => 'billing:mposc_field_2',
        'billing_mposc_field_3' => 'billing:mposc_field_3',

        // --- shipping address ------------------------------------------------
        'shipping_prefix' => 'shipping:prefix',
        'shipping_firstname' => 'shipping:firstname',
        'shipping_middlename' => 'shipping:middlename',
        'shipping_lastname' => 'shipping:lastname',
        'shipping_suffix' => 'shipping:suffix',
        'shipping_firstname_jp' => 'order:shipping_firstname_jp',
        'shipping_lastname_jp' => 'order:shipping_lastname_jp',
        'shipping_company' => 'shipping:company',
        'shipping_street' => 'computed',
        'shipping_city' => 'shipping:city',
        'shipping_region' => 'shipping:region',
        'shipping_region_id' => 'shipping:region_id:num',
        'shipping_postcode' => 'shipping:postcode',
        'shipping_country_id' => 'shipping:country_id',
        'shipping_telephone' => 'shipping:telephone',
        'shipping_fax' => 'shipping:fax',
        'shipping_vat_id' => 'shipping:vat_id',
        'shipping_vat_is_valid' => 'shipping:vat_is_valid:num',
        'shipping_mposc_field_1' => 'shipping:mposc_field_1',
        'shipping_mposc_field_2' => 'shipping:mposc_field_2',
        'shipping_mposc_field_3' => 'shipping:mposc_field_3',

        // --- one-step-checkout / gift wrap / gateway extras ------------------
        'osc_order_comment' => 'order:osc_order_comment',
        'osc_delivery_time' => 'order:osc_delivery_time',
        'osc_survey_question' => 'order:osc_survey_question',
        'osc_survey_answers' => 'order:osc_survey_answers',
        'osc_order_house_security_code' => 'order:osc_order_house_security_code',
        'osc_gift_wrap_amount' => 'order:osc_gift_wrap_amount:num',
        'base_osc_gift_wrap_amount' => 'order:base_osc_gift_wrap_amount:num',
        'gift_wrap_type' => 'order:gift_wrap_type:num',
        'is_pos_order' => 'order:is_pos_order:num',
        'dispute_status' => 'order:dispute_status',
        'mp_delivery_information' => 'order:mp_delivery_information',
        'mp_smtp_email_marketing_synced' => 'order:mp_smtp_email_marketing_synced:num',
        'mp_smtp_email_marketing_order_created' => 'order:mp_smtp_email_marketing_order_created:num',
        'adyen_notification_event_code' => 'order:adyen_notification_event_code',
        'adyen_notification_event_code_success' => 'order:adyen_notification_event_code_success:num',
        'adyen_resulturl_event_code' => 'order:adyen_resulturl_event_code',
        'adyen_charged_currency' => 'order:adyen_charged_currency',

        // --- MSI allocated sources (export-only) ------------------------------
        'allocated_sources' => 'computed',
        'allocated_source_codes' => 'computed',

        // --- 1-to-many collections packed as JSON in a single cell -----------
        'order_status_history' => 'computed',
        'order_status_change_history' => 'computed',
        'order_tax_details' => 'computed',
        'order_level_tax_details' => 'computed',
        'payment_transactions' => 'computed',
    ];

    /**
     * Item-level columns (one distinct set per row).
     */
    public const ITEM_COLUMN_MAP = [
        'item_sku' => 'item:sku',
        'item_parent_sku' => 'computed',
        // As ordered — a snapshot of the jp store view, so Japanese.
        'item_name' => 'item:name',
        // Current default-scope (store 0) catalog name, i.e. the English one.
        'item_name_default' => 'computed',
        'item_description' => 'item:description',
        'item_product_type' => 'item:product_type',
        'item_is_virtual' => 'item:is_virtual:num',
        // Configurable/bundle selections and custom options, as Magento stored them.
        'item_product_options' => 'item:product_options',
        'item_additional_data' => 'item:additional_data',
        'item_applied_rule_ids' => 'item:applied_rule_ids',
        'item_ext_order_item_id' => 'item:ext_order_item_id',
        'item_created_at' => 'item:created_at',
        'item_updated_at' => 'item:updated_at',

        // --- quantities / flags ----------------------------------------------
        'item_qty_ordered' => 'item:qty_ordered:num',
        'item_qty_invoiced' => 'item:qty_invoiced:num',
        'item_qty_shipped' => 'item:qty_shipped:num',
        'item_qty_refunded' => 'item:qty_refunded:num',
        'item_qty_backordered' => 'item:qty_backordered:num',
        'item_is_qty_decimal' => 'item:is_qty_decimal:num',
        'item_no_discount' => 'item:no_discount:num',
        'item_free_shipping' => 'item:free_shipping:num',
        'item_locked_do_invoice' => 'item:locked_do_invoice:num',
        'item_locked_do_ship' => 'item:locked_do_ship:num',
        'item_gift_message_available' => 'item:gift_message_available:num',

        // --- pricing ----------------------------------------------------------
        // The three figures the admin order view shows per line: Original Price,
        // Price and Subtotal, each with its base and tax-inclusive counterpart.
        'item_original_price' => 'item:original_price:num',
        'item_base_original_price' => 'item:base_original_price:num',
        'item_price' => 'item:price:num',
        'item_base_price' => 'item:base_price:num',
        'item_price_incl_tax' => 'item:price_incl_tax:num',
        'item_base_price_incl_tax' => 'item:base_price_incl_tax:num',
        'item_row_total' => 'item:row_total:num',
        'item_base_row_total' => 'item:base_row_total:num',
        'item_row_total_incl_tax' => 'item:row_total_incl_tax:num',
        'item_base_row_total_incl_tax' => 'item:base_row_total_incl_tax:num',
        'item_base_cost' => 'item:base_cost:num',

        // --- tax --------------------------------------------------------------
        'item_tax_amount' => 'item:tax_amount:num',
        'item_base_tax_amount' => 'item:base_tax_amount:num',
        'item_tax_percent' => 'item:tax_percent:num',
        'item_tax_before_discount' => 'item:tax_before_discount:num',
        'item_base_tax_before_discount' => 'item:base_tax_before_discount:num',

        // --- discount ---------------------------------------------------------
        'item_discount_amount' => 'item:discount_amount:num',
        'item_base_discount_amount' => 'item:base_discount_amount:num',
        'item_discount_percent' => 'item:discount_percent:num',
        'item_discount_tax_compensation_amount' => 'item:discount_tax_compensation_amount:num',
        'item_base_discount_tax_compensation_amount' => 'item:base_discount_tax_compensation_amount:num',

        // --- weight -----------------------------------------------------------
        'item_weight' => 'item:weight:num',
        'item_row_weight' => 'item:row_weight:num',

        // --- FPT / gift wrap ---------------------------------------------------
        'item_weee_tax_applied' => 'item:weee_tax_applied',
        'item_weee_tax_applied_amount' => 'item:weee_tax_applied_amount:num',
        'item_weee_tax_applied_row_amount' => 'item:weee_tax_applied_row_amount:num',
        'item_base_weee_tax_applied_amount' => 'item:base_weee_tax_applied_amount:num',
        'item_base_weee_tax_applied_row_amnt' => 'item:base_weee_tax_applied_row_amnt:num',
        'item_weee_tax_disposition' => 'item:weee_tax_disposition:num',
        'item_weee_tax_row_disposition' => 'item:weee_tax_row_disposition:num',
        'item_base_weee_tax_disposition' => 'item:base_weee_tax_disposition:num',
        'item_base_weee_tax_row_disposition' => 'item:base_weee_tax_row_disposition:num',
        'item_osc_gift_wrap_amount' => 'item:osc_gift_wrap_amount:num',
        'item_base_osc_gift_wrap_amount' => 'item:base_osc_gift_wrap_amount:num',

        // Per-item rows of sales_order_tax_item, packed as JSON.
        'item_tax_details' => 'computed',
    ];

    /**
     * Columns whose cell holds a JSON document rather than a scalar.
     *
     * The validator only warns (never errors) on malformed JSON here, and the
     * importer skips what it cannot decode.
     */
    public const JSON_COLUMNS = [
        'order_status_history',
        'order_status_change_history',
        'order_tax_details',
        'order_level_tax_details',
        'payment_transactions',
        'payment_additional_information',
        'item_tax_details',
        'item_product_options',
    ];

    /**
     * Computed columns the importer does not consume — they describe state that
     * belongs to another record (a shipment's source allocation, a gateway's
     * transaction log) or is a derived aggregate. Listed so the intent is
     * explicit rather than implied by the absence of code.
     */
    public const EXPORT_ONLY_COLUMNS = [
        'allocated_sources',
        'allocated_source_codes',
        'order_status_change_history',
        'order_tax_details',
        'order_level_tax_details',
        'payment_transactions',
        'item_name_default',
        'item_tax_details',
    ];

    /**
     * Numeric columns that keep the historic "empty cell means 0" behaviour.
     *
     * Every other numeric column is left untouched when its cell is empty, so a
     * NULL in the source database stays NULL instead of becoming 0.0000 — the
     * `*_incl_tax` family in particular is NULL on plenty of real orders, and
     * zeroing it makes the admin render totals no genuine order would show.
     */
    public const ZERO_DEFAULT_COLUMNS = [
        'grand_total',
        'base_grand_total',
        'subtotal',
        'base_subtotal',
        'tax_amount',
        'base_tax_amount',
        'shipping_amount',
        'base_shipping_amount',
        'discount_amount',
        'base_discount_amount',
        'total_qty_ordered',
        'item_qty_ordered',
        'item_qty_invoiced',
        'item_qty_shipped',
        'item_qty_refunded',
        'item_price',
        'item_base_price',
        'item_row_total',
        'item_base_row_total',
        'item_tax_amount',
        'item_base_tax_amount',
        'item_tax_percent',
        'item_discount_amount',
        'item_base_discount_amount',
        'item_discount_percent',
    ];

    /** Columns that MUST be present (as header) and non-empty (per row) to import. */
    public const REQUIRED_COLUMNS = [
        'increment_id',
        'store_code',
        'order_status',
        'customer_email',
        'item_sku',
        'item_qty_ordered',
    ];

    /** Full ordered header: order columns followed by item columns. */
    public function getHeader(): array
    {
        return array_merge(
            array_keys(self::ORDER_COLUMN_MAP),
            array_keys(self::ITEM_COLUMN_MAP)
        );
    }

    public function getRequiredColumns(): array
    {
        return self::REQUIRED_COLUMNS;
    }

    /**
     * Every column declared with `:num`.
     *
     * @return string[]
     */
    public function getNumericColumns(): array
    {
        $numeric = [];
        foreach (self::ORDER_COLUMN_MAP + self::ITEM_COLUMN_MAP as $column => $spec) {
            if (str_ends_with($spec, ':num')) {
                $numeric[] = $column;
            }
        }
        return $numeric;
    }

    public function isNumericColumn(string $column): bool
    {
        $spec = self::ORDER_COLUMN_MAP[$column] ?? self::ITEM_COLUMN_MAP[$column] ?? '';
        return str_ends_with($spec, ':num');
    }

    public function getJsonColumns(): array
    {
        return self::JSON_COLUMNS;
    }

    /**
     * Columns of one source, as `csv column => db field`.
     *
     * `computed` columns are excluded — they have no single backing field.
     *
     * @param string $source order|payment|billing|shipping|item
     * @return array<string, string>
     */
    public function getFieldsFor(string $source): array
    {
        $fields = [];
        foreach (self::ORDER_COLUMN_MAP + self::ITEM_COLUMN_MAP as $column => $spec) {
            if ($spec === 'computed') {
                continue;
            }
            [$columnSource, $field] = explode(':', $spec);
            if ($columnSource === $source) {
                $fields[$column] = $field;
            }
        }
        return $fields;
    }

    public function isExportOnly(string $column): bool
    {
        return in_array($column, self::EXPORT_ONLY_COLUMNS, true);
    }

    public function hasZeroDefault(string $column): bool
    {
        return in_array($column, self::ZERO_DEFAULT_COLUMNS, true);
    }
}
