# Product Update API Documentation

**Version:** 1.0  
**Module:** IXPF_Syncer  
**Feature:** Single Product Update via API  
**Last Updated:** March 25, 2026

---

## Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Configuration](#configuration)
4. [API Endpoint](#api-endpoint)
   - [Request](#request)
   - [Authentication](#authentication)
   - [Query Parameters](#query-parameters)
   - [Request Body](#request-body)
   - [Updatable Fields](#updatable-fields)
   - [Special Rules](#special-rules)
   - [Response](#response)
5. [Examples](#examples)
   - [Update Product Price](#update-product-price)
   - [Update Product Name & Description](#update-product-name--description)
   - [Update with Use-Default (Store Scope)](#update-with-use-default-store-scope)
   - [Update Media Gallery](#update-media-gallery)
   - [Full Payload Example](#full-payload-example)
6. [Error Handling](#error-handling)
7. [Logging](#logging)
8. [Customer Group Reference](#customer-group-reference)
9. [Developer Reference](#developer-reference)
   - [Class Reference](#class-reference)
   - [Configuration Paths](#configuration-paths)

---

## Overview

The **Product Update API** allows the IXPF external system to update individual Magento products programmatically. It supports updating any attribute that has been explicitly allowed in the admin configuration.

Key behaviours:

- Only attributes listed in the **Allowed Update Attributes** configuration are processed; any others in the payload are silently ignored.
- `special_price = 0` is treated as *"remove special price"* (set to `null`).
- `price = 0` is ignored to prevent accidental zero-price updates.
- `use_default` can reset store-scoped attribute values back to the global default.
- Media gallery images are managed when `media_gallery` is included in the payload and is in the allowed attributes list.
- The endpoint is **synchronous** — the response confirms whether the update succeeded.

---

## Prerequisites

- Magento 2.4.x or higher
- `IXPF_Syncer` module installed and enabled
- A valid Magento integration token (Bearer token)
- Product Update feature enabled in the admin configuration

---

## Configuration

### Enable Product Update

1. Navigate to **Stores → Configuration → IXPF Syncer → Product Settings**
2. Under **Product Update Config**, set **Enable** to `Yes`
3. In **Allow Attributes**, select all attributes that may be updated via this API
4. Click **Save Config**

| Setting | Config Path |
|---|---|
| Enable Product Update | `ixpfng_product/product_update_config/enable` |
| Allowed Update Attributes | `ixpfng_product/product_update_config/allow_attributes` |

---

## API Endpoint

### Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Products/Update` |
| **Content-Type** | `application/json` |

### Authentication

The endpoint uses a Bearer token passed via the `X-Auth-Token` header.

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

The token is validated against Magento's OAuth integration tokens. An invalid or missing token returns a `500` error with `"Unauthorized"`.

### Query Parameters

| Parameter | Required | Type | Description |
|---|---|---|---|
| `id` | ✅ Yes | `int` | Magento product entity ID |
| `set` | ✅ Yes | `int` | Attribute set ID for the product |
| `store` | ❌ No | `int` | Store ID (default: `0` — global scope) |

### Request Body

Send a JSON body with the fields you want to update. Only keys matching allowed attributes are applied; all others are ignored.

```json
{
  "product": {
    "<attribute_code>": "<value>",
    "category_ids": [1, 2, 3],
    "website_ids": [1],
    "special_price": 89.99,
    "price": 99.99
  },
  "use_default": {
    "<attribute_code>": 1
  },
  "stock_data": {
    "manage_stock": 1,
    "use_config_manage_stock": 1
  },
  "media_gallery": [ ... ]
}
```

### Updatable Fields

All fields are gated by the **Allow Attributes** admin configuration.

| Field | Type | Notes |
|---|---|---|
| `product.<attribute_code>` | `mixed` | Any EAV attribute allowed in configuration |
| `product.category_ids` | `array` | Replaces current category assignments |
| `product.website_ids` | `array` | Replaces current website assignments |
| `product.price` | `float` | Ignored if value is `0` |
| `product.special_price` | `float` | `0` is treated as null (remove special price) |
| `use_default.<attribute_code>` | `int` (1) | Resets attribute to global default at store scope |
| `media_gallery` | `array` | Managed via `PhotoStudio::manageGalleryImages()` |

### Special Rules

#### `price = 0`
A `price` value of `0` is **silently skipped** to avoid accidentally zeroing out a product's price. The existing price is preserved. A note is written to the log.

#### `special_price = 0`
A `special_price` value of `0` is treated as **"remove special price"** and is set to `null` internally. A note is written to the log.

#### `use_default`
Only effective when `store != 0`. Include the attribute code with value `1` to reset the store-level override back to the global default:

```json
{
  "use_default": {
    "name": 1,
    "description": 1
  }
}
```

#### `category_ids` / `website_ids`
If not present in the payload, the product's existing values are preserved. If present, they **replace** the current assignments entirely.

#### `stock_data`
If omitted from the payload, a default stock data block is applied:
```json
{
  "manage_stock": 1,
  "use_config_manage_stock": 1
}
```

---

### Response

**Success — HTTP 200**

```json
{
  "success": true,
  "message": "Product updated successfully."
}
```

**Error — HTTP 500**

```json
{
  "error": "<error message>"
}
```

Common error messages:

| Message | Cause |
|---|---|
| `"Unauthorized"` | Invalid or missing Bearer token |
| `"Missing product id parameter"` | `id` query param not provided |
| `"Missing attribute set parameter"` | `set` query param not provided |
| `"Empty product information"` | Request body is empty or not valid JSON |
| `"Product update configuration disable"` | Feature is disabled in admin config |
| `"The product that was requested doesn't exist."` | No product found with the given `id` |

---

## Examples

### Update Product Price

```http
POST /ixpfsyncer/V1/Products/Update?id=42&set=4&store=0
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "product": {
    "price": 149.99,
    "special_price": 129.99
  }
}
```

---

### Update Product Name & Description

```http
POST /ixpfsyncer/V1/Products/Update?id=42&set=4&store=1
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "product": {
    "name": "Premium Wool Rug 200x300",
    "description": "<p>Hand-crafted premium wool rug.</p>",
    "short_description": "Premium wool rug, 200x300 cm."
  }
}
```

---

### Update with Use-Default (Store Scope)

Reset `name` and `description` to the global default value for store ID `1`:

```http
POST /ixpfsyncer/V1/Products/Update?id=42&set=4&store=1
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "product": {},
  "use_default": {
    "name": 1,
    "description": 1
  }
}
```

---

### Update Media Gallery

```http
POST /ixpfsyncer/V1/Products/Update?id=42&set=4&store=0
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "product": {},
  "media_gallery": [
    {
      "url": "https://storage.example.com/images/rug-front.jpg",
      "label": "Front view",
      "position": 1,
      "disabled": 0,
      "types": ["image", "small_image", "thumbnail"]
    },
    {
      "url": "https://storage.example.com/images/rug-back.jpg",
      "label": "Back view",
      "position": 2,
      "disabled": 0,
      "types": []
    }
  ]
}
```

> **Note:** `media_gallery` must be included in the **Allow Attributes** admin configuration.

---

### Full Payload Example

```http
POST /ixpfsyncer/V1/Products/Update?id=42&set=4&store=0
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "product": {
    "name": "Classic Carpet 160x230",
    "price": 199.00,
    "special_price": 0,
    "status": 1,
    "visibility": 4,
    "category_ids": [5, 12],
    "website_ids": [1],
    "color": "beige",
    "material": "wool"
  },
  "use_default": {},
  "stock_data": {
    "manage_stock": 1,
    "use_config_manage_stock": 1
  }
}
```

---

## Error Handling

All exceptions are caught at the controller level. The HTTP response will be `500` with a JSON body:

```json
{
  "error": "<exception message>"
}
```

No partial updates are persisted if an exception is thrown before `$product->save()`.

---

## Logging

All update activity is logged to a daily rotating log file:

```
var/log/ixpf_product_update_<YYYY-MM-DD>.log
```

Logged events include:

- Incoming request parameters (product ID, store ID, attribute set)
- Full raw payload (JSON)
- Prepared attribute payload (after filtering by allowed attributes)
- `use_default` attribute list (if applicable)
- Special-case notes (`price = 0` skipped, `special_price` nulled)

---

## Customer Group Reference

Customer groups are used when configuring tier prices or group-specific pricing on products. Use the values below as `customer_group_id` when sending tier price data.

| `customer_group_id` | `customer_group_code` | Notes |
|---|---|---|
| `0` | `NOT LOGGED IN` | Guest / unauthenticated visitors |
| `1` | `General` | Default logged-in customer group |
| `2` | `Wholesale` | Wholesale customers |
| `3` | `Retailer` | Retailer customers |

> **Note:** These are the default Magento customer groups. Your installation may have additional or different groups. To retrieve the live list, query the `customer_group` table:
> ```sql
> SELECT customer_group_id, customer_group_code FROM customer_group ORDER BY customer_group_id;
> ```

---

## Developer Reference

### Class Reference

| Class | Responsibility |
|---|---|
| `IXPF\Syncer\Controller\V1\Products\Update` | HTTP controller — validates token, parses request, loads product, delegates to Magento helpers |
| `IXPF\Syncer\Helper\Helpers` | Reads `enable` config flag and `allow_attributes` configuration |
| `IXPF\Syncer\Helper\TokenValidator` | Validates the Bearer token against Magento OAuth tokens |
| `IXPF\Syncer\Helper\Logger\ProductUpdateLogger` | Daily-rotating log writer for product update events |
| `Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper` | Initializes a product model from raw data array |
| `Magento\Catalog\Controller\Adminhtml\Product\Initialization\Helper\AttributeFilter` | Handles `use_default` attribute reset logic |
| `IXPF\Syncer\Helper\PhotoStudio` | Manages media gallery image assignment |

### Configuration Paths

| Configuration | Admin Path | Config XML Path |
|---|---|---|
| Enable Product Update | Stores → Config → IXPF Syncer → Product Settings → Product Update Config → Enable | `ixpfng_product/product_update_config/enable` |
| Allowed Update Attributes | Stores → Config → IXPF Syncer → Product Settings → Product Update Config → Allow Attributes | `ixpfng_product/product_update_config/allow_attributes` |
