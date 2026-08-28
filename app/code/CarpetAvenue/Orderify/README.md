# CarpetAvenue\_Orderify

> Order management, fraud prevention, and regional address customization for the CarpetAvenue Magento store.
> **Package:** `CarpetAvenue_Orderify` · **Author:** Strativ Magento Team · **© Carpet Avenue GmbH**

---

## Manual

### What is this module?

`CarpetAvenue_Orderify` provides several standalone features:

- **Refund Statistics & Fraud Detection** — Displays per-order statistics (same email, shipping address, IP) to surface repeat refund patterns directly on the order view page.
- **Customer Blacklist** — Admin UI to blacklist customers by email, shipping address, or IP address. Blacklist status shown inline on order view.
- **Regional Address Settings** — Configurable state/region field behaviour for checkout and admin order creation. Includes optional-region countries, Italy VAT state warnings, and Japan field sort order.
- **Order Cancel Inventory Reindex** — Triggers an immediate, targeted MSI inventory index recalculation when an order is canceled, so salable quantities on the storefront reflect reality without waiting for the next scheduled reindex.
- **Payment Icons ("We accept" strip)** — Per-store-view payment-logo strip rendered on the cart checkout button area and reusable in CMS (e.g. the footer). Icons resolve from a media library by payment-method code and can be bulk-synced from a Google Sheet via CLI or an admin button.

---

### Admin Configuration

**Path:** Stores → Configuration → CarpetAvenue → Orderify

#### Refund Statistic In Order View

| Field      | Description                                                              |
|------------|--------------------------------------------------------------------------|
| **Enable** | Show refund statistics section on the order details page. Default: No.   |

When enabled, a "Refund Statistics" panel loads via AJAX on every order view page. It shows:

- Count of refunded orders sharing the **same shipping address**
- Count of refunded orders sharing the **same customer email**
- Count of refunded orders sharing the **same IP address**
- Whether any **blacklist entries** exist for this customer

Each section links to filtered order grids. Blacklist status is highlighted in orange.

#### Region/State Settings

| Field                          | Description                                                                                      |
|--------------------------------|--------------------------------------------------------------------------------------------------|
| **Enable Custom Region Settings** | Master toggle for optional state/region field behaviour.                                      |
| **Countries to Show Optional State/Region** | Multi-select. Region field remains visible but not required for selected countries. All others use Magento default. Shown only when Enable = Yes. |
| **Enable JP region\_id Position** | Yes/No. If Yes, forces checkout field order for Japan: `country_id → region_id → region → city`. If No, no reordering applied. |

---

### Blacklist Management

**Path:** Sales → Orderify → Address / Email / IP Blacklist

Add, view, and remove blacklist entries. Each entry records the blacklist type, value, and an optional admin note.

| Field              | Constraints                                  |
|--------------------|----------------------------------------------|
| Blacklist Type     | Required — Email / Address / IP              |
| Blacklist Value    | Auto-populated from order                    |
| Note               | Optional — reason/admin comment              |

**From Order View:**

1. Open any order.
2. In the **Refund Statistics** panel, click **Add to Blacklist** next to the relevant entry.
3. Choose type, add an optional note, submit.
4. To remove: click **Remove from Blacklist** from the same panel, or from the Blacklist grid.

**From Blacklist Grid:**

- Filter by type, value, or date.
- Remove any entry via the **Remove** action (confirmation required).

---

### Order Cancel Inventory Reindex

#### Problem

Magento MSI tracks available stock in two layers:

1. **Reservation table** (`inventory_reservations`) — records pending deductions and credits. Updated synchronously when orders are placed or canceled.
2. **Stock index table** (`inventory_stock_*`) — the pre-calculated salable quantity the storefront and cart read from. Updated by the inventory indexer, which by default runs on a schedule.

When an order is canceled, Magento releases the inventory reservation immediately. However, the index table is **not recalculated in real time**. Until the indexer runs (cron or manual), the storefront may still show a product as out-of-stock or with an incorrect quantity — causing lost sales for products that are actually available again.

#### Solution

An observer on the `order_cancel_after` event collects the parent SKUs of the canceled order and calls `SourceItemIndexer::executeList()` with the resolved source item IDs. This triggers a **targeted reindex for only the affected SKUs** — far cheaper than a full reindex — and updates `inventory_stock_*` immediately.

