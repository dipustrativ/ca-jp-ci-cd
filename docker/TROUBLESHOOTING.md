# CarpetAvenue JP — Troubleshooting

Symptom → cause → fix for the production Docker stack.
Build & deploy procedure: **[README.md](README.md)**. Runner, registry, shared
MySQL/OpenSearch/RabbitMQ, server ops: **[INFRA-CI.md](INFRA-CI.md)**.

> Findings dated 2026-08 were verified on the **`carpient`** stack — same image,
> same compose. The procedures apply verbatim; hostnames, Dokploy app-ids and
> absolute numbers are that deployment's, not JP's.

Container names on Dokploy are generated. Resolve them once per session:

```bash
docker ps --format '{{.Names}}\t{{.Image}}' | grep -Ei 'carpetavenue-jp|valkey'
APP=<…-app-1>   CRON=<…-cron-1>   VK=<…-cache-1>
VKPASS=<VALKEY_PASSWORD from Dokploy env>
```

---

## Index

| Symptom | Cause → Fix |
|---|---|
| `exec /entrypoint.sh: exec format error` + `platform (linux/arm64) does not match … (linux/amd64/v3)` | arm64 image from a laptop build → **[§1](#1-exec-format-error--wrong-image-architecture)** |
| Admin edit never reaches the storefront; "flush works sometimes"; random logouts | cache backend or eviction → **[§2](#2-cache-is-not-working)** |
| Every `*.min.js` / `*.min.css` 404s, a few assets 200, `static.php` 404s | minify flags differed between SCD DB and prod DB → **[§3](#3-every-min-asset-404s--minification-mismatch)** |
| Registry push dies with `499` / `504 Gateway Timeout` | Traefik timeout on the ~800 MB layer → **[§4](#4-registry-push-499--504)** |
| `cron` logs `getaddrinfo for db failed` every minute, app is fine | crond drops the container env → **[§5](#5-cron-getaddrinfo-for-db-failed)** |
| Storefront has no CSS at all (not just `.min`) | `static` service or SCD failed → `docker compose logs static`; check both nginx and `static` mount `magento_static` |
| `upgrade` hangs at "Schema creation/updates" | Another instance holds the DB setup-lock on the same database → use an isolated DB, or `--profile localdb` + `DB_HOST=db` |
| `getaddrinfo for mysql / opensearch / rabbitmq failed` | Path B: that shared stack is not deployed, or this app is not on `dokploy-network` → `docker exec $APP getent hosts mysql` ([INFRA-CI §1](INFRA-CI.md#part-1--shared-infrastructure)) |
| `search` container will not start (Paths A/C) | Host `vm.max_map_count` too low → `sysctl -w vm.max_map_count=262144`. Path B: same fix, in the shared opensearch stack |
| Storefront search returns **carpient's** products | Both installs on one `OPENSEARCH_INDEX_PREFIX` → [INFRA-CI §1](INFRA-CI.md#index-prefix--the-one-that-destroys-data) |
| Garbled config / decryption failures | `MAGE_CRYPT_KEY` ≠ the `crypt/key` of the DB it points at. It is tied to the database, not the environment |
| Changed an env var, no effect | Restart the containers — `env.php` is rendered at start, not live |
| nginx `cannot load certificate …pem` | A TLS server block with no certs. Local is HTTP-only; TLS belongs at the edge → pull latest `magento.local.conf` |
| Cannot pull the image on the host | Registry not added / not logged in on that host |
| Everything came back `CACHED`, my edit is missing | Usually correct — BuildKit keys on content ([README §1.6](README.md#16-tags-cache-and-size-all-paths)). Confirm the file is not in `.dockerignore`, then `--no-cache-filter builder` |
| **CI:** job stuck "Waiting for a runner" | Runner offline, or label mismatch — must be exactly `carpetavenue-jp-build` |
| **CI:** `permission denied … docker.sock` | Runner user not in the `docker` group → `sudo usermod -aG docker $USER`, restart the service |
| **CI:** `unauthorized` on push | `REGISTRY_USER`/`REGISTRY_PASS` wrong → verify with `docker login` on Server A |
| **CI:** `composer install` auth failure | `COMPOSER_AUTH` truncated → re-paste all of `auth.json` |
| **SCD step:** `The default website isn't defined` | `docker/scd-scopes.php` missing/stale, or `env.php` still has `db`/`install` during SCD → regenerate per README §8.1 |
| **CI:** build OOMs, Dokploy panel dies with it | ~4 GB peak on a shared Server A → add RAM or move the runner |
| **CI:** green workflow, site unchanged | `IMAGE_TAG`/`NGINX_TAG` pinned to an old `v<N>` → set both back to `latest`. If already `latest`, the webhook is not firing — POST it by hand |
| **CI:** pushed to `main`, nothing ran | By design — only a pushed `v*` git tag triggers it: `git tag v<N> && git push origin v<N>` |
| **CI:** tag pushed, build waits | Earlier tag still building — `concurrency` queues, never cancels. Or no runner (see first row) |
| **Registry disk full** | GC is never scheduled — Dokploy does not touch registry storage, and every build orphans the previous `latest`/`buildcache` manifests at ~1.2 GB a time → [INFRA-CI Step 4](INFRA-CI.md#step-4--cleanup-jobs) |

---

## 1. `exec format error` — wrong image architecture

```
The requested image's platform (linux/arm64) does not match the detected
host platform (linux/amd64/v3) and no specific platform was requested
exec /entrypoint.sh: exec format error
```

An arm64 image was pushed from an Apple Silicon laptop. It hits **all five
roles** — `app`, `upgrade`, `static`, `cron` **and** `nginx` — because all of
them come from the two images you push. `nginx:1.29-alpine` being multi-arch
does not save you: `buildx --push` from an arm64 daemon publishes an
**arm64-only manifest**, so the baked-in base layers are arm64 too. That is why
the failure surfaces as nginx's own `/entrypoint.sh` refusing to exec.

Nothing on the server fixes this. Rebuild **both** images with
`--platform linux/amd64` ([README §1.3](README.md#13-b--building-from-apple-silicon-arm64-host--amd64-image)),
confirm, then redeploy:

```bash
docker buildx imagetools inspect registry.carpetavenue.net/carpetavenue-jp:latest       | grep -i platform
docker buildx imagetools inspect registry.carpetavenue.net/carpetavenue-jp-nginx:latest | grep -i platform
# both must print: Platform: linux/amd64
```

---

## 2. Cache is "not working"

Work down §2.1 → §2.2 in order. **§2.1 first** — it invalidates every other
finding if it trips.

### 2.1 The 2.4.9 silent filesystem fallback (found 2026-08-17)

Magento 2.4.9 dropped the Zend cache stack for Symfony adapters. The `backend`
name in `env.php` is looked up in `SymfonyAdapterProvider::$adapterTypeMap`,
which knows only:

```
redis  valkey  memcached  libmemcached  file  database  apc  apcu  two_levels  twolevel
```

Anything else resolves to `'filesystem'` (`SymfonyAdapterProvider.php:139`) — a
file cache under `var/cache` — with **no error, no warning, nothing in the log**.
`Cm_Cache_Backend_Redis`, which this stack used until 2.4.9, is one of those
"anything else". A failing Redis connection degrades identically: the
`try`/`catch` at `:151` swallows it and returns a FilesystemAdapter.

The result was the whole stack running on files on the shared `magento_var`
volume across all five containers while Valkey held only sessions and stale
pre-upgrade keys. That is the "flush works sometimes" bug — file-backed tag
invalidation across five containers writing one directory.

**Fixed** in `docker/env.php.template` (`backend => 'redis'`). ⚠️ `env.php.template`
and `php.ini` are **baked into the image**, so any change to them needs a
rebuild + `IMAGE_TAG` bump + `cache:flush`; a compose change (e.g.
`VALKEY_MAXMEMORY`) applies on redeploy alone.

Config never proves the backend either way — only the live object does:

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
| `…SymfonyAdapters\GenericTagAdapter` wrapping `RedisAdapter` | correct |
| anything wrapping `Symfony\…\FilesystemAdapter` | **Valkey is not in use — you are on files** |

The Cm-era options went with it: `compress_data`, `compression_lib`,
`compress_threshold` and `use_lua` are **not read** by the Symfony adapter and
are gone from the template.

### 2.2 Four diagnostics — run all of them

They map to four different root causes; they are not alternatives.

**a. Is the built-in FPC switched on?**

```bash
docker exec $APP php bin/magento config:show system/full_page_cache/caching_application
```

`1` = built-in FPC, correct (no Varnish in this stack). `2` = Magento believes
Varnish fronts it, stores **nothing**, and emits cache headers for a proxy that
does not exist — **every request is a full render**. Empty/error = defaults to
`1`. Fix and stop here if it was `2`:

```bash
docker exec $APP php bin/magento config:set system/full_page_cache/caching_application 1
docker exec $APP php bin/magento cache:flush
```

**b. Are the cache types enabled?** `docker exec $APP php bin/magento cache:status`
— every type should be `1` (17 as of 2026-08-17: 15 core plus `adyen` and
`carpetavenue_top_menu_cache`).

`cache:enable` looks like the fix and is not. Cache-type state is **not in the
DB** — it lives only in `app/etc/env.php` → `cache_types`, and
`Cache\State::isEnabled()` reads `$statuses[$code] ?? false`, so a code that is
not listed is disabled; there is no "on by default". `docker/entrypoint.sh:23`
overwrites `app/etc/env.php` from the template on **every container start** in
all five containers. So:

- Enabling a type in Admin → Cache Management rewrites env.php in *that one
  container* and is wiped on the next restart. It never reaches cron or a second
  app replica.
- A module shipping `etc/cache.xml` whose code is missing from the template
  shows DISABLED **forever**, however many times you enable it (this was `adyen`
  and `carpetavenue_top_menu_cache` until 2026-08-17).

Fix = add the code to `cache_types` in `docker/env.php.template` and rebuild.
Treat that list as the only source of truth; the admin toggles are read-only in
practice.

> ⚠️ **Confirm `adyen` on the server.** `adyen/module-payment` is not in the
> local checkout, so the code was read off the admin grid (label "Adyen", tag
> `ADYEN`), not from its `etc/cache.xml`. If prod's `cache:status` prints
> something else, correct the template — a wrong code leaves the row DISABLED
> exactly as before.

**c. Is Valkey evicting?**

```bash
docker exec $VK valkey-cli -a "$VKPASS" info stats  | grep -E 'evicted_keys|keyspace_hits|keyspace_misses'
docker exec $VK valkey-cli -a "$VKPASS" info memory | grep -E 'used_memory_human|maxmemory_human|maxmemory_policy'
docker exec $VK valkey-cli -a "$VKPASS" info keyspace
```

| Reading | Meaning |
|---|---|
| `evicted_keys:0` | memory is **not** your problem — go to **d** |
| `evicted_keys` > 0 and climbing | confirmed → §2.4 ladder |
| `keyspace_misses` ≈ `keyspace_hits` | written but never read back — evicted or flushed constantly |
| `db0`/`db1`/`db2` all present | correct: cache / page_cache / sessions |
| `db1` missing or tiny | FPC storing nothing — recheck **a** |

Eviction breaks *correctness*, not just speed: the backend stores tag→id **index
sets** alongside the data, and `allkeys-lru` evicts those sets too. Once a tag
set is gone, `cache:clean` by tag silently matches nothing — the stale block is
served forever and no amount of re-saving the product in admin clears it.
Sessions share the pool (db2), so eviction also logs users out at random.

**d. Is cron alive?**

```bash
docker exec $CRON tail -20 /var/www/html/var/log/cron.log
docker exec $APP  php bin/magento indexer:status
```

Cron drives cache-invalidation refresh and the indexers. Dead cron looks
*exactly* like a broken cache backend from the frontend. No new lines in the
last few minutes = dead → restart the `cron` container and check
`/etc/magento-env.sh` exists inside it (see §5).

**Symptom → which check:**

| What you see | Cause | Check |
|---|---|---|
| Admin edit never appears on frontend | tag-set eviction, or dead cron | c / d |
| Every page slow, warm and cold identical | FPC off, or type disabled | a / b |
| Fast after flush, slow again hours later | eviction | c |
| Users randomly logged out, carts lost | sessions evicted from the shared pool | c |
| Stale only right after a deploy | cold cache — expected, see §2.3 | — |

### 2.3 Verify a fix actually worked

Correct config is not proof. These two are:

```bash
# A. Eviction must stay at zero under real traffic — now, and again after a day.
docker exec $VK valkey-cli -a "$VKPASS" info stats | grep evicted_keys

# B. FPC must actually serve.
URL=https://playground.carpetavenue.jp/
curl -sS -o /dev/null -w 'cold %{time_starttransfer}s\n' "$URL"
curl -sS -o /dev/null -w 'warm %{time_starttransfer}s\n' "$URL"
```

Warm ≈ cold ⇒ not cached at all, back to §2.2a. A good warm figure is under
~150 ms; cold can legitimately be seconds.

Cache is cold after **every** deploy — `--save ""` means Valkey persists
nothing, and the data is all regenerable. If the first-hit penalty hurts, warm
the top pages: `php bin/magento sitemap:generate`, then
`xargs -P4 -n1 curl -sS -o /dev/null < top-urls.txt`.

### 2.4 Still evicting — next rungs, in order

1. **Raise `VALKEY_MAXMEMORY`** (cheapest). Watch `used_memory_human` plateau —
   that plateau is your real working set. `2gb` is a starting number, not a
   measured one; check `free -g` against OpenSearch's heap plus the fpm workers.
   Override in the Dokploy environment, never by editing the compose.
2. **Cannot give it more RAM** → split sessions onto their own Valkey service
   (256mb, `--maxmemory-policy volatile-lru` — session keys all carry a TTL, so
   volatile-lru is safe there). Stops cache pressure from logging users out.
   *Already done in `docker-compose.dokploy.yml`: a separate `sessions` service.*
3. **Still evicting** → split the FPC onto its own instance with `allkeys-lru`
   and leave db0 on `noeviction`. Adobe's documented layout: the default cache
   holds config/layout/tag data that must never be evicted; the page cache is
   disposable. Three Valkey services.
4. **Not memory at all** → re-run §2.2 end to end, then look for a module
   writing `Cache-Control: no-cache` or calling `setNoCacheHeaders()` on catalog
   pages: `grep -rn 'setNoCacheHeaders\|cacheable="false"' app/code app/design`.

**Changed 2026-08-14** (all targeting eviction): `cache.command`
`--maxmemory 1gb` → `${VALKEY_MAXMEMORY:-2gb}`; `php.ini`
`zlib.output_compression` `On` → `Off` (nginx already gzips). A third edit —
`page_cache` compression in `env.php.template` — was **superseded 2026-08-17**:
it was a no-op, because the backend was resolving to `FilesystemAdapter` (§2.1)
and the Symfony redis adapter ignores those keys regardless.

### 2.5 Ruled out — do not re-investigate

- **File-based cache.** Does not address eviction, moves load onto a Docker
  volume, and its tag invalidation is an O(n) directory scan — one product save
  would walk every FPC file. Adobe rules it out for production.
- **Varnish.** Not in this stack. Never set `caching_application` to `2`.
- **`cacheable="false"` on catalog pages.** Checked — appears only on
  wishlist / OSC / B2B layouts, which is correct.
- **`php.ini` `session.save_path = tcp://cache:6379?database=2`** — hardcoded
  host, no password, but inert: `env.php`'s `session.save => redis` overrides
  the native handler. Left in place; a landmine only if the `session` block ever
  leaves `env.php`.

---

## 3. Every `.min` asset 404s — minification mismatch

Symptom: page loads, `health_check.php` 200, but `requirejs/require.min.js`,
`css/styles.min.css` and `<Module>/css/*.min.css` all 404 — while a few assets
still 200, and `GET /static.php` 404s too.

The 200s are the tell: those ship pre-minified in `lib/web`
(`jquery/jstree/…/style.min.css`, `images/magento-icon.svg`). Every 404 is a
file Magento has to *generate*. `static.php` 404ing is production mode refusing
to build on demand — correct, not the bug.

Cause: `dev/js/minify_files` + `dev/css/minify_files` decide both whether SCD
writes `require.min.js` or `require.js`, **and** which URL the running site asks
for. The builder read them from the SCD database, the site from the production
database. When those disagreed, the image had `require.js` and the page wanted
`require.min.js`.

Both flags are now locked in `app/etc/config.php`, which ships in the image;
deployment config merges last (sortOrder 1000 vs the DB's 100 in
`systemConfigSourceAggregated`), so neither DB can win:

```bash
bin/magento config:set --lock-config dev/js/minify_files 1
bin/magento config:set --lock-config dev/css/minify_files 1
bin/magento config:set --lock-config dev/js/merge_files 0
```

Locked = admin field read-only, DB row ignored (not deleted). No DB change
needed on either side. `dev/css/merge_files` no longer exists in 2.4.9.

`dev/js/merge_files` is pinned **off** deliberately: merging writes to
`pub/static/_cache/merged` at runtime inside `app`, but nginx serves
`magento_static` read-only from the `static` copy job — a merged file would 404
forever. Same symptom, different trigger.

The SCD step now asserts `require.min.js` and `styles.min.css` exist, so drift
fails the build instead of the deploy. Check any image directly:

```bash
docker run --rm --entrypoint sh registry.carpetavenue.net/carpetavenue-jp:v<N> \
  -c 'ls pub/static/adminhtml/Magento/backend/en_US/requirejs/'
# want: require.min.js present
```

---

## 4. Registry push 499 / 504

Not an image problem. The Magento image has one ~800 MB layer (`vendor` +
`pub/static`). Traefik in front of the registry killed the upload before the
registry finished committing the blob — the client sees `499`, Traefik logs
`504`.

Fix in Traefik's **static** config, `/etc/dokploy/traefik/traefik.yml`:

```yaml
entryPoints:
  websecure:
    address: ":443"
    # ...existing http/tls/http3 keys...
    transport:
      respondingTimeouts:
        readTimeout:  "900s"    # time to READ the request body = the blob upload
        writeTimeout: "900s"
        idleTimeout:  "180s"

serversTransport:
  forwardingTimeouts:
    dialTimeout:            "30s"
    responseHeaderTimeout:  "900s"   # THE 504 fix: wait for the registry to commit the big blob
    idleConnTimeout:        "90s"
```

Static config → restart, not hot-reload:

```bash
docker restart dokploy-traefik
docker logs --tail 20 dokploy-traefik   # confirm it came back (no YAML error)
```

`responseHeaderTimeout` is the key line. Confirmed working on this stack. CI is
immune — it pushes to Server A's registry over localhost, never through Traefik.

---

## 5. `cron`: `getaddrinfo for db failed`

App is fine, `cron` logs it every minute. crond does not pass the container
environment to the jobs it spawns, so `env.php`'s `getenv()` calls return false
and fall back to the template defaults (`DB_HOST` → `db`).

`entrypoint.sh` writes `/etc/magento-env.sh` and `cron/magento-cron` sources it.
If that file is missing inside the container, the image predates the fix —
rebuild and redeploy.
