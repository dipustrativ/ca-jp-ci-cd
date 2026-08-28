# Production cache runbook

How to diagnose and fix "cache is not working" on the Dokploy production stack.

**Read §1 before deploying anything.** The config changes in §2 were made from a
static review of this repo — no production runtime data was available at the time.
They are correct hardening, but they are not proven to be *your* bug. §1 is how you
find out which cause you actually have.

---

## 0. The 2.4.9 silent filesystem fallback — check this FIRST (found 2026-08-17)

Magento 2.4.9 dropped the Zend cache stack for Symfony adapters. The `backend` name in
`env.php` is now looked up in `SymfonyAdapterProvider::$adapterTypeMap`, which knows
only:

```
redis  valkey  memcached  libmemcached  file  database  apc  apcu  two_levels  twolevel
```

Anything else resolves to `'filesystem'` (`SymfonyAdapterProvider.php:139`) — a **file
cache under `var/cache`** — with no error, no warning, nothing in the log. `Cm_Cache_Backend_Redis`,
which this stack used until now, is one of those "anything else". A failing Redis
connection degrades identically: the `try`/`catch` at `:151` swallows it and returns a
FilesystemAdapter.

So the whole stack was running on files on the shared `magento_var` volume across all
five containers, while Valkey sat there holding nothing but sessions and stale pre-upgrade
keys. That is the "flush works sometimes" bug: file-backed tag invalidation across five
containers writing the same directory.

**Config never proves this. Only the live object does:**

```bash
docker exec $APP php -r 'require "app/bootstrap.php";
  $om = Magento\Framework\App\Bootstrap::create(BP,$_SERVER)->getObjectManager();
  $b  = $om->get(Magento\Framework\App\Cache\Frontend\Pool::class)->get("default")
           ->getLowLevelFrontend()->getBackend();
  $p  = (new ReflectionObject($b))->getProperty("adapter");
  echo get_class($p->getValue($b)), PHP_EOL;'
```

| Output | Meaning |
|---|---|
| `...SymfonyAdapters\GenericTagAdapter` wrapping `RedisAdapter` | correct |
| anything wrapping `Symfony\Component\Cache\Adapter\FilesystemAdapter` | **Valkey is not in use** — you are on files |

Fixed in `docker/env.php.template` by using `backend => 'redis'`. Needs an image rebuild.
Note the Cm-era options went away with it: `compress_data`, `compression_lib`,
`compress_threshold` and `use_lua` are **not read** by the Symfony adapter — see the
correction in §2.

---

## 1. Diagnose first — four commands

Container names on Dokploy are generated. Find them:

```bash
docker ps --format '{{.Names}}\t{{.Image}}' | grep -Ei 'carpient|valkey'
```

Then set them once:

```bash
APP=<carpient-...-app-1>
CRON=<carpient-...-cron-1>
VK=<carpient-...-cache-1>
VKPASS=<VALKEY_PASSWORD from Dokploy env>
```

Run all four. Each maps to a different root cause — they are not alternatives.

### 1a. Is the built-in FPC even switched on?

```bash
docker exec $APP php bin/magento config:show system/full_page_cache/caching_application
```

| Output | Meaning | Action |
|---|---|---|
| `1` | Built-in FPC (correct — this stack has no Varnish) | move to 1b |
| `2` | Magento thinks Varnish fronts it. It stores **nothing** and emits cache headers for a proxy that does not exist. **Every request is a full render.** | fix below, then stop — this is your bug |
| error / empty | no explicit row, defaults to `1` | move to 1b |

```bash
docker exec $APP php bin/magento config:set system/full_page_cache/caching_application 1
docker exec $APP php bin/magento cache:flush
```

### 1b. Are the cache types enabled?

```bash
docker exec $APP php bin/magento cache:status
```

Every type should be `1` (17 as of 2026-08-17 — 15 core plus `adyen` and
`carpetavenue_top_menu_cache`). `full_page: 0` or `block_html: 0` means someone disabled
them in Admin → System → Cache Management (common during debugging, then forgotten).

`cache:enable` looks like the fix and is not — read on.

Cache-type state is **not in the DB**. It lives only in `app/etc/env.php` →
`cache_types`, and `Magento\Framework\App\Cache\State::isEnabled()` reads
`$statuses[$code] ?? false` — a code that is not listed is **disabled**, there is no
"on by default".

`docker/entrypoint.sh:23` copies `docker/env.php.template` over `app/etc/env.php` on
**every container start**, in all five role containers. Consequences:

