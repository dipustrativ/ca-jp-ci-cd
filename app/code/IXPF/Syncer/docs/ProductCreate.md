# Product Create (Purchase) API Documentation

**Version:** 1.1
**Module:** IXPF_Syncer
**Feature:** Bulk product create/update via purchase payload
**Last Updated:** August 18, 2026

---

## Table of Contents

1. [Overview](#overview)
2. [Configuration](#configuration)
3. [API Endpoint](#api-endpoint)
   - [Request](#request)
   - [Authentication](#authentication)
   - [Request Body](#request-body)
   - [Product Fields](#product-fields)
   - [Special Rules](#special-rules)
   - [Response](#response)
4. [Per-Store Translations (`store_values`)](#per-store-translations-store_values)
5. [Reindex — Important](#reindex--important)
6. [Error Handling & Logging](#error-handling--logging)
7. [Developer Reference](#developer-reference)

---

## Overview

The **Product Create API** lets the IXPF external system push a batch of products
in a single request. Each product is created if its SKU is new, or updated if the
SKU already exists (upsert). The call is **synchronous** — the response reports
per-SKU success/failure.

Key behaviours:

- Base product values are saved at **admin scope (store 0)** — the default shown
  by every store view.
- Optional [`store_values`](#per-store-translations-store_values) writes per-store-view
  translations (name/description text + select/multiselect option labels).
- `categories` paths are resolved to category ids (creating missing levels) and
  merged into `category_ids`.
- MSI stock is assigned from `stock_source` + `qty` — single source or a per-source list.
- `special_price = 0` clears the special price (set to `null`).
- Each product is wrapped independently: one failing SKU does not abort the batch.

---

## Configuration

**Stores → Configuration → IXPF Syncer → Purchase Settings**

| Setting | Config Path |
|---|---|
| Enable Purchase (Create) | `ixpfng_purchase/purchase/enable` |
| Required Attributes | `ixpfng_purchase/purchase/required_attributes` |
| Enable Webhook | `ixpfng_purchase/webhook/enable_webhook` |
| Webhook URL | `ixpfng_purchase/webhook/webhook_url` |
| Webhook Secret | `ixpfng_purchase/webhook/secret_code` |
| Enable Cron | `ixpfng_purchase/cronjob/enabled` |

If **Enable Purchase** is `No`, the endpoint returns `Purchase product is disabled.`
**Required Attributes** is a comma list; every product in the payload must include
each listed attribute or it is rejected (`422`).

---

## API Endpoint

### Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Products/Create` |
| **Content-Type** | `application/json` |

### Authentication

Bearer token via the `X-Auth-Token` header:

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

Invalid/missing token → error response.

### Request Body

```json
{
  "purchase": {
    "id": "IXPF-PO-0001",
    "file_name": "purchase-2026-08-18.csv"
  },
  "products": [
    {
      "sku": "RUG-123",
      "name": "Ghom Silk",
      "price": 199.00,
      "attribute_set": 4,
      "visibility": 4,
      "status": 1,
      "website_ids": "1",
      "categories": "Rugs/Silk,Rugs/Handmade",
      "category_ids": "5,12",
      "stock_source": "default",
      "qty": 3,
      "origin": "Turkey",
      "colors": "Red,Blue",
      "store_values": [
        { "store_code": "de", "name": "Ghom Seide", "origin": "Türkei", "colors": "Rot,Blau" },
        { "store_id": 3, "name": "Ghom Seda" }
      ]
    }
  ]
}
```

**Top-level required:**

| Field | Notes |
|---|---|
| `purchase.id` | IXPF purchase id (stored on the purchase order record) |
| `purchase.file_name` | Source file name |
| `products` | Non-empty array |

**Per product required:** `sku`, `name`, plus every attribute listed in the
**Required Attributes** config.

### Product Fields

| Field | Type | Notes |
|---|---|---|
| `sku` | string | Required. Immutable on update. |
| `name` | string | Required. |
| `status` | int | `1` enabled / `2` disabled. Default `2`. |
| `visibility` | int | Default `4`. |
| `attribute_set` | int | Maps to `attribute_set_id`. Default `4`. |
| `category_ids` | CSV string | Comma list of category ids. |
| `categories` | CSV of path strings | `"A/B/C,A/D"` — resolved to ids (missing levels created), merged into `category_ids`. |
| `website_ids` | CSV string | Comma list of website ids. |
| `store_ids` | CSV string | Used to decide url_key generation (see rules). Not written as an attribute. |
| `stock_source` | string _or_ array | MSI source code for the qty, or a list of `{"stock_source": "<code>", "qty": <int>}` objects for multi-source stock. See [MSI stock assignment](#msi-stock-assignment). |
| `qty` / `qty_ordered` | int | Stock quantity for the source. Ignored when `stock_source` is a list (each entry carries its own `qty`). |
| `special_price` | float | `0` → cleared (`null`). |
| `ongoing_create` | int (0/1) | Marks the purchase item `ready_for_ongoing`. |
| `<attribute_code>` | mixed | Any product EAV attribute. Select/multiselect accept **option labels** (comma-separated for multiselect); missing options are created at store 0. |
| `store_values` | array | [Per-store translations](#per-store-translations-store_values). |

### Special Rules

- **`categories`** → resolved to ids and merged into `category_ids` (same payload). Category
  assignment is **global** (no store scope).
- **`special_price = 0`** → set to `null` (removes special price), logged.
- **Select / multiselect by label** → the label is resolved to an option id; if the
  option does not exist it is created (store-0 label). The product stores the
  **option id**, which is global to all stores.
- **`url_key`** is auto-generated on **create** only when `store_ids` contains `0`,
  from seo/google-feed category name + name + dimensions + sku.
- **Update immutables** — on an existing SKU, `sku`, `ean_barcode`, `tepp_barcode`
  are not overwritten.
- **SKU match (no duplicates)** — the SKU is trimmed (ASCII whitespace, NBSP,
  full-width space) before lookup, so `" RUG-123"` updates `RUG-123` instead of
  creating a second product. Create-or-update holds a per-SKU DB lock (30 s wait),
  so an overlapping retry of the same SKU becomes an update; if the lock can't be
  taken the SKU fails with `SKU is being processed by another request, retry later`.
  The same SKU twice in one payload is created once, then updated.
- **Defaults are create-only** — `status=2`, `visibility=4`, `attribute_set=4` apply only
  when the SKU is new. On update an omitted field keeps its current value; a value sent
  in the payload always wins (create and update).
- **`attribute_set`** must be an existing product attribute set, otherwise that SKU fails
  (`Unknown product attribute_set <id>`).
- **Attributes outside the product's attribute set** are silently dropped by Magento
  (e.g. `brand`, `manufacturer`, `area` are not in set 4) — the SKU still reports success.
- **Categories replace on update** — when `category_ids` and/or `categories` are sent, they
  are the product's full category list: categories not in the payload are unassigned,
  categories that stay keep their position, new ones get position 1000. Omitted, empty
  (`""`) or all-invalid category values leave the categories untouched. This applies to
  this endpoint only — other APIs and the admin keep Magento's merge behaviour.
- **Select labels** match the admin label or, for source-model attributes
  (`gift_message_available`, `options_container`, …), the untranslated label; the
  raw option id/value is accepted too.
- **Empty value** (`""`) on update clears text, select, multiselect, price and boolean
  attributes.
- **Purchase item log** — one `ixpf_purchase_order_items` row per
  (`purchase.id`, `sku`). Resending a SKU under the same purchase id refreshes that
  row and re-points it at the newest purchase order record.

### MSI Stock Assignment

`stock_source` takes two shapes:

```json
"stock_source": "wh_tokyo", "qty": 3
```

```json
"stock_source": [
  { "stock_source": "hamburg", "qty": 3 },
  { "stock_source": "tokyo",   "qty": 5 }
]
```

Rules:

- The per-entry source key may be `stock_source` (what IXPF sends) or `source`.
  A plain list of codes — `["hamburg", "tokyo"]` — is also accepted; those entries
  carry no `qty`, so existing quantities are kept.

- Each entry writes one `inventory_source_item` row; `status` is `1` when its qty
  is `> 0`, otherwise `0`.
- **Omitting `qty`** (both shapes) keeps the source item's **current** quantity —
  it is not reset to `0`. Send `"qty": 0` explicitly to zero a source.
- Entries with an empty or missing `source` are skipped.
- The **`default`** source item is deleted when a specific source is assigned —
  *unless* the same payload also assigns `default`, in which case it is kept.
- With the list shape and no top-level `qty`, the purchase order item records the
  **sum** of the per-source quantities.
- MSI failures are **non-fatal**: logged to the daily purchase log, the product
  save still counts as success.

### Response

**HTTP 200**

```json
{
  "success": 1,
  "failed": 0,
  "product_response": {
    "RUG-123": { "status": true, "id": 2251, "message": "Product create successfully" }
  }
}
```

Per-SKU failure entry:

```json
{ "RUG-999": { "status": false, "error": "<reason>" } }
```

**HTTP 500** (whole request failed before processing):

```json
{ "error": "<message>" }
```

Common top-level errors: `Purchase product is disabled.`, `Empty product information`,
`Empty purchase information`, `IXPF Purchase id is required`, `Purchase file_name is required`.

---

## Per-Store Translations (`store_values`)

Add an optional `store_values` array to any product to make it read differently
per store view. Base values (store 0) remain the fallback. Fully optional and
backward compatible.

### Entry shape

| Key | Required | Notes |
|---|---|---|
| `store_code` **or** `store_id` | ✅ one of | Identifies the store view. `store_id` wins if both present; `store_code` resolved against the `store` table. `store 0` / unknown → entry skipped (logged). |
| `<attribute_code>` | — | Translatable attribute code + its per-store value. |

Send one entry per store view. Each entry writes only the attributes it lists;
everything else falls back to the store-0 default.

### How each attribute type is written (raw SQL)

Chosen by the attribute's `frontend_input`:

- **Text / textarea** (`name`, `description`, `short_description`, `meta_*`, …) →
  `catalog_product_entity_varchar` / `_text`, per product per store
  (unique key `entity_id, attribute_id, store_id`, upsert).
- **Select / multiselect** (`origin`, `colors`, `color`, `material`, …) →
  the product keeps the **global option id** from the base value; only the option
  **label** is translated into `eav_attribute_option_value`
  (unique key `store_id, option_id`, upsert).

> ⚠️ Option labels are **attribute-wide, not per product**. Translating the
> `Turkey` option to `Türkei` for `de` makes *every* product using that option read
> `Türkei` in `de`. Standard Magento behaviour.

**Select/multiselect pairing rule (important):** translated labels are matched to
option ids through the **base (store-0) value**, positionally:

- Comma **order must match** the base order (`"Red,Blue"` ↔ `"Rot,Blau"`).
- Comma **count must match**, else the attribute is skipped for that store (logged).
- Option ids resolved from the **base** labels; missing options created at store 0.

### Ignored keys

These are silently skipped inside `store_values` (not store-view-scoped EAV
attributes): `store_id`, `store_code`, `sku`, `categories`, `category_ids`,
`website_ids`, `store_ids`. Category and website **assignment have no store scope**
in Magento, so they cannot be translated here. Per-store **category name**
translation is a separate concern (category entity), not this endpoint.

---

## Reindex — Important

`store_values` writes use **raw SQL** and bypass Magento indexers/cache. The base
product (saved via the ORM) reindexes normally; only the store-scoped deltas do not.
After a bulk run:

```bash
php bin/magento indexer:reindex catalogsearch_fulltext catalog_product_attribute
php bin/magento cache:flush
```

If indexers are in **Update on Schedule** mode, raw writes do not enqueue changelog
rows, so store-scoped values may not appear on the frontend/search until the next
full reindex.

---

## Error Handling & Logging

- Top-level failures return `500` with `{ "error": "..." }`.
- Per-product failures are captured in `product_response` and do not abort the batch.
- `store_values` failures are **non-fatal** (base product still succeeds) and logged
  with a `[store_values]` prefix in the daily purchase log, e.g.:
  - `unknown store_code '<code>', skipped`
  - `attr '<code>' store <id>: option count mismatch (base N vs translation M), skipped`
  - `attr '<code>' store <id>: option id not found for base label '<label>', skipped`
- All raw payloads and per-product data are written to the daily purchase log.
- On completion the event `ixpf_syncer_after_purchase_product_create` is dispatched
  with `purchase_order_id`, `payload`, `result`.

---

## Developer Reference

| Class | Responsibility |
|---|---|
| `IXPF\Syncer\Controller\V1\Products\Create` | HTTP controller — token check, payload validation, purchase-order bookkeeping, delegates to the service |
| `IXPF\Syncer\Services\Purchase\Purchase` | Orchestrates create/update; base store-0 save, MSI, category positions, then the store-value writer |
| `IXPF\Syncer\Services\Purchase\StoreValueWriter` | Raw-SQL per-store writer (text values + option labels) |
| `IXPF\Syncer\Services\Purchase\ProductAttribute` | Attribute metadata + label→option-id resolution (shared cache) |
| `IXPF\Syncer\Services\Purchase\CategoryResolver` | Resolves `categories` path strings to ids |
| `IXPF\Syncer\Helper\ProductPurchase` | Reads purchase config (enable, required attributes, webhook) |

### Configuration Paths

| Configuration | Config XML Path |
|---|---|
| Enable Purchase | `ixpfng_purchase/purchase/enable` |
| Required Attributes | `ixpfng_purchase/purchase/required_attributes` |
| Enable Webhook | `ixpfng_purchase/webhook/enable_webhook` |
| Webhook URL | `ixpfng_purchase/webhook/webhook_url` |
| Webhook Secret | `ixpfng_purchase/webhook/secret_code` |
| Enable Cron | `ixpfng_purchase/cronjob/enabled` |
