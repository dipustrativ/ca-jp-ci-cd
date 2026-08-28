# Benchmark — Storefront response times

| | |
|---|---|
| **Date** | 2026-08-22 |
| **Question** | Where does uncached render time actually go, and can every page reach 150–180 ms uncached? |
| **Verdict** | 🟡 **Partly.** Home (101 ms) and product detail (120 ms) meet it. Category (≈200 ms) and search (≈225 ms) do not, and cannot without changing what a tile renders. Cached is **16 ms** everywhere. |
| **Method** | Origin-side, inside `carpient-app` against `nginx` — no Cloudflare, no WAN. Production, real data. |

## Method

Uncached numbers are **not** taken with `cache:disable full_page`. That writes
`env.php`, and php-fpm runs `opcache.validate_timestamps=0`, so FPM keeps serving
with FPC **on** while the CLI reports it off — the resulting numbers are fiction.

Instead: flush `full_page` **once**, then take the *first* hit of each distinct
URL. Every one is a guaranteed MISS, and nothing else in the cache is disturbed.

Appending a unique throwaway query param does **not** work either — Magento
normalises unknown query params out of the FPC identifier, so the request still
HITs.

```sh
# pass A — uncached: one flush, then first hit per URL
php bin/magento cache:clean full_page
for u in "$@"; do curl -s -o /dev/null -w '%{time_starttransfer}\n' \
  -H 'Host: carpient.carpetavenue.net' -H 'X-Forwarded-Proto: https' "http://nginx$u"; done
# pass B — cached: 5 more hits per URL, take the median
```

## Results

| URL | uncached | cached | KB |
|---|---|---|---|
| `/` | **101 ms** | 16 ms | 123 |
| `/de/` | 99 ms | 16 ms | 124 |
| `/fr/` | 101 ms | 16 ms | 124 |
| `/se/` | 104 ms | 17 ms | 124 |
| `/all-carpets` | **200 ms** | 16 ms | 350 |
| `/de/alle-teppiche` | 193 ms | 15 ms | 350 |
| `/fr/tous-tapis` | 201 ms | 18 ms | 295 |
| `/se/alla-mattor` | 196 ms | 16 ms | 295 |
| `/all-carpets?p=2` | 209 ms | 16 ms | 349 |
| `/all-carpets?product_list_order=price` | 221 ms | 18 ms | 347 |
| `/all-carpets?price=100-200` | 215 ms | 17 ms | 342 |
| product detail (en) | **121 ms** | 16 ms | 162 |
| product detail (de) | 119 ms | 16 ms | 162 |
| `/catalogsearch/result/?q=carpet` | 218 ms | 16 ms | 303 |

Language has **no** measurable effect — every store view lands within ±5 ms of
its English counterpart. Sorting and filtering each get their own cache entry and
cost ~15–20 ms more uncached than the plain listing.

## Where the time goes

| probe | uncached | note |
|---|---|---|
| `/health_check.php` | 6 ms | nginx + php-fpm floor, no Magento |
| `/contact` | 68 ms | full Magento boot + layout, 29 queries |
| 404 page | 84 ms | full boot + layout |
| `/` | 101 ms | 100 queries |
| product detail | 120 ms | 89 queries |
| `/all-carpets` | 204 ms | 195 queries |

For a 204 ms category render:

| tier | cost |
|---|---|
| MySQL (192 statements) | **36 ms** |
| OpenSearch (10 queries) | **7 ms** |
| PHP | **~160 ms** |

So it is **CPU-bound in PHP**, not I/O-bound. ~70 ms is the Magento floor that
every page pays; ~130 ms is category-specific rendering.

## Tried and rejected — with numbers

- **opcache JIT (`tracing`, 128 MB).** Made it **worse**: category 197–206 ms →
  221–222 ms, home and product detail unchanged. Tested on production via a
  drop-in ini + `docker kill --signal=USR2 carpient-app` (php-fpm graceful reload,
  zero downtime), then removed the same way. The existing "JIT intentionally OFF"
  comment in `docker/php/php.ini` is now **measured**, not assumed.
- **Batching the three N+1 query loops.** A category render issues 40 queries each
  for `catalog_product_entity_tier_price`, `catalogrule_product_price` and
  `catalog_product_super_link` — 120 of the 195 queries. Worth far less than it
  looks: MySQL round-trip from the app container is **0.033 ms**, so all 120 cost
  ~4 ms combined, and 5.3 ms of DB time. Not the bottleneck.
- **`product_list_limit`.** 1, 12 and 40 all returned the same 352 KB and
  195–203 ms — the param is overridden by the merged AjaxScroll/layered-nav
  listing, so it cannot be used to measure per-product cost.

## Why category cannot reach 180 ms

The floor is ~70 ms (any Magento page). The remaining ~130 ms is 40 product tiles,
each rendering a price box carrying Productify's JPY-ceiling and omnibus
lowest-price logic. Cutting it means rendering fewer or simpler tiles — a business
logic change, which is out of scope. DB and search are already negligible.

The user-visible number is the cached one: **16 ms at origin**. Uncached renders
only happen after a cache invalidation, so a cache warmer matters far more here
than shaving the MISS path.

## Re-run

Self-contained — paste into the app container:

```bash
docker exec -i carpient-app sh -s -- "/" "/all-carpets" "/all-carpets?p=2" \
  "/catalogsearch/result/?q=carpet" "/de/" "/de/alle-teppiche" \
  "/se/" "/se/alla-mattor" <<'EOF'
cd /var/www/html
H='-H Host:carpient.carpetavenue.net -H X-Forwarded-Proto:https'
php bin/magento cache:clean full_page >/dev/null
printf '%-42s %8s %8s\n' URL uncached cached
for u in "$@"; do
  un=$(curl -s $H -o /dev/null -w '%{time_starttransfer}' "http://nginx$u")   # first hit = MISS
  for i in 1 2 3 4 5; do
    curl -s $H -o /dev/null -w '%{time_starttransfer}\n' "http://nginx$u"
  done | sort -n | sed -n 3p | { read ca; printf '%-42s %8s %8s\n' "$u" "$un" "$ca"; }
done
EOF
```

One flush for the whole run: each URL's first hit is a guaranteed MISS, the
median of the next five is the cached number.

Re-run after every Magento/PHP upgrade and after any change to the listing,
layered navigation or price rendering.
