# CarpetAvenue_FeedExport

Product feed export (Google Shopping, PriceRunner, CSV lists). In-house replacement for the
export part of `Xtento_ProductExport`. Existing Xtento XSL templates run unchanged.

## What it does

Per **profile**: filter products of one store view → build an intermediate XML
(`<objects><object>…</object></objects>`) → apply the profile XSL template → write file(s)
to a local directory (default `pub/media/feeds/`, public at `/media/feeds/`).

Runs from: admin button, cron (per-profile expression), CLI.

Out of scope (not used by CarpetAvenue profiles): FTP/SFTP/HTTP/e-mail destinations,
category/review entities, rule-condition builder (only "SKU is one of" is supported, as SKU filter),
event-triggered exports, export history per product.

## Admin

- **CarpetAvenue → Feed Export → Export Profiles** — grid, add/edit, *Run Export Now*, delete.
  The edit page uses Xtento's left-tab layout: General Configuration, Export Destinations, Output Format,
  Stores & Filters, Category Mapping, Manual Export, Automatic Export, Profile Execution Log (latest 50 runs).
  Xtento's *Profile Export History* tab has no equivalent (per-product export history is out of scope).
- **CarpetAvenue → Feed Export → Execution Log** — every run: trigger, status, products, files, duration, error.
- **Stores → Configuration → CarpetAvenue → Feed Export** — cron master switch, batch size (500), log retention days (30).

ACL: `CarpetAvenue_FeedExport::profile`, `::run`, `::log`, `::config`.

### Profile fields

| Field | Meaning |
|---|---|
| Store View | Prices/currency/labels/URLs taken from this store (store emulation). 0 = admin values. |
| Customer Group | Group used for the `catalog_product_index_price` join (`min_price`, `final_price`…). |
| Product Status | `Enabled only` also applies CarpetAvenue_Productify ProductStatus rules (approved / online / weight > 0, per config). |
| Visibility / Exclude Product Types / In Stock Only / Only These SKUs | Collection filters. |
| XSL Template | `<files><file filename="…">` + `xsl:stylesheet`. Several `<file>` nodes = several files. Edited in an Ace editor (Xtento layout) — see *XSL Template editor* below. |
| Category Mapping | Category tree of the profile store, one taxonomy autocomplete per category (Xtento layout). Stored as JSON `{"category_id": "taxonomy path"}` → field `xtento_mapped_category` (alias `mapped_category`). |
| Taxonomy | Autocomplete source: `[Google] ja_JP` (default), `[Google] en_US`, or none (free text). Column `taxonomy_source`. |
| Line Breaks / Strip Tags / Remove ___store / Remove /pub/ | Value formatting, same as Xtento. |
| Destination Directory | Relative to Magento root; `..` and absolute paths rejected. |
| Run by Cron / Cron Expression | Store timezone; several expressions separated by `;`. |

### XSL Template editor

Same tools as Xtento's *Output Format* tab:

- **Test XSL Template** — runs the editor's *unsaved* template against one product of the *saved*
  profile (filters, store, mapping) and shows the output below. Nothing is written or logged.
  Needs a saved profile. "Product ID to test with" defaults to the first product the profile exports.
- **Serve file to browser** — also downloads the test output (several `<file>` nodes → zip).
  Kept per profile + admin user in `var/tmp/carpetavenue_feedexport/`.
- **Show fields available for export** — popup with every field of the test product as the template
  receives it (`objects/object/...`, hover = path), plus *Show raw XML*.

If Ace fails to load, the plain textarea is shown instead.

### Category mapping taxonomies

Files in `etc/data/google/` (copied from Xtento; Google product taxonomy, Xtento row format `<id> - <path>`).
To add a locale: copy the file and add it to `Model\Taxonomy::SOURCES`. The mapped value is the path
label, so switching taxonomy does not rewrite existing mappings.

Ace editor (`view/adminhtml/web/js/ace/`, BSD licence) is vendored from the Xtento module and converted to
Ace's *noconflict* form: module calls rewritten `define("ace/` → `ace.define("ace/`, `window.require([` →
`ace.require([`, `ACE_NAMESPACE="ace"`, and the tail `a.define = window.define` → `window.ace.define`.
Without that, Ace registers its modules into Magento's RequireJS and mode/theme lookups fail silently
(blank, read-only editor). `xsl-editor.js` loads the files with plain script tags, not RequireJS.
Re-apply the rewrite if the Ace files are ever replaced.

