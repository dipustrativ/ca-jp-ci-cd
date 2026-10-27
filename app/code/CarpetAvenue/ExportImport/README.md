# CarpetAvenue_ExportImport

Store-wise **CSV export** and **validated CSV import** for CarpetAvenue orders
and everything hanging off them — **invoices**, **credit memos**, **shipments**,
**payment transactions** — plus the **customers** and **customer addresses**
behind them.

Admin: **CarpetAvenue → Order Export / Import** — fourteen screens (Export/Import
Orders, Invoices, Credit Memos, Shipments, Transactions, Customers, Customer
Addresses). Stores → Config → CarpetAvenue → Order Export / Import holds the
on/off switch and the
[order-totals sync flag](#order-totals-are-recomputed-from-the-documents). Also
usable from the CLI.

Five engines. The three document types share one; the rest have their own,
because their tables are not document-shaped:

| | Orders | Invoices / credit memos / shipments | Payment transactions | Customers | Customer addresses |
|---|---|---|---|---|---|
| Code | `Model/Order/` | `Model/Document/` | `Model/Transaction/` | `Model/Customer/` | `Model/CustomerAddress/` |
| Keyed by | `increment_id` | `increment_id` | `order_increment_id` + `txn_id` | `email` + `website_code` | field fingerprint |
| Needs | nothing | the order must already exist | the order **and its payment record** must already exist | nothing | the customer must already exist |
| Columns | 246 | 78 (invoice) / 82 (credit memo) / 26 (shipment) | 7 | 20 + EAV | 26 + EAV |
| Row granularity | one per order item | one per document item | one per transaction | one per customer | one per address |

**Import order for a full move:** customers → customer addresses → orders →
invoices / credit memos / shipments → transactions. Each step errors out on a
row whose parent is missing, so the order is enforced, not just recommended.

> Magento ships its own customer import/export (`Magento_CustomerImportExport`,
> under System → Data Transfer). It still works and is untouched. This module's
> customer half exists to match the rest of the module — the same store-wise
> filters, the same two-step validate gate with a per-line report, the same CLI
> pair, and a key (`email` + `website_code`) that survives a move between
> installs — and to carry the EAV attributes this install has added.

## What it does

- **Export** — filter orders by **store view**, **date range** (`created_at`), and
  one or more **order statuses**, then download a CSV. Row granularity is
  **one row per order item**; order-level columns repeat on every line of the same
  order (keyed by `increment_id`). Column layout is defined once in
  `Model/Order/Schema.php` and shared by export and import so they never drift.
- **Import** — a two-step, manual-check flow mirroring product import:
  1. **Validate** — upload the CSV; it is parsed and checked (header completeness,
     required fields, store-code/status validity, email format, numeric types,
     product existence). `order_status` / `order_state` may be empty — see
     [Empty status or state](#empty-order_status-or-order_state). Errors block the import; warnings (e.g. unknown SKU) do not.
     Nothing is written.
  2. **Run Import** — only offered when validation passed. Upserts by `increment_id`:
     existing → **update** (status/state + audit comment, plus a
     [product re-link](#re-importing-links-products-that-were-missing-the-first-time)),
     new → **create** a full order straight from the CSV values.
- **Invoices, credit memos and shipments** — the same export / validate / import
  flow, one screen per document type over one shared engine and one CLI pair for
  all three. See [Invoices, credit memos and shipments](#invoices-credit-memos-and-shipments).
- **Payment transactions** — the same two-step flow over its own, much smaller
  engine, because `sales_payment_transaction` is not shaped like a document. See
  [Payment transactions](#payment-transactions).
- **Customers and customer addresses** — two more files, keyed by email +
  website and matched by address fingerprint, with every EAV attribute this
  install has. See
  [Customers and customer addresses](#customers-and-customer-addresses).

## CLI

```bash
# Export
bin/magento exportimport:orders:export
bin/magento exportimport:orders:export --store=jp --status=processing,complete --from=2026-01-01 --to=2026-03-31 --output=jp_q1.csv

# Import (validation always runs first)
bin/magento exportimport:orders:import --file=var/import/orders.csv --validate-only
bin/magento exportimport:orders:import --file=var/import/orders.csv

# Invoices / credit memos / shipments — one command pair, --type selects the document
bin/magento exportimport:documents:export --type=invoice
bin/magento exportimport:documents:export --type=creditmemo --store=jp --state=2 --from=2026-01-01
bin/magento exportimport:documents:export --type=shipment --order=CA-2020078380,CA-20200000000002

bin/magento exportimport:documents:import --type=invoice --file=var/import/invoices.csv --validate-only
bin/magento exportimport:documents:import --type=creditmemo --file=var/import/creditmemos.csv
bin/magento exportimport:documents:import --type=shipment --file=var/import/shipments.csv

# ...or the one-per-type import commands (same thing, no --type)
bin/magento exportimport:invoices:import --file=var/import/invoices.csv
bin/magento exportimport:creditmemos:import --file=var/import/creditmemos.csv
bin/magento exportimport:shipments:import --file=var/import/shipments.csv --strict

# Payment transactions — their own command pair, no --type
bin/magento exportimport:transactions:export
bin/magento exportimport:transactions:export --store=jp --type=capture,refund --from=2026-01-01
bin/magento exportimport:transactions:import --file=var/import/transactions.csv --validate-only
bin/magento exportimport:transactions:import --file=var/import/transactions.csv

# Customers, then their addresses (both filter on the CUSTOMER)
bin/magento exportimport:customers:export --website=base --group=General,Wholesale
bin/magento exportimport:customers:export --from=2026-01-01 --email=a@b.com,c@d.com
bin/magento exportimport:customers:import --file=var/import/customers.csv --validate-only
bin/magento exportimport:customers:import --file=var/import/customers.csv

bin/magento exportimport:customer-addresses:export --website=base
bin/magento exportimport:customer-addresses:import --file=var/import/addresses.csv --validate-only
bin/magento exportimport:customer-addresses:import --file=var/import/addresses.csv
```

**Document and transaction import from the CLI skips invalid records.** Any document with a
row that fails validation is left out whole (all of its rows, so an invoice is
never written with an item missing), the rest are imported, and a **Not
imported** table lists every skipped (`validation`) and failed (`import`)
document at the end, with line numbers. The exit code is non-zero if anything
was left out. `--strict` restores all-or-nothing. A file-level error (a missing
header column, no data rows) always aborts: no row can be trusted then.
`exportimport:transactions:import` works the same way, per row, since a
transaction is one row. The admin import screens are unchanged and still
all-or-nothing, and so are the order and customer imports.

`--state` is the numeric column, not a label: invoice `1` pending / `2` paid /
`3` canceled, credit memo `1` open / `2` refunded / `3` canceled. It is **ignored
for shipments** — `sales_shipment` has no state column.

**Order and document import commands report progress.** Each phase prints a
timestamped line (reading, validating, importing), and the write phase draws a
progress bar: done/total, elapsed, estimated, memory, and the increment id last
written. Piped to a file the bar prints at most one line every 0.5–2 s. The
command validates once and hands that result to the importer, so a large file
is no longer validated twice before the first write.

On `exportimport:transactions:export`, `--type` is the transaction type
(`order`, `authorization`, `capture`, `void`, `refund`, `payment`), not a
document type.

On both customer commands `--group` takes group **codes** (what the CSV carries)
or ids, and `--from` / `--to` filter the customer's registration date — for
addresses too.

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
   family plus `total_paid` / `total_due`. Magento derives these from the order's
   invoices and credit memos, so the order CSV does not carry them: they are
   **recomputed from the documents** once those are imported (see
   [Order totals are recomputed from the documents](#order-totals-are-recomputed-from-the-documents)).
   Carrying them in the order file as well would only let the two disagree.
   `qty_shipped` works the same way now that shipments are exported: it is
   recomputed from the shipments once those are imported, by
   [their own synchroniser](#order-totals-are-recomputed-from-the-documents).
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

## Imported orders keep the CSV's state and status

Every `orderRepository->save()` runs Magento's
`Magento\Sales\Model\ResourceModel\Order\Handler\State::check()` from the order
resource model's `_beforeSave()`, and that handler re-derives the state from the
order's aggregates. For an imported order those aggregates are incomplete by
design: the order CSV carries no `total_paid` (see the scope notes in
`Model/Order/Schema.php`). With `total_paid` NULL, `canCreditmemo()` is false, and
once every item's `qty_shipped` is filled in, `checkForClosedState()` rewrote a
`complete` order to **`closed/closed`** on the same save that created it. Updates
and the document-import totals resync did the same.

The importer now saves inside `Model/Order/StateGuard::run()`, and
`Plugin/PreserveImportedOrderState` (around `Handler\State::check()`) skips the
handler while the guard is active. The CSV's `order_state` / `order_status` land
unchanged in `sales_order`, `sales_order_grid` and the status-change log. Outside
an import the handler runs exactly as in core.

**Caveat:** the order's aggregates stay as imported. An order with no
`total_paid` still gets closed by core the next time anything else saves it (an
admin comment, a Syncer update). To prevent that, import its paid invoices, which
[fill in `total_paid`](#order-totals-are-recomputed-from-the-documents).

Verified 2026-09-25 (local): a `complete` order with full `qty_shipped` and no
`total_paid` imports as `complete/complete` on create and on re-import. The same
order saved outside the import becomes `closed/closed`.

### Empty `order_status` or `order_state`

Neither column is required. An empty one is filled from the other by
`Model/Order/StatusResolver`, using this instance's status/state assignments
(`sales_order_status_state`):

| CSV status | CSV state | Written |
|---|---|---|
| `processing` | `processing` | as given |
| empty | `complete` | the state's default status → `complete/complete` |
| `fraud` | empty | the state the status is assigned to, preferring one it is the default of → `payment_review/fraud` |
| empty | empty | create: `new/pending`; update: the order keeps its current status and state |

Each derived value is reported as a validation **warning**, once per order, so
the outcome is visible before anything is written. A status this instance does
not know is still an **error**.

Before this, an empty `order_status` failed validation and blocked the whole
file, and a status with an empty state created the order as state `new`
whatever the status was.

Verified 2026-10-27 (local), all four rows above plus `payment_review/payment_review`
from state only; re-importing the same orders with both columns emptied left
every status and state unchanged.

Invoice, credit-memo and shipment `state` / `creditmemo_status` /
`shipment_status` were already optional: empty imports as NULL on create and
leaves the value alone on update.

## Invoices, credit memos and shipments

`Model/Document/` handles all three. The tables are the same shape — a document
row, an item table and a comment table, all hanging off one order — so Schema,
Exporter, Validator and Importer are written **once** and parameterised by a
`Model/Document/TypeInterface` implementation (`Type/Invoice`,
`Type/Creditmemo`, `Type/Shipment`). One set of virtualTypes per document type
binds them in `etc/di.xml`; `Model/Document/Pool` maps the type code used in URLs
and CLI options to the right set. Adding a further document type means one `Type`
class plus a block of virtualTypes — no controller, block or command changes,
which is exactly how the shipment type was added.

Row granularity is **one row per document item**, document-level columns repeated
on every line of the same document, keyed by `increment_id` — the same convention
as the order CSV.

### Column coverage

Every business column of `sales_invoice` / `sales_creditmemo` / `sales_shipment`
and their item tables. Only three groups are absent:

1. **Internal ids and relations** — `entity_id`, `parent_id`, `store_id`,
   `order_id`, `customer_id`, `product_id`, `order_item_id`,
   `billing_address_id`, `shipping_address_id`, and a credit memo's `invoice_id`.
   Replaced by:

   | Column | Replaces | Resolved on import against |
   |---|---|---|
   | `order_increment_id` | `order_id` | `sales_order.increment_id` |
   | `store_code` | `store_id` | store view codes |
   | `item_sku` | `order_item_id`, `product_id` | the order's own items |
   | `invoice_increment_id` (credit memo) | `invoice_id` | the order's invoices |

2. **`sales_shipment.shipping_label`** — a `mediumblob` holding the carrier's raw
   label bytes. Binary in a CSV cell would need base64 and would multiply the
   file size by the weight of every label; a label is re-printable from the
   carrier and is not a record of the shipment. Not exported, not imported, and
   an existing one is never touched.

3. Nothing else. There are no lifecycle aggregates to leave out (a document *is*
   the record of one) and no cardholder data on these tables. The one derived
   column that is carried, `sales_invoice.base_total_refunded`, is imported
   verbatim **and** recomputed by the synchroniser, so it is right either way.

`sales_invoice_item` and `sales_creditmemo_item` are column-for-column identical,
so their item map lives in `Type/AbstractType` and neither subclass repeats it.
`sales_shipment_item` has 8 business columns rather than ~30 — no tax, discount
or FPT columns, since a shipment moves goods and does not price them — so
`Type/Shipment` declares its own. The document-level maps are per type: the tables
overlap heavily but not exactly (a credit memo has the `adjustment_*` family, an
invoice has `total_qty`, `can_void_flag`, `is_used_for_refund`,
`base_total_refunded`, a shipment has `total_weight`, `packages` and
`shipment_status` and no money at all), and column order is CSV header order.

Comments (`sales_invoice_comment` / `sales_creditmemo_comment` /
`sales_shipment_comment`) ride in one JSON-packed `document_comments` cell,
oldest first, carrying `comment`, `is_customer_notified`, `is_visible_on_front`,
`created_at`, `user_id` and `user_type`.

### Adjustment-only credit memos have no item rows

A refund can be an adjustment alone (`adjustment_positive` /
`adjustment_negative`) with no product line. Export writes such a credit memo as
one row with every `item_*` column empty. Validation accepts that row for credit
memos only (`TypeInterface::allowsItemlessDocument()`), and the importer skips it
when building items, so the credit memo is created with its amounts and no items.
A warning flags one that has no items, no adjustment and a zero total. For an
invoice or a shipment an empty item row is still an error (`item_sku is
required`): with no items they record nothing. A row with an `item_sku` but no
`item_qty` is an error for every type.

### What is different about a shipment

Three things, all declared on `Type/Shipment` so the shared engine needs no
special cases:

- **No money.** `sales_shipment` carries no amount, no currency code and no
  conversion rate, so `hasCurrencyColumns()` is false: the importer writes no
  currency fallback and the validator raises no
  [cross-currency warning](#cross-base-currency-imports-stored-as-is-never-converted).
  The item table's `price` / `row_total` are a copy of the order item's figures
  at ship time and are carried as-is.
- **No state.** `getStateField()` is null, so the export screen shows no status
  filter, `--state` is ignored, and the update path has no state to change.
  `shipment_status` travels as an ordinary column — it is a legacy column Magento
  never writes, not a lifecycle state.
- **`packages` is an array on the model and JSON in the column.**
  `Magento\Sales\Model\ResourceModel\Order\Shipment` declares it in
  `$_serializableFields`, so the resource json-encodes on save and decodes on
  load. It is therefore a `computed` column: the exporter writes the JSON text
  and the importer decodes it back to an array before saving. Handing the
  resource the JSON *string* would store a json-encoded string, and the admin
  shipment view then dies in `Magento_Shipping::order/packaging/packed.phtml`
  (`foreach ($block->getPackages() ...)` on a string). The same applies to
  `sales_order_item.product_options` and both `additional_information` columns,
  which the order and transaction importers decode for the same reason — those
  four are the only serialized columns in the tables this module writes.
- **Tracks (tracking numbers).** `sales_shipment_track` is a second 1-to-many
  child, packed into a `document_tracks` JSON cell exactly as comments are packed
  into `document_comments`, carrying `track_number`, `title`, `carrier_code`,
  `description`, `qty`, `weight` and `created_at`.

  Next to it are **three flattened, export-only columns** — `track_numbers`,
  `track_carrier_codes`, `track_titles` — comma-separated in the same order, so a
  tracking number is readable (and copy-pasteable into a carrier's site) without
  opening the JSON. A shipment usually has one track, in which case each holds a
  single value. They are **never read on import**: `document_tracks` is the
  authority, so editing a flat column changes nothing — edit the JSON.

  Like comments, tracks are replayed **after the first save**
  (`Shipment::addTrack()` stamps the track's `NOT NULL` `parent_id` from the
  shipment at add time) and **only on the create path** — there is no key to
  match an exported track against a stored one, since a carrier can reuse a
  number across shipments.

### The order must exist first

A row whose `order_increment_id` is unknown here is an **error**, not a warning,
and blocks the file. That is the one hard difference from the order validator: an
order can be imported with an unlinked item and still be a complete record; an
invoice or a shipment with no order is not a record of anything and Magento has
nowhere to put it. **Import orders first, then their documents.**

An `item_sku` the order does not have is only a **warning** — the item imports
with its amounts intact but no `order_item_id`, so it counts towards nothing. A
later re-import links it (see below).

### Import creates documents verbatim — it does not use Magento's services

The create path does **not** go through `InvoiceService::prepareInvoice()`,
`CreditmemoFactory::createByOrder()` or `ShipmentFactory::create()`, recalculates
nothing, touches no stock and sends no email. Two reasons, and the second is
decisive:

1. Those services recalculate from the order and would overwrite the figures the
   file exists to preserve.
2. **They refuse to run on a migrated order at all.** `Order::canInvoice()` is
   false once `qty_invoiced` equals `qty_ordered` — which the order CSV has
   already imported — so the service path cannot reproduce the invoices of an
   order it has just imported. Same for `canCreditmemo()` and `canShip()`.

`Invoice::register()` / `pay()` and `Creditmemo\RefundOperation` are avoided for a
third reason: both reach into the payment method (`register()` can capture,
`pay()` calls `$payment->pay()`), and a migration import must never touch a
gateway.

Increment ids are taken from the CSV.
`Magento\Sales\Model\ResourceModel\EntityAbstract::_beforeSave()` only pulls
from the sequence when `increment_id` is empty, so an imported id is kept — with
the same caveat as orders: ids from another instance can collide with future
locally generated ones unless `sales_sequence_*` is advanced past them.

Comments — and a shipment's tracks — are added **after** the first save and the
document is saved again. Every model stamps the child's `parent_id` from the
document id at add time, and that column is `NOT NULL`, so attaching them to an
unsaved document would insert a null parent.

**MSI stock deduction is switched off for imported documents.** "Touches no
stock" was not true on its own: MSI observes `sales_order_shipment_save_after`
(`SourceDeductionProcessor`) and `sales_order_invoice_save_after`
(`VirtualSourceDeductionProcessor`) and deducts source stock for every new
document, whoever saves it. For a shipment that also crashed. The processor
takes the source from the shipment's `source_code` extension attribute and falls
back to the default source only in single-source mode. An imported shipment has
no source code and this install has several sources, so `$sourceCode` was never
assigned (`SourceDeductionProcessor.php:112`), the warning became an exception, and
`ShipmentRepository::save()` reported only **"The shipment couldn't be saved."**
The importer now saves inside `Model/Document/StockDeductionGuard::run()`, and
`Plugin/SkipImportedDocumentStockDeduction` skips both observers while the guard
is active. Outside an import both run exactly as in core.

**Other observers still fire on imported documents.** IXPF's
`InvoiceSaveWebhook` / `ShipmentSaveWebhook` / `CreditmemoSaveWebhook` send the ERP
a webhook for every document saved (create *and* update) when
`ixpfng/product/enable_webhook` is on, and `IXPF\Syncer\Observer\ReservationRefund`
records a refunded reservation for every new credit memo. Turn the webhook off
on the target instance for a bulk migration unless the ERP should be told.

**Failure reasons name the real cause.** Magento's sales repositories wrap any
error in a generic `CouldNotSaveException`. `Model/ErrorMessage::describe()` walks
the exception chain, so the Not imported list reads for example "The shipment
couldn't be saved. — caused by: Warning: Undefined variable $sourceCode in …"
rather than the wrapper alone. The order and transaction importers use it too.

### Update path

An existing `increment_id` is an **update**, and it changes almost nothing:
`state` (nothing, for a shipment — it has no state column), plus an
`order_item_id` / `product_id` link for items that have none. Amounts are never
rewritten — a document is an accounting record. Comments and tracks are not
replayed either: there is no key to match an exported child row against an
existing one, so a re-import would duplicate the thread.

Re-linking is the document counterpart of the order importer's
[product re-link](#re-importing-links-products-that-were-missing-the-first-time)
and has the same three conditions: the item currently has **no** order item, its
SKU appears in the CSV being imported, and that SKU now resolves against the
order. An item that already points somewhere is never re-pointed.

### Order totals are recomputed from the documents

One synchroniser runs once per touched order after its documents are in, and
**which** one is wired per document type in `etc/di.xml`
(`Model/Document/OrderAggregateSynchronizerInterface`):

| Document type | Synchroniser | Rebuilds |
|---|---|---|
| invoice, creditmemo | `OrderTotalsSynchronizer` | the invoiced / paid / refunded family, below |
| shipment | `ShipmentQtySynchronizer` | the order items' `qty_shipped`, and nothing else |

They are kept apart deliberately: both are full recomputes that reset an item
with no matching document rows to 0, so a single combined synchroniser would zero
the shipped quantities of an install whose shipments were never imported the
moment an invoice was imported (and vice versa).

`OrderTotalsSynchronizer` rebuilds — from the complete set of invoices and credit
memos now in the database:

- order: `total_invoiced`, `subtotal_invoiced`, `tax_invoiced`,
  `shipping_invoiced`, `discount_invoiced`,
  `discount_tax_compensation_invoiced` (+ `base_*`), `total_paid`,
  `total_refunded`, `subtotal_refunded`, `tax_refunded`, `shipping_refunded`,
  `shipping_tax_refunded`, `discount_refunded`,
  `discount_tax_compensation_refunded`, `adjustment_positive`,
  `adjustment_negative`, `total_online_refunded`, `total_offline_refunded`
  (+ `base_*`)
- order items: `qty_invoiced`, `row_invoiced`, `tax_invoiced`,
  `discount_invoiced`, `discount_tax_compensation_invoiced`, `qty_refunded`,
  `amount_refunded`, `tax_refunded`, `discount_refunded`,
  `discount_tax_compensation_refunded` (+ `base_*`)
- each invoice's `base_total_refunded`

`ShipmentQtySynchronizer` rebuilds one figure, `sales_order_item.qty_shipped`, as
the sum of the order's `sales_shipment_item.qty` per order item. There is no
order-level "total shipped" column to maintain.

Turn either off with **Sync Order Totals After Document Import** = No — the one
switch covers every document type. Leave it on unless the order CSV is the
authority for those figures: with it off, an imported invoice leaves the order
showing *Total Paid 0* and an imported shipment leaves every item at 0 shipped.

Four properties worth knowing:

- **It is a full recompute, not an increment.** Magento adds each new document's
  amounts to the running order totals; this sums them from scratch. That is what
  makes re-importing the same file a no-op instead of doubling every total.
- **Canceled documents contribute nothing** (`state = 3` on both money-document
  tables), matching `Invoice::cancel()`, which subtracts a canceled invoice back
  out. Shipments have no state, so every shipment row of the order counts.
- **`total_due` is not written.** `Order::getData()` intercepts `total_due` and
  `base_total_due` and returns `grand_total - total_paid` computed on read, so
  whatever is in those columns is ignored.
- **The order's `state` and `status` are never changed.** A migrated order's
  status came from its own CSV; importing an invoice does not move it to
  `processing`, nor a shipment to `complete`, the way doing either in the admin
  would. The resync save runs inside `StateGuard`. Without it, Magento's state
  handler closed the order, for example when shipments were imported with no
  paid invoice. See
  [Imported orders keep the CSV's state and status](#imported-orders-keep-the-csvs-state-and-status).

The **online / offline refund split is a heuristic.** No column records which
bucket a refund went into — Magento decides it at refund time from whether the
payment was refunded through the gateway and keeps only the two running order
totals. A credit memo with a `transaction_id` is treated as online. The split can
be wrong for a gateway that writes no transaction id; the **sum** of the two
buckets is exact either way, and that is what `total_refunded` and the admin's
Refunded column show.

A sync failure is logged and swallowed. The documents are already saved and
correct; refusing to report the import as done because a derived total could not
be refreshed would be the wrong trade.

### Verified

On the local stack (2 real invoices, 1 real credit memo, plus a synthetic
two-item invoice + credit memo on a cross-currency order):

| Check | Result |
|---|---|
| Export of the real documents | 2 invoices / 1 credit memo, every mapped value matching the DB |
| Create path, 2-item invoice on EUR-base / JPY-order order | `order_item_id` resolved by SKU (14, 15), `product_id` inherited from the order items (1112, 1113), comment restored with its original `created_at`, amounts byte-identical to the CSV |
| Create path, credit memo with `invoice_increment_id` | linked to the right invoice; that invoice's `base_total_refunded` synced to 365.04 |
| Order aggregates after import | `total_invoiced` / `total_paid` / `total_refunded` 62654.20, `base_*` 365.04, per-item `qty_invoiced` and `qty_refunded` 1.0000 each |
| Round-trip diff (import → export) | no real differences: only `updated_at` (save stamp), zero-default columns filled from empty, currency rates filled from the order, `base_total_refunded` computed by the synchroniser, and ints round-tripping as strings inside the comments JSON |
| Re-import of the same files (update path) | no duplicate documents, items or comments; every total unchanged |
| Missing order / non-numeric qty / unknown SKU | 2 errors + 1 warning, nothing written |

`phpcs --standard=Magento2`: 0 errors. `phpstan analyse` on the whole module:
`[OK] No errors`.

The **shipment** type, the **transaction** half and the **customer / address**
half were added later and have **not yet been exercised against live data on the
local stack** — the table above covers invoices and credit memos only. The local
DB container could not be started when they were written (host port 3306 held by
another project's stack), so verification stopped at static analysis: `phpcs
--standard=Magento2` 0 errors, `phpstan analyse` `[OK] No errors`, and every new
class constructed through the real object manager to prove the di.xml wiring.
Before relying on any of the three, run the round-trip in
[Verifying shipments and transactions](#verifying-shipments-and-transactions) or
[Verifying customers and addresses](#verifying-customers-and-addresses).

## Payment transactions

`Model/Transaction/` handles `sales_payment_transaction` — the log of gateway
calls behind **Sales → Transactions** in the admin. It is deliberately *not* a
`Model/Document/` type: the table has no items, no comments, no increment id, no
store column and no money on it. It is a flat log keyed by the gateway's own
`txn_id` and parented to another transaction rather than to items, so forcing it
through the document engine would have meant a flag per difference on Schema,
Exporter, Validator and Importer alike. Its own four classes are the smaller
half.

Row granularity is **one row per transaction**, seven columns:

| Column | Notes |
|---|---|
| `txn_id` | the gateway's id — half the key |
| `order_increment_id` | replaces `order_id`; also how `payment_id` is found |
| `parent_txn_id` | replaces `parent_id`; empty for a root transaction |
| `txn_type` | `order` / `authorization` / `capture` / `void` / `refund` / `payment` |
| `is_closed` | |
| `created_at` | |
| `additional_information` | the gateway's own response payload, as JSON |

Absent: `transaction_id`, `parent_id`, `order_id`, `payment_id` — all
instance-local, all re-resolved on import. There is no cardholder data on the
table; whatever a gateway put in `additional_information` is copied, never
inspected.

### The order must exist — and so must its payment record

`sales_payment_transaction.payment_id` is `NOT NULL` and comes from the order's
`sales_order_payment` row, so **two** things are errors that block the file: an
unknown `order_increment_id`, and a known order that has no payment record.
An unknown `txn_type` is an error too — Magento's own code switches on the six
types it knows, and a seventh value would sit in the table unreadable by the
admin grid and by every gateway.

Two rows claiming the same `order_increment_id` + `txn_id` are an error as well:
that pair is the table's unique key (with `payment_id`), so the second row would
collide on insert.

### Upsert key, and what an update changes

The key is **`order_increment_id` + `txn_id`**, not `txn_id` alone: the unique
index is on order + payment + txn_id, and a gateway that numbers per merchant
account rather than globally can legitimately reuse an id across orders.

An update writes `is_closed`, and fills in a `txn_type` or an
`additional_information` payload that is missing locally. An existing payload is
never overwritten — it is the gateway's own record of what happened — and
`created_at` is left as it is.

### Import never touches a gateway, or Magento's transaction linking

The importer writes the CSV values verbatim through `TransactionRepository`. It
does **not** go through `Payment::addTransaction()` or
`Payment\Transaction\Builder`. Beyond the usual reason, transaction auto-linking
is the specific hazard: attaching a capture to an authorization through the model
closes the authorization, and voiding does likewise, so replaying a migrated
payment history through it would rewrite the very flags the file carries.

### Two columns are written with SQL after the save

- **`created_at`** — `Transaction::beforeSave()` stamps the current time on every
  transaction that has no id yet, unconditionally, and there is no hook to opt
  out (the repository, the resource and `save()` all go through it). Without the
  correction every imported transaction would land dated the day of the import.
- **`parent_id`** — a pure re-resolution of `parent_txn_id` to a local id. The
  sales resource model already does this in its own `_beforeSave()`, but only
  against transactions already in the database, so a parent created *later in
  the same run* is missed; the second pass fills those in. It only ever writes a
  resolved id, never clears one, so the two cannot fight.
  (`Transaction::setParentTxnId()` is avoided throughout — it loads the parent
  and brings in the auto-linking above.)

Both are corrections to an already-saved row, done in a **second pass over
everything the run saved** — which is what lets a file list a child before its
parent. The exporter still writes parents first (`transaction_id ASC`). A
`parent_txn_id` that matches nothing on the order is left unset, with a warning
from the validator; the transaction is a complete record of the gateway call
without the link.

### Verifying shipments and transactions

Neither half has a live round-trip on record yet. What to run, on the local stack
with real data:

```bash
# Shipments
bin/magento exportimport:documents:export --type=shipment --order=<order with a shipment>
bin/magento exportimport:documents:import --type=shipment --file=var/export/carpetavenue_documents/<file> --validate-only
# then on a target instance whose orders are already imported:
bin/magento exportimport:documents:import --type=shipment --file=<file>

# Transactions
bin/magento exportimport:transactions:export --order=<order with transactions>
bin/magento exportimport:transactions:import --file=var/export/carpetavenue_transactions/<file> --validate-only
bin/magento exportimport:transactions:import --file=<file>
```

Check, in this order: the created shipment's items carry an `order_item_id`, its
tracks and comments came back with their original `created_at`, the order items'
`qty_shipped` matches the shipment quantities, a re-import of the same file
creates no duplicate shipment / item / track / comment, the imported
transactions' `created_at` is the exported one and not today, and
`parent_id` points at the transaction named by `parent_txn_id`.

## Customers and customer addresses

`Model/Customer/` and `Model/CustomerAddress/`. Two files, two screens, two CLI
pairs — **customers first, addresses second**, because an address whose customer
is not here is a hard error.

### The files are as sensitive as a database dump

The customer CSV carries `password_hash`, and the two files together carry every
email address, date of birth, phone number and postal address in the store.
`password_hash` is a hash, not a password, and it is carried deliberately:
without it every migrated customer has to reset their password before they can
log in, which turns a migration into a mass password reset. But it means the
file must be handled like a DB dump — encrypted in transit, deleted from
`var/export` when the move is done, never left in a shared folder.

Not carried, on purpose: `rp_token` / `rp_token_created_at` (a live
reset-password token has no business travelling), and `failures_num` /
`first_failure` / `lock_expires` / `session_cutoff` (login-lockout state — an
import must not lock a customer out of an account they can use).

Not carried because it **cannot be imported**: `is_active`, on both the customer
and the address. `Magento\Eav\Model\Entity\AbstractEntity::_collectSaveData()`
drops any value whose code has no `eav_attribute` row and is not one of the
entity's default static attributes (`created_at`, `updated_at`, `parent_id`,
`increment_id`, plus `store_id` / `website_id` for the customer), and `is_active`
is neither. Magento itself never writes it — it is a legacy column that stays at
its `NOT NULL DEFAULT 1`. A column that exports a value and then silently fails
to import it is worse than no column, so it is not in either map.

### Why two files

A customer has 0..n addresses. One file would mean either one row per address
with every customer column repeated — a password hash on every line — or a
JSON-packed cell, which would make the addresses unreadable in the one place
this data actually gets fixed up by hand, a spreadsheet. Two flat files it is.

### Keys

| File | Key | Why |
|---|---|---|
| Customers | `email` + `website_code` | Exactly what `customer_entity` enforces as unique, and what Magento's own duplicate check uses. Email alone is wrong: the same person may have an account on two websites. |
| Addresses | a fingerprint of the address fields | An address has **no** business key — no increment id, no code, nothing a second install could match on. |

Everything instance-local is replaced by something portable: `website_id` →
`website_code`, `group_id` → `group_code`, `store_id` → `store_code`,
`parent_id` → `customer_email` + `customer_website_code`, and the customer's
`default_billing` / `default_shipping` address ids → `is_default_billing` /
`is_default_shipping` flags on the address row, which is the row that knows
which address it is.

A `group_code` this install does not have is an **error** — the import does not
create customer groups. An empty one is a warning and falls back to the
configured default group. An unknown `store_code` is a warning and falls back to
the website's default store, never to store 0: a customer with `store_id = 0`
gets the admin scope's locale in every transactional email, which is how a
migrated Japanese customer ends up receiving English mail.

### EAV: the `extra_attributes` column

Customers and addresses are EAV entities, so their column set is
**install-specific** — this install alone has `is_approved` on the customer, from
`CarpetAvenue_B2B`, and a module or an admin user can add another tomorrow. A
purely hardcoded map would drop those silently.

So the declared maps cover the standard attributes (a stable, reviewable header)
and **every other attribute of the entity type is packed into one
`extra_attributes` JSON cell**, resolved at runtime in both directions by
`Model/Eav/ExtraAttributes`. Add an attribute to the install and it starts
round-tripping with no code change; import a file from an instance that has an
attribute this one does not and that value is skipped with a warning, the rest of
the row landing normally. The import screen lists the codes this install carries.

### Update path

An existing (email, website) is an **update**, and unlike the sales half it does
rewrite the profile: names, DOB, gender, taxvat, group, store, the account flags
and the extra attributes. A customer record is not an accounting record, and the
file is the authority for who this person is.

Three things it will not touch: `created_at` (the local account's own history),
and `password_hash` / `confirmation`, which are only filled in when the local
account has **none**. Rewriting a hash would change the password of an account
someone may already be using here; rewriting `confirmation` would push a
confirmed account back into "unconfirmed".

### Addresses are matched by fingerprint, never duplicated

A row is matched against the customer's existing addresses by a SHA-1 of its own
field values — `firstname`, `lastname`, `company`, `street`, `city`, `region`,
`postcode`, `country_id`, `telephone`, each trimmed and lower-cased so casing or
whitespace differences between the two installs do not create a duplicate.
Deliberately outside the fingerprint: the timestamps, the `vat_*` validation
family, `is_active`, and the default flags — the same address can be the default
on one instance and not the other and still be the same address.

- **no match** → create the address
- **match** → leave it exactly as it is; only the default billing / shipping
  flags can still be applied

What that buys: re-importing the same file is a no-op instead of giving every
customer a second copy of their address — the thing that makes an address import
dangerous to run twice. What it does not: an address **edited** on the source
after the first import has a different fingerprint, so it arrives as an
additional address rather than replacing the old one. That is the trade. Matching
loosely (postcode + street line 1, say) and overwriting would silently rewrite an
address a customer may have corrected here, and a CSV cannot tell the two
situations apart.

Two rows claiming `is_default_billing = 1` for one customer are an error — a file
that says so does not say what it means.

### Regions are resolved by name, not by id

`customer_address_entity.region_id` points at `directory_country_region`, whose
ids come from Magento's own data patches: they match between two installs for a
region Magento created, and do not for one an admin or a module added. So the
importer resolves `country_id` + `region` (the name, or the code) against this
install's region list first, and only falls back to the CSV's `region_id` when
the name matched nothing — with a warning, because that is the one case where a
foreign id is trusted. An unknown `country_id` is an error.

### It writes the models, and turns Magento's address validator off

Neither importer uses the repositories (`CustomerRepositoryInterface`,
`AddressRepositoryInterface`): both convert through the `Api\Data` interfaces and
drop any attribute not on them — exactly what `extra_attributes` exists to carry
— and the customer repository additionally re-derives the group, resets
`rp_token`, re-validates the default addresses, and dispatches the
extensible-object save events, which on this install reach third-party observers
(newsletter sync, the Mageplaza SMTP marketing push). A migration must notify
nobody.

The model resource save is the floor: it keeps every `setData()` value and still
enforces the two guards that matter (email present, email not already on the
website).

### The admin grid needs its own reindex, and the import does it

The Customers grid does **not** read `customer_entity` — it reads the flat index
`customer_grid_flat`, so a customer with no row there is invisible in the grid
however correct the account is. `Customer::afterSave()` looks like it handles
that but only behind two gates: the `customer_grid` index must be in state
**VALID** (it usually is not on a migration target — any `setup:upgrade` or bulk
change invalidates it, and then no reindex is even scheduled), and the index must
not be in **Update by Schedule** mode (for which `customer_grid` has no working
mview action in 2.4.9, so nothing would ever pick the row up).

So both imports reindex the rows they wrote themselves, once per run, via
`Model/Customer/GridReindexer` — a row-level reindex of ids the run already has,
so the cost scales with the import and not with the customer table, and the
index's own state is left alone. The address import needs it too, and there it is
the only thing that can work: the grid's `billing_*` columns come from the
default billing address, and the default pointers are written with a statement
that fires no model hook at all.

If the reindex itself fails it is logged, the admin screen warns, and the CLI
prints the fix — the customers are saved and correct either way:

```bash
bin/magento indexer:reindex customer_grid
```

The address save sets `should_ignore_validation`. That is considered:
`Magento\Customer\Model\ResourceModel\Address::_beforeSave()` runs the
`customer_address` validator, whose rules depend on the **target** install's
configuration (allowed countries, whether a region is required for them,
telephone rules), so it would reject addresses the source install accepted and
shipped orders to. This module's own validator is the gate instead — it checks
every `NOT NULL` column, the country code and the region, and reports per line
before anything is written. Note the customer resource's own `_validate()` is
dead code in Magento 2.4.9 (nothing calls it), which is the other half of why the
gate matters here more than anywhere else in the module.

No emails are ever sent: nothing goes through `AccountManagement`, which is what
sends the welcome and confirmation mails.

### Not covered: newsletter subscriptions

`newsletter_subscriber` is its own table, keyed by email and store, and this
module does not touch it. A migrated customer arrives with no subscription
record. Migrating subscriptions is a separate job — flagged here so nobody
assumes the customer file carried it.

### Verifying customers and addresses

Not yet exercised against live data — see
[Verifying shipments and transactions](#verifying-shipments-and-transactions) for
why (the local DB was not reachable when this was written). What to run:

```bash
bin/magento exportimport:customers:export --email=<a real customer email>
bin/magento exportimport:customers:import --file=var/export/carpetavenue_customers/<file> --validate-only
bin/magento exportimport:customer-addresses:export --email=<the same email>
bin/magento exportimport:customer-addresses:import --file=<file> --validate-only
```

Check, in this order: the exported customer's `extra_attributes` contains
`is_approved`; a create on a second install logs the customer in with their old
password; `store_code` came back non-empty for a storefront-registered account;
the addresses landed with the right `region_id` for a JP prefecture;
`default_billing` / `default_shipping` point at the right rows; the customer
appears in the admin grid straight after the import (the run reindexes its own
grid rows); and a second run of both files reports 0 created / everything
already present, with no duplicate address.

## Important: what the create path does NOT do

Order **create** trusts the CSV totals verbatim. It does **not** rebuild a quote,
recalculate totals, decrement stock, or send emails. It is a data round-trip /
migration tool, not a checkout. **Update** deliberately leaves a placed order's
financials untouched — it sets status/state, adds a history comment, and links
items to a product they had none for.

Document **create** trusts the CSV totals verbatim for the same reasons and with
the extra constraint that Magento's own services cannot be used at all — see
[Import creates documents verbatim](#import-creates-documents-verbatim--it-does-not-use-magentos-services).
Document **update** changes `state` and an item's order-item link, nothing else.
The one thing document import *does* write beyond the document itself is the
parent order's derived totals, and that is
[deliberate and switchable](#order-totals-are-recomputed-from-the-documents).

Transaction **create** and **update** write nothing outside
`sales_payment_transaction`: no order aggregate, no payment flag, and above all
no gateway call and no
[transaction auto-linking](#import-never-touches-a-gateway-or-magentos-transaction-linking).

Customer **create** and **update** send no email, subscribe nobody to the
newsletter and never overwrite a password hash that already exists here.
Address **create** never duplicates an address the customer already has, and
address **update** does not exist: a matched address is left exactly as it is,
[by design](#addresses-are-matched-by-fingerprint-never-duplicated).

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
- `Model/Order/StateGuard.php` + `Plugin/PreserveImportedOrderState.php` — keep
  Magento's `Handler\State` from rewriting an imported order's state/status on save.
- `Controller/Adminhtml/Export/{Index,Download}.php`, `Import/{Index,Validate,Submit}.php`.
- `Model/Order/StatusResolver.php` — fills an empty `order_status` / `order_state`
  from the other.
- `Console/Command/{ExportOrdersCommand,ImportOrdersCommand}.php`.
- `Console/ImportProgress.php` — step lines and progress bar for the import commands.

Invoices, credit memos and shipments:

- `Model/Document/TypeInterface.php` — everything that differs between the
  document types; the seam the shared code is written against.
- `Model/Document/Type/{AbstractType,Invoice,Creditmemo,Shipment}.php` — the
  column maps and the per-type model wiring.
- `Model/Document/Schema.php` — the same helper API as the order Schema, over
  whichever type it was given.
- `Model/Document/{Exporter,Validator,Importer}.php` — written once, one instance
  per document type (virtualTypes in `etc/di.xml`).
- `Model/Document/OrderAggregateSynchronizerInterface.php` — the seam between a
  document type and the order figures it feeds.
- `Model/Document/OrderTotalsSynchronizer.php` — rebuilds the order's
  invoiced/paid/refunded figures from the invoices and credit memos.
- `Model/Document/ShipmentQtySynchronizer.php` — rebuilds the order items'
  `qty_shipped` from the shipments.
- `Model/Document/Pool.php` — type code → exporter / validator / importer.
- `Model/ResourceModel/DocumentExtraData.php` — read-only SQL translating order
  increment ids ↔ ids and listing an order's item SKUs.
- `Model/ResourceModel/OrderDocumentTotals.php` — read-only SQL summing an
  order's documents and document items.
- `Controller/Adminhtml/Document/Export/{Index,Download}.php`,
  `Document/Import/{Index,Validate,Submit}.php` — one pair for every type, the
  type travelling in the URL.
- `Block/Adminhtml/Document/{Export,Import}/Form.php` +
  `view/adminhtml/templates/document/{export,import}/form.phtml`.
- `Console/Command/{ExportDocumentsCommand,ImportDocumentsCommand}.php`.

Payment transactions:

- `Model/Transaction/Schema.php` — the seven-column layout, the required-column
  list and the upsert key builder.
- `Model/Transaction/{Exporter,Validator,Importer}.php` — the transaction half's
  own engine; see that Schema's docblock for why it is not a document type.
- `Model/ResourceModel/TransactionExtraData.php` — read-only SQL for an order's
  payment id and existing transactions, plus the one raw `UPDATE` that restores
  `created_at` and writes the resolved `parent_id`.
- `Controller/Adminhtml/Transaction/Export/{Index,Download}.php`,
  `Transaction/Import/{Index,Validate,Submit}.php`.
- `Block/Adminhtml/Transaction/{Export,Import}/Form.php` +
  `view/adminhtml/templates/transaction/{export,import}/form.phtml`.
- `Console/Command/{ExportTransactionsCommand,ImportTransactionsCommand}.php`.

Customers and customer addresses:

- `Model/Eav/ExtraAttributes.php` — the EAV attribute codes an entity type has
  that the CSV maps do not name, plus the never-carried exclusion list. Shared by
  both entities.
- `Model/Customer/Schema.php`, `Model/CustomerAddress/Schema.php` — the column
  layouts, the required-column lists, and (addresses) the fingerprint fields.
- `Model/Customer/{Exporter,Validator,Importer}.php`,
  `Model/CustomerAddress/{Exporter,Validator,Importer}.php`.
- `Model/CustomerAddress/RegionResolver.php` — `country_id` + region name → local
  `region_id`, with the CSV id as a last resort.
- `Model/ResourceModel/CustomerExtraData.php` — read-only SQL for (email,
  website) ↔ customer id, group codes, and the existing address fingerprints,
  plus the one raw `UPDATE` that sets `default_billing` / `default_shipping`.
- `Controller/Adminhtml/Customer/Export/{Index,Download}.php`,
  `Customer/Import/{Index,Validate,Submit}.php`, and the same five under
  `Customer/Address/`. **That path is not a style choice:** Magento's
  `ActionList::get()` turns every `_` in the URL's controller segment into a `\`,
  so `exportimport/customer_address_export/index` resolves to
  `Controller\Adminhtml\Customer\Address\Export\Index` and nothing else. A
  class under `Controller/Adminhtml/CustomerAddress/` is never found — the page
  just 404s. Same rule for `document_export`, `transaction_import`, etc.
- `Block/Adminhtml/Customer/{Export,Import}/Form.php`,
  `Block/Adminhtml/CustomerAddress/{Export,Import}/Form.php` +
  `view/adminhtml/templates/{customer,customer_address}/{export,import}/form.phtml`.
- `Console/Command/{Export,Import}CustomersCommand.php`,
  `Console/Command/{Export,Import}CustomerAddressesCommand.php`.

Logs: `var/log/carpetavenue/exportimport/exportimport_YYYY-MM-DD.log`.