- Enabling a type in Admin → Cache Management rewrites env.php **in that one
  container** and is wiped on the next restart/redeploy. It never reaches cron, the
  consumers, or a second app replica.
- A module that ships `etc/cache.xml` but whose code is missing from the template
  shows `DISABLED` in the grid **forever**, no matter how many times you enable it.
  That was the case for `adyen` and `carpetavenue_top_menu_cache` until 2026-08-17.

Fix = add the code to `cache_types` in `docker/env.php.template` and rebuild. Treat
that list as the only source of truth; the admin toggles are read-only in practice.

> **Confirm `adyen` on the server.** `adyen/module-payment` is not installed in the local
> checkout, so its type code was taken from the grid (label "Adyen", tag `ADYEN`) rather
> than read from its `etc/cache.xml`. If `cache:status` on prod prints something other than
> `adyen`, correct the template — a wrong code leaves the row DISABLED exactly as before.

### 1c. Is Valkey evicting?

```bash
docker exec $VK valkey-cli -a "$VKPASS" info stats  | grep -E 'evicted_keys|keyspace_hits|keyspace_misses'
docker exec $VK valkey-cli -a "$VKPASS" info memory | grep -E 'used_memory_human|maxmemory_human|maxmemory_policy'
docker exec $VK valkey-cli -a "$VKPASS" info keyspace
```

| Reading | Meaning |
|---|---|
| `evicted_keys:0` | memory is **not** your problem — the §2 changes will not help, go to 1d |
| `evicted_keys` > 0 and climbing | confirmed. §2 is your fix |
| `keyspace_misses` ≈ `keyspace_hits` | cache is being written but never read back — evicted or flushed constantly |
| `db0`/`db1`/`db2` all present | correct: cache / page_cache / sessions |
| `db1` missing or tiny | FPC is storing nothing — recheck 1a |

Why eviction breaks correctness, not just speed: `Cm_Cache_Backend_Redis` stores
tag→id **index sets** alongside the data. `allkeys-lru` evicts those sets too. Once a
tag set is gone, `cache:clean` by tag silently matches nothing — so the stale block
stays served forever and no amount of saving the product in admin clears it. Sessions
live in the same pool (db2), so eviction also logs users out at random.

### 1d. Is cron alive?

```bash
docker exec $CRON tail -20 /var/www/html/var/log/cron.log
docker exec $APP  php bin/magento indexer:status
```

Cron drives cache invalidation refresh and the indexers. Dead cron looks *exactly*
like a broken cache backend from the frontend: content goes stale and never recovers.
No new lines in the last few minutes = cron is dead. Restart the `cron` container and
check `/etc/magento-env.sh` exists inside it (see `docker/entrypoint.sh:30`).

### 1e. Confirm the symptom matches

| What you see | Cause | Section |
|---|---|---|
| Admin edit never appears on frontend | tag-set eviction, or dead cron | 1c / 1d |
| Every page slow, warm and cold identical | FPC off (`caching_application` = 2) or type disabled | 1a / 1b |
| Fast after flush, slow again hours later | eviction | 1c |
| Users randomly logged out, carts lost | sessions evicted from shared pool | 1c |
| Stale only right after a deploy | cold cache, expected — see §4 | — |

---

## 2. What was changed (2026-08-14)

Three edits, all targeting the eviction cause in 1c.

| File | Before | After | Why |
|---|---|---|---|
| `docker-compose.dokploy.yml` (`cache.command`) | `--maxmemory 1gb` | `--maxmemory ${VALKEY_MAXMEMORY:-2gb}` | 1GB held cache db0 + FPC db1 + sessions db2 in one pool |
| `env.php.template` (`page_cache`) | `compress_data => '0'` | `'1'` + gzip + 20KB threshold | FPC stored raw HTML; layered-nav SEO filter URLs mint one entry per filter combination |
| `php/php.ini` | `zlib.output_compression = On` | `Off` | nginx already gzips (`docker/nginx/nginx.conf`) |

> **Superseded 2026-08-17.** The `page_cache` compression row above was a no-op: on 2.4.9
> the backend was resolving to `FilesystemAdapter` (§0), and the Symfony redis adapter
> ignores `compress_data` / `compression_lib` / `compress_threshold` regardless. Those keys
> are gone from `env.php.template`. Everything below about `compression_lib` describes the
> pre-2.4.9 Cm backend and no longer applies. Re-measure FPC memory once §0 is deployed and
> entries actually land in Valkey.

