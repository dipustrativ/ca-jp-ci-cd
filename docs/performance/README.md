← [Knowledge base index](../README.md)

# Performance

Storefront performance work: measurements, decisions, and the reasoning behind
settings that look wrong but are deliberate.

Read the audit before changing anything in this area — several "obvious"
optimisations here were measured and rejected with numbers.

| Doc | Covers |
|---|---|
| [2026-08-21 frontend performance audit](2026-08-21-frontend-performance-audit.md) | Full baseline: server render times, Cloudflare edge, hover-image fix, `___store` fix, config audit of Valkey/OpenSearch/MySQL/PHP/nginx, HTML-minify decision, the per-tile cost curve, and a production incident post-mortem. §4's GeoIP conclusion is marked wrong in place — the real cause is [ISSUE-004](../Issues/ISSUE-004-fpc-store-vary-collapse-use-store-in-url.md) |
| [Capacity planning](capacity-planning.md) | Sizing for 30 k active / 80 k total on 1 store, and 30 k active / 150 k total on 20 stores. MySQL, OpenSearch, PHP-FPM, Valkey, disk, and the order to scale in |
| [Storefront response times](../benchmark/storefront-response-times.md) | 2026-08-22 benchmark: every page type × 4 languages, cached vs uncached, where the 200 ms category render goes (PHP 160 / DB 36 / search 7), and why JIT and N+1 batching were measured and rejected |

## Standing facts

Numbers from 2026-08-21, production (`carpient.carpetavenue.net`, GCP Brussels).

- **Origin render is fast.** Cached **18 ms** (p95 21 ms). Uncached: home 104 ms,
  product detail 128 ms, category @40/page 193–207 ms.
- **Category cost model:** `TTFB ≈ 110 ms base + 2.9 ms × products`. A catalog-free
  CMS page is 116 ms. Confirmed at 24 / 40 / 80 products per page.
- **Perceived slowness from South Asia was network, not the server.** Brussels
  origin, 226–320 ms RTT. Cloudflare proxying cut the handshake from 510 ms to
  36 ms and page total from 1030 ms to 320 ms.
- **The store is European** — EUR, default country DE, 12 store views. Brussels is
  the correct origin location.
- **A prefix-less URL resolves its store view from the URL only, never the `store`
  cookie.** That keeps `/` deterministic (English on the main domain, the website's
  default elsewhere) *and* cacheable as a single entry. Varying the page cache by
  store instead caused two production regressions —
  [ISSUE-004](../Issues/ISSUE-004-fpc-store-vary-collapse-use-store-in-url.md).
- **`Cache-Control: no-store` on every storefront response is normal.** The built-in
  FPC writes its entry and *then* calls `setNoCacheHeaders()`
  (`framework/App/PageCache/Kernel.php`, `process()`), so the browser never caches
  while Magento's own cache does. Absence of `X-Magento-Tags` is the same thing —
  it is stripped outside developer mode. Do not read either as "FPC is broken";
  measure cold-vs-warm TTFB at the origin instead.

## Settings that are deliberately "off" — do not flip without reading

| Setting | State | Why |
|---|---|---|
| `dev/template/minify_html` | **0**, locked in `docker/env.php.template` | Minified templates are written to `var/view_preprocessed` by SCD only. The persistent `magento_var` volume masks the image's `var/`, so deploys ship templates that never render, and clearing the tree renders every block empty. Measured benefit: **3.8 KB per page view** after gzip. Not worth it |
| `dev/js/merge_files`, `enable_js_bundling` | **0** | Net loss over HTTP/2; bundling defeats the theme's RequireJS lazy loading |
| `geoip/module/active` | **0** | Unused. `Mageplaza_GeoIP` has no `etc/frontend` directory, so it never touched the storefront — it was wrongly blamed on 2026-08-21 for the German-homepage bug. Real cause: [ISSUE-004](../Issues/ISSUE-004-fpc-store-vary-collapse-use-store-in-url.md) |
| OpenSearch `number_of_replicas` | **0** | Single node — replicas can never be assigned and the cluster sits yellow forever |

## Measured non-bottlenecks

Do not spend time here; all were checked on 2026-08-21 and were healthy:

- **MySQL** — buffer pool hit rate 99.97 %, no disk temp tables. (128 MB pool vs a
  527 MB DB *looks* wrong; the working set fits. This was initially misdiagnosed
  as the main problem and the data disproved it.)
- **OpenSearch** — 6 ms of a listing render, 22 ms filtered.
- **Valkey** — 92 % hit rate, zero evictions.
- **CPU / RAM** — load average 0.18, app container 0.00 %, ~0.24 req/s.
