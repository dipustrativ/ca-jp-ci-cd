# ISSUE-004 — All store views share one FPC entry; `/` serves the wrong language

| | |
|---|---|
| **Status** | 🟡 WORKAROUND — frontend plugin |
| **Affects** | `magento/module-store` (2.4.9 CE) with `web/url/use_store = 1` |
| **Origin** | Adobe/Magento core |
| **Workaround** | `CarpetAvenue\Core\Plugin\IgnoreStoreCookie` |
| **Found** | 2026-08-22 |

## Symptom

`/` renders **German** for everyone until `full_page` is cleared, then comes back
later. Whichever store view renders `/` first wins:

```bash
bin/magento cache:clean full_page
curl -s -H 'Cookie: store=de' https://<host>/ | grep -o '<html lang="[a-z]*"'  # de  (correct)
curl -s                       https://<host>/ | grep -o '<html lang="[a-z]*"'  # de  (WRONG)
```

Prefixed URLs (`/de/alle-teppiche`) are unaffected — the code is in the URL.

## Root cause

Two core behaviours combine, and only on a store whose **every** store view has a
URL prefix, English included (`/en/`). `/` then belongs to no store view.

1. `StoreResolver::getCurrentStoreId()` falls back to the `store` **cookie** when
   the path carries no store code. So `/` renders whatever the visitor last
   switched to.
2. `module-store/App/Action/Plugin/Context.php` → `updateContext()`:

   ```php
   case $store->isUseStoreInUrl():
       $defaultStore = $store;        // current store passed as its own default
   ```

   `framework/App/Http/Context.php` → `getData()` drops any value equal to its
   default, so the store never reaches the vary string and every store view gets
   the same FPC key:

   ```
   no cookie : store=en, identifier=722091b2…, context data: []
   store=de  : store=de, identifier=722091b2…, context data: []
   ```

Core assumes `use_store=1` means the URL always identifies the store, which makes
(2) safe. Here it does not, so one visitor's cookie decides `/` for everyone.

## Proof local code is not at fault

- `general/locale/code` = `en_US` at default scope, no `stores/1` override.
  `Resolver::getLocale()` is correct per store.
- German body is served while `getStore()` returns `en` — the render is right, the
  *cache lookup* is wrong. `cache:clean full_page` fixes it with no config change.
- No `app/code` plugin on `Http\Context`, `Identifier`, `StoreResolver` or
  `StoreCookieManager` existed before this fix.

## Ruled out

- **`Cache-Control: no-store` on every page** — normal. `PageCache\Kernel::process()`
  calls `setNoCacheHeaders()` *after* writing the entry. Caching works: home
  107 ms cold → 17 ms warm, category 205 ms → 18 ms.
- **`Mageplaza_GeoIP`** — has no `etc/frontend` directory; cannot touch the
  storefront. (Earlier notes blamed it. Wrong.)
- **Cloudflare** — reproduced against the origin directly.
- **Redis/session errors in `system.log`** — real, but only during the container
  recreation window of a deploy.

## Fix

`afterGetStoreCodeFromCookie` on `Magento\Store\Api\StoreCookieManagerInterface`
(frontend only) returns `null`, so **the store view is resolved from the URL and
nothing else**.

With `use_store=1` the prefix already decides every real page; the cookie only ever
answered for the prefix-less root. Dropping it makes `/` resolve to the default
store view of whichever website the request landed on — `en` on the main domain,
`se` on `carpient-se`, correct on any domain added later with no per-domain code.
Deterministic, and cacheable with an empty vary: one FPC entry for `/`, no
per-store variants.

Exempt: paths containing `/stores/store/` (the switch and redirect controllers).
`Magento\Quote\Plugin\UpdateQuoteStore` reads the *old* cookie right after a switch
to decide whether to move the visitor's quote. The switcher itself does not need
the cookie — core's `SwitcherUrlProvider` and the theme's `HomeSwitcherUrlProvider`
both send `___from_store` explicitly.

**Rejected:** varying the FPC by store instead (two production regressions, see the
log — a non-empty vary cannot be matched by the FPC *load*, which runs in
`BuiltinPlugin::aroundDispatch` before the context holds a store, so first-time
visitors and crawlers never hit cache); `301 / → /en/` (adds a hop to the busiest
page); core patch (same effect, worse upgrades); disabling FPC for `/` (+110 ms).

## Re-check after every upgrade

```bash
# 1. Does core still fall back to the cookie, and still collapse the vary?
grep -n -A6 'getStoreCodeFromCookie' vendor/magento/module-store/Model/StoreResolver.php
grep -n -A6 'isUseStoreInUrl()'      vendor/magento/module-store/App/Action/Plugin/Context.php

# 2. The check that decides it — the cookie must NOT change what "/" renders:
bin/magento cache:clean full_page
curl -s -H 'Cookie: store=de' https://<host>/ | grep -o '<html lang="[a-z]*"'   # expect en
curl -s                       https://<host>/ | grep -o '<html lang="[a-z]*"'   # expect en

# 3. Every root must warm up. Run inside the container network so the number is
#    render time, not RTT: expect ~104 ms then ~18 ms. Flat ~104 ms = the store
#    is in the vary string again.
for h in carpient.carpetavenue.net carpient-se.carpetavenue.net; do
  for i in 1 2 3; do docker run --rm --network <compose>_default curlimages/curl \
    -s -o /dev/null -w "$h %{time_total}s\n" \
    -H "Host: $h" -H "X-Forwarded-Proto: https" http://carpient-nginx/; done
done

# 4. Switching still works and still moves the quote:
#    switch to German in a browser, add to cart, confirm the cart survives.
```

## Verification log

| Date | Version | Result |
|---|---|---|
| 2026-08-22 | 2.4.9 CE / PHP 8.5.9 | 🔴 Reproduced on production, both orderings. Cause confirmed at `Context.php:139-160` + `Http/Context.php:104-112`. |
| 2026-08-22 | v47 | ⚠️ Fix #1 (vary by store) applied to every store view — broke caching on prefixed URLs, `/de/alle-teppiche` 16 ms → 199 ms. |
| 2026-08-22 | v48 | 🟡 Narrowed to prefix-less URLs. `/` correct per visitor, prefixed URLs cached again — but `/` still followed the cookie, so a visitor who switched to German kept seeing German at `/`. |
| 2026-08-22 | v49 | ⚠️ Same vary trap hit the new `carpient-se` domain: root flat 104 ms, never cached. Compared against the *global* default store, not the website's. Superseded, never deployed. |
| 2026-08-22 | v50 | 🟢 Fix #2 — ignore the cookie entirely. `/` is `en` with or without a `store=de` cookie, Sweden root is `sv`, both cached. |
