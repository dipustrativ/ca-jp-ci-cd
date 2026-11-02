# Quote / Order Create API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Admin-manual-order style order creation via API (custom price supported)
**Last Updated:** August 21, 2026

---

## Overview

The **Quote / Order Create API** lets the IXPF external system build a Magento
order the same way the admin **Sales → Orders → Create New Order** screen does,
including **custom per-line prices**.

It is a **stateful, multi-step flow**:

1. **Create** a quote (customer + items + custom prices + addresses) → returns `quote_id`.
2. **Get shipping methods** available for the quote.
3. **Get payment methods** active for the quote's store.
4. **Submit** the quote (choose shipping + payment) → places the order.

Admin parity is achieved by:

- Setting **super mode** + **ignore old qty** on the quote → allows custom price. Requested qty is **still validated** explicitly against real MSI salable qty (see *Stock / qty validation* below), so a line can never exceed what is actually sellable.
- **Emulating the target store** during totals collection → correct price, currency and tax scope.
- Placing the order via `QuoteManagement::submit($quote)` on the in-memory quote (the same path admin uses), not `placeOrder($id)` which would reload the quote and drop the runtime flags.

---

## Prerequisites

- Magento 2.4.x or higher
- `IXPF_Syncer` module installed and enabled
- A valid Magento integration token (Bearer token)
- Order Create feature enabled in the admin configuration

---

## Configuration

**Admin path:** Stores → Configuration → **IXPF Syncer → Order → Order Create Configuration**

| Setting | Config Path | Type | Default | Scope |
|---|---|---|---|---|
| Enable (on/off) | `ixpfng_order/order_create/enabled` | Yes/No | **No (`0`)** | Default only |
| Allowed Payment Methods | `ixpfng_order/order_create/payment_methods` | multiselect | *(empty)* | Default only |
| Allow Over-sell (admin parity) | `ixpfng_order/order_create/allow_oversell` | Yes/No | **No (`0`)** | Default only |

### On / Off switch

The whole Quote/Create + Submit flow is gated by the **Enable** flag
(`ixpfng_order/order_create/enabled`). It ships **disabled (`0`)** — turn it on
before using the API.

- **On** (`Yes` / `1`) → endpoints work.
- **Off** (`No` / `0`, default) → every call returns HTTP `500`
  `Order create is disabled in config` (checked right after token auth in the
  `Create` controller via `Helper\Config::isOrderCreateEnabled()`).

Scope is **default (global) only** (`showInWebsite=0`, `showInStore=0`) — one
switch for the whole install.

Enable via CLI instead of the admin:

```bash
bin/magento config:set ixpfng_order/order_create/enabled 1
bin/magento cache:clean config
```

### Allowed Payment Methods

Multiselect (`ixpfng_order/order_create/payment_methods`) — restrict which methods
the **PaymentMethods** endpoint returns. Leave empty = return all active methods.

### Allow Over-sell (admin parity)

`ixpfng_order/order_create/allow_oversell` — ships **disabled (`0`)**.

- **Off** (default) → requested-qty is validated against MSI salable qty; a line
  cannot exceed what is actually sellable (step 4 below).
- **On** (`1`) → the requested-qty check is **skipped**, so any product can be
  added regardless of available qty — exactly like the admin **Create New Order**
  screen (which runs pure super mode). The product must **still** exist, be
  **enabled** and **salable** (steps 1–3 below still enforced).

Turn this on when the API must accept the same products the admin can add (e.g.
made-to-order carpets with 0 on-hand stock). Enable via CLI:

```bash
bin/magento config:set ixpfng_order/order_create/allow_oversell 1
bin/magento cache:clean config
```

> **Price note:** a product with **0 catalog price** at the target store still
> shows `price: 0` — that mirrors admin, where staff type the price per line.
> Send `items[].custom_price` to set the unit price for such lines.

### `no_discount` is per-line

- **`no_discount`** is a **per-line request flag**, not a config value.

---

## Authentication

All endpoints use a Bearer token via the `X-Auth-Token` header.

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

