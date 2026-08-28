# Frontend performance audit — 2026-08-21

Full record of the investigation and the changes made. Written so it can be
reviewed and re-run later.

**Production:** `https://carpient.carpetavenue.net` — GCP `34.77.45.178`,
europe-west1 (Brussels), Dokploy, 3 vCPU / 10 GB.
**Reported symptom:** "site is slow on the server, fine locally."

---

## 1. Headline finding

**The origin was never slow.** It is faster than the local dev stack.

Server-side render time, measured from *inside* the server (`docker exec carpient-nginx curl 127.0.0.1`,
so zero network):

| page | uncached (FPC miss) | cached |
|---|---|---|
| `/en/` home | 103 ms | 18 ms |
| `/en/all-carpets` (24/page) | 168 ms | 18 ms |
| `/en/all-carpets` (40/page) | 193–207 ms | 18 ms |
| category + layered-nav filter | 175–191 ms | — |
| category + pagination | 193–229 ms | — |
| product detail | 121–128 ms | 17 ms |
| search | 136 ms | — |
| CMS page | 116 ms | — |

Cached p50 **18 ms**, p95 **21 ms**, max **23 ms** over 30 consecutive runs — no
variance. Load average 0.18. App container 0.00 % CPU. 146 HTTP requests in 10
minutes total (mostly the IXPF poller). For comparison, the same uncached pages
on the local dev stack: login 387 ms, cart 515 ms — i.e. **production PHP is
2–5× faster than the laptop.**

### Where the perceived ~1000 ms came from

Distance. The origin is in Brussels; the reporter is in Dhaka. Measured RTT
**226–320 ms**.

```
DNS                    3 ms
TCP handshake     +  226 ms   (1 RTT)
TLS 1.3 handshake +  284 ms   (1 RTT)
request → 1st byte+  395 ms   (1 RTT + ~170 ms server)
                   ─────────
                     908 ms   ← matched the observed 750–1000 ms
```

With a 0 ms server the floor was still ~675 ms. Three mandatory round trips at
225 ms each.

**Important context:** the store is European — base currency EUR, default country
DE, 12 active store views (`en de es fr it hu nl pl pt fi dk …`). A customer in
Frankfurt (~10 ms RTT to Brussels) was already getting ~200 ms uncached / ~50 ms
cached. The origin location is correct for the customer base; the slowness was
specific to observing it from South Asia.

---

## 2. Cloudflare (enabled during this session)

`carpient.carpetavenue.net` was a bare `A` record straight to GCP. It is now
proxied through the existing Cloudflare zone. Effect measured from Dhaka
(`cf-ray: …-DAC` = Dhaka edge):

| | before | after |
|---|---|---|
| TCP connect | 226 ms | **10 ms** |
| TLS handshake | 284 ms | **26 ms** |
| handshake total | 510 ms | **36 ms** |
| TTFB | 750–1000 ms | **319 ms** |
| page total | 1030 ms | **320 ms** |

Already in place and verified: TLS 1.3, HTTP/2, HTTP/3 (`alt-svc: h3`), Polish
(`cf-bgj: imgq:85`), static assets `Cache-Control: public, max-age=31536000, immutable`.

### Cloudflare items still worth doing

- **Origin cert renewal trap.** Origin runs Let's Encrypt via Traefik
  (`CN=carpient.carpetavenue.net`, expires **2026-10-21**). Behind a CF proxy with
  "Always Use HTTPS", the ACME HTTP-01 challenge commonly breaks and the cert
  silently fails to renew. Fix before October: either install a **Cloudflare
  Origin CA** cert on Traefik (15-year, no renewal) or switch Traefik to **DNS-01**
  with a Cloudflare API token.
- **WAF.** The apex `carpetavenue.net` issues a managed challenge
  (`cf-mitigated: challenge`). If that ruleset is zone-wide it will challenge
  Googlebot and real customers on the store hostname. Add a skip rule.
- **Apex is broken, separately.** `carpetavenue.net` returns **525** — Cloudflare
  cannot complete TLS to whatever origin it points at. Unrelated to the store.
- **Cache rules.** Bypass `/checkout`, `/customer`, `/cart`, `/admin`,
  `/ixpfsyncer`, `/rest/` first; then cache `/static/` and `/media/` respecting
  origin TTL.