Exceptions are caught and logged without re-throwing, so a reindex failure **never blocks the order cancellation**.

#### Why this approach?

| Alternative | Why rejected |
|---|---|
| Full reindex on cancel | Too expensive — recalculates every product in the catalog |
| Plugin on `Order::cancel()` | Observer is cleaner; event fires after state is committed, not during |
| CLI/cron only | Delay between cancel and storefront update causes lost sales |
| Dispatching `cataloginventory_stock_item_save_after` | Does not directly affect MSI index tables (`inventory_stock_*`) |

The `SourceItemIndexer::executeList($sourceItemIds)` path is the same entry point Magento itself uses when source items are saved via the admin. It recalculates salable qty per stock per SKU and updates the index table atomically.

An added benefit: `IXPF/SyncerMsi` has an `aroundExecuteList` plugin on this same indexer, so triggering it also notifies the external MSI sync system automatically — no extra code needed.

#### Admin Configuration

**Path:** Stores → Configuration → CarpetAvenue → Orderify → Order Cancel Inventory Reindex

| Field | Description |
|---|---|
| **Enable** | Master toggle. If No, observer exits immediately and no reindex is performed. Default: Yes. |

#### Log File

All activity is written to a dedicated log file (not `system.log`):

```
var/log/orderify_inventory_reindex.log
```

**Success entry example:**
```
[Orderify] Inventory reindexed after cancel. Order #100012345 | 2 item(s): "Wool Carpet 200x300" (SKU: WC-200-300, qty: 1), "Rug Pad" (SKU: RP-200, qty: 1)
```

**Failure entry example:**
```
[Orderify] Inventory reindex failed after cancel. Order #100012345: <exception message>
```

#### Flow

```
order_cancel_after
  └─ CancelInventoryReindex::execute()
       ├─ Config disabled? → return early
       ├─ Loop getAllItems() — skip child items (getParentItemId() != null)
       ├─ array_unique(skus) — empty? → return early
       ├─ GetSourceItemsBySkuInterface::execute($sku) × N skus
       ├─ GetSourceItemIds::execute($allSourceItems)
       ├─ SourceItemIndexer::executeList($sourceItemIds)
       │    └─ IXPF/SyncerMsi plugin fires automatically (MSI webhook)
       ├─ Log success (order #, item count, names, SKUs, qtys)
       └─ catch Exception → log error, swallow
```

---

### Italy (IT) — State Warning

When an order has an Italian (`IT`) shipping address with **no state/region assigned**, a warning is injected into the order view toolbar. The warning includes links to edit both billing and shipping addresses. This prevents invoicing without the state information required for Italian VAT compliance.

---

### Japan (JP) — Checkout Field Order

When **Enable JP region\_id Position** is set to **Yes**, the Mageplaza One Step Checkout layout is intercepted and field sort orders are forced to:

```
country_id → region_id → region → city
```

Set to **No** to skip this reordering entirely (the plugin exits immediately with no changes).

---

### Optional Region — Checkout Behaviour

Countries configured under **Countries to Show Optional State/Region** will have their `region_id` and `region` checkout fields visible but not required. A JavaScript mixin removes the `required-entry` validation rule when the user selects one of these countries. All other countries retain Magento's default required/hidden behaviour.

---

### Installation