Invalid/missing token → HTTP `500` with `"Unauthorized"`.

---

## Endpoint 1 — Create / Update Quote (upsert)

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Quote/Create` |
| **Content-Type** | `application/json` |

Omit `quote_id` → **creates** a new quote. Include `quote_id` → **updates** that
quote. On update: **items are full-replaced** from the payload (payload = desired
state), addresses/coupon updated only when provided, `store_id` and customer kept
from the existing quote unless supplied. Response `mode` = `created` | `updated`.

### Request Body

```json
{
  "store_id": 1,
  "customer_id": 123,
  "customer_group_id": 2,
  "guest_email": "buyer@example.com",
  "items": [
    { "sku": "CARPET-001", "qty": 2, "custom_price": 49.90, "no_discount": true },
    { "sku": "CARPET-002", "qty": 1 }
  ],
  "billing_address": {
    "firstname": "John",
    "lastname": "Doe",
    "company": "Carpet Avenue AS",
    "street": "Karl Johans gate 1",
    "city": "Oslo",
    "region": "Oslo",
    "region_id": 0,
    "postcode": "0154",
    "country_id": "NO",
    "telephone": "+4712345678",
    "email": "buyer@example.com"
  },
  "shipping_address": {
    "firstname": "John",
    "lastname": "Doe",
    "street": "Karl Johans gate 1",
    "city": "Oslo",
    "postcode": "0154",
    "country_id": "NO",
    "telephone": "+4712345678"
  }
}
```

### Fields

| Field | Type | Required | Description |
|---|---|---|---|
| `quote_id` | int | no | Omit = create new. Provide = update this quote (items full-replaced) |
| `store_id` | int | create only | Target store view id (drives price/currency/tax scope). Ignored on update |
| `customer_id` | int | one of | Registered customer id — quote assigned to this customer |
| `customer_group_id` | int | no | Override the customer group for this quote (admin "select customer group" parity — **changes VAT/tax class**). Requires `customer_id`; validated against existing groups. Omit = use the customer's own group |
| `guest_email` | string | one of | Guest order email (used when no `customer_id`) |
| `items` | array | yes | Line items |
| `items[].sku` | string | yes | Product SKU |
| `items[].qty` | float | yes | Quantity (> 0). Validated against salable qty — see *Stock / qty validation* |
| `items[].custom_price` | float | no | Overrides unit price for that line (admin custom price). Applied **only when > 0**; omit it, or send `0`/`null`/`""`, to use the catalog price |
| `items[].no_discount` | bool | no | `true` = exclude this line from coupon / cart-price-rule discounts; other lines still get them. Default `false`. Accepts `true`/`1`/`"true"`/`"yes"` |
| `coupon_code` | string | no | Apply a cart price rule coupon at create time |
| `shipping_method` | string | no | Set shipping method code (from ShippingMethods) — validated against available rates |
| `billing_address` | object | no | Billing address fields (optional at create — may be set before submit) |
| `shipping_address` | object | no | Shipping address; defaults to billing when omitted |

> **Addresses are optional at create.** A quote can be created with items only.
> A shipping address is required before **ShippingMethods** returns rates and
> before **Submit** places a physical order.

**Street** may be a newline string (`"line1\nline2"`) or an array.

### Customer group override & VAT

`customer_group_id` mirrors the admin *Create New Order* "customer group"
selector — it changes the tax class used for the quote, so **VAT recalculates**
(e.g. switching to a Tax-Free / reverse-charge group zeroes VAT).

- Requires a registered `customer_id`; ignored for guest orders.
- Validated against existing groups — an unknown id is rejected (see *Error Handling*).
- Omit it to keep the customer's own group.
- The chosen group is stored on the quote, so **ShippingMethods**, **Set
  Shipping Method**, **Apply Coupon** and **Submit** all keep the same
  group's tax without resending the field.

Minimal example:

```json
{
  "store_id": 3,
  "customer_id": 79,
  "customer_group_id": 2,
  "items": [{ "sku": "CARPET-001", "qty": 1 }]
}
```

### Stock / qty validation

Even though the quote runs in super mode (for custom price), each line's `qty` is
validated **explicitly** against real salable stock before it is added — super
mode does **not** open a hole to over-sell.

Per line, in order:

1. SKU exists in the store → else `SKU '<sku>' does not exist`
2. Product enabled → else `SKU '<sku>' is disabled and cannot be ordered`
3. Product salable at all → else `SKU '<sku>' is not available (out of stock or not sold in this store)`
4. **Requested qty ≤ salable qty** (MSI, honors backorders / min-max / manage-stock)
   → else `SKU '<sku>' cannot be ordered: <reason> (requested <x>, available <y>)`
   **Skipped** when *Allow Over-sell* config is on (admin parity — steps 1–3 still apply).

Stock is resolved from the quote store's **website → MSI stock**. Example refusal
when a line asks for more than exists:

```
SKU 'OSK6497' cannot be ordered: Backorders are disabled; Manage stock is enabled; The requested qty is not available (requested 4, available 1)
```

### Per-line coupon exclusion (`no_discount`)

Set `"no_discount": true` on any line to exclude it from the applied coupon /
cart-price-rule. Other lines still receive the discount. This mirrors the admin
**Create New Order** screen where you can remove the discount from a single row.

Behavior depends on the **coupon type**:

| Coupon type | Excluded line | Remaining lines |
|---|---|---|
| **Percent** (`by_percent`) | gets `0` discount | unchanged — each keeps its own % |
| **Fixed-per-item** (`by_fixed`) | gets `0` discount | unchanged |
| **Whole-cart fixed** (`cart_fixed`) | gets `0` discount | the **full** cart amount redistributes onto them (total cart discount stays constant) |

The flag is **stateless / full-replace**: on update the whole item list is rebuilt,
so omit `no_discount` (or send `false`) to bring the discount back, resend `true`
to exclude again. Fully reversible per request.

### Response — HTTP 200

```json
{
  "success": true,
  "data": {
    "quote_id": 456,
    "store_id": 1,
    "is_virtual": false,
    "items": [
      { "item_id": 1, "sku": "CARPET-001", "name": "Carpet 001", "qty": 2, "price": 49.90, "row_total": 99.80 },
      { "item_id": 2, "sku": "CARPET-002", "name": "Carpet 002", "qty": 1, "price": 120.00, "row_total": 120.00 }
    ],
    "totals": {
      "subtotal": 219.80,
      "shipping_handling": 0.0,
      "shipping_handling_incl_tax": 0.0,
      "tax": 54.95,
      "grand_total_excl_tax": 219.80,
      "grand_total_incl_tax": 274.75
    }
  }
}
```

---

## Endpoint 2 — Get Shipping Methods

| Property | Value |
|---|---|
| **Method** | `GET` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Quote/ShippingMethods?quote_id=456` |