- **HTML at the edge** would be the remaining big win but is **not safe on a
  standard plan**: Magento varies FPC by the `X-Magento-Vary` cookie (store view,
  currency, customer group) and sends `Cache-Control: no-store` + a fresh
  `PHPSESSID` on every HTML response. Caching HTML without `X-Magento-Vary` in
  the cache key would serve the German page to English visitors. Custom cache
  keys with cookies are Enterprise-tier.
- **Polish will not give you WebP here.** Response header reads
  `cf-polished: webp_bigger` — Cloudflare converts and finds the WebP *larger*
  than the JPEG for these detailed carpet textures, so it serves the JPEG. The
  levers for image weight are source quality and dimensions, not WebP.

---

## 3. Changes made

### 3.1 Code (in this repo — must ship in an image build to persist)

**`app/code/CarpetAvenue/Core/Helper/Product.php`**

`getProductSecondImage()` built hover-image URLs as `$mediaBaseUrl . $images[1]`
— the **raw uploaded original**, unresized. Every product tile carried a
`data-hover-image` pointing at a full-resolution file.

Measured on the live category page: **24 files, 8202 KB, average 342 KB, largest
561 KB**, fetched the moment a mouse crossed a tile.

Now builds a resized cache URL via `ParamsBuilder` + `View\Asset\ImageFactory`
at the `category_page_grid` display area (same size as the main thumbnail, so the
swap does not reflow the tile). Resize params are resolved **once per request**,
not per product — `UrlBuilder::getUrl()` re-reads `view.xml` on every call, which
cost ~19 ms across a 40-product grid.

Result on production: **40 files, 1698 KB, average 43 KB** — 87 % smaller per
image, while showing 40 products instead of 24.

Five templates route through this one helper, so all of them were fixed at once:
`Widget/…/items.phtml`, `Widget/…/sku_slider.phtml`,
`Productify/…/ajax_similar.phtml`, `Productify/…/similar_product_grid.phtml`,
and the theme's `Magento_Catalog/templates/product/list.phtml`.

> **Superseded (2026-08-25):** the `data-hover-image` attribute and the
> per-template JS `img.src` swap described here no longer exist. The second
> image is now a stacked `<img class="product-image-photo--hover">` faded in by
> CSS (`Magento_Catalog/web/css/source/_extend.less`), so it is fetched with the
> grid rather than on first mouse-over. The helper and its resized URLs are
> unchanged — still ~43 KB per image. On `(hover: none)` devices the image is
> `display: none` and, verified over CDP, **0 network requests** are made for it
> (24 of 24 skipped on a category page).

**`app/code/CarpetAvenue/Core/Plugin/StripStoreParamFromImageUrl.php`** (+ `etc/frontend/di.xml`)

Strips the `?___store=<code>` Magento core appends to every product image URL.
Full write-up: **[ISSUE-003](../Issues/ISSUE-003-catalog-image-url-store-param.md)**.
Verified: 40 → **0** of 157 media URLs carry the param.

### 3.2 Production config (in the database — survives redeploys)

| setting | from | to | why |
|---|---|---|---|
| `catalog/frontend/grid_per_page` | 24 | **40** | requested. Note `grid_per_page_values` is `24,40,50,80` — **48 is not an allowed value**, which is why `?product_list_limit=48` silently fell back to 24 |
| `geoip/module/active` | 1 | **0** | see below |

### 3.3 OpenSearch

Cluster was **yellow** — 12 unassigned shards, 64.7 % active. Single node with
`number_of_replicas: 1`, so replicas could never be assigned. Set replicas to 0
on all `magento2*` indices and added index template `magento-single-node`
(priority 100) so reindexes stay that way. Now **green, 0 unassigned, 100 %**.

---

## 4. Homepage showing German — root cause

> **This section's conclusion was WRONG.** Kept as a record of the wrong turn.
> The real cause is [ISSUE-004](../Issues/ISSUE-004-fpc-store-vary-collapse-use-store-in-url.md),
> confirmed 2026-08-22: core resolves a prefix-less URL from the `store` cookie
> and then drops the store from the full-page-cache vary when `use_store=1`, so
> all store views shared one entry for `/`.

Reported as "default domain shows Deutsch, locally fine". Initially misattributed
to Cloudflare; **direct-to-origin showed the same**, so that was wrong.

Then attributed to FPC poisoning by `Mageplaza_GeoIP`. Also wrong —
`Mageplaza_GeoIP` has no `etc/frontend` directory and cannot touch the
storefront. The symptom went away because the same command flushed the cache.