```bash
bin/magento module:enable CarpetAvenue_Orderify
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

**Requirements:** PHP 8.1+, Magento 2.4.x, Mageplaza One Step Checkout (for JP sort order plugin).

---

## Developer

### Architecture

```
CarpetAvenue_Orderify/
├── Api/
│   └── BlacklistInterface.php            # Contract: addToBlacklist, getBlackList, removeBlackList, getBlackListFromOrder
├── Block/Adminhtml/Order/
│   ├── View.php                          # Extends order view — Italy state warning injection
│   ├── RefundStatisticsSection.php       # Template block — renders AJAX trigger container
│   └── Ajax/RefundStatistic.php          # AJAX block — email/address/IP refund counts + blacklist status
├── Block/OnestepCheckout/
│   └── OptionalStateRegion.php           # Frontend block — passes optional/required countries to JS
├── Controller/Adminhtml/
│   ├── Blacklist/
│   │   ├── Index.php                     # GET — render blacklist grid
│   │   ├── Add.php                       # POST — add blacklist entry from order
│   │   └── Remove.php                    # GET — remove blacklist entry by id
│   └── Order/
│       └── RefundStatistics.php          # GET (AJAX) — returns rendered refund statistics HTML
├── Model/
│   ├── Blocklist.php                     # Implements BlacklistInterface — all blacklist business logic
│   ├── BlacklistModel.php                # Magento AbstractModel for blacklist entity
│   ├── ResourceModel/
│   │   ├── Blacklist.php                 # DB resource model → carpetavenue_order_black_list
│   │   └── Blacklist/Collection.php      # Collection class
│   └── Source/BlacklistType.php          # Dropdown source: Email / Address / IP
├── Observer/Order/
│   └── CancelInventoryReindex.php        # order_cancel_after — reindexes MSI inventory for canceled order SKUs
├── Plugins/
│   ├── Checkout/
│   │   ├── LayoutProcessor.php           # afterProcess — injects optionalCountries into JS layout (native checkout)
│   │   └── MageplazaLayoutProcessor.php  # afterProcess — JP region_id sort order (Mageplaza OSC)
│   └── Adminhtml/
│       ├── Tax/TaxCalculationCountryPlugin.php     # aroundCalculateTax — patches country for admin order create
│       └── View/ShippingStateMessagePlugin.php     # aroundGetFormattedAddress — Italy state warning
├── Ui/Component/Listing/Column/
│   └── Action.php                        # Custom action column — Remove link for blacklist grid
├── etc/
│   ├── module.xml                        # Module declaration, sequence: CarpetAvenue_Core, Magento_Sales, Magento_InventoryApi, Magento_InventoryIndexer
│   ├── di.xml                            # Preferences, plugin registration, virtual types, custom logger, collection data provider
│   ├── events.xml                        # Global observer: order_cancel_after → CancelInventoryReindex
│   ├── db_schema.xml                     # carpetavenue_order_black_list table definition
│   ├── acl.xml                           # ACL resources
│   ├── config.xml                        # Default config values
│   └── adminhtml/
│       ├── di.xml                        # Tax calculation plugin (adminhtml scope only)
│       ├── menu.xml                      # Sales → Orderify → Blacklist
│       ├── routes.xml                    # Route ID: orderify
│       └── system.xml                    # Config: fake_customer_statistic + region_settings + cancel_inventory_reindex + failed_payment_email groups
├── view/adminhtml/
│   ├── layout/
│   │   ├── orderify_blacklist_index.xml           # Blacklist grid page
│   │   ├── sales_order_view.xml                   # Injects RefundStatisticsSection into order view
│   │   └── sales_order_create_index.xml           # Injects VAT script for admin order create
│   ├── templates/order/view/
│   │   ├── fake_customer_statistics.phtml         # AJAX trigger container
│   │   └── ajax/refund_statistics.phtml           # Refund statistics modal HTML
│   ├── templates/order/create/
│   │   └── vat.phtml                              # VAT/country JS init for admin order create
│   ├── ui_component/
│   │   └── orderify_blacklist_listing.xml         # Blacklist grid definition
│   └── web/js/order/create/
│       └── scripts.js                             # Patches window.order.loadArea() — injects country params
└── view/frontend/
    ├── layout/
    │   └── onestepcheckout_index_index.xml        # Injects OptionalStateRegion block
    ├── requirejs-config.js                        # Region mixin registration
    ├── templates/onesetpcheckout/
    │   └── optional_region_state.phtml            # Inline script — passes countries config to JS
    └── web/js/
        ├── mixin/region-mixin.js                  # Mixin for region/select — removes required-entry for optional countries
        ├── view/region-validator.js               # Custom validator: validate-region-required
        └── onesetpcheckout/optional_region_state.js  # Region visibility toggle on country change
