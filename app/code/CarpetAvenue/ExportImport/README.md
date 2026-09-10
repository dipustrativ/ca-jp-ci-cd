# CarpetAvenue_ExportImport

Store-wise **order CSV export** and **validated order CSV import** for CarpetAvenue.

Admin: **CarpetAvenue → Order Export / Import** (Stores → Config → CarpetAvenue →
Order Export / Import for the on/off switch). Also usable from the CLI.

## What it does

- **Export** — filter orders by **store view**, **date range** (`created_at`), and
  one or more **order statuses**, then download a CSV. Row granularity is
  **one row per order item**; order-level columns repeat on every line of the same
  order (keyed by `increment_id`). Column layout is defined once in
  `Model/Order/Schema.php` and shared by export and import so they never drift.
- **Import** — a two-step, manual-check flow mirroring product import:
  1. **Validate** — upload the CSV; it is parsed and checked (header completeness,
     required fields, store-code/status validity, email format, numeric types,
     product existence). Errors block the import; warnings (e.g. unknown SKU) do not.
     Nothing is written.
  2. **Run Import** — only offered when validation passed. Upserts by `increment_id`:
     existing → **update** (status/state + audit comment, plus a
     [product re-link](#re-importing-links-products-that-were-missing-the-first-time)),
     new → **create** a full order straight from the CSV values.

## CLI

```bash
# Export
bin/magento exportimport:orders:export
bin/magento exportimport:orders:export --store=jp --status=processing,complete --from=2026-01-01 --to=2026-03-31 --output=jp_q1.csv

# Import (validation always runs first)
bin/magento exportimport:orders:import --file=var/import/orders.csv --validate-only
bin/magento exportimport:orders:import --file=var/import/orders.csv
```

## Column coverage: what "everything" means

246 columns — 187 order-level, 59 item-level. **Every business column** of
`sales_order`, `sales_order_item`, `sales_order_payment` and
`sales_order_address` is exported. Three groups are deliberately left out, and
`Model/Order/Schema.php` says so in its class docblock:

1. **Internal ids and relations** — `entity_id`, `store_id`, `customer_id`,
   `quote_id`, `*_address_id`, `parent_item_id`, `product_id`, `relation_*`,
   `protect_code`, `edit_increment`. The CSV addresses products by SKU and stores
   by code so a file can move between instances; carrying source ids defeats that.
2. **Lifecycle aggregates** — the `*_invoiced` / `*_refunded` / `*_canceled`
   family plus `total_paid` / `total_due`. Magento maintains these as invoices,
   shipments and credit memos are created. **Those documents are not exported**,
   so the aggregates could not be reconstructed from this file; carrying them
   would only let the two disagree. Exporting invoices/shipments/credit memos is
   the natural next step if full-lifecycle fidelity is needed.
3. **Cardholder data** — `cc_number_enc`, `cc_owner`, `cc_exp_month/year`, the
   `cc_ss_*` switch/solo fields, `echeck_routing_number`, `echeck_account_name`
   and the `cc_debug_*` gateway blobs. A plaintext CSV is the wrong place for
   them. Non-sensitive gateway state (`cc_type`, `cc_last_4`, the status fields,
   transaction ids, `adyen_psp_reference`) *is* exported.

### Verified round-trip

Exporting a two-item order and re-importing it as a new `increment_id` reproduces
**283 of 288** mapped field values byte-identically. The five that differ:

| Field | Why |
|---|---|
| `updated_at` (order + both items) | Magento stamps it on save. Unavoidable. |
| `qty_backordered` (both items) | `NULL` in the source becomes `0.0000` in the copy — the column's own DB default (`0.0000`) applies because the importer omits an empty cell rather than writing an explicit NULL. Semantically identical (nothing backordered). |

Two cells also differ between the DB and the *file* (not the re-imported order):
a telephone stored as `+1 (134) 883-1201` is written as `` +1 (134) 883-1201``.
That leading space is Magento's own anti-formula-injection guard in
`Magento\Framework\Filesystem\Driver\File::filePutCsv()`, which prefixes a space
to any value starting with `=`, `+` or `-`. `CsvReader` trims every cell, so it
never reaches the database on import.

## How the column layout is declared

`Schema::ORDER_COLUMN_MAP` and `Schema::ITEM_COLUMN_MAP` are **ordered**
`csv column => "source:field[:num]"` lists:

```php
'remote_ip'          => 'order:remote_ip',
'payment_amount_paid'=> 'payment:amount_paid:num',
'billing_city'       => 'billing:city',
'item_qty_ordered'   => 'item:qty_ordered:num',
'store_code'         => 'computed',
```

Declaration order *is* the CSV header order, so every column is declared exactly
once and its position, origin and type come from the same line. The exporter and
importer walk these maps, so adding a column is a one-line change — no matching
edit in two files, and no way for header and mapping to drift. `computed` columns
are the only ones needing code on either side.

`:num` marks a column as numeric: validated as a number on import and written as
a float. An empty numeric cell is **skipped**, leaving a nullable column NULL
rather than writing `0.0000` — except for the columns in
`Schema::ZERO_DEFAULT_COLUMNS` (the core totals), which keep the historic
"empty means 0" behaviour so older CSVs import unchanged.

## 1-to-many data (JSON-packed columns)

Since the CSV is one row per item, per-order child collections are packed as JSON
into single cells (order-level, repeated per item row):

- `order_status_history` — full status/comment history from
  `sales_order_status_history` (status, comment, notified, visible-on-front,
  created_at). **Re-imported** on create, in array order, so it is exported
  **oldest-first** — Magento's own history collection is newest-first, which
  would replay the history backwards.
  Note that `Order::addCommentToStatusHistory()` calls `setStatus()` for every
  entry, so the importer re-asserts the CSV's `order_status`/`order_state` after
  the replay; without that a `closed` order lands as `state=closed`,
  `status=pending`.
- `order_status_change_history` — the status-*transition* log from
  `sales_order_status_change_history` (status, created_at). A different table from
  the one above: Magento writes a row here on every status change (see
  `Magento\Sales\Observer\StoreStatusChangeObserver`), with no comment or
  notification data. **Export-only** — Magento repopulates it itself as the
  imported order's status moves.
- `order_tax_details` — per-rate applied tax breakdown from `sales_order_tax`
  (code, title, percent, amount, base_amount). **Export-only** — a derived
  aggregate of the tax calculation.
- `order_level_tax_details` — the `sales_order_tax_item` rows whose `item_id` is
  NULL (shipping tax, and anything else not attached to a product line). They
  belong to no item row, so they ride at order level. **Export-only**.
- `item_product_options` — `sales_order_item.product_options`: the configurable /
   bundle selections and custom options, i.e. *which variant was actually bought*.
   **Re-imported**, and decoded first — the field is an array on the model and
   JSON in the column, so handing Magento the exported string would store a
   JSON-encoded string instead of the option structure.
- `item_tax_details` — **per-item** rows of `sales_order_tax_item`, joined to
  `sales_order_tax` so each carries its rate `code`/`title`, plus
  `taxable_item_type`, `tax_percent`, `amount`, `base_amount`, `real_amount`,
  `real_base_amount`, `associated_item_id`. Item-level (one distinct value per
  row), unlike the columns above. **Export-only**; the scalar
  `item_tax_amount` / `item_base_tax_amount` / `item_tax_percent` columns are
  imported.
- `payment_transactions` — gateway transactions from `sales_payment_transaction`
  (txn_id, parent_txn_id, txn_type, is_closed, additional_information,
  created_at). **Export-only** — replaying gateway transactions on import would
  fabricate payment records that never happened.
- `payment_additional_information` — the payment's `additional_information` map
  (method title, instructions, gateway keys). **Re-imported** on create.

## Cross-base-currency imports: stored as-is, never converted

Amounts are imported **verbatim**. Nothing is recalculated or converted, even
when the CSV's base currency is not the target store's.

That is safe because Magento keeps `base_currency_code`, `order_currency_code`
and `base_to_order_rate` **per order**. An order that says "my base is JPY at
rate 1.0" is internally consistent no matter what the surrounding store's base
currency is: `Order::isCurrencyDifferent()` is false, so no conversion path runs
and the admin formats every figure in the order's own currency.

Verified: a JPY-base order (`base_grand_total == grand_total == 255000`) imported
into this EUR-base store reproduced **228 of 228** mapped field values exactly,
and its admin Original Price rendered `￥255,000.00`.

**The one thing that must not be missing is the rate.** An order left with a NULL
`base_to_order_rate` has no rate of its own, so base amounts get converted at the
store's *live* rate instead. That is what produced a ¥39,410,000 unit price on a
¥255,000 order here: `231818.18 × 170 = 39,409,090.6`, then rounded up by
`CarpetAvenue\Productify\Plugin\Currency\JPCurrencyConvert`. The order concerned
had been imported by an earlier version of this module that carried neither the
currency rates nor the `*_incl_tax` columns.

So validation:

- **warns** when `base_currency_code` differs from the store's — the import
  proceeds and the order displays correctly on its own, but store-level sales
  reports total `base_*` across orders and will mix the two currencies;
- **errors** only when such an order also has an empty or zero
  `base_to_order_rate`, since that is the combination that renders wrong prices.
  Use `1` when the order currency equals its base currency.

## Placed from IP

`remote_ip` and `x_forwarded_for` carry the admin order view's "Placed from IP".
Both **are** imported, so a migrated order keeps its provenance instead of
appearing to have been placed from nowhere. Blank when Magento never recorded one
(admin-created and CSV-created orders have no IP).

## Item pricing: three distinct figures

The admin order view shows **Original Price**, **Price** and **Subtotal** per line,
and they are three different numbers — a line on special price has
`original_price` 46000 with `price` 18100. All three are exported, each with its
base and tax-inclusive counterpart: `item_original_price`, `item_price`,
`item_row_total` (+ `item_base_*`, + `item_*_incl_tax`), plus
`item_discount_percent`, `item_base_discount_amount`, the
`item_*discount_tax_compensation_amount` pair, `item_weight` and `item_row_weight`.

Order level gains the matching tax-inclusive totals: `subtotal_incl_tax`,
`shipping_incl_tax`, `shipping_tax_amount`, the
`discount_tax_compensation_amount` family, `total_item_count` and `weight` (all
with their `base_` counterparts).

**Why it matters on import:** the `*_incl_tax` columns are what a tax-inclusive
store's admin actually renders. Magento leaves them NULL on some orders, so the
importer only writes them when the CSV cell is non-empty — writing `0.0000`
instead of NULL makes the admin display totals no real order would show.
`item_original_price` likewise falls back to `item_price` only when the column is
missing entirely (older CSVs), rather than flattening a discounted line's
Original Price down to what was charged.

## Product names: ordered vs catalog

- `item_name` — `sales_order_item.name`, a snapshot of what the storefront showed
  at order time. This store has one store view (`jp`), so it is Japanese.
- `item_name_default` — the **current** default-scope (store 0) catalog name, i.e.
  the English one, resolved from the SKU by
  `Model/Product/DefaultNameProvider.php` (batched per order, cached per export
  run). Export-only, and not a historical value: it reflects the catalog as of the
  export, and is blank for a SKU no longer in the catalog. Equal to `item_name`
  when the product has no jp-scope override.

## Payment columns

Every `payment_*` column is a `sales_order_payment` field: the method, the
transaction references (`payment_last_trans_id`, `payment_cc_trans_id`,
`payment_adyen_psp_reference`), the gateway status fields
(`payment_cc_status`, `payment_cc_avs_status`, `payment_protection_eligibility`,
`payment_account_status`, `payment_address_status`, …), the eCheck descriptors,
and every amount pair — ordered / authorized / paid (incl. `*_online`) /
refunded / canceled, plus the shipping amount / captured / refunded pairs.

Cardholder data is excluded on purpose — see **Column coverage** above.

Two lookalikes are *not* payment-table columns:
`order_payment_authorization_amount` and `order_payment_auth_expiration` live on
`sales_order`, and carry the `order_` prefix so the distinction is visible in the
header.

All of these are applied on create, but only when the cell is non-empty — a CSV
that predates a column leaves Magento's own default alone instead of zeroing the
payment out.

## Allocated sources

`allocated_sources` (source names, e.g. `Hamburg`) and `allocated_source_codes`
(`hamburg`) carry the MSI source(s) the order was fulfilled from — the same value
as the sales order grid's "Allocated Sources" column, derived the same way
(`sales_shipment` → `inventory_shipment_source` → `inventory_source`, in
`Model/ResourceModel/OrderExtraData.php`, without depending on
`Magento_InventoryShippingAdminUi`). Empty until the order has a shipment, or when
MSI is absent. **Export-only** — allocation is a consequence of shipping, not
order data.

Item children (configurable/bundle sub-lines) export via `getAllItems`, each
linked to its parent with `item_parent_sku`; the importer re-links them via
`setParentItem` on create.

## Product matching is by SKU, never by id

The CSV carries **no product_id**. On import the item's `product_id` is resolved
fresh from the **SKU** against the *target* instance (`ProductRepository::get`).
So a CSV exported from one Magento (where SKU `X` is product 12) imports cleanly
into another (where SKU `X` is product 5) — the SKU matches, the local id is
assigned. Unknown SKU → item imported with a null product link (a validation
warning, not an error).

## Re-importing links products that were missing the first time

Import an order whose SKU is not in the catalog yet and the item is stored with
`product_id = NULL` — SKU, name and prices are all kept, but nothing points at a
product row. Import the catalog later, then **re-import the same order CSV**: the
update path fills the link in.

```
$ bin/magento exportimport:orders:import --file=var/import/orders.csv
Import: 0 created, 8 updated, 0 failed.
Re-linked 2 item(s) to a catalog product that was missing at first import:
+---------------------+--------------+
| order               | items linked |
+---------------------+--------------+
| CA-20200000000002   | 2            |
+---------------------+--------------+
```

The admin import screen reports the same as a notice, and each affected order gets
a status-history comment (`N order item(s) linked to their catalog product via
ExportImport CSV import.`) at its **current** status, so the re-link is auditable
without moving the order.

Scope is deliberately narrow — `Importer::relinkItemProducts()` touches an item
only when **all three** hold:

1. it currently has **no** `product_id`,
2. its SKU appears in the CSV being imported, and
3. that SKU **now** resolves against this catalog.

An item that already points at a product is never re-pointed: a CSV re-import must
not be able to move a live order onto a different product row. Items whose SKU is
still absent are left NULL and re-reported as warnings, so the same file can be run
again after the next catalog import.

**Limitation:** matching is by SKU string. A SKU later reused for a *different*
product links the item to that product. `product_options` is never rewritten, so a
re-linked item keeps the option ids it was actually bought with even if the new
product's options differ.

Persistence relies on `Magento\Sales\Model\ResourceModel\Order\Relation::processRelation()`,
which saves every item of the order on `orderRepository->save()` — mutating the
loaded items is enough, no separate item save.

Related: an item with `product_id = NULL` is also what triggers
`docs/Issues/ISSUE-005` (admin order view redirects to the grid), so re-linking
removes the underlying data problem, not just the missing link.

### Why a missing rate no longer blocks an update

`base_to_order_rate` empty or zero on a cross-base-currency order is an **error on
create only**. `updateOrder()` writes status/state and the item product link —
never a base amount or a rate — so an update cannot introduce a missing rate.
Erroring there blocked the whole file: one legacy order with a NULL rate stopped
every other order in the CSV from being updated, re-links included.

## Important: what the create path does NOT do

Order **create** trusts the CSV totals verbatim. It does **not** rebuild a quote,
recalculate totals, decrement stock, or send emails. It is a data round-trip /
migration tool, not a checkout. **Update** deliberately leaves a placed order's
financials untouched — it sets status/state, adds a history comment, and links
items to a product they had none for.

## Files

- `Model/Order/Schema.php` — single source of truth for the CSV columns: the two
  ordered `csv column => source:field` maps that drive both halves.
- `Model/Product/DefaultNameProvider.php` — batched, cached default-scope (English)
  product name lookup by SKU.
- `Model/ResourceModel/OrderExtraData.php` — read-only SQL for the side tables the
  sales models expose poorly or not at all: `sales_order_tax_item`,
  `sales_order_status_change_history`, allocated MSI sources.
- `Model/Order/Exporter.php` — filters → rows → CSV under `var/export/carpetavenue_orders`.
- `Model/Order/CsvReader.php` — CSV → header + associative rows (with line numbers).
- `Model/Order/Validator.php` → `ValidationResult` — the manual-check gate.
- `Model/Order/Importer.php` — upsert engine (create/update by `increment_id`).
- `Controller/Adminhtml/Export/{Index,Download}.php`, `Import/{Index,Validate,Submit}.php`.
- `Console/Command/{ExportOrdersCommand,ImportOrdersCommand}.php`.

Logs: `var/log/carpetavenue/exportimport/exportimport_YYYY-MM-DD.log`.
