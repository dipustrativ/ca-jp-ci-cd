# CarpetAvenue\_Developify

> Developer/operator tooling for the CarpetAvenue Magento store.
> **Package:** `CarpetAvenue_Developify` · **Author:** Strativ Magento Team · **© Carpet Avenue GmbH**

---

## Manual

### What is this module?

`CarpetAvenue_Developify` provides admin-side tooling aimed at developers and store operators:

- **Cron Manager** — Read-only view of `cron_schedule`, with status counts
  (System → Developify → Cron Manager).
- **Cron Jobs** — Read-only, filterable grid of every registered cron job
  (System → Developify → Cron Jobs).
- **Log Viewer** — Browse, download, and delete files under `var/log` from the admin
  (System → Developify → Log Viewer).
- **Support** — Small admin form for support/order-status operations
  (System → Developify → Support).
- **Config Scope Hints** — Flag store-config fields that are overridden at a narrower scope, with
  links straight to those scopes and the value each one holds.

---

### Cron Manager

Two separate pages, because they answer different questions from different sources:

- **Cron Manager** (`developify/cron/index`) — what *ran*. Status tiles over
  `cron_schedule`, then a grid of the table itself.
- **Cron Jobs** (`developify/cron/jobs`) — what is *supposed* to run. A filterable grid of
  every job from `Magento\Cron\Model\ConfigInterface`. A job that has never scheduled leaves
  no row in `cron_schedule`, so the schedule table alone cannot answer this.

**These are viewers and only viewers.** No dispatch, no kill, no flush, no delete, no mass
actions, no `webapi.xml`. There is no write controller to reach, so neither page can alter
cron state.

| File | Role |
|------|------|
| `Controller/Adminhtml/Cron/Index.php` | Cron Manager page |
| `Controller/Adminhtml/Cron/Jobs.php` | Cron Jobs page |
| `Block/Adminhtml/Cron/Summary.php` | Status counts, cached 60s |
| `Ui/DataProvider/CronJobDataProvider.php` | Feeds the jobs grid from cron config |
| `Ui/Component/Listing/Column/CronStatus.php` | Status → coloured badge |
| `Ui/Component/Listing/Column/Message.php` | Truncates `messages` to 200 chars |
| `Model/Config/Source/CronStatus.php` | The five statuses — filter options and badge labels |
| `Model/Config/Source/CronGroup.php` | Declared cron groups — the jobs grid's group filter |
| `view/adminhtml/ui_component/developify_cron_listing.xml` | Schedule grid |
| `view/adminhtml/ui_component/developify_cronjobs_listing.xml` | Jobs grid |
| `view/adminhtml/web/css/cron.less` | Tile and badge colours |

**Cron Jobs grid — why the data provider is hand-rolled.** Registered jobs come from every
module's `crontab.xml`, not a table, so there is nothing for the stock `SearchResult` to select
from. `CronJobDataProvider` extends `Magento\Ui\DataProvider\AbstractDataProvider` — which
supplies all the metadata plumbing — and overrides only `addFilter`, `addOrder`, `setLimit` and
`getData`, doing the work in PHP over the ~100 declared jobs. That set is bounded by the
codebase, not by store data, so it cannot grow with traffic.

Two things there are load-bearing:

- `setLimit($offset, $size)` receives a **1-based page number**, not a row offset, despite the
  parameter name — that is what `Magento\Ui\Component\Paging::getOffset()` passes. The slice is
  `($page - 1) * $size`. Reading it as a row offset silently breaks every page after the first.
- `getSearchResult()` throws instead of returning null. Only massactions and the CSV/XML
  exporters call it, and this grid has neither; the override turns a future null dereference
  into a readable message.

**Implementation notes — these are load-bearing:**

`cron_schedule` has **no `entity_id`** and exactly **one index**, `(job_code, status, scheduled_at)`.
The table also grows without bound between `cron/purgehistory` runs. Everything below follows from
that, and each point is what keeps the page from degrading as the table fills up:

- `primaryFieldName` / `requestFieldName` / `indexField` are all **`schedule_id`**. The usual
  `entity_id` does not exist here and the grid will not load if it is named.
