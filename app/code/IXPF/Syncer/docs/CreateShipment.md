# Create Shipment API Documentation

**Version:** 1.0  
**Module:** IXPF_Syncer  
**Feature:** Order Shipment Creation via API  
**Last Updated:** June 10, 2026

---

## Overview

The **Create Shipment API** lets the IXPF external system create a shipment for a
Magento order. It supports:

- **Partial or full shipment** — by `order_item_id` or `sku`
- **Custom tracking** — `track_number`, `title`, and `carrier_code` as free/custom values
- **MSI source selection** — `source_code` decides which inventory source qty deducts from (mirrors Magento admin). Pass `"auto"` to let Magento's Source Selection Algorithm pick the source from product availability.
- **Comment** — added to the shipment, optionally customer-visible and emailed
- **Email notify** — sends the Magento shipment email to the customer

Synchronous: the response returns the created shipment and its tracks.

---

## API Endpoint

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Shipments/Create?id=<order_id>` |
| **Content-Type** | `application/json` |

`id` = order **entity_id**.

### Authentication

Bearer token via `X-Auth-Token` header:

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

Invalid/missing token → `500` with `"Unauthorized"`.

---

## Request Body

```json
{
  "items": [
    { "order_item_id": 123, "qty": 2 },
    { "sku": "RUG-001", "qty": 1 }
  ],
  "tracks": [
    { "track_number": "1Z999AA10123456784", "title": "UPS Express", "carrier_code": "custom" }
  ],
  "source_code": "warehouse_a",
  "comment": "Shipped via courier, expect 3-5 days",
  "notify": true,
  "visible_on_front": false
}
```

### Fields

| Field | Type | Required | Description |
|---|---|---|---|
| `items` | `array` | **Yes** | Products + qty to ship. At least one. |
| `items[].order_item_id` | `int` | One of id/sku | Order item id. |
| `items[].sku` | `string` | One of id/sku | SKU; resolved to order item. |
| `items[].qty` | `float` | **Yes** | Positive qty to ship for that item. |
| `source_code` | `string` | No | MSI source to deduct from. Omit/`"auto"` → resolved automatically from product availability (Source Selection Algorithm). Explicit code must exist. Single-source mode → default source. |
| `tracks` | `array` | No | Tracking rows. |
| `tracks[].track_number` | `string` | Yes (per track) | Tracking number. Alias: `number`. |
| `tracks[].title` | `string` | No | Track title (carrier label / free text). |
| `tracks[].carrier_code` | `string` | No | Carrier code. Default `custom` — title/number treated as custom value. |
| `comment` | `string` | No | Comment added to shipment. |
| `notify` | `bool` | No | Send shipment email + mark comment notified. Default `false`. |
| `visible_on_front` | `bool` | No | Comment visible in customer account. Default `false`. |

---

## Response

```json
{
  "success": true,
  "message": "Shipment created for order #100000123",
  "data": {
    "order_id": 456,
    "increment_id": "100000123",
    "shipment_id": 78,
    "shipment_increment_id": "300000045",
    "total_qty": 3,
    "source_code": "warehouse_a",
    "tracks": [
      {
        "track_id": 12,
        "track_number": "1Z999AA10123456784",
        "title": "UPS Express",
        "carrier_code": "custom"
      }
    ],
    "comment": "Shipped via courier, expect 3-5 days",
    "state": "complete",
    "status": "complete",
    "notified": true
  }
}
```

### Error

```json
{ "error": "Order #100000123 cannot be shipped. State: complete, status: complete" }
```

Returned with HTTP `500`. Common causes: order not found, order not shippable
(no pending qty), missing `track_number`, SKU not in order, source not found,
no source has stock, or (with `"auto"`) items span multiple sources.

### Auto source — multi-source split

A single Magento shipment deducts from one source. If `"auto"` resolves items
to more than one source, the API rejects with a breakdown:

```json
{ "error": "Items span multiple sources for order #100000123 — ship per source with explicit source_code. Breakdown — warehouse_a: RUG-001 x1 | warehouse_b: RUG-002 x2" }
```

Ship per source: call the API once per source with explicit `source_code` and
that source's `items`.

---

## Examples

### Ship items, source auto

```http
POST /ixpfsyncer/V1/Shipments/Create?id=456
```
```json
{
  "items": [
    { "sku": "RUG-001", "qty": 2 },
    { "order_item_id": 124, "qty": 1 }
  ]
}
```

### Shipment with custom carrier track + comment + notify

```http
POST /ixpfsyncer/V1/Shipments/Create?id=456
```
```json
{
  "items": [{ "sku": "RUG-001", "qty": 1 }],
  "tracks": [{ "track_number": "TRACK123", "title": "DHL", "carrier_code": "custom" }],
  "comment": "Partial shipment dispatched",
  "notify": true,
  "visible_on_front": true
}
```

---

## Logging

Requests/results logged daily via `OrderLogger` (same channel as invoice/creditmemo).

---

## Developer Reference

| Type | Class |
|---|---|
| Controller | `IXPF\Syncer\Controller\V1\Shipments\Create` |
| Service | `IXPF\Syncer\Services\Order\CreateShipment` |

Route frontName `ixpfsyncer` → `etc/frontend/routes.xml`.