### Response — HTTP 200

```json
{
  "success": true,
  "data": {
    "quote_id": 456,
    "is_virtual": false,
    "methods": [
      {
        "code": "flatrate_flatrate",
        "carrier_code": "flatrate",
        "method_code": "flatrate",
        "carrier_title": "Flat Rate",
        "method_title": "Fixed",
        "price": 10.0
      }
    ],
    "totals": {
      "subtotal": 219.80,
      "shipping_handling": 0.0,
      "shipping_handling_incl_tax": 0.0,
      "tax": 54.95,
      "grand_total_excl_tax": 219.80,
      "grand_total_incl_tax": 274.75
    }
  }
}
```

Virtual quotes return an empty `methods` array with `is_virtual: true`.

**Totals block** (returned by Create, ShippingMethods, and Submit) mirrors the admin order summary:

| Field | Admin label |
|---|---|
| `coupon_code` | Applied coupon code (empty when none) |
| `subtotal` | Subtotal (excl. tax) |
| `subtotal_incl_tax` | Subtotal (incl. tax) — admin "Subtotal" when catalog prices include tax |
| `discount_amount` | Discount (negative value) |
| `shipping_handling` | Shipping & Handling (excl. tax) |
| `shipping_handling_incl_tax` | Shipping & Handling (incl. tax) |
| `tax` | Tax |
| `grand_total_excl_tax` | Grand Total (Excl. Tax) |
| `grand_total_incl_tax` | Grand Total (Incl. Tax) |