```

### Key Classes

| Class                                          | Responsibility                                                                             |
|------------------------------------------------|--------------------------------------------------------------------------------------------|
| `Model/Blocklist.php`                          | Core blacklist logic — extract email/address/IP from order, DB insert/delete/query         |
| `Block/Adminhtml/Order/Ajax/RefundStatistic.php` | Counts refunded orders by email, address, IP; fetches blacklist status for AJAX response |
| `Plugins/Checkout/LayoutProcessor.php`         | Injects `optionalCountries` into native checkout JS layout on `afterProcess`               |
| `Plugins/Checkout/MageplazaLayoutProcessor.php`| Forces JP field sort order on Mageplaza OSC layout; exits immediately if config disabled   |
| `Plugins/Adminhtml/Tax/TaxCalculationCountryPlugin.php` | Intercepts `aroundCalculateTax` — patches `country_id` from request params for correct admin-side tax |
| `Plugins/Adminhtml/View/ShippingStateMessagePlugin.php` | Injects Italy state warning into order view via `aroundGetFormattedAddress`          |
| `Api/BlacklistInterface.php`                   | Contract for all blacklist operations — preference: `Model/Blocklist.php`                  |
| `Observer/Order/CancelInventoryReindex.php`    | Observes `order_cancel_after` — extracts parent SKUs, resolves source item IDs, calls `SourceItemIndexer::executeList()`, logs result |

### DB Table: `carpetavenue_order_black_list`

Stores all customer blacklist entries. Queried on every order view page load (via AJAX) to check if the order's email, address, or IP is blacklisted.

| Column            | Type            | Notes                                         |
|-------------------|-----------------|-----------------------------------------------|
| `entity_id`       | int PK AI       |                                               |
| `blacklist_type`  | varchar(255)    | `email` / `address` / `ip`                   |
| `blacklist_value` | varchar(255)    | The blacklisted value                         |
| `note`            | text, nullable  | Admin-provided reason                         |
| `created_at`      | timestamp       | Auto-set on insert                            |

### Config Paths

| Setting                                    | Path                                           |
|--------------------------------------------|------------------------------------------------|
| Refund statistics enable                   | `orderify/fake_customer_statistic/enable`      |
| Custom region settings enable              | `orderify/region_settings/enable`              |
| Optional state/region countries            | `orderify/region_settings/optional_countries`  |
| JP region\_id sort enabled                 | `orderify/region_settings/jp_region_id_sort_enabled` |
| Cancel inventory reindex enable            | `orderify/cancel_inventory_reindex/enabled`          |

### Admin Routes

| Route                                    | Controller                          | Action                          |
|------------------------------------------|-------------------------------------|---------------------------------|
| `orderify/blacklist/index`               | `Blacklist/Index`                   | Render blacklist grid           |
| `orderify/blacklist/add`                 | `Blacklist/Add`                     | POST — add blacklist entry      |
| `orderify/blacklist/remove`              | `Blacklist/Remove`                  | Remove entry by `id`            |
| `orderify/order/refundstatistics`        | `Order/RefundStatistics`            | AJAX — return statistics HTML   |

### ACL Resources

```
CarpetAvenue_Core::carpetavenue
└── CarpetAvenue_Orderify::orderify
    ├── CarpetAvenue_Orderify::add_blacklist     ← required for Blacklist/Add
    └── CarpetAvenue_Orderify::remove_blacklist  ← required for Blacklist/Remove
