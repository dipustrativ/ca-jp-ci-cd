# CarpetAvenue\_Productify

> The catalog module for the CarpetAvenue store — product data, category
> presentation, layered navigation, product listing behaviour and pricing.
> **Package:** `CarpetAvenue_Productify` · **Author:** Strativ Magento Team · **© Carpet Avenue GmbH**

---

## Manual

### What is this module?

`CarpetAvenue_Productify` owns the catalog-facing behaviour of the store. It grew by absorbing
four previously separate modules, so it now covers seven areas:

| Area | What it does |
|------|--------------|
| **Group Products & Variants** | Design-ID grouping, variant references, CSV import, variant cache |
| **URL Rewrite** | Detect and generate missing product URL rewrites, CSV-driven fixes |
| **Pricing** | JPY ceiling conversion, hidden `.00` decimals, lowest-price (omnibus) tracking |
| **Product Listing** | Smart thumbnails, related carousel, similar products, BF-cache init |
| **Category Enhancements** | Category thumbnail / mobile image / short description, admin product-assignment grid, default link position |
| **Advanced Product Status** | Approved / online / weight gating across catalog, search index, sitemap |
| **Layered Navigation** | Multiselect filters, AJAX filtering, price slider, SEO filter URLs |
| **Infinite Scroll** | Load-more / infinite scroll on category, search and advanced-search listings |

The last four came from merged modules — see [Merged Modules](#merged-modules).

---

### Admin Configuration

**Path:** Stores → Configuration → CarpetAvenue → Productify

Every setting lives under the `productify/` config path. Groups:

| Group | Config path prefix | Notes |
|-------|--------------------|-------|
| General Setting | `productify/general/` | Full reindex toggle |
| Cron Job | `productify/cronjob/` | Queue processing |
| Group Products | `productify/group_products/` | CSV overwrite toggles, variant cache |
| URL Rewrite | `productify/url_rewrite/` | CSV fixed, missing URL, update URL |
| Product Schema JSON-LD | `productify/product_schema_json_ld/` | |
| Product Details Page Settings | `productify/product_details/` | Custom add-to-cart |
| Catalog Pricing | `productify/catalog_price/` | Hide zero decimals, JPY ceiling thresholds |
| Similar Product | `productify/similar_product/` | |
| Product Listing Thumbnail Behavior | `productify/smart_thumbnail/` | AI thumbnails, hover image |
| **Category Enhancements** | `productify/enhance_category/` | merged |
| **Advanced Product Status** | `productify/product_status/` | merged |
| **Layered Navigation** | `productify/layerednav/` | merged |
| **Infinite Scroll** | `productify/ajaxscroll/` | merged |

---

### Category Enhancements

Adds category attributes and admin tooling on top of `Magento_Catalog`.

**Category attributes** (Catalog → Categories → Content):

| Attribute | Installed by |
|-----------|--------------|
| `thumbnail` | `EnhanceCategory\Setup\Patch\Data\AddCategoryImageAttributes` |
| `mobile_image` | `EnhanceCategory\Setup\Patch\Data\AddCategoryImageAttributes` |
| `short_description` | `EnhanceCategory\Setup\Patch\Data\AddCategoryShortDescription` |

Productify's own patches add the Display Settings fields (`custom_title`, `megamenu_badge`,
`top_banner`, `custom_bottom_block`, `show_description_top`, `description_read_more`, …). All of
them render from the single `view/adminhtml/ui_component/category_form.xml`.

**Default product position in category**

| Field | Config path |
|-------|-------------|
| Default position on Products in Category | `productify/enhance_category/product_category_link_position` |

Magento assigns position `0` to every product added to a category. This overrides that value.
Applied through a rewrite of `Magento\Catalog\Model\Category\Link\SaveHandler` — there is no
cleaner hook for it.

**Admin category product grid** — `AssignProducts` and `Category\Grid` are preferenced to add a
thumbnail column to the product-assignment grid.

---

### Advanced Product Status

Gates product visibility on custom attributes, beyond Magento's enabled/disabled status.

| Field | Config path |
|-------|-------------|
| Product can be set offline/online | `productify/product_status/online_enabled` |
| Products must be approved | `productify/product_status/approve_enabled` |
| Products must have weight | `productify/product_status/weight_enabled` |

