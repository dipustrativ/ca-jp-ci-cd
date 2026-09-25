# ISSUE-009 — `get.php` serves the "missing image" placeholder as a cacheable 200, so a CDN stores it in place of the real image

| | |
|---|---|
| **Affects** | Magento 2.4.9 (`magento/module-media-storage`), any install behind a caching CDN |
| **Status** | 🟢 WORKAROUND — plugin sends `no-store` on placeholder responses |
| **First seen** | 2026-09-22 |
| **Last re-checked** | 2026-09-25 |
| **Origin** | Diagnosed on the sibling Carpient install (same Magento 2.4.9 image, same Cloudflare + get.php path); workaround ported here as a precaution |

## What breaks

One store view shows the grey Magento hexagon placeholder where a product image
should be. Every other store view shows the real image — **same product, same
media path**. Re-running `catalog:images:resize` does not help, because the file
on disk was never the problem.

Reported case (Carpient, multi-domain): `AW1611` (Pakistan Bokhara 185x185).
The same defect applies to this store — any hostname behind Cloudflare can be
poisoned the same way, one image size at a time.

| Store | URL | Result |
|---|---|---|
| EN | `carpient.carpetavenue.net/en/oriental-carpets-pakistan-bokhara-185x185-aw1611` | ✅ real image |
| NL | `carpient.carpetavenue.net/nl/oosterse-tapijten-arabella-pakistan-bokhara-185x185-aw1611` | ✅ real image |
| SE | `carpient-se.carpetavenue.net/se/orientaliska-mattor-arabella-pakistan-bokhara-185x185-aw1611` | ❌ placeholder |

The giveaway is that the **main image is fine and only some thumbnails break** —
the sizes are separate cache paths, so each one is poisoned independently.

## Verified root cause

Not a missing file. The same path, cache-busted so it reaches the origin,
returns the real image on **both** hostnames:

```bash
P=/media/catalog/product/cache/79d62b5a17d20b122cb8cbf050cc7241/p/a/pakistan_bokhara_aw1611_185x185_oriental_carpet_177245185543025975264341.png

curl -sS -o /dev/null -w '%{content_type} %{size_download}\n' "https://carpient-se.carpetavenue.net$P"
# image/jpeg 1317        <- CDN-cached placeholder
curl -sS -o /dev/null -w '%{content_type} %{size_download}\n' "https://carpient-se.carpetavenue.net$P?cb=1"
# image/png  219546      <- origin, the real file. It was there all along.
```

Headers tell the rest. The poisoned response carries `x-powered-by: PHP/8.5.10`
and no `etag`, so **PHP** produced it, not nginx — i.e. it came from `pub/get.php`:

```
content-type: image/jpeg
content-length: 1317
x-powered-by: PHP/8.5.10
cf-polished: ok, orig_size=1692     <- 1692 B is exactly the placeholder
cache-control: max-age=2678400      <- Cloudflare's Browser Cache TTL, 31 days
cf-cache-status: HIT
age: 775
```

`vendor/magento/module-media-storage/App/Media.php:192-205`

```php
try {
    $this->createLocalCopy();

    if ($this->directoryPub->isReadable($this->relativeFileName)) {
        $this->response->setFilePath($this->directoryPub->getAbsolutePath($this->relativeFileName));
    } else {
        $this->setPlaceholderImage();
    }
} catch (Exception $e) {
    $this->setPlaceholderImage();
}
```

and `Media.php:259-264`

```php
private function setPlaceholderImage(): void
{
    $placeholder = $this->placeholderFactory->create(['type' => 'image']);
    $this->createPlaceholderLocalCopy($placeholder->getRelativePath());
    $this->response->setFilePath($placeholder->getPath());
}
```

`setPlaceholderImage()` swaps the body and **nothing else**: the status stays
`200` and no cache header is set. A "this file does not exist" answer is
therefore indistinguishable, to any cache, from a successful one.

### How one store view ends up poisoned and the others don't

1. `nginx.conf.sample:182-187` — a miss under `/media/` falls through to
   `try_files $uri $uri/ /get.php$is_args$args`.
2. `get.php` normally *fixes* the miss: `Media::createLocalCopy()` calls
   `imageResize->resizeFromImageName()` and writes the real file. Verified
   locally — delete a cache file, request it, and it comes back 63605 B and is
   rewritten to disk.
3. When that resize fails instead — original missing, fpm `memory_limit` blown
   on a large PNG, worker timeout — the `catch` hands back the placeholder, 200.
4. The CDN stores it. Cloudflare's cache key includes the **hostname**, so the
   entry poisons only the store view that happened to make that request, for the
   full Browser Cache TTL (31 days here). The other hostnames requested the same
   path at a different moment, got the real file, and cached that.

