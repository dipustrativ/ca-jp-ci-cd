# Inventory Sources API Documentation

**Version:** 1.0
**Module:** IXPF_SyncerMsi
**Feature:** List MSI inventory sources via API
**Last Updated:** July 31, 2026

---

## Overview

Returns the full list of Magento MSI (Multi-Source Inventory) sources. For each
source it returns the `source_code`, `name`, and `is_enabled` flag.

Backed by Magento's native `Magento\InventoryApi\Api\SourceRepositoryInterface`,
so it reflects everything under **Stores → Inventory → Sources**.

---

## Request

| Property | Value |
|---|---|
| **Method** | `GET` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Inventory/Sources` |

### Authentication

Bearer token via the `X-Auth-Token` header (same as all Syncer endpoints):

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

No query params. No request body.

---

## Response

**Success — HTTP 200**

```json
{
  "success": true,
  "data": [
    {
      "source_code": "default",
      "name": "Default Source",
      "is_enabled": true
    },
    {
      "source_code": "warehouse_uk",
      "name": "UK Warehouse",
      "is_enabled": false
    }
  ]
}
```

| Field | Type | Description |
|---|---|---|
| `source_code` | `string` | Unique MSI source code |
| `name` | `string` | Human-readable source name |
| `is_enabled` | `bool` | Whether the source is active |

**Error — HTTP 500**

```json
{
  "success": false,
  "message": "<error message>"
}
```

---

## Error Handling

| Scenario | Message |
|---|---|
| Invalid/missing Bearer token | `Unauthorized` |
| Repository failure | `<exception message>` |

---

## Example

```http
GET /ixpfsyncer/V1/Inventory/Sources
X-Auth-Token: Bearer abc123token
```

---

## Developer Reference

| Class | Responsibility |
|---|---|
| `IXPF\SyncerMsi\Controller\V1\Inventory\Sources` | HTTP controller — validates token, delegates, responds |
| `IXPF\SyncerMsi\Services\Inventory\Sources` | Loads sources via `SourceRepositoryInterface`, maps to array |
| `IXPF\Syncer\Helper\TokenValidator` | Validates the Bearer token against Magento OAuth tokens |