Attributes are installed by `ProductStatus\Setup\Patch\Data\InstallApprovedOnline`.

Enforcement points:

| Surface | Mechanism |
|---------|-----------|
| Admin product save | Observer on `catalog_product_save_before` (adminhtml only) |
| Frontend product / category | Plugins on `Catalog\Model\{Product,Category}` (frontend only) |
| Product collections | Plugin on `Catalog\Model\ResourceModel\Product\Collection` (frontend only) |
| Search index | Preference on `CatalogSearch\...\Fulltext\Action\DataProvider` |
| Sitemap | Preference on `Sitemap\Model\ResourceModel\Catalog\Product` |

```bash
bin/magento productstatus:reindex
```

> **Known dead code:** `ProductStatus/Plugin/Model/Indexer/Fulltext/Action/Full.php` is not wired
> in any `di.xml`. It was already unreferenced before the merge and is kept only so the merge diff
> stays a pure move. Remove it or wire it deliberately.

---

### Layered Navigation

A fully custom Amasty-lite replacement (Improved Layered Navigation + ShopbySeo). No Amasty code
or dependencies.

| Field | Config path |
|-------|-------------|
| Enable Enhanced Layered Navigation | `productify/layerednav/general/enabled` |
| Allow Multiple Values Per Attribute | `productify/layerednav/general/multiselect_enabled` |
| Batch Apply (Show Results Button) | `productify/layerednav/general/batch_apply` |
| Update Results Without Page Reload | `productify/layerednav/ajax/enabled` |
| Show Price Filter As Slider | `productify/layerednav/slider/enabled` |
| Slider Step | `productify/layerednav/slider/step` |
| Enable SEO Filter URLs | `productify/layerednav/seo/enabled` |
| Allow Indexing Of Single-Filter Pages | `productify/layerednav/seo/allow_index_single_filter` |
| Multi-Value Separator In URL | `productify/layerednav/seo/multi_value_separator` |

**Behaviour**

- Multiselect filters: options within one attribute combine with **OR**, attributes combine with **AND**.
- Full-page-GET AJAX with a `.column.main` DOM swap plus `pushState`. Fires `collectionUpdated`,
  which the Infinite Scroll feature listens for.
- jQuery-UI price slider; decimal attributes (`width2`/`height2`) render as a cm range slider.
- A slide-out filter modal reuses the Magento UI modal (`type: 'filter'`).
- SEO filter URLs: `/all-carpets/production_type-handmade_machine-made`. Custom router at
  **sortOrder 15** (ahead of the core urlrewrite router at 20), slugs derived from option labels,
  no DB tables, 301 from the legacy `?attr=id` form.
- SEO paths apply on **category pages only**. Price and decimal filters stay query parameters.

**Gotcha — area-level virtualType arguments replace, they do not merge.** The `categoryFilterList`
and `searchFilterList` overrides in `etc/frontend/di.xml` must repeat the stock CatalogSearch
`decimal` and `category` entries, or `FilterList` silently falls back to its non-search Catalog
defaults.

`Carpient_Filter` is a presentation-only module that reuses this feature for its data, batch AJAX,
price slider and option renderer. It sequences `CarpetAvenue_Productify`.

---

### Infinite Scroll

Loads the next page of products on scroll, with a "See more" button after a configurable page
threshold.

| Group | Config path prefix | Contents |
|-------|--------------------|----------|
| General | `productify/ajaxscroll/general/` | Enabled |
| Instances | `productify/ajaxscroll/instances/` | Categories, grid/list, layer, search, advanced search, parameter, delay |
| Selectors | `productify/ajaxscroll/selectors/` | content, pagination, toolbar, next, grid_mode, list_mode |
| Design | `productify/ajaxscroll/design/` | Loading image/text, done text, hide toolbar, local mode, buffer, load-more threshold and labels, progress bar |

Active handles: `catalog_category_view`, `catalog_category_view_type_default`,
`catalogsearch_result_index`, `catalogsearch_advanced_result` — each pulls in the shared
`ajaxscroll` handle.

> **Do not drop `layout="2columns-left"`** from `catalog_category_view.xml`. That attribute arrived
> with this feature and is the only source of it on that handle — the live category page renders
> `page-layout-2columns-left` because of it.

