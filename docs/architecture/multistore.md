# Multistore — store code in the URL path

Store views are selected by the first path segment, using Magento's native
`web/url/use_store`. **No webserver configuration is involved.**

```
website base   /en/ (default) /de/ /es/ /fr/ /it/ /hu/ /nl/ /pl/ /pt/ /fi/
website ca_dk  /dk/                                    /admin  unaffected
website ca_se  /se/ — on its own domain, carpient-se.carpetavenue.net
```

Store views on one website share cart, customer accounts and base currency; only
locale, translations, theme and CMS content vary. A country needing its own cart
or base currency must become a separate *website*, which `use_store` does not
cover — that needs `MAGE_RUN_CODE` / `MAGE_RUN_TYPE=website` from the webserver,
keyed on hostname ([below](#giving-a-website-its-own-domain)).

## Running it on another machine or in production

`docs/architecture/multistore-setup.php` applies the whole setup. It is **idempotent** and
**environment-agnostic** — it never writes a hostname, so the same invocation
works on any checkout or on production. Dry run by default.

```bash
php docs/architecture/multistore-setup.php            # print the plan, change nothing
php docs/architecture/multistore-setup.php --apply    # apply
```

Local (docker-magento) — `docs/` is not bind-mounted, so copy it in first:

```bash
bin/copytocontainer docs
bin/clinotty php docs/architecture/multistore-setup.php            # dry run
bin/clinotty php docs/architecture/multistore-setup.php --apply
```

Store views must already exist; the script reports and skips missing codes
rather than creating them (Magento core has no `store:create` CLI). Extend the
`LOCALES` map at the top of the script when adding store views. Set
`ENABLE_CANONICALS` to `false` to skip the SEO change.

The manual equivalent of every step is below, in order.

## What was done

1. **Created the `se` store view** (Swedish) — Admin → Stores → All Stores →
   Create Store View, under website `base` / the default store group. Core has
   no `store:create` CLI. `de` and the rest already existed.

2. **Removed every store-scope URL override.** A previous attempt had left
   `base_url` *and* `base_link_url` on store `de` pointing at
   `https://carpient.devs/de/`. Under `use_store` Magento appends the code on
   top of those, producing `/de/de/`.

   ```sql
   DELETE FROM core_config_data WHERE scope='stores' AND path LIKE 'web/%url%';
   ```

3. **Enabled store code in URLs and set locales.**

   ```bash
   bin/magento config:set web/url/use_store 1
   bin/magento config:set --scope=stores --scope-code=de general/locale/code de_DE
   bin/magento config:set --scope=stores --scope-code=se general/locale/code sv_SE
   ```

4. **Enabled canonical tags** — both were off. `use_store` leaves English
   reachable at both `/all-carpets` and `/en/all-carpets` (200, no redirect), so
   without these nothing tells crawlers which URL wins.

   ```bash
   bin/magento config:set catalog/seo/category_canonical_tag 1
   bin/magento config:set catalog/seo/product_canonical_tag 1
   ```

5. **Cleared orphan EAV rows** blocking all catalog config changes — see
   [Orphan `eav_attribute_label` rows](#orphan-eav_attribute_label-rows).

6. `bin/magento cache:flush`.

That is the whole setup. Final state:

```
default   web/unsecure/base_url                https://carpient.devs/
default   web/secure/base_url                  https://carpient.devs/
default   web/url/use_store                    1
default   catalog/seo/category_canonical_tag   1
default   catalog/seo/product_canonical_tag    1
stores/2  (de)  general/locale/code            de_DE
stores/11 (se)  general/locale/code            sv_SE
```

Base URLs stay at **default scope only**; Magento appends the code at runtime
(`Store::_updatePathUseStoreView()`). Static and media need no pinning — that
method applies to link-type URLs only, so `/static/` and `/media/` stay at the
root automatically. Theme is not set per store view; all inherit `Carpient Rugs`
from website scope.

## Rules that are easy to get wrong

- **No `web/…url` row may exist at store scope.** `base_link_url` is a separate
  config path from `base_url` and is the one usually missed. Audit:
  ```bash
  bin/mysql -e "SELECT scope,scope_id,path,value FROM core_config_data \
    WHERE scope='stores' AND path LIKE 'web/%url%';"   # expect zero rows
  ```
- **Store code ≠ locale.** Sweden is code `se`, locale `sv_SE`.
- **Codes must not collide** with a Magento front name (`admin`, `setup`,
  `static`, `media`, `pub`, `errors`, `rest`, `graphql`, `soap`, `health_check`)
  or any root-level category `url_key`.
- **Set URLs in the DB, never `env.php`.** Do not use `config:set --lock-env`:
  `app/etc/env.php` is gitignored and the local hostname differs per machine
  (`carpient.devs` vs `carpient.test`).
- **Flush the cache before trusting the store switcher.** Its links live in
  `block_html`/FPC; stale entries show doubled prefixes like `/es/de/` and look
  exactly like a config bug.
- **Language packs are a separate `composer require`.** Setting the locale alone
  changes date/number formatting but does not translate the frontend.
- Each store view has its **own translated URL keys** —
  `/en/oriental-carpets-nain-100x150-epr1006` vs
  `/de/orientteppiche-metz-nain-100x150-epr1006`. An English path under `/de/`
  correctly 404s; that is not a routing bug.

To add a store view, follow
[../runbooks/add-store-view.md](../runbooks/add-store-view.md) — it applies these
rules as steps.

## Verifying

```bash
for u in "" en/ de/ se/ de/all-carpets se/all-carpets admin; do
  printf "%-20s " "/$u"
  curl -sk -o /tmp/o.html -w "HTTP %{http_code}  " "https://carpient.devs/$u"
  grep -o 'lang="[a-zA-Z-]*"' /tmp/o.html | head -1
done

curl -sk https://carpient.devs/de/ | grep -c 'carpient.devs/de/de/'      # 0
curl -sk https://carpient.devs/de/ | grep -c 'carpient.devs/de/static/'  # 0
curl -sk https://carpient.devs/all-carpets | grep -o 'rel="canonical" href="[^"]*"'
```

Expected: `/` and `/en/` → `lang="en"`, `/de/` → `lang="de"`, `/se/` →
`lang="sv"`, all `200`; canonical on `/all-carpets` → `https://…/en/all-carpets`.

## Orphan `eav_attribute_label` rows

`bin/magento config:set catalog/seo/…` failed with:

```
SQLSTATE[23000]: Integrity constraint violation: 1452 … `eav_attribute_label`
```

The dev DB was imported with FK checks disabled, leaving 118
`eav_attribute_label` rows for store IDs 12, 13, 17–20, which no longer exist.
Any save to the `catalog` config section fires
`Magento\Catalog\Observer\SwitchPriceAttributeScopeOnConfigChange` → attribute
save → rewrites store labels including the dead IDs → FK violation. This blocked
**every** catalog config change, admin included.

```sql
DELETE FROM eav_attribute_label WHERE store_id NOT IN (SELECT store_id FROM store);
```

It came from the dump, not from Magento, so production may carry the same.

## Why not "bare root = English, others prefixed"

That layout was built, verified, then deliberately reverted. It required nginx
`$MAGE_RUN_CODE` maps plus prefixing `SCRIPT_NAME` so Laminas `detectBaseUrl()`
returned `/de` via its `dirname()` fallback. It worked, but depended on vendor
internals with no test or contract, needed a re-check at every Magento upgrade,
and `/en/`-style prefixes for all locales are the SEO-recommended shape anyway.
`use_store=1` costs one thing by comparison: English answers on two URLs, handled
by the canonical tags.

Two approaches that look plausible and are **not** viable, if revisited:

- **`rewrite ^/de/(.*)$ /$1 last;`** — no effect. nginx's stock `fastcgi_params`
  passes `REQUEST_URI $request_uri`, the client's *original* URI, so Magento
  still sees `/de/…` and 404s.
- **Overriding `fastcgi_param REQUEST_URI`** with the stripped path — fixes
  routing, then breaks `Magento\Store\Model\BaseUrlChecker`, which tests
  `strpos($requestUri, '/de/')`, finds nothing, and redirects to base. The
  redirect is re-stripped on arrival: **infinite 302 loop**.

`web/url/use_store` also **cannot** be scoped to exempt the default store view —
both `Store::isUseStoreInUrl()` and `StorePathInfoValidator::getValidStoreCode()`
read the flag at default scope with no store argument.

> **Cache consequence.** Because `/` belongs to no store, core resolved it from the
> `store` cookie — and it drops the store from the full-page-cache vary when
> `use_store=1`, so all store views shared one entry for `/`. One visitor with
> `store=de` cached a German homepage for everyone. Fixed by
> `CarpetAvenue\Core\Plugin\IgnoreStoreCookie`, which resolves the store view from
> the URL only; see
> [ISSUE-004](../Issues/ISSUE-004-fpc-store-vary-collapse-use-store-in-url.md).

## Giving a website its own domain

Store *views* are path-based and need no webserver config. A **website** with its
own hostname does — Magento resolves it only from `MAGE_RUN_TYPE` /
`MAGE_RUN_CODE` supplied by the webserver.

| File | Role |
|---|---|
| `docker/nginx/nginx.conf` | `map $http_host` → `$MAGE_RUN_CODE`, one line per domain. `$MAGE_RUN_TYPE` is derived from it. Unlisted hostnames get `""` = base website |
| `docker/nginx/fastcgi_extra/multistore.conf` | The two `fastcgi_param` lines |
| `nginx.conf` | PHP entry point includes `/etc/nginx/fastcgi_extra/*.conf` |

They must be included at **location** level: that block declares its own
`fastcgi_param` directives, so nginx inherits none from the server level. The
wildcard include matches nothing on the local docker-magento stack, which has no
`$MAGE_RUN_*` map — that is what keeps the shared `nginx.conf` portable.

The domain's store views leave the main domain when its base URL is set:
`https://carpient.carpetavenue.net/se/…` redirects to
`https://carpient-se.carpetavenue.net/se/…`. The `/se/` prefix survives on the
dedicated domain, because `use_store` is default-scope only (above); the root of
that domain serves `se` unprefixed as the website's default store view — the same
two-URL situation English has, covered by the canonical tags.

Step-by-step: [../runbooks/add-website.md](../runbooks/add-website.md).

## nginx file layout

Multistore needs nothing from nginx, but the layout changed while getting here:

| File | Role |
|---|---|
| `nginx.conf` | **Active config.** Non-vendor copy of the sample; identical to it apart from a header. All nginx customisation goes here. |
| `nginx.conf.sample` | Magento skeleton file, replaced on upgrade. Carries a "NOT USED" header. Never edit. |
| `docker/Dockerfile.nginx` | `COPY nginx.conf /etc/nginx/magento.conf` |
| `../compose.override.yaml` | Mounts `src/nginx.conf` for `app`, `phpfpm`, `phpfpm-xdebug` |

Stock `compose.dev.yaml` mounts `nginx.conf.sample` into all three services via
one YAML anchor; the override replaces it at the same container path.
`compose.dev.yaml` is deliberately untouched so a `bin/update` of the
docker-magento stack cannot silently revert it. After a Magento upgrade:
`diff -u nginx.conf.sample nginx.conf`.
