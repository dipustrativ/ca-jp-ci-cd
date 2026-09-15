# CarpetAvenue_Japan

Home for Japan-specific (ja_JP store) logic for the CarpetAvenue Magento store.

Use this module for anything that is only true because the store operates in
Japan — locale/market rules that don't belong in the general catalog, order or
checkout modules. Examples of what fits here:

- Japanese address / name formatting (kana fields, postal-code lookup)
- JPY-specific display or rounding rules not already in `Productify` pricing
- Japan tax / consumption-tax specifics not covered by `CarpetAvenue_VAT`
- Japan-only shipping, carrier or delivery-date behaviour
- Locale-conditional emails, labels or content

Sequences after `CarpetAvenue_Core` (the shared foundation every CarpetAvenue
module builds on). Add further `<sequence>` entries in `etc/module.xml` as the
logic here starts to depend on other modules.

## Structure

Standard Magento 2 module layout — add directories as needed:

- `etc/` — `di.xml`, `events.xml`, `adminhtml/`, `frontend/`, config
- `Model/`, `Block/`, `ViewModel/`, `Observer/`, `Plugin/`, `Controller/`
- `Setup/Patch/Data/` — data patches
- `view/frontend/`, `view/adminhtml/` — templates, layout, web assets
- `Test/Unit/` — PHPUnit tests

Run `setup:upgrade` after enabling.

## Carpet Finder

A home/CMS carpet finder ported from the base store's `Evalent_CarpetFinder`
(home page + widget scope only). It shows two "steps" of quick-filter buttons
that deep-link into the **all-carpets** root category:

- **Price step** — pretty price buckets per currency (JPY `[50000, 130000]`),
  links `?price=<from>-<to>` (values in base currency).
- **Size step** — common room sizes, links `?width2=<cm>&height2=<cm>` range
  filters. Depends on the `width2` / `height2` decimal attributes being
  populated (ERP-sourced) and read by the Productify layered nav.

### Config

Stores → Config → **CarpetAvenue → Japan → Carpet Finder**
(section `japan`, group `carpet_finder`):

| Path | Meaning |
|---|---|
| `japan/carpet_finder/enabled` | Master on/off |
| `japan/carpet_finder/step_price_enabled` | Show price step |
| `japan/carpet_finder/step_size_enabled` | Show size step |
| `japan/carpet_finder/mobile_hide` | Hide on mobile |

### How it renders

`carpet_finder.xml` **declares** the `carpetfinder` block (orphan — not placed).
`cms_index_index.xml` / `cms_page_view.xml` apply that handle so the block is
available on CMS pages. It is **not** auto-placed anywhere — drop the
**"Carpet Finder block"** widget into a CMS page's content (WYSIWYG → Insert
Widget) to render it where you want. The root category comes from
`Evalent_MegaMenu` (`catalog/navigation/top_menu_root_category_id`).

### Differences from the base module (intentional)

- **No Amasty** — this store uses the custom `CarpetAvenue_Productify` layered
  nav. The Amasty-coupled category-page steps (`AttributeStep`, `SizeStep`),
  the AJAX `Plugin/Category/View`, and `carpetfinder.js` were **not** ported.
- **No `catalog_product_save_before` observer / import plugin** — populating
  `height2` / `width2` is handled upstream (ERP/IXPF sync), not here.
- Config moved from `catalog/carpet_finder/*` to `japan/carpet_finder/*`.

## Special Price Guard

Keeps `special_price` at or above a cost-derived floor, and puts the original
price back when the floor later drops.

```
floor = round(cost * multiplier + shipping * (1 + tax/100), 2)
final = max(intended special price, floor)
```

