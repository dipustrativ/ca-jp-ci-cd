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

**Stores → Configuration → CarpetAvenue → Trackify → Server-Side Refunds**

| Field | Path | Value |
|---|---|---|
| Enable | `carpetavenue_analytics/measurement_protocol/active` | Yes |
| Measurement ID | `carpetavenue_analytics/measurement_protocol/measurement_id` | `G-8LK5BJC80N` |
| API Secret | `carpetavenue_analytics/measurement_protocol/api_secret` | GA4 Admin → Data Streams → *stream* → Measurement Protocol API secrets. Stored encrypted |
| Validation Mode | `carpetavenue_analytics/measurement_protocol/debug` | No in production — see below |

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
| `add_shipping_info` | `Plugin\Checkout\ShippingInformation` → customer-data queue |
| `add_payment_info` | `Plugin\Checkout\PaymentMethod` → customer-data queue |
| `select_promotion` | `Plugin\Quote\Coupon` (checkout) / `Observer\ApplyCouponCart` (cart page) → queue |
| `view_promotion` | `dl/quote.phtml`, whenever a coupon is already on the quote |
| `refund` | `Observer\ReportRefund` → **Measurement Protocol, server-side** (not the browser) |

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

## The mid-funnel checkout steps

`add_shipping_info`, `add_payment_info` and `select_promotion` are plugins in the
**global** `etc/di.xml`, not `etc/frontend`. The storefront drives the whole checkout over
the REST API, which runs in the `webapi_rest` area — a plugin under `etc/frontend` would
never execute. `etc/frontend/sections.xml` therefore also invalidates the section on REST
routes (`rest/*/V1/carts/*/shipping-information` and friends); wildcards are how Magento
matches these client-side, and Mageplaza OSC's own `sections.xml` uses the same form.

Each plugin targets the **concrete** class rather than its interface, because the guest
variants resolve the masked cart id and then delegate straight into it —
`GuestShippingInformationManagement` → `ShippingInformationManagement`,
`GuestPaymentMethodManagement` → `PaymentMethodManagement`, `GuestCouponManagement` →
`CouponManagement`. One binding each covers guests and logged-in customers. OSC reaches the
same place: `CheckoutManagement::saveCheckoutInformation()` calls
`ShippingInformationManagement::saveAddressInformation()`.

**These steps are re-saved constantly, so they must not report every call.**
`CustomerData\Trackify::queueOnce()` keys on the thing that has to change — the shipping
method, the payment method code, the coupon — and emits only when it does. Two concrete
reasons:

- OSC calls `saveAddressInformation()` on practically every field change.
- `PaymentInformationManagement::savePaymentInformation()` calls
  `PaymentMethodManagement::set()` **a second time at place-order** with the same method,
  so `add_payment_info` would double-count on every single order.

Keying on the value rather than suppressing outright keeps the repeats that are real: a
customer who switches shipping or payment method gets a second, correct event.
`Test/Unit/CustomerData/TrackifyTest.php` covers this — its failure mode is silent.

## Promotions

A coupon is the only promotion this storefront exposes, so the coupon **is** the promotion:
the code becomes `promotion_id` and the sales rule behind it supplies `promotion_name`,
without which GA4's promotion report reads "(not set)".

- **`select_promotion`** — the act of applying one. Two entry points, because the cart page
  and the checkout genuinely do not share a code path: `checkout/cart/couponPost` sets the
  code straight on the quote (hence `Observer\ApplyCouponCart`, bound to
  `controller_action_postdispatch_checkout_cart_couponPost`), while the checkout goes
  through `CouponManagement::set()` (hence `Plugin\Quote\Coupon`). Both build the payload
  with `DataLayer::getPromotionPayload()`, so the shape cannot drift; they differ only in
  `creative_slot` (`cart` vs `checkout`).
- **`view_promotion`** — the discount being on display. Rendered from `dl/quote.phtml`, so
  it repeats on every view of a discounted cart, which is what "viewed" should mean.

