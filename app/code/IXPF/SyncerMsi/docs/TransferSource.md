# Transfer Source API Documentation

**Version:** 1.0
**Module:** IXPF_SyncerMsi
**Feature:** Move MSI source-item quantity from one source to another
**Last Updated:** July 31, 2026

---

## Overview

Moves the **full source-item quantity** of each SKU from one MSI source to
another.

For each SKU: destination qty `+=` from-source qty, and the from-source qty is
set to `0`. Example: from-source has `4` → destination gains `4`, from-source
becomes `0`.

Rules per SKU:

- SKU has no source item on the from-source → `failed`.
- from-source qty is `0` → `failed` (nothing to move).
- Destination has no source item → **created** (status in stock) so no stock is
  lost. Existing destination status is preserved.
- Source-item rows are kept (from-source stays at qty 0), not deleted.

Backed by Magento's native `SourceRepositoryInterface` and `SourceItemsSaveInterface`.

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Inventory/TransferSource` |
| **Content-Type** | `application/json` |

### Authentication

Bearer token via the `X-Auth-Token` header (same as all Syncer endpoints):

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

### Request Body

```json
{
  "from_source": "hamburg",
  "to_source": "berlin",
  "products": ["SKU1", "SKU2", "SKU3"]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `from_source` | `string` | yes | Source code to move quantity from |
| `to_source` | `string` | yes | Source code to move quantity to (must differ from `from_source`) |
| `products` | `array` \| `string` | yes | SKUs. Array, or comma-separated string |

---

## Response

**Success — HTTP 200**

```json
{
  "success": true,
  "from_source": "hamburg",
  "to_source": "berlin",
  "summary": {
    "total": 2,
    "transferred": 1,
    "failed": 1
  },
  "transferred": [
    { "sku": "SKU1", "quantity": 4, "from_after": 0, "to_after": 10 }
  ],
  "failed": [
    { "sku": "SKU2", "reason": "SKU not assigned to from source" }
  ]
}
```

| Field | Type | Description |
|---|---|---|
| `summary.transferred` | `int` | SKUs moved |
| `summary.failed` | `int` | SKUs that could not be moved |
| `transferred[].quantity` | `float` | Amount moved |
| `transferred[].from_after` | `float` | From-source qty after (always 0) |
| `transferred[].to_after` | `float` | Destination qty after |
| `failed` | `object[]` | `{sku, reason}` |

**Validation error — HTTP 422**

```json
{
  "success": false,
  "message": "from_source and to_source must be different"
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
| Missing `from_source` / `to_source` | 422 | `from_source and to_source are required` |
| Same from/to source | 422 | `from_source and to_source must be different` |
| Missing / empty `products` | 422 | `products is required (...)` |
| from-source does not exist | 422 | `from_source '<code>' not found` |
| to-source does not exist | 422 | `to_source '<code>' not found` |
| Invalid/missing Bearer token | 500 | `Unauthorized` |
| Per-SKU: not on from-source | 200 | in `failed[]` — `SKU not assigned to from source` |
| Per-SKU: from qty is 0 | 200 | in `failed[]` — `No quantity to transfer on from source` |
| Per-SKU: save error | 200 | in `failed[]` — `Save error: <detail>` |

> Source-item save is all-or-nothing per batch — if the batch save throws, every
> processed SKU is reported in `failed[]`.

---

## Example

```http
POST /ixpfsyncer/V1/Inventory/TransferSource
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "from_source": "hamburg",
  "to_source": "berlin",
  "products": ["SKU1", "SKU2", "SKU3"]
}
```

---

## Developer Reference

| Class | Responsibility |
|---|---|
| `IXPF\SyncerMsi\Controller\V1\Inventory\TransferSource` | HTTP controller — token auth, payload validation, delegates |
| `IXPF\SyncerMsi\Services\Inventory\TransferSource` | Validates sources, moves qty from → to, builds result |
| `IXPF\Syncer\Helper\TokenValidator` | Validates the Bearer token against Magento OAuth tokens |