The editor and category tree are `htmlContent` blocks rendered with the module template
`form/components/html` (`bindHtml`). Magento's default content template uses the KO `html` binding,
which never runs `data-mage-init`, so the widgets would not start.

### `<file>` attributes

`filename` (variables `%Y% %y% %m% %d% %h% %i% %s% %timestamp% %collectioncount% %exportid% %uuid%`),
`encoding` (iconv target, e.g. `ISO-8859-1//TRANSLIT`), `search` / `replace` (characters replaced in values,
`quote` = `"`), `quotes` (`double` / `remove` / custom), `addUtf8Bom="1"`.

XSL parameters available when referenced: `$timestamp`, `$collectioncount`, `$exportid`, `$guid`, `$rootCategoryId`.

PHP functions callable via `php:functionString(...)`: string/number helpers only
(see `XslRenderer::ALLOWED_PHP_FUNCTIONS`).

## Data available in templates (per `objects/object`)

- every loaded product attribute (option label for select/multiselect, `<code>_raw` = stored value,
  `<code>_value_N` per multiselect option)
- `entity_id`, `created_at`, `store_id`, `original_price`, `original_store_price`, `price` (+ `price_excl_tax`,
  `price_incl_tax`), `final_price`, `min_price`, `max_price`, `special_price_active`, `cost`
- `image`, `small_image`, `thumbnail` (full media URL, + `_raw`, `_cache_url`), `images/image/url` (gallery)
- `product_url`, `xtento_mapped_category`, `attribute_set_name`, `website_codes`
- `cats/cat` (`name`, `path_name`, `children_count`, `level`, `url_key`, `product_url`, …)
- `parent_id`, `parent_item/*` (configurable/grouped parent, incl. `option_parameters_in_url`, `parent_item/cats`)
- `msi_stocks/msi_stock` (`stock_name`, `qty`, `manage_stock`), `stock/*` (legacy stock item)
- `ca_is_salable` (stock index qty + reservations − min qty, and stock index is_salable)

Only fields referenced in the template (`select="…"` / `test="…"`) are computed.
Old templates iterating `products/product` are rewritten to `objects/object` automatically.

Extend: implement `Model\Export\DataProvider\DataProviderInterface`, add it to
`ProductDataBuilder` `providers` in `etc/di.xml`.

## CLI

```bash
bin/magento carpetavenue:feedexport:list
bin/magento carpetavenue:feedexport:run 1 2 3        # or --all (enabled profiles)
bin/magento carpetavenue:feedexport:import-xtento --dry-run
bin/magento carpetavenue:feedexport:import-xtento [--keep-cron]
```

`import-xtento` copies product profiles from `xtento_productexport_profile` (template, filters, store,
category mapping, cron expression, local destination path). Idempotent (`xtento_profile_id`).
Cron is imported **off** unless `--keep-cron`, so both modules never write the same file at once.

## Cron

Group `carpetavenue_feedexport` (separate process).

- `carpetavenue_feedexport_scheduler` — every minute; runs profiles whose expression matched any minute since the
  previous tick (catch-up up to 120 min, so profiles due during a long export are not lost).
- `carpetavenue_feedexport_log_cleanup` — 03:30 daily, deletes log rows older than the configured days.

A profile never runs twice at the same time (lock `carpetavenue_feedexport_profile_<id>`).
Files are written to a temp name and renamed, so consumers never fetch a half-written feed.

## Events

- `carpetavenue_feedexport_collection_prepare` (`profile`, `collection`) — add custom collection filters.
- `carpetavenue_feedexport_after_export` (`profile`, `result`, `trigger`).

## Switching over from Xtento

1. Deploy, `setup:upgrade`, `setup:di:compile`.
2. `bin/magento carpetavenue:feedexport:import-xtento --dry-run`, then without `--dry-run`.
3. Per profile: *Run Export Now*, compare file with the Xtento one.
4. Disable cron on Xtento profiles, enable *Run by Cron* on the new ones.
5. Later, removing `xtento/productexport` also needs cleanup in `Evalent_ProductAdminMods`
   (preferences on Xtento classes) and `CarpetAvenue_ProductStatus` (Xtento plugin + `<sequence>`).