- Default sort is `schedule_id DESC` — primary-key order, no filesort. Sorting by any date column
  *is* a filesort; that cost is only paid when a user explicitly clicks one.
- `messages` has **no filter**. It is TEXT and unindexed, so `LIKE '%…%'` on it is a full scan.
  It is also truncated server-side — a page of untruncated stack traces is megabytes of JSON.
- The summary counts run over a **fixed window of the most recent 20,000 `schedule_id`s**, not a
  time window. `MAX(schedule_id)` is an O(1) index lookup and the count is then a `PRIMARY` range
  scan with a ceiling that never moves. A `scheduled_at >= NOW() - INTERVAL 24 HOUR` predicate
  *looks* equivalent but cannot use the one index — `job_code` leads it — so it would be a full
  scan. Add an index on `scheduled_at` first if an exact time window is ever wanted.
- No `Model` / `ResourceModel` / `Collection` / `db_schema.xml` of our own. Magento's
  `Magento\Cron\Model\ResourceModel\Schedule` already declares the table and its key, so the stock
  `SearchResult` virtualType in `etc/adminhtml/di.xml` is the entire data layer.
- The status column sets `bodyTmpl` to `ui/grid/cells/html`. Without it the badge markup renders
  as escaped text.

---

### Config Scope Hints

When a store-config field is overridden at a narrower scope, an alert icon and message appear
below the field, listing the exact scopes that override it, each linking to that scope. Clicking a
scope row expands the field's value at that scope.

**No admin configuration.** It is a single `sortOrder="100"` plugin on
`Magento\Framework\Data\Form\Element\Fieldset::addField` (declared in `etc/adminhtml/di.xml`),
plus CSS/JS injected into the `adminhtml_system_config_edit` layout handle.

Hints only cover scopes *narrower* than the one being viewed — at default scope you see website
and store-view overrides; at website scope you see only store views under that website; at store
view scope there is nothing narrower, so no hints appear.

| File | Role |
|------|------|
| `ConfigScopeHints/Helper/Data.php` | Walks the scope tree, compares values, builds the hint HTML |
| `ConfigScopeHints/Plugin/Framework/Data/Form/Element/Fieldset.php` | `beforeAddField` — appends the hint to the field's `comment` |
| `view/adminhtml/layout/adminhtml_system_config_edit.xml` | Loads the CSS/JS |
| `view/adminhtml/web/css/configscopehints.less` | Collapsed/expanded styling — self-contained |
| `view/adminhtml/web/js/configscopehints.js` | Delegated click/keyboard toggle |

**Implementation notes — these are load-bearing:**

- The core field renderer prints a field comment inside `<p class="note"><span>…</span></p>`.
  The hint therefore emits **inline elements only** (`<span>`); a `<div>`/`<dl>`/`<ul>` there makes
  the browser close the `<p>` early and drops the `.note` styling. All block layout comes from CSS.
- The LESS carries its own `display: none` for `.override-value`. It must not rely on the admin
  theme's `.accordion dd` rule — that is what silently broke the expand/collapse before.
- Values of `type="obscure"` / `Magento\Config\Model\Config\Backend\Encrypted` fields render as
  `******`. `ScopeConfigInterface::getValue()` returns those **decrypted**, so the mask is what
  keeps admin passwords out of the page source.
- `showInWebsite` / `showInStore` are deliberately ignored when deciding whether to look for
  overrides. A global-only field with a stray scoped row in `core_config_data` still changes what
  the frontend reads, and the hint is the only place an admin can see it.
- The scope tree and resolved structure elements are memoised per request — the plugin runs once
  per field on the page.

**Known limitations (inherited from upstream):**

- Config groups using non-standard fieldset renderers are skipped. Among native fields that means
  **Advanced → Advanced → Disable Modules Output** and **Sales → Payment Methods**.
