# CarpetAvenue_Trackify

Google Tag Manager container + GA4 ecommerce dataLayer for carpetavenue.jp.

Replaces Anowave (`Anowave_Ec`/`Ec4`), which production runs today. Anowave is
proprietary, licensed per-install, vendored into `app/code` instead of composer, and caps
at PHP 8.2 — this build is PHP 8.5 / Magento 2.4.9.

## Configuration

**Stores → Configuration → CarpetAvenue → Trackify → Google Tag Manager**

| Field | Path | Value |
|---|---|---|
| Enable | `carpetavenue_analytics/tagmanager/active` | Yes |
| Container ID | `carpetavenue_analytics/tagmanager/container_id` | `GTM-MNDVSZTS` (dev) / `GTM-TZ87GV2` (production) |

The **container ID only** — not the `<script>` snippet, which this module renders. Any
value that is not a `GTM-XXXXXXX` id is ignored and no container is output
(`Helper\TagManagerHelper::getContainerId()`).

Everything else — the GA4 tag, Google Ads, Bing UET, Yahoo! JAPAN, Meta, Klaviyo,
CookieScript — is configured **inside the GTM container**, not here.

`Magento_GoogleGtag` must stay off (`google/gtag/analytics4/active = 0`); it only emits
`page_view` + `purchase` and would double-count against the container.

## Events

| Event | Where it comes from |
|---|---|
| `pageType`, `currentStore`, `pageLanguage`, `currency` | `head.phtml`, pushed **before** `gtm.js` |
| `visitor_data` | `CustomerData\Trackify` (customer-data section) |
| `view_item` | `dl/view_item.phtml` on `catalog_product_view` |
| `view_item_list` | `dl/item_list.phtml` on category + search |
| `select_item` | `datalayer.js`, indexed off the listing blob — no extra markup |
| `add_to_cart` / `remove_from_cart` | `Observer\AddToCart` / `Observer\RemoveFromCart` → customer-data queue |
| `add_to_wishlist` | `Observer\AddToWishlist` → customer-data queue |
| `remove_from_wishlist` | `Observer\RemoveFromWishlist` → customer-data queue |
| `view_cart` / `begin_checkout` | `dl/quote.phtml` |
| `purchase` | `dl/purchase.phtml` on `checkout_onepage_success` |

## Why JSON blobs instead of inline pushes

Payloads render as `<script type="application/json" class="trackify-dl">` and
`web/js/datalayer.js` pushes them into `dataLayer`. Two constraints force this:

- Productify's layered navigation replaces `.column.main` with a raw `innerHTML` write
  (`filter-ajax.js`), and **innerHTML never executes `<script>` tags** — an inline payload
  would silently stop firing after the first filter.
- Infinite scroll (IAS) appends whole `div.products.wrapper` nodes, so a listing blob that
  lives inside the wrapper arrives with every appended page for free.

A `MutationObserver` on `.column.main` picks up both cases, with no coupling to
Productify's `collectionUpdated` event or to IAS internals. Blobs are also inert for CSP.

This is why the listing blob **must stay inside `div.products.wrapper`** in
`app/design/frontend/carpetavenue/rugs/Magento_Catalog/templates/product/list.phtml`.

## Prices

Base currency is EUR, the store displays JPY, and Productify's `JPCurrencyConvert` plugin
applies a JPY rounding ceiling inside `Currency::convert()`. So catalog prices go through
`PriceCurrencyInterface::convert()` — that is what makes GA4 report the same ¥ figure the
customer sees. Catalog prices already include tax
(`tax/calculation/price_includes_tax = 1`), so there is no tax maths here.

Quote items are read at two different lifecycle points: on
`checkout_cart_product_add_after` totals have **not** been collected, `price_incl_tax` is
null and `getPrice()` still holds the base EUR amount. `DataLayer::getQuoteItemPrice()`
handles both — see the comment there.

`view_cart` / `begin_checkout` report `value` as the sum of the items sent, per GA4's
definition, which also avoids the quote grand total changing under the block depending on
whether shipping has been collected yet. `purchase` uses the order's own final totals.

## `pageLanguage`

Mirrors the page's `<html lang>` (`ja` on this store), read from `View\Page\Config` so it
cannot drift from the markup.

It exists for container-side tags that must vary per market. The GTM container carries one
CookieScript consent widget per CarpetAvenue market, and selecting between them on Page
Hostname is brittle — the same storefront is `carpetavenue-jp.devs` on dev,
`playground.carpetavenue.jp` on staging and `carpetavenue.jp` in production. A dataLayer
variable on `pageLanguage` is correct in all three. It is pushed before the container
loads, so it is available to tags firing on Page View / Consent Initialization.

## Things worth knowing

- `purchase` re-renders on every success-page load, so `datalayer.js` keeps a
  per-`transaction_id` `sessionStorage` guard. A reload must not book a second purchase.
- Mageplaza OSC delegates to the stock `checkout_onepage_success` handle, so no
  OSC-specific variant is needed.
- Visitor properties arrive **after** the container, because they come down customer-data
  rather than the FPC-cached page. GTM tags that need them must trigger on the
  `visitor_data` event, not on Page View.
- **Wishlist has two add paths.** Logged-in customers fire stock `wishlist_add_product`;
  guests go through BelVG_GuestWishlist, which fires its own `guestwishlist_add_product`.
  `Observer\AddToWishlist` is bound to **both** (`etc/frontend/events.xml`) — binding only
  the stock event would silently miss every guest add, which is most of them. Both events
  carry the same `['wishlist', 'product', 'item']` payload.
- **Wishlist removes have no event to hook.** Stock `Index\Remove`, BelVG's `Index\Remove`
  and BelVG's `Index\RemoveProduct` (in two places) all just call `$item->delete()` and
  dispatch nothing. `Observer\RemoveFromWishlist` therefore listens on the *model's*
  `..._delete_commit_after` event for both item classes, which is the one point every
  remove path goes through. `commit_after` rather than `delete_after` so a rolled-back
  delete never reports a removal that did not happen. It swallows and logs its own
  errors: `getProduct()` throws when the catalog product is gone, and that is precisely
  when someone is clearing a stale wishlist row — analytics must never block a delete.
- Moving a wishlist item to the cart deletes the wishlist row, so those routes emit
  **both** `remove_from_wishlist` and `add_to_cart`. That is intended.
- Never render anything from `CustomerData\Trackify` into page markup — that is the FPC
  leak the verification step greps for.
- Consent Mode v2 is present but commented out in `head.phtml`. Switch it on once
  CookieScript is configured in the container.
- Review fields (`item_reviews_count` / `item_rating_summary`) are omitted: the theme uses
  Trustpilot and loads no rating summary, so they would cost an extra query for zeros.

## After changing this module

```
bin/magento setup:upgrade
bin/magento cache:flush
docker restart carpetavenue-jp-phpfpm-1 carpetavenue-jp-phpfpm-xdebug-1
```

The restart is not optional — php-fpm runs `opcache.validate_timestamps=0`, so
`cache:flush` alone leaves the browser on stale bytecode. If a constructor changed, also
`rm -rf generated/code/CarpetAvenue/Trackify`.

In production mode add:
```
bin/magento setup:static-content:deploy -f --area frontend --theme carpetavenue/rugs ja_JP
```
