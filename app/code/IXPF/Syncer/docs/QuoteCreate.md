# Quote / Order Create API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Admin-manual-order style order creation via API (custom price supported)
**Last Updated:** August 20, 2026

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

- Setting **super mode** + **ignore old qty** on the quote → bypasses salable/min-max qty checks and allows custom price.
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

**Stores → Configuration → IXPF Syncer → Order → Order Create Configuration → Enable = `Yes`**

| Setting | Config Path |
|---|---|
| Enable Order Create | `ixpfng_order/order_create/enabled` |
| Allowed Payment Methods | `ixpfng_order/order_create/payment_methods` |

**Allowed Payment Methods** (multiselect) — restrict which methods the
PaymentMethods endpoint returns. Leave empty = return all active methods.

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
  "guest_email": "buyer@example.com",
  "items": [
    { "sku": "CARPET-001", "qty": 2, "custom_price": 49.90 },
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
| `guest_email` | string | one of | Guest order email (used when no `customer_id`) |
| `items` | array | yes | Line items |
| `items[].sku` | string | yes | Product SKU |
| `items[].qty` | float | yes | Quantity (> 0) |
| `items[].custom_price` | float | no | Overrides unit price for that line (admin custom price) |
| `coupon_code` | string | no | Apply a cart price rule coupon at create time |
| `billing_address` | object | no | Billing address fields (optional at create — may be set before submit) |
| `shipping_address` | object | no | Shipping address; defaults to billing when omitted |

> **Addresses are optional at create.** A quote can be created with items only.
> A shipping address is required before **ShippingMethods** returns rates and
> before **Submit** places a physical order.

**Street** may be a newline string (`"line1\nline2"`) or an array.

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
  "send_email": true,
  "comment": "Order placed via IXPF phone order",
  "comment_visible_storefront": false,
  "comment_notify_customer": false
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `payment_method` | string | yes | Payment method code from Endpoint 3 |
| `shipping_method` | string | yes for physical | `carrier_method` code from Endpoint 2 |
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
| Product refused | `Cannot add sku '<sku>': <reason>` |
| No customer/guest | `customer_id or guest_email is required` |
| Missing quote id | `quote_id is required` |
| Quote already ordered | `Quote #<id> is not active (already ordered?)` |
| Missing payment | `payment_method is required` |
| Missing shipping (physical) | `shipping_method is required for a physical order` |
| Invalid/missing token | `Unauthorized` |

All errors return HTTP `500`:

```json
{ "success": false, "error": "<error message>" }
```

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
| `Controller\V1\Quote\PaymentMethods` | List active payment methods (GET) |
| `Controller\V1\Quote\ApplyCoupon` | Apply coupon (POST) |
| `Controller\V1\Quote\RemoveCoupon` | Remove coupon (POST) |
| `Controller\V1\Quote\Submit` | Place order (POST) |
| `Services\Quote\QuoteBuilder` | Build quote, add items + custom price, coupon, addresses, totals |
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

### Configuration Path

| Config | XML Path |
|---|---|
| Enable Order Create | `ixpfng_order/order_create/enabled` |
