# ISSUE-003 — Magento appends `?___store=<code>` to every product image URL

| | |
|---|---|
| **Affects** | Magento 2.4.9 (core), any install with `web/url/use_store = 1` |
| **Status** | 🟢 WORKAROUND — after-plugin strips the param |
| **First seen** | 2026-08-21 |
| **Last re-checked** | 2026-08-21 |

## What breaks

Every product image rendered through `$block->getImage($product, $displayArea)` comes out as:

```
/media/catalog/product/cache/<hash>/2/0/file.jpg?___store=en
```

The `___store` query param is meaningless for a static file on disk — the resized
image is *already* keyed by a hash of its resize params, and the file is served
straight off the filesystem by nginx with no PHP involved.

The cost is at the CDN. A query string is part of the cache key, so the same
physical file is cached **once per store view**. This install has 12 active store
views (`en, de, es, fr, it, hu, nl, pl, pt, fi, dk, …`), so one image can occupy
up to 12 Cloudflare edge entries. Edge hit rate drops and origin image fetches
multiply for no benefit.

Measured on `/en/all-carpets` before the fix: 40 of 157 media URLs carried the
param — exactly one per product, the main grid thumbnail.

## Verified root cause

`vendor/magento/module-catalog/Block/Product/ImageFactory.php:176-183`

```php
$data = [
    'data' => [
        'template' => 'Magento_Catalog::product/image_with_borders.phtml',
        'image_url' => $imageAsset->getUrl() .
            (
                $this->scopeConfig->isSetFlag(Store::XML_PATH_STORE_IN_URL) ?
                '?' . StoreManagerInterface::PARAM_NAME . '=' . $product->getStore()->getCode() : ''
            ),
```

It is unconditional: if `web/url/use_store` is enabled, the param is appended.
There is no config to suppress it.

## Proof local code isn't at fault

- `Store::getBaseUrl(URL_TYPE_MEDIA)` returns a clean URL — verified by bootstrapping
  the app and printing it: `http://carpient.devs/media/`.
- `Magento\Catalog\Model\Product\Image::getUrl()` (line 638) is just
  `return $this->imageAsset->getUrl();` — no param.
- `Magento\Catalog\Model\View\Asset\Image::getUrl()` (line 143) returns
  `$this->context->getBaseUrl() . DIRECTORY_SEPARATOR . $this->getImageInfo()` — no param.
- Building the URL directly via `ParamsBuilder` + `View\Asset\ImageFactory`
  (as `CarpetAvenue\Core\Helper\Product` does for hover images) produces **no**
  `___store`, while `$block->getImage()` on the same page produces one per product.
- No plugin in `app/code` touches any image class (`grep` over all `etc/di.xml`).

## Workaround in force

After-plugin on the block factory, stripping only the `___store` param:

- `app/code/CarpetAvenue/Core/Plugin/StripStoreParamFromImageUrl.php`
- `app/code/CarpetAvenue/Core/etc/frontend/di.xml`

## Alternatives rejected

- **Disable `web/url/use_store`** — the storefront relies on store codes in the
  path (`/en/…`, `/de/…`) for SEO. Not an option.
- **Preference/override on `ImageFactory`** — replaces a core class wholesale;
  an after-plugin touching one string is a far smaller surface.
- **Strip at the CDN (Cloudflare cache-key rule ignoring `___store`)** — fixes the
  cache fragmentation but leaves the redundant param in the HTML, and custom
  cache keys are an Enterprise-tier feature on Cloudflare.

## Re-check procedure

After any Magento upgrade:

```bash
# 1. Is the core line still there?
grep -n "PARAM_NAME . '=' . \$product->getStore()->getCode()" \
  vendor/magento/module-catalog/Block/Product/ImageFactory.php

# 2. Does a listing still render the param when the plugin is disabled?
#    (temporarily comment the plugin out of etc/frontend/di.xml, then:)
curl -s https://carpient.carpetavenue.net/en/all-carpets \
  | grep -o '/media/[^"]*___store[^"]*' | wc -l     # >0 means still broken upstream

# 3. With the plugin active, expect 0:
curl -s https://carpient.carpetavenue.net/en/all-carpets \
  | grep -o '/media/[^"]*___store[^"]*' | wc -l     # expect 0
```

If step 1 finds nothing, upstream has fixed it — drop the plugin and move this
file to **Resolved**.
