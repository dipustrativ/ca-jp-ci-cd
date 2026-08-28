# Update Source Item Status API Documentation

**Version:** 1.0
**Module:** IXPF_SyncerMsi
**Feature:** Assign/update MSI source item status for SKUs
**Last Updated:** July 31, 2026

---

## Overview

Updates the **source-item status** for product SKUs already linked to a given
MSI source.

**This route only updates — it never creates source items.** A SKU that has no
existing source item on the source is reported in `failed`; use
[AssignSource](AssignSource.md) to create the link first.

Per SKU:

- SKU has a source item on the source → status updated, **existing qty preserved** → `updated`.
- SKU has no source item on the source → reported in `failed` (`Source item does not exist ...`).

Backed by Magento's native `SourceRepositoryInterface` and `SourceItemsSaveInterface`.

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Inventory/UpdateSourceItemStatus` |
| **Content-Type** | `application/json` |

### Authentication

Bearer token via the `X-Auth-Token` header (same as all Syncer endpoints):

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

### Request Body

```json
{
  "source_code": "hamburg",
  "source_item_status": 1,
  "products": ["SKU1", "SKU2", "SKU3"]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `source_code` | `string` | yes | Target MSI source code |
| `source_item_status` | `int` \| `bool` \| `string` | yes | Source-item status. `1`/`0`, `true`/`false`, or `"in stock"`/`"out of stock"` |
| `products` | `array` \| `string` | yes | SKUs. Array, or comma-separated string |

**Accepted `source_item_status` values**

| In stock (1) | Out of stock (0) |
|---|---|
| `1`, `true`, `"yes"`, `"in stock"`, `"in_stock"`, `"enabled"` | `0`, `false`, `"no"`, `"out of stock"`, `"out_of_stock"`, `"disabled"` |

---

## Response

**Success — HTTP 200**

```json
{
  "success": true,
  "source_code": "hamburg",
  "source_item_status": 1,
  "summary": {
    "total": 2,
    "updated": 1,
    "failed": 1
  },
  "updated": ["SKU1"],
  "failed": [
    { "sku": "SKU2", "reason": "Source item does not exist for this SKU on the source" }
  ]
}
```

| Field | Type | Description |
|---|---|---|
| `source_item_status` | `int` | Applied status (1 = in stock, 0 = out of stock) |
| `summary.updated` | `int` | SKUs whose status was updated |
| `summary.failed` | `int` | SKUs that could not be processed |
| `updated` | `string[]` | Status-updated SKUs (qty preserved) |
| `failed` | `object[]` | `{sku, reason}` |

**Validation error — HTTP 422**

```json
{
  "success": false,
  "message": "source_item_status is required (1/0, true/false, in stock/out of stock)"
}
```

**Failure — HTTP 500**

```json
{
  "success": false,
  "message": "<error message>"
}
```

---

## Error Handling

| Scenario | HTTP | Message |
|---|---|---|
| Empty / invalid JSON body | 422 | `Empty or invalid payload` |
| Missing `source_code` | 422 | `source_code is required` |
| Missing `source_item_status` | 422 | `source_item_status is required (...)` |
| Invalid `source_item_status` | 422 | `source_item_status invalid. Use 1/0, true/false, "in stock"/"out of stock"` |
| Missing / empty `products` | 422 | `products is required (...)` |
| Source code does not exist | 422 | `Source not found: <detail>` |
| Invalid/missing Bearer token | 500 | `Unauthorized` |
| Per-SKU: no source item on source | 200 | in `failed[]` — `Source item does not exist for this SKU on the source` |
| Per-SKU: save error | 200 | in `failed[]` — `Save error: <detail>` |

> Source-item save is all-or-nothing per batch — if the batch save throws, every
> processed SKU is reported in `failed[]`.

---

## Example

```http
POST /ixpfsyncer/V1/Inventory/UpdateSourceItemStatus
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "source_code": "hamburg",
  "source_item_status": "out of stock",
  "products": ["SKU1", "SKU2", "SKU3"]
}
```

---

## Developer Reference

| Class | Responsibility |
|---|---|
| `IXPF\SyncerMsi\Controller\V1\Inventory\UpdateSourceItemStatus` | HTTP controller — token auth, payload + status validation, delegates |
| `IXPF\SyncerMsi\Services\Inventory\UpdateSourceItemStatus` | Validates source, updates status on existing source items only (preserves qty) |
| `IXPF\Syncer\Helper\TokenValidator` | Validates the Bearer token against Magento OAuth tokens |