---

## Merged Modules

Four modules were absorbed into `CarpetAvenue_Productify`. Code moved with `git mv`, so history
follows the files. Upstream copyright headers and `AjaxScroll/LICENSE*.txt` are retained.

| Was | Now | Kind |
|-----|-----|------|
| `CarpetAvenue_EnhanceCategory` | `CarpetAvenue\Productify\EnhanceCategory\*` | first-party |
| `CarpetAvenue_LayeredNavigation` | `CarpetAvenue\Productify\LayeredNavigation\*` | first-party |
| `CarpetAvenue_ProductStatus` | `CarpetAvenue\Productify\ProductStatus\*` | first-party |
| `Lof_AjaxScroll` | `CarpetAvenue\Productify\AjaxScroll\*` | third-party (Landofcoder), app/code-only fork |

`Lof_All` — the Landofcoder licence/marketplace manager — was removed outright. It existed only to
host `Lof_AjaxScroll`'s admin menu parent and ACL resource, and its licence observer bound to a
custom `lof_check_license` event that nothing dispatched. Nothing else referenced it.

### Config paths — all settings moved under `productify/`

| Old path | New path |
|----------|----------|
| `lofajaxscroll/*` | `productify/ajaxscroll/*` |
| `carpetavenue_layerednav/*` | `productify/layerednav/*` |
| `catalog/advanced_product_status/*` | `productify/product_status/*` |
| `catalog/frontend/product_category_link_position` | `productify/enhance_category/product_category_link_position` |

Saved values are migrated automatically by
`Setup/Patch/Data/MigrateMergedModuleConfig.php`, which runs on `setup:upgrade`. The patch is
idempotent — a second run finds nothing on the old paths. It also deletes two settings whose
feature was dropped (see below).

The `dev/image/adapters/*` and other core paths are untouched.

### Other renames

| Item | Old | New |
|------|-----|-----|
| Admin config section | `carpetavenue_layerednav`, `lofajaxscroll` (tab `landofcoder`) | groups inside the `productify` section, tab `carpetavenue` |
| ACL resource for scroll config | `Lof_All::config` | `CarpetAvenue_Productify::productify_ajaxscroll` |
| Admin menu parent | `Lof_All::lof_all` | removed — settings reachable from Stores → Configuration |
| RequireJS module | `Lof_AjaxScroll/js/script` | `CarpetAvenue_Productify/js/ajaxscroll/script` |
| RequireJS map keys | `ajaxscroll`, `carpetavenueFilterAjax`, `carpetavenuePriceSlider` | **unchanged** — templates resolve them by name. `carpetavenueFilterModal` (and `js/filter-modal.js`) were dropped: nothing ever initialised the widget, so the `#filter-popup` it built never existed |
| Template refs | `CarpetAvenue_LayeredNavigation::layer/*`, `Lof_AjaxScroll::init.phtml` | `CarpetAvenue_Productify::layer/*`, `CarpetAvenue_Productify::ajaxscroll/init.phtml` |
| LESS | separate `_module.less` per module | `_layerednav.less` / `_ajaxscroll.less`, `@import`ed from `_module.less` |
| Slug cache key prefix | `carpetavenue_layerednav_slugs_` | `productify_layerednav_slugs_` |

### Dropped in the merge

Layout handles that do not exist in this installation, so the code behind them could never run:

- `similar_products_view.xml`, `vesbrand_brand_view.xml`, `vesbrand_group_view.xml`
- `init_brand.phtml`, `init_brand_group.phtml`
- config `productify/ajaxscroll/instances/enabled_brand` and `enabled_similar` (deleted by the
  migration patch — the toggles drove the handles above)

### Data patches

The three moved data patches declare their previous FQCN via `getAliases()`. Magento's
`PatchApplier` checks aliases against `patch_list`, so they do **not** re-run on stores that
already applied them under the old module name:

| Patch | Alias |
|-------|-------|
| `EnhanceCategory\...\AddCategoryImageAttributes` | `CarpetAvenue\EnhanceCategory\Setup\Patch\Data\AddCategoryImageAttributes` |
| `EnhanceCategory\...\AddCategoryShortDescription` | `CarpetAvenue\EnhanceCategory\Setup\Patch\Data\AddCategoryShortDescription` |
| `ProductStatus\...\InstallApprovedOnline` | `CarpetAvenue\ProductStatus\Setup\Patch\Data\InstallApprovedOnline` |