> **Shipping value:** at Create and ShippingMethods no carrier is selected yet,
> so `shipping_handling` is `0` — the selectable rates are listed in `methods[].price`.
> The chosen shipping cost is applied at **Submit**, whose `totals` block is the
> authoritative final summary.

---

## Endpoint 2b — Set Shipping Method

Set/update the shipping method on an existing quote and get refreshed totals
(shipping reflected). Use a `code` returned by ShippingMethods.

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Quote/SetShippingMethod?quote_id=456` |
| **Content-Type** | `application/json` |

### Request Body

```json
{ "shipping_method": "matrixrate_matrixrate_4108" }
```

### Response — HTTP 200

Full quote summary (items + totals), `mode` = `shipping_method_set`, plus
`shipping_method`. Invalid/unavailable method → HTTP `500`
`Shipping method '<code>' is not available for this quote`.

> **Use a fresh code.** Some carriers (e.g. matrixrate) embed a row id in the
> method code that can change on re-import. Always take the code from a recent
> ShippingMethods response for the same quote.

---

## Endpoint 3 — Get Payment Methods

| Property | Value |
|---|---|
| **Method** | `GET` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Quote/PaymentMethods?quote_id=456` |

### Response — HTTP 200

```json
{
  "success": true,
  "data": {
    "quote_id": 456,
    "methods": [
      { "code": "checkmo", "title": "Check / Money order" },
      { "code": "banktransfer", "title": "Bank Transfer Payment" }
    ]
  }
}
```

> **Filtered by config.** Only methods selected in **Allowed Payment Methods**
> (`ixpfng_order/order_create/payment_methods`) are returned. Empty = all active.

> **Offline methods recommended.** Online gateways (card, PayPal, etc.) will
> attempt a real authorize/capture on submit. For manual/admin-style orders use
> offline methods such as `checkmo` or `banktransfer`.

---

## Endpoint 3b — Apply / Remove Coupon

Apply, change, or remove a coupon on an existing quote and get refreshed totals.
Send an empty `coupon_code` to remove the current coupon.

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Quote/ApplyCoupon?quote_id=456` |
| **Content-Type** | `application/json` |

### Request Body

```json
{ "coupon_code": "XMAS10" }
```

### Response — HTTP 200

Returns the **same full shape** as Create/Update (items + totals), with
`mode` = `coupon_applied`.

```json
{
  "success": true,
  "data": {
    "quote_id": 262140,
    "store_id": 1,
    "is_virtual": false,
    "items": [ { "...": "same item shape as Create" } ],
    "totals": {
      "coupon_code": "TESTCODE10",
      "subtotal": 3388,
      "subtotal_incl_tax": 4235,
      "discount_amount": -423,
      "shipping_handling": 0,
      "shipping_handling_incl_tax": 0,
      "tax": 762.4,
      "grand_total_excl_tax": 3049.6,
      "grand_total_incl_tax": 3812
    },
    "mode": "coupon_applied"
  }
}
```

An invalid coupon returns HTTP `500` with `Coupon '<code>' is not valid for this quote`.

---

## Endpoint 3c — Remove Coupon

Removes the current coupon from the quote and recollects totals. No body needed.

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Quote/RemoveCoupon?quote_id=456` |

### Response — HTTP 200

Same full shape as Create/Update, with `mode` = `coupon_removed` and
`totals.coupon_code` empty (`discount_amount` back to `0`).

---