```

### Dependencies

| Module / Package        | Reason                                                        |
|-------------------------|---------------------------------------------------------------|
| `CarpetAvenue_Core`     | Admin tab, shared ACL root                                    |
| `Magento_Sales`         | Order model, order view block extension                       |
| `Magento_Tax`           | Tax calculation plugin (adminhtml order create)               |
| `Magento_Directory`     | Country/region data for config source                         |
| `Mageplaza_Osc`         | Layout processor plugin for JP sort order (optional)          |
| `IXPF_Syncer`           | Order URL forwarding in refund statistics (optional — checked at runtime) |
| `Magento_InventoryApi`  | `GetSourceItemsBySkuInterface` — resolve source items by SKU  |
| `Magento_InventoryIndexer` | `SourceItemIndexer`, `GetSourceItemIds` — targeted MSI reindex |

---

## Payment Icons ("We accept" strip)

Per-store-view payment-logo strip. Same config drives two render locations:

1. **Cart checkout button area** — the "Nous acceptons" strip under the
   "Commander" / "To Checkout" button (rendered by `Evalent_CheckoutMods`,
   template `onepage/link.phtml`).
2. **Anywhere else, incl. CMS** — via the block directive below (e.g. the footer
   `footer-payment-logos` blocks).

Icons live in a media library and are matched to payment methods **by filename =
method code**. The whole per-store mapping can be bulk-synced from a Google Sheet.

### Admin Configuration

**Path:** Stores → Configuration → CarpetAvenue → Orderify → **Payment Icons**

| Field | Scope | Description |
|-------|-------|-------------|
| **Enabled** | Store View | Master toggle for the checkout strip on this store view. |
| **Heading** | Store View | Strip heading, e.g. "Nous acceptons". Blank = no heading. |
| **Icons** | Store View | Dynamic rows (label + icon). Drag to reorder. "Choose / Upload" browses the library or uploads a new SVG/PNG. Usually written by the sheet sync. |
| **Google Sheet ID** | Default | Document id from the sheet URL. Sharing must be "Anyone with the link". |
| **Google Sheet Grid ID** | Default | Tab `gid`. `0`/blank = first tab (auto-handled for uploaded .xlsx). |
| **Update from Sheet** | Default | Button — rebuilds every listed store view's strip from the sheet. |

Config path: `orderify/payment_icons/{enabled,heading,icons,sheet_id,gid}`.

### Icon library (media)

Files live in **`pub/media/payment_icons/`**. A file is matched to a sheet row
by `method_code` → `payment_icons/{code}.svg`, then `.png` (svg preferred).

Filenames must equal the Adyen brand/method code. Required for the current
sheet:

| Method | File needed | In repo source `TASK/LOGOS/` |
|--------|-------------|------------------------------|
| Visa | `visa.svg` | yes |
| Mastercard | `mastercard.svg` | yes |
| Apple Pay | `apple_pay.png` | yes |
| Klarna | `klarna.svg` | yes |
| Carte Bleue | `cartebancaire.png` | yes (png only) |
| iDEAL | `ideal.svg` | yes |
| Vipps | `vipps.svg` | yes |
| Blik | `blik.svg` | yes |
| TWINT | `twint.svg` | yes |
| Swish | `swish.svg` | yes |
| MobilePay | `mobilepay.svg` | yes |
| Online Banking | `paybybank.svg` | yes (generic bank icon) |
| Sofort | _none_ | n/a — disabled in sheet (Adyen merged it into Klarna) |

> **Note:** `pub/media` is a separate Docker volume — host copies are not visible
> to the container. Seed with `docker cp pub/media/payment_icons/<file>
> <container>:/var/www/html/pub/media/payment_icons/<file>`.

To add a **new** payment method: drop `code.svg|png` into the folder (filename =
the code you put in the sheet's `method_code`), then re-run the sync. No code
change needed. SVG uploads via the admin chooser are sanitized.

### Google Sheet sync

Sheet is the source of truth. Each listed store view's icons are **replaced**;
unlisted store views and the heading are left untouched.

**Sheet columns** (header row, case-insensitive; **column order does not matter**
— rows are matched by header name, not position):

| Column | Required | Purpose |
|--------|----------|---------|
| `store_view_code` | yes | Join key — must equal a Magento store view code. |
| `method_code` | yes | Resolves `payment_icons/{code}.svg\|png`. |
| `label` | no | Alt text (falls back to `method_code`). |
| `position` | no | Ascending order (falls back to row order). |
| `enabled` | no | `1` keep / `0` skip (blank = keep). |
| `store_name` | no | Human label — **ignored** by the importer. |

Rows with an unknown store view, a missing icon file, or `enabled=0` are skipped
and logged; one bad row never aborts the run.

**Run it:**

```bash
# Preview without writing:
bin/magento orderify:payment-icons:update --dry-run

