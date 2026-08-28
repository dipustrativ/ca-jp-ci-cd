# Order Cancel API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Order Cancellation via API
**Last Updated:** June 11, 2026

---

## Overview

Dedicated endpoint to cancel a Magento order. Uses Magento's native `Order::cancel()`, which:

- Restores reserved stock (via MSI or legacy CatalogInventory)
- Sets `qty_canceled` on each order item
- Triggers the `sales_order_cancel_after` event
- Adds a status history comment

> The [Order Update endpoint](OrderUpdate.md) can also cancel by sending `"status": "canceled"`. This endpoint is the explicit alternative with optional comment support.

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Orders/Cancel?id=<order_id>` |
| **Content-Type** | `application/json` |

### Authentication

Bearer token via the `X-Auth-Token` header (same as all Syncer endpoints):

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

### Request Body (optional)

```json
{
  "comment": "Cancelled per customer request",
  "visible_storefront": false
}
```

Every cancellation adds the status history note **"Order canceled by IXPF API"**. If `comment` is provided, it is appended: `Order canceled by IXPF API — <comment>`.

| Field | Type | Default | Description |
|---|---|---|---|
| `comment` | `string` | — | Extra text appended to the history note |
| `visible_storefront` | `bool` | `false` | Show the comment to the customer |

---

## Response

**Success — HTTP 200**

```json
{
  "success": true,
  "message": "Order #100000123 cancelled",
  "data": {
    "order_id": 123,
    "increment_id": "100000123",
    "state": "canceled",
    "status": "canceled"
  }
}
```

**Error — HTTP 500**

```json
{
  "error": "<error message>"
}
```

---

## Error Handling

| Scenario | Error Message |
|---|---|
| Missing `id` query parameter | `Order ID is required` |
| Order not found | `Order #<id> not found` |
| Order not in `new` state | `Order #<increment_id> can only be cancelled in the 'new' state. Current state: <state>, status: <status>` |
| Order not cancellable (invoiced/shipped/already canceled/on hold) | `Order #<increment_id> cannot be cancelled. State: <state>, status: <status>` |
| Invalid/missing Bearer token | `Unauthorized` |

---

## Logging

All requests and errors are logged by `IXPF\Syncer\Helper\Logger\OrderLogger`:

```
var/log/ixpf_order_<YYYY-MM-DD>.log
```

---

## Example

```http
POST /ixpfsyncer/V1/Orders/Cancel?id=123
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "comment": "Cancelled — payment never received",
  "visible_storefront": true
}
```

---

## Developer Reference

| Class | Responsibility |
|---|---|
| `IXPF\Syncer\Controller\V1\Orders\Cancel` | HTTP controller — validates token, enforces `new` state, checks `canCancel()`, cancels, saves |
| `IXPF\Syncer\Helper\TokenValidator` | Validates the Bearer token against Magento OAuth tokens |
| `IXPF\Syncer\Helper\Logger\OrderLogger` | Daily-rotating log writer for order events |