`compression_lib => 'gzip'` is pinned deliberately. The backend auto-selects
snappy → lz4 → zstd → lzf → gzip based on installed extensions
(`Cm/Cache/Backend/Redis.php:414-436`); this image ships only `igbinary` + `redis`,
so gzip is what it would pick anyway. Pinning makes it deterministic if extensions
change later. Entries carry a per-entry compression prefix, so old uncompressed
entries still decode after the switch — no forced flush needed, though §3 flushes anyway.

**`2gb` is a starting number, not a measured one.** Check Server B has the headroom
(`free -g`) against OpenSearch's 1g heap plus 50 fpm workers. Override with
`VALKEY_MAXMEMORY` in the Dokploy environment rather than editing the compose.

---

## 3. Deploy

`env.php.template` and `php.ini` are **baked into the image** (`docker/Dockerfile`),
so they need a rebuild. The compose change applies on redeploy alone.

```bash
# 1. Build + push (from a machine with the repo + auth.json)
docker buildx build --secret id=composer_auth,src=auth.json \
  --secret id=env_php_scd,src=docker/env.php.scd \
  -f docker/Dockerfile -t registry.carpetavenue.net/carpient:<next-tag> --push .

# 2. Dokploy → set IMAGE_TAG=<next-tag> → Redeploy
# 3. After it settles
docker exec $APP php bin/magento cache:flush
```

`/ship-pr` and the `build-image` skill cover the tagging convention.

---

## 4. Verify it actually worked

Config being correct is not proof. These two are.

```bash
# A. Eviction must stay at zero under real traffic. Check now, and again after a day.
docker exec $VK valkey-cli -a "$VKPASS" info stats | grep evicted_keys

# B. FPC must actually serve. Second request should be dramatically faster.
URL=https://carpient.carpetavenue.net/
curl -sS -o /dev/null -w 'cold %{time_starttransfer}s\n' "$URL"
curl -sS -o /dev/null -w 'warm %{time_starttransfer}s\n' "$URL"
```

Warm ≈ cold means the page is not being cached at all — go back to §1a.
A useful warm figure is under ~150ms; cold can legitimately be seconds.

Cache is cold after every deploy: `--save ""` means Valkey persists nothing, so a
restart empties it. That is deliberate (the data is all regenerable). If the first-hit
penalty after deploy hurts, warm the top pages:

```bash
docker exec $APP sh -c 'php bin/magento sitemap:generate' # if not already scheduled
# then crawl the top URLs, e.g.
xargs -P4 -n1 curl -sS -o /dev/null < top-urls.txt
```

---

## 5. If it is still broken — next rungs, in order

1. **Evictions persist at 2gb.** Raise `VALKEY_MAXMEMORY` first (cheapest). Watch
   `used_memory_human` plateau — that plateau is your real working set.
2. **Cannot give Valkey more RAM.** Split sessions onto their own Valkey service
   (256mb, `--maxmemory-policy volatile-lru` — session keys all carry a TTL, so
   volatile-lru is safe there). This stops cache pressure from logging users out,
   which is the worst-feeling symptom. Point `session.redis.host` in
   `env.php.template:89` at the new service.
3. **Still evicting after both.** Split the FPC onto its own instance with
   `allkeys-lru` and leave the default cache (db0) on `noeviction`. This is Adobe's
   documented layout: the default cache holds config/layout/tag data that must never
   be evicted, the page cache is disposable. Requires three Valkey services.
4. **Not memory at all.** Re-run §1 end to end. Then check for a module writing
   `Cache-Control: no-cache` or calling `setNoCacheHeaders()` on catalog pages —
   `grep -rn 'setNoCacheHeaders\|cacheable="false"' app/code app/design`. As of this
   writing `cacheable="false"` appears only on wishlist / OSC / B2B layouts, which is
   correct and does not affect catalog pages.

---

## 6. Things ruled out during the review

Recorded so you do not re-investigate them.

- **File-based cache.** Rejected. Does not address eviction, moves the load onto a
  Docker volume, and the file backend's tag invalidation is an O(n) directory scan —
  a single product save would walk every FPC file. Adobe rules it out for production.
- **Varnish.** Not in this stack, confirmed. Do not set `caching_application` to `2`.
- **`cacheable="false"` on catalog pages.** Checked, none. Only wishlist / OSC / B2B.
- **`php.ini` `session.save_path = tcp://cache:6379?database=2`** — hardcoded host, no
  password, but inert: `env.php` `session.save => redis` overrides the native handler.
  Left in place. It is a landmine only if the `session` block ever leaves `env.php`.
