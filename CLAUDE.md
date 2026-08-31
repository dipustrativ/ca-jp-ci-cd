# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Magento 2.4.9 Community Edition store (**CarpetAvenue** — an online carpet/rug
retailer, dev codename "carpient"). This directory (`.../carpetavenue-jp/src`) is
the Magento application root and its own git repo. The parent directory
(`.../carpetavenue-jp/`) holds the local Docker stack (a
[markshust/docker-magento](https://github.com/markshust/docker-magento) setup)
and its `bin/` wrapper scripts.

Two separate Docker setups exist — don't confuse them:
- **Local dev** — parent `../compose.yaml` + `../bin/*` wrappers. Containers are
  named after the parent directory, `carpetavenue-jp-*` (e.g.
  `carpetavenue-jp-phpfpm-1`) — older notes say `carpient-*`.
- **Production deploy** — `docker/` inside this repo (Dokploy + private
  registry, one image run as five roles). See `docker/README.md`. Never build
  or run the production compose files for local work.

## Running commands (local dev)

The app runs at `/var/www/html` inside `carpetavenue-jp-phpfpm-1`. The host `bin/magento`
shebang fails in this environment, so run Magento CLI through the container:

```bash
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento <cmd>"
```

The parent-dir docker-magento wrappers (`../bin/cli`, `../bin/magento`,
`../bin/copytocontainer`, `../bin/clinotty`, `../bin/mysql`, etc.) are the
intended UX when they work — `bin/cli <cmd>` runs inside the container,
`bin/copytocontainer <path>` syncs host → container.

- **DB:** `docker exec carpetavenue-jp-db-1 mariadb -umagento -pmagento magento`
- **Search:** OpenSearch at `localhost:9200`
- **Frontend:** `https://carpetavenue-jp.devs` — the local hostname differs per
  machine, so don't hardcode it. Resolve it first:

  ```bash
  docker exec carpetavenue-jp-db-1 mariadb -umagento -pmagento magento -N -B \
    -e "select value from core_config_data where path='web/secure/base_url';"
  ```

  Self-signed — always `curl -sk`. If the host returns `000`, it's missing from
  `/etc/hosts` (`127.0.0.1 ::1 carpetavenue-jp.devs`).
- Other services: `carpetavenue-jp-redis-1` (valkey), `carpetavenue-jp-rabbitmq-1`,
  `carpetavenue-jp-mailcatcher-1`. No phpmyadmin container in this stack.

### After changing code

```bash
# after DI/schema/module changes
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento setup:upgrade --keep-generated"
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:flush"
```

php-fpm runs with `opcache.validate_timestamps = 0` and `opcache.enable_cli = 0`
(`/usr/local/etc/php/conf.d/zz-magento.ini`), so **edited PHP does not take effect in
the browser until the container restarts** — CLI sees the new code, FPM keeps serving
the old bytecode. `cache:flush` is not enough. Never call a browser-facing fix verified
from a CLI-only test.

```bash
docker restart carpetavenue-jp-phpfpm-1 carpetavenue-jp-phpfpm-xdebug-1
```

Static assets do **not** regenerate on the fly reliably (requirejs-config.js
404s). After frontend/theme changes, deploy manually:

```bash
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento setup:static-content:deploy -f --area frontend --theme carpetavenue/rugs ja_JP"
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:clean full_page block_html layout"
```

For LESS work, grunt gives source maps (DevTools names the origin `.less` file
instead of `styles-m.css`) and a watch loop, so you don't redeploy static content
on every save:

```bash
docker exec -it carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && npx grunt watch:rugs"
```

`watch:rugs`, never bare `grunt watch` (two targets fight over livereload port
35729). The target is defined in `dev/tools/grunt/configs/local-themes.js`
(`carpetavenue/rugs`, `ja_JP`). Requires developer mode and a one-time
`npm install` + `grunt exec:rugs` inside the container — full setup, gotchas and
verification in `docs/runbooks/frontend-less-grunt.md`.

**Turn full-page cache off before starting the watch loop** — otherwise all
storefront JS dies on your first LESS save:

```bash
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:disable full_page && php bin/magento cache:flush"
```

Why: this repo's `watch.js` adds a `<name>Src` target that runs `exec:rugs` on
every `.less` save under `app/design` (so watch sees source edits at all).
Magento's `exec:<theme>` is `grunt clean:<theme> && bin/magento
dev:source-theme:deploy`, and `clean.js` deletes `pub/static/**/*.js` and
`deployed_version.txt` — taking `requirejs-config.js` with it. That file is
virtual: `static.php` cannot resolve it, and Magento only rewrites it during an
*uncached* page render. With FPC on, that render never happens, so every request
404s — served as `text/plain`, so the browser refuses the script and the whole
requirejs graph fails. With FPC off it regenerates on the next reload, every
time. Re-enable with `cache:enable full_page` when done.

## Lint / static analysis / tests

Dev tooling is in `require-dev` (phpstan, php-cs-fixer, phpcs with
magento-coding-standard, phpunit ^12, MFTF ^6). No repo-level `phpstan.neon`
or `phpcs.xml` — pass paths explicitly. `.php-cs-fixer.dist.php` targets
`*.phtml` only (PSR-2 + short arrays + concat spacing).

```bash
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && vendor/bin/phpcs --standard=Magento2 app/code/<Vendor>/<Module>"
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && vendor/bin/phpstan analyse app/code/<Vendor>/<Module>"
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && vendor/bin/phpunit app/code/<Vendor>/<Module>/Test/Unit/<Test>.php"
docker exec carpetavenue-jp-phpfpm-1 sh -c "cd /var/www/html && php -l <file.php>"   # quick syntax check
```

Quick browser render check (system Chrome, ignore the self-signed cert):

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new \
  --disable-gpu --ignore-certificate-errors --dump-dom https://carpetavenue-jp.devs/
  # confirm the host against base_url first — it differs per machine
```

## Git commits

No `Co-Authored-By: Claude` trailer on commits in this repo.

## Repo docs

**`docs/README.md` is the index — read it first, and add a row to it for every
new doc.** It maps tasks to documents ("build the image", "add a website", "add a
store view") and states the rules that keep the set organised. Chapters:

| Chapter | Holds |
|---|---|
| `docs/runbooks/` | Do a task. Imperative, copy-pasteable, opens with **Use when** |
| `docs/architecture/` | How something is built and why. Rejected designs belong here |
| `docs/performance/`, `docs/benchmark/` | Measurements and the decisions they drove. Every claim carries its number |
| `docs/Issues/` | Upstream defects only |
| `docker/*.md` | Infrastructure, kept next to the compose files it describes |
| `app/code/<Vendor>/<Module>/README.md`, `IXPF/Syncer/docs/` | Module-specific |

`docs/Issues/` records **upstream** defects (Adobe/Magento core, third-party
vendor, PHP version) that affect this install and can't be fixed in `app/code`.
Each file carries the verified vendor `file:line` root cause, proof local code
isn't at fault, the workaround in force, alternatives rejected and why, and a
copy-pasteable **re-check procedure** for after every Magento/PHP upgrade.

**Read `docs/Issues/` before debugging any error whose stack trace is entirely
inside `vendor/`** — it may already be documented. When a new vendor-side defect
is confirmed, add a file there rather than only fixing it in place; when an
upgrade lands, run the open issues' re-check procedures. Bugs in
CarpetAvenue/IXPF/Evalent code don't go here — fix them and let git history
record it.

When a fix lands, update the docs that recorded the old behaviour **in the same
commit**, including ones that recorded a theory since disproved.

## Architecture

Standard Magento 2 layout. Custom code lives in `app/code/<Vendor>/<Module>`;
the active custom theme is `app/design/frontend/carpetavenue/rugs` —
"CarpetAvenue Rugs", parent `Magento/blank`, locale `ja_JP`. An older
`app/design/frontend/carpient/rugs` ("Carpient Rugs") is still on disk and
registered in the `theme` table, but nothing uses it — `design/theme/theme_id`
points at `carpetavenue/rugs`. Edit the carpetavenue one. `vendor/`, `generated/`, `pub/*`, `var/*`, and
`app/etc/*` (except `config.php`) are gitignored — only custom code, the theme,
and `config.php` are tracked.

Versions of everything (Magento, PHP, services, local-vs-production differences):
`docs/architecture/stack.md`.

### Module ecosystem

Vendors and their roles:

- **CarpetAvenue** — the store's own modules. `Core` is the shared foundation
  (top-level admin menu + docs hub) every other CarpetAvenue module sequences
  after. Others: `Productify` (the whole catalog surface — see below), `Orderify`
  (order mgmt, refund fraud detection, customer blacklist), `Proforma` (proforma
  invoices), `Trackify`, `Developify`, `Widget`, `Utilities`, `ThemeContent`,
  `B2B`, `VAT`.
  Three modules have absorbed others; each documents the merge in its own README:
  `Productify` (`EnhanceCategory`, `LayeredNavigation`, `ProductStatus`,
  `Lof_AjaxScroll`), `Utilities` (`MagestyApps_WebImages`) and `Developify`
  (`EW_ConfigScopeHints` — absorbed by `Utilities` first, then moved).
  **Filesystem/module name is `CarpetAvenue` (capital A)** — some
  older notes/composer names use `carpetavenue`/`carpet-avenue`; match the
  on-disk casing (`CarpetAvenue_Core`).
- **IXPF** — `Syncer` (+ `SyncerMsi`) is the ERP integration surface: a large set
  of custom REST endpoints under `/ixpfsyncer/...` (bearer token via
  `X-Auth-Token` header) for the external IXPF system to update products, orders,
  customers, shipments, invoices, and credit memos, plus bulk PDF actions.
  **Every endpoint is documented in `app/code/IXPF/Syncer/docs/`** — read the
  relevant `.md` before touching an endpoint. Endpoints are extensible via
  after-plugins on `BulkAction::resolveActionData()`.
- **Evalent** — store-specific extensions: `MegaMenu`, `Banner`, `ProductCertificate`
  (Certificate of Authenticity PDF on orders), `MagicGallery`, `DynamicSimilarProducts`,
  `CatalogPriceHistory`, `MatrixRateMods`, `AdminActivityLog`.
  `Evalent\InvoicePaymentMods` overrides the adminhtml PDF renderer behind the
  Syncer bulk-PDF endpoints.
- **Carpient** — `Theme` (storefront view models and controllers, including the
  language switcher's redirect URLs) and `Filter` (attribute-option images).
- Third-party / vendored: `Mageplaza/{Osc,DeliveryTime,GeoIP}`,
  `BelVG/GuestWishlist`. These merged into first-party modules and no longer
  exist standalone: `MagestyApps/WebImages` → `CarpetAvenue_Utilities`;
  `EW/ConfigScopeHints` → `CarpetAvenue_Developify`; `Lof/AjaxScroll` →
  `CarpetAvenue_Productify`.
  `Lof/All` (Landofcoder licence manager) was removed outright — it only hosted
  AjaxScroll's admin menu parent and ACL resource. It leaves an unused
  `lof_all_license` table behind.

### CarpetAvenue_Productify

The catalog module. Owns group products/variants, URL rewrites, pricing (JPY
ceiling, hidden `.00`, omnibus lowest price), product-listing behaviour, plus
four merged features in sub-namespaces: `EnhanceCategory`, `LayeredNavigation`,
`ProductStatus`, `AjaxScroll`. **All settings live under the `productify/` config
path** (Stores → Config → CarpetAvenue → Productify) — the old
`carpetavenue_layerednav`, `lofajaxscroll` and `catalog/advanced_product_status`
paths were migrated by `Setup/Patch/Data/MigrateMergedModuleConfig`.
Full detail in `app/code/CarpetAvenue/Productify/README.md`.

Layered navigation is a fully custom Amasty-lite replacement (Improved Layered
Navigation + ShopbySeo) — no Amasty code or deps. Multiselect filters (OR within
an attribute, AND across), full-page-GET AJAX with `.column.main` DOM swap +
pushState (fires `collectionUpdated`, which the merged infinite scroll listens
for), jQuery-UI price slider, and SEO filter URLs
(`/all-carpets/production_type-handmade_machine-made`, custom Router at sortOrder
15, slugs from option labels, no DB tables, 301 from `?attr=id`). SEO paths apply
on category pages only; price/decimal filters stay query params. A slide-out
filter modal reuses Magento UI modal (`type: 'filter'`).

Two things that look inert but are not: `catalog_category_view.xml` carries
`layout="2columns-left"` (only source of it on that handle), and the merged data
patches declare `getAliases()` with their pre-merge FQCNs so they don't re-run.

### Magento DI gotchas (project-specific)

- Area-specific virtualType arguments **replace, not merge** — redeclaring a
  virtualType argument in `etc/adminhtml/di.xml` (etc.) drops the global values.
- The fulltext collection shares the `SearchCriteriaBuilder` singleton — be
  careful reusing it across secondary searches (e.g. facet-count queries).