- Magento core bug [MAGETWO-62648](https://github.com/magento/magento2/issues/7943) can produce a
  false positive at website scope: child store views set to "Use Website" may show as overridden.

---

### Provenance

Config Scope Hints started as the vendored fork `EW_ConfigScopeHints`
([ericthehacker/magento2-configscopehints](https://github.com/ericthehacker/magento2-configscopehints)).
It was absorbed into `CarpetAvenue_Utilities`, then moved here. The upstream licence is retained at
`ConfigScopeHints/LICENSE`. It was never required through Composer, so nothing is lost from the
upgrade path.

| Was | Now |
|-----|-----|
| `EW\ConfigScopeHints\*` → `CarpetAvenue\Utilities\ConfigScopeHints\*` | `CarpetAvenue\Developify\ConfigScopeHints\*` |
| `CarpetAvenue_Utilities::{css,js}/configscopehints.*` | `CarpetAvenue_Developify::{css,js}/configscopehints.*` |
| DI plugin `carpetavenue_utilities_config_scope_hints` (global `di.xml`) | `carpetavenue_developify_config_scope_hints` (`etc/adminhtml/di.xml`) |

No config paths and no database rows are involved, so there is no data migration.

**Deploy checklist for existing environments:**

```sql
-- optional cleanup, if this row survived the original merge
DELETE FROM setup_module WHERE module = 'EW_ConfigScopeHints';
```

```bash
# stale DI metadata still points at the old FQCN and will fatal the config page
rm -rf generated/metadata generated/code/Magento/Framework/Data/Form/Element/Fieldset
bin/magento setup:upgrade --keep-generated

# stale static content under the old module path must go too
rm -rf pub/static/adminhtml var/view_preprocessed
bin/magento setup:static-content:deploy -f --area adminhtml --theme Magento/backend en_US
bin/magento cache:flush
```

---

### Dependencies

| Module            | Reason                                          |
|-------------------|-------------------------------------------------|
| `Magento_Backend` | Admin panel, backend URL builder                |
| `Magento_Config`  | Config Scope Hints — fieldset plugin target and `Config\Structure` lookups |
| `Magento_Cron`    | Cron Manager — `cron_schedule` resource model, `ConfigInterface`, `Schedule::STATUS_*` |
| `Magento_Ui`      | Cron Manager and Support — UI component listing/form                              |

---

### Layout

```
app/code/CarpetAvenue/Developify/
├── Block/Adminhtml/
│   ├── Button/SaveButton.php
│   └── Cron/Summary.php              # status tiles
├── ConfigScopeHints/
│   ├── Helper/Data.php
│   ├── Plugin/Framework/Data/Form/Element/Fieldset.php
│   └── LICENSE                       # upstream licence, retained
├── Controller/Adminhtml/
│   ├── Cron/{Index,Jobs}.php         # both read-only
│   ├── Logviewer/{Index,Fetch,Download,Delete}.php
│   └── Support/{Index,UpdateOrder}.php
├── Helper/FileScanner.php            # var/log tree
├── Model/Config/Source/{OrderStatus,Type,CronStatus,CronGroup}.php
├── Ui/
│   ├── Component/Listing/Column/{CronStatus,Message}.php
│   └── DataProvider/{EmptyDataProvider,CronJobDataProvider}.php
├── etc/
│   ├── module.xml / acl.xml
│   └── adminhtml/
│       ├── di.xml                    # Config Scope Hints plugin + cron grid data source
│       ├── menu.xml
│       └── routes.xml
└── view/
    ├── adminhtml/
    │   ├── layout/
    │   │   ├── adminhtml_system_config_edit.xml   # Config Scope Hints css/js
    │   │   ├── developify_cron_index.xml
    │   │   ├── developify_cron_jobs.xml
    │   │   ├── developify_logviewer_index.xml
    │   │   └── developify_support_index.xml
    │   ├── templates/
    │   │   ├── index.phtml           # log viewer
    │   │   └── cron/summary.phtml
    │   ├── ui_component/
    │   │   ├── developify_cron_listing.xml
    │   │   ├── developify_cronjobs_listing.xml
    │   │   └── developify_support_form.xml
    │   └── web/
    │       ├── css/{configscopehints,cron}.less
    │       └── js/configscopehints.js
    └── frontend/
        ├── requirejs-config.js
        └── web/js/osc/email-guest-mixin.js
```