Ported from the base store's `CarpetAvenue\JPWebsiteRules\Cron\FixAttributes`,
which read a `vw_jp_specialprice` **database view**. No view is created here —
see [Why no view](#why-no-view).

### Config

Stores → Config → **CarpetAvenue → Japan → Special Price Guard**
(section `japan`, group `special_price_guard`). **Ships disabled.**

| Path | Default | Meaning |
|---|---|---|
| `japan/special_price_guard/enabled` | `0` | Master switch — off means no price ever changes |
| `japan/special_price_guard/cron_enabled` | `1` | Allow the scheduled run (the CLI ignores this) |
| `japan/special_price_guard/cron_expr` | `40 * * * *` | Schedule, read by `crontab.xml` via `<config_path>` |
| `japan/special_price_guard/cost_multiplier` | `1.5` | Cost multiplier |
| `japan/special_price_guard/shipping_base` | `100` | Flat shipping/handling in base currency (EUR) |
| `japan/special_price_guard/tax_percent` | `5` | Tax on the shipping amount only |
| `japan/special_price_guard/excluded_owners` | *(empty)* | Comma-separated `owner` values never clamped |
| `japan/special_price_guard/store_id` | `0` | Scope read and written |
| `japan/special_price_guard/max_affected_percent` | `20` | Abort a run that would reprice more than this share of the catalog |
| `japan/special_price_guard/batch_size` | `500` | Products per scan query |
| `japan/special_price_guard/log_retention_days` | `90` | Log rows older than this are pruned each run |

The defaults reproduce the base store's formula exactly: `cost * 1.5 + 100 * 1.05`.

> **Not** shared with `carpet_reports/jp/cost_multiplier` and `cost_base`, even
> though that group describes the same shape. Its `cost_base` is **60** and the
> JP royalty report reads it — changing one must not move the other.

### CLI

```bash
bin/magento carpetavenue:japan:special-price-guard [--dry-run] [--sku=A,B] [--limit=N] [--force]
```

| Option | Meaning |
|---|---|
| `--dry-run` | Decide and log, write nothing to the catalog |
| `--sku` | Restrict to these SKUs (also bypasses the blast-radius cap) |
| `--limit` | Stop after scanning N products |
| `--force` | Ignore the cap **and** the master switch |

Exits non-zero when the run aborts or a write fails.

### What gets recorded

The guard is the first user of the **shared Japan price tables**, described in
[Price action tables](#price-action-tables) below. Its rows carry
`rule_code = special_price_guard` and `attribute_code = special_price`.

Actions: `raise`, `skip`, `error`.
Reasons: `below_min`, `no_cost`, `no_special_price`, `in_range`, `error`.

**Only real actions are logged, never skips** — a run that changes nothing
writes nothing, so the table does not grow once the catalog has settled.

### The rule has no memory

It compares the live `special_price` against the floor and nothing else. There is
no record of what a price used to be, which has one consequence worth being
explicit about:

**Prices only ever move up.** If cost falls and the floor drops below the
product's original price, the product keeps the raised price. Only an ERP write
or a manual admin edit brings it back down.

This was a deliberate trade for a simpler design. The alternative — remembering
the pre-raise value so it can be restored — needs a state table, because with a
single store view the raise overwrites the only copy of the original price that
exists. See [Why not a store-view override](#why-not-a-store-view-override).

### Why not a store-view override

The base store kept the original price at store 0 and the clamp at store 17, so
it could always see both. That is impossible here:

```
catalog/price/scope     = 0   (GLOBAL)
special_price.is_global = 1   (GLOBAL)
```

With global price scope Magento reads **only** `store_id = 0`. Verified on this
install: writing `special_price = 10.00` at store 1 and reindexing left the
indexed `final_price` at `98.40` — the store-1 row is dead data.

Making the base-store design work here would mean switching
`catalog/price/scope` to *website*, which changes pricing for the entire store —
every ERP write, the price indexer, the tier-price tables, and
`CarpetAvenue_Utilities`' attribute-override tool, whose job is deleting
`store_id > 0` price rows.

### Why no view

- A view bakes in `attribute_id` literals. `owner` is **210** here and **184**
  in the base store (where 184 is `button_background_color`) — the view would
  have been silently wrong. `Scanner` resolves codes at runtime.
- A view lives only in the DB: no migration, no git history, absent after a
  fresh install or a restore.
- A view hardcodes `1.5`, `100`, `1.05` and `'Shahram'`. Changing the markup
  would mean `DROP VIEW` / `CREATE VIEW` on production.
- A view cannot be filtered or paged, so `--sku`, `--limit` and batching are
  impossible against it.

### Performance

Measured on production-shaped data (1,114 products, 11,617 decimal rows):

| | |
|---|---|
| Scan | **4.5 ms.** `special_price IS NOT NULL` makes the optimiser lead with the 690-row special-price set; everything else joins `eq_ref rows: 1`. No temp table, no filesort |
| Paging | Keyset (`entity_id > ?`), not OFFSET — page 100 costs the same as page 1 |
| First run | 253 products / 209 distinct floor values → 209 `updateAttributes` calls (grouped by value). Sub-second |
| Steady state | Zero writes. Work only appears when ERP changes a price or a config constant moves |
| Indexers | `catalog_product_price` and `productify_indexer_product` are Update by Schedule; the EAV writes land in the mview changelogs for a **partial** reindex |

The `40 * * * *` default puts the run before Productify's on-sale flag job at
`:00` and `Evalent_CatalogPriceHistory` at `:10`, both of which read
`catalog_product_index_price`.

### Rounding

The floor is computed with **bcmath, not float**. `133.19 * 1.5` evaluates to
`199.78499999999996` in binary floating point, so the half-cent tie at `304.785`
rounds *down* to `304.78` — a cent under the minimum, the one direction a floor
must never go. MySQL's exact decimal maths in the base store's view produced
`304.79`, and so does `Calculator::floorFor()`. Twelve products in the current
catalog hit this.

### Differences from the base cron (intentional)

- **Store 0, not store 17** — this install has one store view and ERP writes at
  store 0, so the raise overwrites the same row the ERP wrote.
- **Stale clamps are released.** The base view required
  `default_special_price IS NOT NULL`, so a product whose special price was
  cleared upstream dropped out of the view and kept its override forever. Here
  that product is found by `Scanner::fetchUnscanned()` and the clamp is
  removed.
- **`owner` is a LEFT join.** The base view inner-joined it, silently dropping
  every product without an owner row.
- **Owner exclusions release existing clamps.** Adding an owner to
  `excluded_owners` hands back any price the guard is still holding, rather than
  freezing it at the clamped value. Excluded owners are filtered in SQL (fewer
  rows reach PHP — 676 to 404 on the current data when `Vani` is excluded), so
  `Scanner::fetchUnscanned()` exists to find the products the main scan can no
  longer see but the rule is still governing.
- **Blast-radius cap.** Editing `cost_multiplier` would otherwise reprice the
  whole catalog in one unattended cron run.

## Price action tables

One table shared by **every Japan price rule** — the special price guard today,
any `price` or `pricing` rule tomorrow. Rules are kept apart by `rule_code` +
`attribute_code`, so one product can be under two rules at once without either
overwriting the other's memory.

| Table | Holds |
|---|---|
| `carpetavenue_japan_price_action_log` | Append-only audit — which rule, which product, which action, which reason, which run |

Admin grid: **CarpetAvenue → Price Action Log**
(`carpetavenue_japan/priceaction/index`, ACL `CarpetAvenue_Japan::price_action_log`).
Filterable by rule, attribute, action and reason, so every Japan price change
lands in one screen.

### Columns worth knowing

| Column | Meaning |
|---|---|
| `rule_code` | Which rule acted — `special_price_guard`, … |
| `attribute_code` | Attribute written — `special_price`, `price`, `pricing` |
| `threshold_value` | The limit the rule enforced (for the guard: the minimum price) |
| `basis_value` | What the threshold was derived from (for the guard: `cost`) |

Money columns are `decimal(12,4)`. These tables hold **numeric** attributes; an
attribute whose value is not a number does not belong here.

### Recording from a new rule

Depend on `PriceActionRecorderInterface`, never on the tables:

```php
use CarpetAvenue\Japan\Api\PriceActionRecorderInterface;
use CarpetAvenue\Japan\Model\PriceAction\Entry;

public function __construct(private readonly PriceActionRecorderInterface $recorder) {}

$this->recorder->logMany([
    new Entry(
        ruleCode: 'my_rule', productId: $id, attributeCode: 'price',
        storeId: $storeId, action: Entry::ACTION_RAISE, reason: Entry::REASON_BELOW_MIN,
        oldValue: $before, newValue: $after
    ),
], $runId, $dryRun);

// keep the table from growing forever, scoped to your own rule
$this->recorder->prune($days, 'my_rule');
```

Then add the rule to `Model/Source/PriceAction/RuleCode` so the grid can filter
it, and any new reason codes to `ReasonCode`.

Two rules the guard follows and a new one should too:

- **Log actions, never skips.** A run that changes nothing must write nothing,
  or the table grows by the catalog size every run.
- **Scope every query by `rule_code`.** Including the prune —
  `$recorder->prune($days, 'my_rule')` — so one rule's retention setting cannot
  delete another's history.