What was correct and still holds:

- Magento's own config was fine: `store_group.default_store_id = 1` = `en`.
- Uncached `/` rendered `lang="en"`; only the **cached** copy was `lang="de"`.
  A render that is right while the cached copy is wrong points at the cache key,
  not at the store resolution.

`geoip/module/active` was set to `0` — correct anyway, since the module is
unused; it just was not the fix. Setting it needed a direct `core_config_data`
write because Mageplaza does not declare the path in `system.xml`.

**Still to do:** the module is disabled but still installed. Full removal needs an
image build. `Mageplaza_Osc` references `Mageplaza\GeoIP\Helper\Address` but
guards it with `isModuleOutputEnabled`, so removal is safe.

---

## 5. HTML minification — measured, deliberately left OFF

`dev/template/minify_html` is locked to `'0'` in `docker/env.php.template:196`
with a documented reason. That lock is **correct** and was re-confirmed:

> With `minify_html` on, production resolves every `.phtml` to
> `var/view_preprocessed/pub/static/<abs path>` and only *computes* that path —
> the minified copy is written by SCD, never on demand (Magento generates it only
> in developer/default mode). `var/` is the persistent `magento_var` volume, which
> masks the image's own `var/`, so the tree outlives every deploy: a new image
> ships new templates that are never rendered (stale markup against fresh CSS),
> and deleting the tree renders every block empty — `Template::fetchView()` logs
> "Invalid template file" and returns `''`.

Measured benefit on a real category page, to justify the decision with numbers:

| | uncompressed | gzip -9 |
|---|---|---|
| as served | 360 027 B | 31 708 B |
| whitespace-collapsed | 227 346 B | 27 806 B |
| saving | −36.9 % | **−12.3 % = 3.8 KB per page view** |

**3.8 KB on the wire** against a failure mode of *every block on the site
rendering empty after a deploy*. Not worth it. Gzip already recovers most of what
the minifier would save — exactly as the original comment claimed.

If it is ever wanted, the standard fix is to stop treating a build artifact as
persistent state: stash `var/view_preprocessed` outside `var/` at build time
(e.g. `/opt/view_preprocessed`) and rsync it into place in `entrypoint.sh` on
container start, so every deploy refreshes it. That is a build-pipeline change,
not a config flip.

---

## 6. Page weight

`/en/all-carpets` at 40 products/page, after the hover fix:

| type | files | bytes |
|---|---|---|
| jpg | 75 | 4329 KB |
| webp (category banners only) | 38 | 486 KB |
| css | 6 | 122 KB |
| png | 5 | 33 KB |
| woff2 | 1 | 20 KB |
| js | 4 | 14 KB |
| svg | 4 | 8 KB |
| **total on load** | **134** | **4.90 MB** |

Already good: **152 of 158 `<img>` carry `loading="lazy"`**, so below-the-fold
images are not fetched on load.

Remaining image opportunities (none applied yet):

- Grid images are **636×840, up to 187 KB**, for a ~288 px display box (2.2×).
  `view.xml` `category_page_grid` is `640×840` — deliberate 2× retina, documented
  in the theme. Dropping to 480×630 (1.67×) would cut roughly 40 %.
- `system/upload_configuration/jpeg_quality` is **unset** (Magento default 80).
  72–75 is typically indistinguishable on photos and saves ~20 %. Changing it
  changes the resize hash, so all images regenerate on demand via `get.php`.
- **No `srcset`** anywhere — mobile downloads desktop-sized images.
- **No `fetchpriority="high"`** on the LCP image.

---

## 7. Config audit — everything checked

Requested explicitly. All of the following were verified healthy; **no changes
needed**:

| component | finding |
|---|---|
| **Valkey (cache)** | hit rate **92.3 %** (280 038 / 303 471), 53 MB of 1 GB used, **0 evictions**, `allkeys-lru`, fragmentation 1.23 |
| **Valkey (sessions)** | separate instance, db2, 1.22 MB, `volatile-lru` |
| **Cache wiring** | Valkey for default cache (db0), page_cache (db1), sessions (db2), `igbinary` serializer, gzip session compression. No Varnish — built-in FPC, fine at 18 ms |
| **MySQL 8.4** (Dokploy service, local to the box) | buffer pool 128 MB vs 527 MB DB — **but hit rate 99.97 %** (129 482 misses in 443 M reads), 1024 of 8192 pages still free, `wait_free=0`, `Created_tmp_disk_tables=0`. Working set fits; **not a bottleneck**. I initially called this out as the main problem and the data disproved it |
| **OpenSearch 3** | heap 1 GB at 28 %, 12 product indices @ 1909 docs / 2.2 MB. Was yellow → now green (see 3.3). Only **6 ms** of a listing render, 22 ms filtered — not a bottleneck |
| **PHP-FPM** | `pm=dynamic`, `max_children=20`, `start_servers=4`, `max_requests=1000` — fine for 0.24 req/s |
| **opcache** | 512 MB, 100 k files, `validate_timestamps=0`, interned strings 64 MB. JIT disabled (marginal for Magento) |
| **nginx** | gzip on (level 5), **no brotli**, no `open_file_cache`, no `$request_time` in the log format |
| **Magento** | production mode, all cache types enabled, **all 17 indexers `Ready` + `Schedule`** |
| **RabbitMQ** | briefly showed 44.6 % CPU — transient stats sweep, back to 0.18 %. All queues empty. Note: **0 consumers** on all queues (consumers run via `cron:run`) |

MySQL top statements by total latency did surface one thing worth a look, unrelated
to frontend latency:

```
ms_total  count  avg_ms   query
139933       28  4997613  INSERT INTO catalog_product_index_price_history (...)
```

**~5 seconds per insert**, 28 executions. That is `Evalent_CatalogPriceHistory`
work on cron. Also `slow_query_log` is **OFF** with `long_query_time=10` — no
visibility into anything slower than 10 s.

---

## 8. Target vs actual

Target asked for: **150 ms uncached** for home, listing and product detail,
including filters and pagination.

| page | uncached now | 150 ms target |
|---|---|---|
| home | **104 ms** | ✅ |
| product detail | **128 ms** | ✅ |
| category 40/page | 193 ms | ❌ |
| category + filter | 191 ms | ❌ |
| category + pagination | 229 ms | ❌ |

### Why the category page cannot reach 150 ms by configuration

Measured cost curve, uncached:

| products/page | TTFB |
|---|---|
| 24 | 168 ms |
| 40 | 207 ms |
| 80 | 331 ms |

That is **~2.9 ms per product tile** on a **~100–116 ms base** (a plain CMS page
with no catalog at all is 116 ms). At 40 products: 116 + 116 ≈ 207 ms.

To hit 150 ms at 40/page, per-tile cost has to drop from 2.9 ms to ~1.0 ms.
Where that time goes:

- **40 price boxes**, one per product (`data-role="priceBox"` × 40). This is
  Productify's pricing — JPY ceiling, hidden `.00`, omnibus lowest price. It is
  the most business-critical logic in the catalog.
- Not swatches — `show_swatches_in_product_list` is unset and the listing renders
  no swatch markup.
- Not OpenSearch — 6 ms.
- Not MySQL — 99.97 % buffer pool hit rate.

So reaching 150 ms means changing what each tile renders, which means touching
price rendering. **That was not done**, because the instruction was explicitly to
hit the target *without losing business logic*, and stripping or deferring price
rendering would do exactly that.

### Options that reach the goal without touching business logic

1. **Keep FPC hot** (standard practice). Uncached is 207 ms; **cached is 18 ms**.
   A cache-warmer crawler over category + filter + pagination URLs after each
   reindex/deploy means real users essentially never pay the uncached cost. This
   is the highest-value, lowest-risk option and it needs no code change.
2. **Page size 24** → 168 ms uncached. Closest config-only option, but conflicts
   with the 40/page requirement.
3. **Edge-cache HTML** → ~100 ms from Dhaka, but needs `X-Magento-Vary` in the
   Cloudflare cache key (Enterprise) — see §2.
4. **Optimise price rendering** — a real project with regression risk on prices.
   Would need its own branch, test plan and sign-off.

---

## 9. Incident — ~4 minutes of production downtime

**Caused by me, during this session.** Recorded so it is not repeated.

Running `php bin/magento setup:di:compile` **inside the live `carpient-app`
container** failed partway:

```
The directory "/var/www/html/generated/code/Magento/Customer/Model" cannot be deleted
Warning!rmdir(/var/www/html/generated/code/Magento/Catalog): Directory not empty
```

Root-owned directories under `generated/` versus the `www-data` CLI user. The
compile **wiped `generated/` without regenerating it**, and every request 500'd:

