# Order Bulk Action API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Multiple order actions through a single endpoint
**Last Updated:** June 18, 2026

---

## Overview

One route, many actions. The `action` field selects what runs over the given
`order_ids`. The response shape depends on the action group:

- **State actions** (`cancel`, `hold`, `unhold`) — return per-order results plus
  a summary. A single failing order does **not** stop the rest (partial success,
  HTTP 200).
- **Print actions** (`print_invoice`, `print_shipment`, `print_creditmemo`) —
  return **one combined PDF** (base64) covering all selected orders.

This module owns **core Magento actions only**. Other modules add their own
actions via an after-plugin on `BulkAction::resolveActionData()` (which returns
null for unknown actions). **IXPF_CarpetAvenue** adds these store-specific
actions (no code for them exists in this module):
- `ready_for_handling` — CarpetAvenue order status flow
- `print_shipping_slip` — combined slip PDF; per order: picking slip if it can
  still ship, else the Magento shipment packing slip (already-shipped orders)

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Orders/BulkAction` |
| **Content-Type** | `application/json` |

### Authentication

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

### Request Body

```json
{
  "action": "cancel",
  "order_ids": [123, 124, 125],
  "comment": "optional note",
  "visible_storefront": false
}
```

| Field | Type | Applies to | Description |
|---|---|---|---|
| `action` | `string` | all | Core: `cancel`, `hold`, `unhold`, `print_invoice`, `print_shipment`, `print_creditmemo`. Plugin (IXPF_CarpetAvenue): `ready_for_handling`, `print_shipping_slip` |
| `order_ids` | `int[]` | all | Order entity IDs. Also accepts CSV string `"123,124"` |
| `comment` | `string` | state actions | Appended to status-history note |
| `visible_storefront` | `bool` | state actions | Show comment to customer (default `false`) |

---

## Response — State Actions

**HTTP 200**

```json
{
  "success": true,
  "action": "cancel",
  "summary": { "total": 3, "success": 2, "failed": 1 },
  "results": [
    { "order_id": 123, "success": true, "increment_id": "100000123", "state": "canceled", "status": "canceled" },
    { "order_id": 124, "success": true, "increment_id": "100000124", "state": "canceled", "status": "canceled" },
    { "order_id": 125, "success": false, "error": "Order #100000125 cannot be cancelled (state: complete, status: complete)" }
  ]
}
```

## Response — Print Actions

**HTTP 200**

```json
{
  "success": true,
  "action": "print_invoice",
  "count": 5,
  "order_count": 3,
  "missing": [125],
  "file_name": "invoices_bulk.pdf",
  "file_content": "<base64 PDF>",
  "mime_type": "application/pdf"
}
```

| Field | Description |
|---|---|
| `count` | Number of documents merged into the PDF |
| `order_count` | Distinct orders covered |
| `missing` | Requested order IDs that had no document of this type |

## Response — Error

**HTTP 400**

```json
{ "success": false, "error": "<error message>" }
```

---

## Action → Config Gate

| Action | Enabled by |
|---|---|
| `cancel`, `hold`, `unhold` | `ixpfng_order/order_update/enabled` |
| `print_invoice` | `ixpfng_order/invoice_update/enabled` |
| `print_shipment` | `ixpfng_order/shipment_update/enabled` |
| `print_creditmemo` | `ixpfng_order/creditmemo_update/enabled` |
| `ready_for_handling` | not gated (handled by IXPF_CarpetAvenue plugin) |
| `print_shipping_slip` | not gated; picking slip if `canShip`, else shipment packing slip |

---

## Error Handling

| Scenario | Error Message |
|---|---|
| Missing `action` | `action is required` |
| Missing `order_ids` | `order_ids is required (non-empty array of order IDs)` |
| Unknown action | `Invalid action '<x>'. Allowed (core): ...` |
| Action disabled in config | `Action '<x>' is disabled in configuration` |
| No docs for print | `No invoices found for the selected orders` |
| Per-order failure (state) | captured in `results[].error`, loop continues |
| Invalid/missing token | `Unauthorized` |

---

## Examples

**Bulk cancel**

```http
POST /ixpfsyncer/V1/Orders/BulkAction
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{ "action": "cancel", "order_ids": [123, 124], "comment": "fraud review" }
```

**Mark ready for handling** (added by IXPF_CarpetAvenue plugin)

```http
{ "action": "ready_for_handling", "order_ids": [123, 124] }
```

**Combined shipment PDFs (core Magento)**

```http
{ "action": "print_shipment", "order_ids": [123, 124, 125] }
```

**Combined shipping slips / picking slips (IXPF_CarpetAvenue plugin)**

```http
{ "action": "print_shipping_slip", "order_ids": [123, 124, 125] }
```

---

## Developer Reference

| Class | Responsibility |
|---|---|
| `IXPF\Syncer\Controller\V1\Orders\BulkAction` | Core dispatch by `action`; state loop + combined PDF render. `resolveActionData()` is the plugin seam |
| `IXPF\CarpetAvenue\Plugin\Syncer\Orders\BulkActionReadyForHandling` | Adds `ready_for_handling` + `print_shipping_slip` via after-plugin on `resolveActionData()` |
| `IXPF\CarpetAvenue\Model\Order\ReadyForHandling` | Owns the ready-for-handling logic (CarpetAvenue module) |
| `IXPF\CarpetAvenue\Model\Order\PrintShippingSlip` | Builds combined picking-slip PDF via Evalent_PickingSlip |
| `Magento\Sales\Api\OrderManagementInterface` | `hold` / `unHold` |
| `IXPF\Syncer\Helper\ActivityLogger` | Records each action into `ixpf_order_api_activity` |
| `IXPF\Syncer\Helper\Logger\OrderActionLogger` | Daily log `var/log/ixpf_order_action_<date>.log` |