Aliases are plain string literals, not `::class` — the old classes no longer exist.

### Deploy checklist for existing environments

```bash
# generated/ holds interceptors for the old FQCNs; setup:upgrade will fatal
# with "Class CarpetAvenue\ProductStatus\... not found" unless it is cleared first.
rm -rf generated/code generated/metadata

# stale static content for the removed modules breaks static-content:deploy
rm -rf pub/static/frontend pub/static/adminhtml pub/static/deployed_version.txt var/view_preprocessed

bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f --area frontend --theme carpient/rugs en_US
bin/magento setup:static-content:deploy -f --area adminhtml en_US
bin/magento cache:flush
# php-fpm runs with opcache.validate_timestamps=0
docker restart carpient-phpfpm-1 carpient-phpfpm-xdebug-1
```

Optional cleanup — `Lof_All` leaves a table behind:

```sql
DROP TABLE IF EXISTS lof_all_license;
```

---

## Performance notes

Everything below runs **once per product tile**, so anything expensive in it is
multiplied by the page size (~50 tiles per listing page, more with infinite
scroll). Measured on the local stack against `/all-carpets`.

| Path | Was | Now |
|------|-----|-----|
| `Helper\CollectorTag::isCollectorProduct()` | 3 SQL queries **per tile** (156 per page) | 3 queries **per request**, answered from memory afterwards |
| `tag/collector.phtml` | inline `<style>` emitted for every tile, collector or not | styles live in `_module.less`; the template emits nothing for non-collector products |
| `Plugin\CollectorTagPlugin`, `Plugin\ProductTilePlugin` | `layout->createBlock()` per tile | one renderer block created lazily and reused |

Net effect on a full-page-cache **miss** of a 52-tile category page: ~465 ms →
~275 ms. FPC hits were already ~25 ms and are unaffected.

`CollectorTag` resolves the whole "Collectors" subtree in three queries
(categories named *Collectors* → their descendants → the products assigned to
any of them) and answers per-product lookups from that set. It is
request-scoped, not persisted, so there is no invalidation to get wrong — the
pages it feeds are themselves full-page cached.

### Similar-products AJAX (`/productify/similarto/ajax`)

HTML caching in `Controller\SimilarTo\Ajax` is **off on purpose**. The markup
embeds rendered prices, which are customer-group and tax dependent, while the
cache key was only scoped by product + store — a hit would have served one
group's prices to another. The read side had been commented out for that
reason, but the **write** was still running, so every product page paid a ~40 KB
`json_encode` + tagged cache write that nothing ever read. That write is gone;
the endpoint went ~50 ms → ~25 ms and returns byte-identical HTML.

To re-enable caching: put the customer group id (and whatever tax/VAT scope this
store varies prices by) into the key, then restore the `load()` branch —
`isCacheStillValid()` is the stock guard it used.

---

## Developer

### Architecture