# Apply:
bin/magento orderify:payment-icons:update
```

…or click **Update from Sheet** in the admin config. Both run the exact same
importer.

### How a sync run behaves

Every run (CLI or button) sorts each store view's rows by `position`, builds the
icon set, and classifies the outcome per store / per row:

| Outcome | Meaning | Error? | Action |
|---------|---------|--------|--------|
| **updated** | The store's icon set (or its enabled flag) **changed** vs what is saved. | No | Config written for that store. |
| **unchanged** | The store's icon set is **identical** to what is already saved. | No (logic) | **Write skipped** — nothing touched. |
| **disabled** | Row has `enabled=0`. | No (intentional) | Row excluded; not counted as a failure. |
| **failed** | Row's `store_view_code` is unknown, `method_code` has no icon file, or a required cell is blank. | **Yes** | Row dropped; admin notification raised. |

**Idempotent — only changed store views are written.** If nothing changed, no
config is saved and **caches are not flushed**. Re-running back-to-back is safe
and cheap. A hard failure (bad Sheet ID, unreachable sheet, missing required
columns) aborts the whole run, raises a **critical** admin notification, and
rethrows — no partial writes.

### Logging

Everything goes to a single daily file:

```
var/log/carpetavenue/orderify/payment_icons_YYYY-MM-DD.log
```

Each run logs: start (row count + source URL), every store's
updated/unchanged state with the resolved icon paths, whether caches were
flushed, a `WARNING` line per failed row, and a final summary
(`N updated, N unchanged, N disabled, N FAILED`).

### Admin notifications

Raised in the admin inbox (🔔 top-right bell / **System → Notifications**):

- **Major** — one or more rows **failed** to import. Lists each failing row,
  the full updated/unchanged/disabled/failed counts, a hint on the cause, and
  the log path. Fires from CLI, the admin button, and cron.
- **Critical** — the run **aborted** (sheet unreachable, bad config, missing
  columns).

Notifications fire on **real runs only** — never on `--dry-run`. `enabled=0`
(disabled) and `unchanged` are **not** failures, so they never notify.

**Sample / backup template:** `resource/PAYMENT_ICONS_import_template.xlsx`
(shipped with the module — Option B long format, with `reference`, `store_views`
and `readme` tabs). If the live Google Sheet is ever lost, re-upload this file to
Google Drive and set its new Sheet ID in config. Column order is free — the
sample has `store_name` first, but the importer matches by header name, so any
arrangement works.

> The importer reads **only** from the configured Google Sheet (server-side
> fetch). The bundled `.xlsx` is a re-seed source, not auto-read at runtime.

### CMS / footer block

Render the strip anywhere via a block directive:

```
{{block class="CarpetAvenue\Orderify\Block\PaymentIcons" align="left" css_class="footer"}}
```

| Param | Values | Default | Purpose |
|-------|--------|---------|---------|
| `align` | `left` / `center` / `right` | `center` | Horizontal alignment of the row. |
| `css_class` | any class name(s) | _none_ | Extra wrapper class for host spacing tweaks. |
| `show_heading` | `1` | off | Also render the configured heading (off so a host `<h3>` stays the title). |

Markup uses the namespaced `.ca-payment-icons` classes (CSS in
`view/frontend/web/css/payment-icons.css`, loaded site-wide via `default.xml`),
independent of the checkout strip's styles. Example host tweak:
`.ca-payment-icons.footer { padding-top: 10px; }`.

### Files

| Path | Role |
|------|------|
| `Model/PaymentIcon/IconRepository.php` | Media list / URL / `resolveByCode()` (filename match). |
| `Model/PaymentIcon/SvgSanitizer.php` | Strips script/handlers from uploaded SVG. |
| `Model/PaymentIcon/SheetImporter.php` | Fetch + parse sheet → idempotent per-store write, categorise, log, notify. |
| `Logger/Handler/PaymentIconsLog.php` | Daily log file `var/log/carpetavenue/orderify/payment_icons_*.log` (virtualType `PaymentIconsLogger` in `di.xml`). |
| `Console/Command/UpdatePaymentIconsCommand.php` | `orderify:payment-icons:update [--dry-run]`. |
| `Controller/Adminhtml/Icon/{Listing,Upload,Update}.php` | Chooser list / upload / sheet-sync endpoints. |
| `Block/Adminhtml/System/Config/Form/Field/{Icons,IconColumn,UpdateButton}.php` | Admin dynamic-rows + button. |
| `Block/PaymentIcons.php` + `view/frontend/templates/payment-icons.phtml` | Reusable frontend / CMS strip. |

### Dependencies (Payment Icons)

| Module / Package | Reason |
|------------------|--------|
| `Magento_Config` | System config dynamic-rows + serialized backend. |
| `Magento_MediaStorage` | SVG/PNG uploader. |
| `Magento_AdminNotification` | Admin inbox notifications on failed/aborted imports. |
| `Evalent_CheckoutMods` | Renders the strip in the cart checkout-button area. |
