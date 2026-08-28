# Assign Source API Documentation

**Version:** 1.0
**Module:** IXPF_SyncerMsi
**Feature:** Assign an MSI source to product SKUs via API
**Last Updated:** July 31, 2026

---

## Overview

Assigns a single MSI (Multi-Source Inventory) source to a list of product SKUs.
For every SKU it creates an `inventory_source_item` link on the given source with
`quantity = 0` and status **in stock** (qty is set later by sync).

Rules applied per SKU:

- SKU not found in catalog → reported in `failed`.
- SKU already linked to the source → reported in `skipped` (existing qty/status untouched).
- Otherwise → linked and reported in `added`.

Backed by Magento's native `SourceRepositoryInterface` (source validation) and
`SourceItemsSaveInterface` (link creation).

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Inventory/AssignSource` |
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
  "products": ["SKU1", "SKU2", "SKU3"]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `source_code` | `string` | yes | Target MSI source code |
| `products` | `array` \| `string` | yes | SKUs to assign. Array of SKUs, or a comma-separated string |

> `products` also accepts a comma-separated string: `"SKU1,SKU2,SKU3"`.

---

## Response

**Success — HTTP 200**

```json
{
  "success": true,
  "source_code": "hamburg",
  "summary": {
    "total": 3,
    "added": 1,
    "skipped": 1,
    "failed": 1
  },
  "added": ["SKU1"],
  "skipped": [
    { "sku": "SKU2", "reason": "Already assigned to source" }
  ],
  "failed": [
    { "sku": "SKU3", "reason": "SKU not found in catalog" }
  ]
}
```

| Field | Type | Description |
|---|---|---|
| `summary.total` | `int` | added + skipped + failed |
| `summary.added` | `int` | Newly linked SKUs |
| `summary.skipped` | `int` | Already-linked SKUs (no change) |
| `summary.failed` | `int` | SKUs that could not be linked |
| `added` | `string[]` | Linked SKUs |
| `skipped` | `object[]` | `{sku, reason}` |
| `failed` | `object[]` | `{sku, reason}` |

**Validation error — HTTP 422**

```json
{
  "success": false,
  "message": "source_code is required"
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
| Missing / empty `products` | 422 | `products is required (array or comma-separated string of SKUs)` |
| Source code does not exist | 422 | `Source not found: <detail>` |
| Invalid/missing Bearer token | 500 | `Unauthorized` |
| Per-SKU: not in catalog | 200 | in `failed[]` — `SKU not found in catalog` |
| Per-SKU: already linked | 200 | in `skipped[]` — `Already assigned to source` |
| Per-SKU: save error | 200 | in `failed[]` — `Save error: <detail>` |

> Note: source-item save is all-or-nothing. If the batch save throws, every SKU
> in that batch is reported in `failed[]`.

---

## Example

```http
POST /ixpfsyncer/V1/Inventory/AssignSource
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "source_code": "hamburg",
  "products": ["SKU1", "SKU2", "SKU3"]
}
```

---

## Developer Reference

| Class | Responsibility |
|---|---|
| `IXPF\SyncerMsi\Controller\V1\Inventory\AssignSource` | HTTP controller — token auth, payload validation, delegates, responds |
| `IXPF\SyncerMsi\Services\Inventory\AssignSource` | Validates source + SKUs, creates source items, builds result |
| `IXPF\Syncer\Helper\TokenValidator` | Validates the Bearer token against Magento OAuth tokens |