## Endpoint 4 — Submit (Place Order)

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Quote/Submit?quote_id=456` |
| **Content-Type** | `application/json` |

### Request Body

```json
{
  "shipping_method": "flatrate_flatrate",
  "payment_method": "checkmo",
  "order_email": "buyer@example.com",
  "send_email": true,
  "comment": "Order placed via IXPF phone order",
  "comment_visible_storefront": false,
  "comment_notify_customer": false
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `payment_method` | string | yes | Payment method code from Endpoint 3 |
| `shipping_method` | string | yes for physical | `carrier_method` code from Endpoint 2. Optional if already set via SetShippingMethod / Create |
| `order_email` | string | no | Email stored on the order — **all order mails** (confirmation + comment notifications) go here. Validated. Omit = use the customer's email (admin manual-order parity) |
| `send_email` | bool | no | Send order confirmation email (default `false`) |
| `comment` | string | no | Order status-history comment added after placing |
| `comment_visible_storefront` | bool | no | Show the comment to customer on storefront (default `false`) |
| `comment_notify_customer` | bool | no | Email the comment to customer (default `false`) |

### Response — HTTP 200

```json
{
  "success": true,
  "data": {
    "quote_id": 456,
    "order_id": 789,
    "increment_id": "100000789",
    "status": "pending",
    "totals": {
      "subtotal": 219.80,
      "shipping_handling": 10.0,
      "shipping_handling_incl_tax": 12.50,
      "tax": 57.45,
      "grand_total_excl_tax": 229.80,
      "grand_total_incl_tax": 287.25
    }
  }
}
```

---

## Error Handling

| Scenario | Message |
|---|---|
| Feature disabled | `Order create is disabled in config` |
| Missing store | `store_id is required` |
| No items | `items is required` |
| Bad item | `Each item needs a sku and qty > 0` |
| Unknown SKU | `SKU '<sku>' does not exist` |
| Disabled product | `SKU '<sku>' is disabled and cannot be ordered` |
| Not salable | `SKU '<sku>' is not available (out of stock or not sold in this store)` |
| Qty over stock | `SKU '<sku>' cannot be ordered: <reason> (requested <x>, available <y>)` |
| Product refused | `SKU '<sku>' cannot be added: <reason>` |
| No customer/guest | `customer_id or guest_email is required` |
| Group override without customer | `customer_group_id override requires a registered customer_id` |
| Unknown group | `customer_group_id '<id>' does not exist` |
| Missing quote id | `quote_id is required` |
| Quote already ordered | `Quote #<id> is not active (already ordered?)` |
| Missing payment | `payment_method is required` |
| Invalid order email | `order_email is not a valid email` |
| Missing shipping (physical) | `shipping_method is required for a physical order` |
| Invalid/missing token | `Unauthorized` |

All errors return HTTP `500`:

```json
{ "success": false, "error": "<error message>" }
```

---

## Test Cases

All cases below verified live (store 1 / website 1, guest group).
Data used: `OSK6497` (price 967, salable qty 1), `OSK6837` (price 385, salable qty 1),
coupon `TESTCODE10` = 10% (`by_percent`), a temp `cart_fixed` = 100-off coupon.

### A. Stock / qty validation

| # | Payload `items` | Expected | Verified result |
|---|---|---|---|
| A1 | `[{sku:OSK6497, qty:4}]` (salable 1) | **reject** | ❌ `SKU 'OSK6497' cannot be ordered: Backorders are disabled; Manage stock is enabled; The requested qty is not available (requested 4, available 1)` |
| A2 | `[{sku:OSK6497, qty:1}]` | **accept** | ✅ quote created, item qty 1 |
| A3 | `[{sku:NOPE, qty:1}]` | reject | `SKU 'NOPE' does not exist` |
| A4 | `[{sku:<disabled>, qty:1}]` | reject | `SKU '<sku>' is disabled and cannot be ordered` |
| A5 | `[{sku:<oos>, qty:1}]` | reject | `SKU '<sku>' is not available (out of stock or not sold in this store)` |
| A6 | `[{sku:X}]` / `qty:0` | reject | `Each item needs a sku and qty > 0` |

### B. `no_discount` with **percent** coupon (`TESTCODE10`, 10%)

Items: `OSK6497` + `OSK6837`, coupon applied.

| # | product1 `no_discount` | OSK6497 discount | OSK6837 discount | cart discount |
|---|---|---|---|---|
| B1 | *(absent)* baseline | 97.00 | 77.00 | −174 |
| B2 | `true` (excluded) | **0.00** | 77.00 | −77 |

→ Percent is per-item: excluding one just drops its share, the other is unchanged.

### C. `no_discount` with **cart_fixed** coupon (100 off whole cart)

| # | product1 `no_discount` | OSK6497 discount | OSK6837 discount | cart discount |
|---|---|---|---|---|
| C1 | *(absent)* baseline | 56.00 | 44.00 | −100 |
| C2 | `true` (excluded) | **0.00** | **100.00** | −100 |

→ Whole-cart fixed redistributes the **full** amount onto the remaining line
(matches admin Create-Order behavior). Total cart discount constant.

### D. Toggle across updates (same `quote_id`)

Percent coupon `TESTCODE10`, single quote reused via `quote_id`.

| Step | Action | OSK6497 | OSK6837 | cart discount |
|---|---|---|---|---|
| D1 | create, product1 `no_discount:true` | 0.00 | 77.00 | −77 |
| D2 | update, flag removed | **97.00** | 77.00 | −174 |
| D3 | update, `no_discount:true` again | 0.00 | 77.00 | −77 |

→ Full-replace item list rebuilds the flag every request — fully reversible, no stale state.

---

## Logging

Written by `IXPF\Syncer\Helper\Logger\QuoteLogger` (daily rotating):

```
var/log/ixpf/quote/quote-<YYYY-MM-DD>.log
```

Logged: raw create payload, raw submit payload, placed order increment id, all errors.

---

## Developer Reference

| Class | Responsibility |
|---|---|
| `Controller\V1\Quote\Create` | Create quote (POST) |
| `Controller\V1\Quote\ShippingMethods` | List shipping rates (GET) |
| `Controller\V1\Quote\SetShippingMethod` | Set/update shipping method (POST) |
| `Controller\V1\Quote\PaymentMethods` | List active payment methods (GET) |
| `Controller\V1\Quote\ApplyCoupon` | Apply coupon (POST) |
| `Controller\V1\Quote\RemoveCoupon` | Remove coupon (POST) |
| `Controller\V1\Quote\Submit` | Place order (POST) |
| `Services\Quote\QuoteBuilder` | Build quote, add items + custom price, qty validation, `no_discount`, coupon, addresses, totals |
| `Services\Quote\ShippingMethods` | Collect grouped shipping rates |
| `Services\Quote\PaymentMethods` | Active payment method list for store |
| `Services\Quote\ApplyCoupon` | Set/remove coupon, recollect totals |
| `Services\Quote\QuoteSubmit` | Set methods, `QuoteManagement::submit()` |
| `Helper\Config` | Reads `ixpfng_order/order_create/enabled` |
| `Helper\Logger\QuoteLogger` | Daily log writer |

### Custom price mechanic

```php
$product->setIsSuperMode(true);
$item = $quote->addProduct($product, $qty);
$item->setCustomPrice($price);
$item->setOriginalCustomPrice($price);
$item->getProduct()->setIsSuperMode(true);
// then: $quote->setTotalsCollectedFlag(false)->collectTotals();
```

### Qty validation mechanic

Super mode is set for custom price, so `addProduct` no longer blocks over-sell.
Qty is checked explicitly against MSI before adding:

```php
$stockId = $this->stockByWebsiteIdResolver->execute($store->getWebsiteId())->getStockId();
$res = $this->isProductSalableForRequestedQty->execute($sku, $stockId, $qty);
if (!$res->isSalable()) {
    // reasons from $res->getErrors(); available from getProductSalableQty->execute($sku,$stockId)
    throw new Exception("SKU '$sku' cannot be ordered: ...");
}
```

### `no_discount` mechanic

```php
if (!empty($row['no_discount'])) {
    $item->setNoDiscount(1); // SalesRule\Validator skips this item (line ~466)
}
```

### Configuration Path

| Config | XML Path |
|---|---|
| Enable Order Create | `ixpfng_order/order_create/enabled` |