```
Error: Class "Magento\Framework\App\Config\Initial\Reader\Proxy" not found
  at vendor/magento/framework/ObjectManager/Factory/AbstractFactory.php:126
```

Recovery that worked:

```bash
docker exec -u root carpient-app sh -c \
  "rm -rf /var/www/html/generated/code/* /var/www/html/generated/metadata/*; \
   chown -R www-data:www-data /var/www/html/generated"
docker exec carpient-app sh -c "cd /var/www/html && php bin/magento setup:di:compile"
docker exec -u root carpient-app chown -R www-data:www-data /var/www/html/generated
docker restart carpient-app && sleep 18
docker exec carpient-app sh -c "cd /var/www/html && php bin/magento cache:flush"
```

**Rule going forward:** never run `di:compile` in the live container. Either build
a new image, or put the site in maintenance mode first. If a hot-patch must
happen live, write the class so the **compiled constructor signature stays valid**
— add new dependencies as optional trailing arguments — so only `docker cp` plus a
container restart is needed. (A `docker cp` of a `.php` file still needs a restart:
`opcache.validate_timestamps=0`.)

---

## 10. State of production right now

**Live and verified** — `lang="en"`, 0 `___store` params, 40 hover images all
resized, all pages HTTP 200:

- hover-image fix, `___store` plugin, GeoIP off, 40/page, OpenSearch green.

### Shipped as image v46

Built, pushed and deployed on 2026-08-21. `IMAGE_TAG` in the Dokploy stack
`carpient-app-fj43zo` moved `v45 → v46` (previous `.env` kept as `.env.bak-v45`).

> **Update `IMAGE_TAG` in the Dokploy UI too** — Dokploy regenerates
> `/etc/dokploy/compose/carpient-app-fj43zo/code/.env` from its own environment
> settings, so the next UI-driven redeploy would otherwise roll back to v45.

Verified after deploy, from the image rather than a hot patch: `setup:upgrade`
succeeded, the plugin file is present, the helper has zero `ObjectManager`
references, `grid_per_page=40` and `geoip/module/active=0` survived.

Post-deploy, after cache warm — matches pre-deploy, no regression:

| page | uncached | cached |
|---|---|---|
| home | 103 ms | 18 ms |
| category 40/page | 231 ms | 19 ms |
| category + filter | 218 ms | — |
| category + pagination | 205 ms | — |
| product detail | 129 ms | — |

Store views verified consistent: `/` → `en` (default), `/de/ /fr/ /nl/` correct,
all with 40 resized hover images, 0 raw originals, 0 `___store` params.
`/de/all-carpets` 404s **correctly** — the German store uses the localized key
`alle-teppiche` (verified in `url_rewrite`), and `/de/alle-teppiche` renders 42
products.

**`jpeg_quality` was trialled at 75** (4815 → 4293 KB per page, −11 %) and
**reverted** — too aggressive for carpet texture, where the detail is the
product. Reverted by deleting the config row rather than setting 80, so the
resize hash returns to its original value and every existing cache image is
reused.

### Next actions, in priority order

1. Cloudflare: fix the cert-renewal trap before **2026-10-21**; add the WAF skip
   for `carpient.carpetavenue.net` **and `carpient-se.carpetavenue.net`** (the
   latter still returns 403 "Just a moment…"); add the cache rules from §2.
2. Decide on the category target — realistically option 1 (cache warming) from §8.
   Confirmed 2026-08-22: ~200 ms uncached is ~160 ms of PHP tile rendering, so
   nothing short of simpler tiles moves it. JIT and N+1 batching were measured
   and rejected — [../benchmark/storefront-response-times.md](../benchmark/storefront-response-times.md).
3. Image weight: `jpeg_quality` 75, and/or `category_page_grid` 480×630, plus
   `srcset` and `fetchpriority` on the LCP image.
4. Remove `Mageplaza_GeoIP` properly — safe: `Mageplaza_Osc` guards its use of
   `Mageplaza\GeoIP\Helper\Address` with `isModuleOutputEnabled`.
5. Investigate the 5 s `catalog_product_index_price_history` insert; enable
   `slow_query_log` with `long_query_time=1`.
6. Add `mem_limit` and CPU limits to the deployed Dokploy compose.
7. Optional: brotli and `open_file_cache` in nginx; `$request_time` in the log
   format for future diagnosis.