```
CarpetAvenue_Productify/
├── Api/LowestPriceInterface.php
├── Block/                            # adminhtml config fields, category, pricing, widgets
├── Console/                          # BuildVariant, CampaignImport, QueueRun, ProductSync
├── Controller/
│   ├── Adminhtml/                    # Campaign, GroupProducts, Japan blacklist, RewriteURL
│   └── SimilarTo/                    # frontend ajax + index
├── Cron/                             # campaign cache, missing URLs, queue, view
├── Helper/                           # Config, Data, Product, Campaign, Currency, …
├── Model/                            # indexer, collections, variant reference, group cache
├── Observer/
├── Plugin/                           # currency, customer data, product tile, wishlist
├── Service/                          # CollectionBuilder, QueueManagement, VariantBuilder, …
├── Setup/Patch/Data/                 # product + category attributes
│   └── MigrateMergedModuleConfig.php # moves saved config onto productify/* paths
├── Ui/
├── ViewModel/
│
├── EnhanceCategory/                  # merged CarpetAvenue_EnhanceCategory
│   ├── Block/Adminhtml/Category/     # AssignProducts + product tab
│   ├── Controller/Adminhtml/Category/Grid.php
│   ├── Helper/Data.php
│   ├── Model/Category/DataProvider.php
│   ├── Rewrite/Magento/Catalog/Model/{Category.php,Category/Link/SaveHandler.php}
│   └── Setup/Patch/Data/{AddCategoryImageAttributes,AddCategoryShortDescription}.php
│
├── LayeredNavigation/                # merged CarpetAvenue_LayeredNavigation
│   ├── Controller/Router.php         # SEO filter URL router, sortOrder 15
│   ├── Model/Config.php
│   ├── Model/Layer/Filter/           # Attribute, Price, Decimal, Item, SliderFilterInterface
│   ├── Model/Search/FacetProvider.php
│   ├── Model/Seo/                    # SlugProvider, UrlBuilder, UrlParser
│   ├── Plugin/                       # CategoryViewSeoMeta, StateClearUrl
│   └── ViewModel/FilterConfig.php
│
├── ProductStatus/                    # merged CarpetAvenue_ProductStatus
│   ├── Api/                          # ConfigInterface, StatusManagerInterface
│   ├── Console/Reindex.php           # productstatus:reindex
│   ├── Model/                        # Config, StatusManager, search DataProvider, sitemap
│   ├── Observer/ProductBeforeSave.php
│   └── Plugin/Model/                 # Product, Category, Collection (+ unwired Fulltext/Full)
│
├── AjaxScroll/                       # merged Lof_AjaxScroll
│   ├── Block/Init.php
│   ├── Helper/Data.php
│   ├── Model/Config/Source/Categories.php
│   └── LICENSE.txt, LICENSE_AFL.txt  # upstream licences, retained
│
├── etc/
│   ├── module.xml / di.xml / acl.xml / config.xml / db_schema.xml
│   ├── events.xml / crontab.xml / indexer.xml / mview.xml / widget.xml
│   ├── catalog_attributes.xml
│   ├── adminhtml/{menu,routes,system,di,events}.xml
│   └── frontend/{di,routes}.xml
└── view/
    ├── adminhtml/{layout,templates,ui_component}/
    └── frontend/
        ├── requirejs-config.js       # owlcarousel + merged filter/ajaxscroll maps
        ├── layout/                   # category, search, advanced search, ajaxscroll, layered
        ├── templates/
        │   ├── ajax/init.phtml       # layered nav AJAX bootstrap
        │   ├── ajaxscroll/           # init.phtml, scripts.phtml
        │   ├── layer/                # filter.phtml, state.phtml
        │   └── …                     # category, price, product list, tile, widget
        └── web/
            ├── css/source/           # _module.less → @import _layerednav, _ajaxscroll
            └── js/                   # filter-ajax, price-slider, owl, ajaxscroll/
```

### DB tables

Declared in `etc/db_schema.xml`:

| Table | Purpose |
|-------|---------|
| `carpetavenue_productify_group_product_cache` | Serialized variant groups |
| `carpetavenue_productify_variant_reference` | Design-ID → variant map |
| `carpetavenue_productify_queue` | Async processing queue |
| `carpetavenue_productify_omni_lowest` | Omnibus lowest-price tracking |
| `carpetavenue_productify_omni_price_event` | Price change events |
| `carpetavenue_productify_collection` | Collection snapshots |
| `carpetavenue_productify_product_change_log` | Product/name change log |

The merged features add no tables. Layered Navigation deliberately stores no slugs in the DB —
they are derived from option labels and cached under `productify_layerednav_slugs_{storeId}`.

### Dependencies

| Module | Reason |
|--------|--------|
| `CarpetAvenue_Core` | Shared foundation, admin menu root, ACL parent |
| `Magento_Catalog` | Products, categories, gallery, filter items |
| `Magento_CatalogSearch` | Fulltext index, search filter list, facets |
| `Magento_LayeredNavigation` | Navigation blocks the filter renderers replace |
| `Magento_CatalogInventory`, `Magento_InventorySales`, `Magento_InventoryCatalog`, `Magento_InventoryIndexer` | Advanced Product Status stock gating |

`Magento_Sitemap` is preferenced but not sequenced — the preference is inert if the module is absent.
