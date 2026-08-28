# Unassign Source API Documentation

**Version:** 1.0
**Module:** IXPF_SyncerMsi
**Feature:** Remove an MSI source assignment from product SKUs
**Last Updated:** July 31, 2026

---

## Overview

Removes the `inventory_source_item` link of each SKU from a given MSI source
(i.e. unassigns the source from the product). Any qty on that source item is
dropped along with the link.

Rules per SKU:

- SKU has a source item on the source → deleted → `removed`.
- SKU has no source item on the source → reported in `failed`.

Reverse of [AssignSource](AssignSource.md). Backed by Magento's native
`SourceRepositoryInterface` and `SourceItemsDeleteInterface`.

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Inventory/UnassignSource` |
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
| `source_code` | `string` | yes | Source code to unassign |
| `products` | `array` \| `string` | yes | SKUs. Array, or comma-separated string |

---

## Response

**Success — HTTP 200**

```json
{
  "success": true,
  "source_code": "hamburg",
  "summary": {
    "total": 2,
    "removed": 1,
    "failed": 1
  },
  "removed": ["SKU1"],
  "failed": [
    { "sku": "SKU2", "reason": "Source item does not exist for this SKU on the source" }
  ]
}
```

| Field | Type | Description |
|---|---|---|
| `summary.removed` | `int` | SKUs unassigned |
| `summary.failed` | `int` | SKUs that could not be unassigned |
| `removed` | `string[]` | Unassigned SKUs |
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
| Missing / empty `products` | 422 | `products is required (...)` |
| Source code does not exist | 422 | `source_code '<code>' not found` |
| Invalid/missing Bearer token | 500 | `Unauthorized` |
| Per-SKU: no source item on source | 200 | in `failed[]` — `Source item does not exist for this SKU on the source` |
| Per-SKU: delete error | 200 | in `failed[]` — `Delete error: <detail>` |

> Source-item delete is all-or-nothing per batch — if the batch delete throws,
> every processed SKU is reported in `failed[]`.

---

## Example

```http
POST /ixpfsyncer/V1/Inventory/UnassignSource
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
| `IXPF\SyncerMsi\Controller\V1\Inventory\UnassignSource` | HTTP controller — token auth, payload validation, delegates |
| `IXPF\SyncerMsi\Services\Inventory\UnassignSource` | Validates source, deletes existing source items for the SKUs |
| `IXPF\Syncer\Helper\TokenValidator` | Validates the Bearer token against Magento OAuth tokens |