This is the same cache-key fragmentation as [ISSUE-003](ISSUE-003-catalog-image-url-store-param.md),
one layer down. ISSUE-003 removed the `?___store=` fork, which reduces the number
of independent keys but does not stop a placeholder being cached under any of them.

## Proof local code isn't at fault

- The file exists on disk and nginx serves it correctly — the cache-busted
  request above returns 219546 B of PNG with an `etag` from both hostnames.
- One `magento_media` volume is shared by every role in the stack
  (`docker/docker-compose.dokploy.yml:127,154,169`), so there is no
  per-store media directory that could be missing files.
- `setPlaceholderImage()` is `private` and sets no headers — there is no config
  or extension point in core to make the fallback uncacheable.

## Workaround in force

Before-plugin on `Response::setFilePath()` — the only public seam, since
`setPlaceholderImage()` is private:

- `app/code/CarpetAvenue/Core/Plugin/NoStoreOnMediaPlaceholder.php`
- `app/code/CarpetAvenue/Core/etc/di.xml`
- `app/code/CarpetAvenue/Core/Test/Unit/Plugin/NoStoreOnMediaPlaceholderTest.php`

A path under `/placeholder/` gets `Cache-Control: no-store, no-cache,
must-revalidate, max-age=0`. Real files served by `get.php` — including the
resize-on-demand success path — are left alone and stay cacheable.

**The plugin is registered in `etc/di.xml`, not `etc/frontend/di.xml`.**
`get.php` runs in `Area::AREA_GLOBAL` (`Media::launch()` line 175), so a
frontend-area plugin would never fire for a media request.

`Response::sendResponse()` passes `getHeaders()` to the transfer adapter, so
headers set this way do reach the client — verified below.

## Alternatives rejected

- **Return 404 instead of 200.** Correct HTTP, but browsers render a broken
  image for a 404 regardless of the body, so the visible placeholder is lost.
- **`add_header Cache-Control "no-store"` in the nginx `/media/` location.**
  nginx cannot tell a placeholder from a real file — both arrive from `get.php`
  through the same internal redirect — so this would also make every
  resize-on-demand hit uncacheable.
- **A Cloudflare rule.** Same blindness as nginx, plus it leaves every other
  environment and any future CDN exposed.
- **Just re-run `catalog:images:resize`.** Does not touch the poisoned CDN
  entries, and does nothing about the next transient resize failure.

## Cleanup after deploying the fix

The plugin stops *new* poisoning. Entries already in the CDN survive their TTL —
purge them, or wait up to 31 days:

```bash
# Cloudflare → Caching → Configuration → Purge Everything, or per-URL purge
# for the affected /media/catalog/product/cache/... paths.
```

## Re-check procedure

After any Magento upgrade:

```bash
# 1. Does the fallback still return a bare 200 with no cache headers?
grep -n "setPlaceholderImage" -A 6 vendor/magento/module-media-storage/App/Media.php

# 2. A path that cannot exist must come back as an uncacheable placeholder.
#    Resolve the local host first — it differs per machine.
BASE=$(docker exec carpetavenue-jp-db-1 mariadb -umagento -pmagento magento -N -B \
  -e "select value from core_config_data where path='web/secure/base_url' and scope='default';")
curl -skD - -o /dev/null \
  "${BASE}media/catalog/product/cache/79d62b5a17d20b122cb8cbf050cc7241/n/o/nope-$RANDOM.jpg" \
  | grep -iE '^(HTTP|content-length|cache-control|x-powered-by)'
# want: 200, x-powered-by PHP, cache-control no-store...

# 3. A real file must NOT have been made uncacheable. Pick any existing derived
#    cache file, delete it (it regenerates), request it, then request it again.
REL=$(docker exec carpetavenue-jp-phpfpm-1 sh -c \
  "cd /var/www/html/pub/media && find catalog/product/cache -type f -name '*.jpg' | head -1")
docker exec carpetavenue-jp-phpfpm-1 rm -f "/var/www/html/pub/media/$REL"
curl -skD - -o /dev/null "${BASE}media/$REL" | grep -i cache-control   # expect none (get.php)
curl -skD - -o /dev/null "${BASE}media/$REL" | grep -i cache-control   # expect public, max-age=31536000 (nginx)
```

If step 1 shows core setting a non-200 status or a `no-store` header itself,
upstream has fixed it — drop the plugin and move this file to **Resolved**.

## Known gap, not fixed here

A **real** file served by `get.php` (the resize-on-demand path) carries no cache
headers at all, so a CDN falls back to its own default TTL rather than the
`public, max-age=31536000` that nginx sends for the same file on the next
request. Harmless today — the second request is served by nginx — and left alone
deliberately so this change stays confined to the placeholder path.