`getPromotionPayload()` returns an empty array when no coupon is applied, which is what
makes it safe for the template to call unconditionally. The promotion fields ride on both
the `ecommerce` object and each `items[]` entry — GA4 reads them off the items, the
container's variables read the ecommerce object.

The cart controller reports a rejected coupon through the message manager and still returns
normally, so success is determined the only reliable way: by reading the code back off the
quote afterwards. That also makes coupon *removal* a no-op here, since the code comes back
empty.

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

## Refunds are the one server-side event

Every other event reaches GA4 through the container in the browser. A refund has no
browser — it is raised in the admin, through the Sales API, or pushed by IXPF from the ERP
— so `refund` goes straight from PHP to GA4's Measurement Protocol
(`Model\MeasurementProtocol`).

**The client id is the whole problem.** The Measurement Protocol will not attribute an
event without one, and by the time a refund happens the customer's `_ga` cookie is long
gone. So `Observer\CaptureClientId` reads it at order placement and stores it in
`sales_order.trackify_client_id` (`etc/db_schema.xml`). The cookie reads
`GA1.<domain depth>.<client id>` and the id is itself dotted, so the parser counts from the
front rather than assuming a fixed number of parts. That capture is deliberately **not**
gated on the refund toggle — the id has to be taken before anyone knows they will want it,
or switching the feature on would only ever work for future orders.

An order with no client id (placed before this existed, or by a customer who declined
analytics consent) is simply not reported. Inventing an id would fabricate a user in the
reports.

### Which event it listens to, and why not the obvious one

`sales_order_creditmemo_save_commit_after`, **not** `sales_order_creditmemo_refund`. The
latter looks right and fires exactly once per refund, but it is dispatched from inside
`RefundOperation::execute()` while the transaction is still open, so a rolled-back refund
would report money returned that never was. Same reasoning as `Observer\RemoveFromWishlist`
listening on `_delete_commit_after`.

Firing on every save instead means this has to be idempotent, which is what
`sales_creditmemo.trackify_refund_sent_at` is for. It is written only once Google has
accepted the event, so a re-saved credit memo never double-reports and a failed send is
retried by the next save rather than lost. It is written with a direct `UPDATE` — calling
`$creditmemo->save()` would re-enter the observer through `save_commit_after` and recurse.

### A credit memo with no refundable lines is not sent

GA4 reads a `refund` carrying no `items` as a reversal of the **entire transaction**. A
shipping-only or adjustment-only credit memo is the opposite of that, so sending it would
reverse the whole order instead of the few hundred yen actually returned. There is no way
to express that case in GA4's model, so it is reported as nothing at all and the observer
logs why. `value` is the credit memo's own grand total rather than the sum of the items,
because refunded shipping and tax live there and nowhere else.

### Validation Mode

Sends to Google's `/debug/mp/collect`, which validates the payload and logs what is wrong
with it instead of recording it. **Nothing reaches the reports while it is on**, and
`send()` deliberately returns false in that mode so credit memos stay unflagged and
re-send once it is switched off. Use it to prove the wiring, then turn it off.

### Ceiling

The send is synchronous, with a 5s timeout, wrapped so a refund never fails because of
analytics. Refunds are rare enough that this is the right trade. If volume ever makes even
that matter, move the send to a queue consumer — the retry behaviour is already there,
since `trackify_refund_sent_at` is only written on success.

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
`cache:flush` alone leaves the browser on stale bytecode.

**If a constructor changed, or a plugin was added or removed, run `setup:di:compile`** —
not `rm -rf generated/code/...`, which is what this file used to say and is not enough.
This install has compiled DI (`generated/metadata` exists) even though it runs in developer
mode, so the constructor signatures *and* the per-area plugin lists are cached there.
`setup:upgrade --keep-generated` deliberately preserves all of it, and the symptoms are a
"Too few arguments to function __construct()" fatal and a new plugin that silently never
fires:

```
bin/magento setup:di:compile
```

In production mode add:
```
bin/magento setup:static-content:deploy -f --area frontend --theme carpetavenue/rugs ja_JP
```
