# ISSUE-003 — Magento appends `?___store=<code>` to every product image URL

| | |
|---|---|
| **Affects** | Magento 2.4.9 (core), any install with `web/url/use_store = 1` |
| **Status** | 🟢 WORKAROUND — after-plugins strip the param at all three core call sites |
| **First seen** | 2026-08-21 |
| **Last re-checked** | 2026-09-22 |

## What breaks

Every product image URL core builds comes out as:

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

Core appends the param at **three** independent call sites, each gated on the
same `Store::XML_PATH_STORE_IN_URL` flag. The original workaround only covered
the first; the PDP gallery kept shipping `…/file.jpg?___store=se` until
2026-09-22.

| Call site | Renders |
|---|---|
| `Block/Product/ImageFactory.php:176-183` | listing / grid / related thumbnails (`$block->getImage()`) |
| `Block/Product/View/Gallery.php:132-136` | the PDP gallery JSON — `thumb`, `img`, `full` and every configured extra key. `Evalent_MagicGallery` reuses this block, so its template inherits the bug |
| `ViewModel/Product/Gallery.php:54-56` | `getMainProductImage()`, the PDP preload `<link rel=preload>` / main image |

### The first call site

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

One plugin class, registered on all three types, stripping only the `___store`
param:

- `app/code/CarpetAvenue/Core/Plugin/StripStoreParamFromImageUrl.php`
  — `afterCreate()`, `afterGetGalleryImagesJson()`, `afterGetMainProductImage()`
- `app/code/CarpetAvenue/Core/etc/frontend/di.xml`
- `app/code/CarpetAvenue/Core/Test/Unit/Plugin/StripStoreParamFromImageUrlTest.php`

`afterGetGalleryImagesJson()` returns early when the JSON carries no `___store`.
That is not just an optimisation: a gallery with no images never resolves a
product, so calling `$subject->getProduct()->getStore()` unconditionally would
fatal there. Core only reaches `getProduct()` when it appended the param, so the
guard keeps the plugin exactly as safe as the code it patches.

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
# 1. How many core call sites append the param now? Expect the 3 in the table
#    above — a 4th means a new one appeared and needs its own plugin method.
grep -rn "XML_PATH_STORE_IN_URL" vendor/magento/module-catalog \
  | grep -v "/Test/"

# 2. With the plugins active, every page type must report 0.
#    (`-L` matters: the store-code paths 301 before they render.)
for u in /en/collections \
         /de/collections \
         /en/modern-carpets-lunja-berber-183x240-aw1063 \
         /de/moderne-teppiche-lunja-berber-183x240-aw1063; do
  echo -n "$u  "
  curl -skL "https://carpient.devs$u" \
    | grep -oE '/media/[^"'"'"' \]*___store[^"'"'"' \]*' | wc -l
done
```

Both the listing **and** a PDP must be in the sweep — they take different code
paths, and the PDP one was missed for a month because only listings were checked.

If step 1 finds nothing, upstream has fixed it — drop the plugin and move this
file to **Resolved**.

## Gotcha when re-testing locally

`generated/code/**/Interceptor.php` is not regenerated when you add a plugin to
an already-intercepted class, so the new plugin silently does nothing. Delete the
specific interceptors (never all of `generated/code`, see ISSUE-001), then flush
and restart php-fpm:

```bash
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && \
  rm -f generated/code/Magento/Catalog/Block/Product/View/Gallery/Interceptor.php \
        generated/code/Magento/Catalog/ViewModel/Product/Gallery/Interceptor.php && \
  php bin/magento cache:flush"
docker restart carpient-phpfpm-1 carpient-phpfpm-xdebug-1
```
