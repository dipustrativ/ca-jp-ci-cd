# Create Shipment API Documentation

**Version:** 1.1  
**Module:** IXPF_Syncer  
**Feature:** Order Shipment Creation via API  
**Last Updated:** October 8, 2026

---

## Overview

The **Create Shipment API** lets the IXPF external system create a shipment for a
Magento order. It supports:

- **Partial or full shipment** — by `order_item_id` or `sku`
- **Custom tracking** — `track_number`, `title`, and `carrier_code` as free/custom values
- **MSI source selection** — `source_code` decides which inventory source qty deducts from (mirrors Magento admin). Set it **per item** (`items[].source_code`) or once for all items (top-level `source_code`). Pass `"auto"` / omit to let Magento's Source Selection Algorithm pick the source from product availability.
- **Multi-source split** — a Magento shipment deducts from ONE source. Items resolving to different sources are split into **one shipment per source** (same as the admin "Ship" flow), saved in a single transaction: all shipments are created, or none.
- **Comment** — added to the shipment, optionally customer-visible and emailed
- **Email notify** — sends the Magento shipment email to the customer

Synchronous: the response returns the created shipment(s) and their tracks.

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
    { "order_item_id": 123, "qty": 2, "source_code": "hamburg" },
    { "sku": "RUG-001", "qty": 1, "source_code": "hamburg_outlet" }
  ],
  "tracks": [
    { "track_number": "1Z999AA10123456784", "title": "UPS Express", "source_code": "hamburg" },
    { "track_number": "JD014600003SE", "title": "DHL", "source_code": "hamburg_outlet" }
  ],
  "source_code": "warehouse_a",
  "comment": "Shipped via courier, expect 3-5 days",
  "notify": true,
  "visible_on_front": false
}
```

### Source rules

An explicit `source_code` (item or top-level) must:

- exist and be enabled,
- be assigned to the stock of the order's website (e.g. `base` → `hamburg`, `hamburg_outlet`),
- have the SKU assigned (source item exists).

Available qty is checked by Magento's source deduction when saving (respects backorders).
Not enough qty in any source → the whole request is rolled back.

### Fields

| Field | Type | Required | Description |
|---|---|---|---|
| `items` | `array` | **Yes** | Products + qty to ship. At least one. |
| `items[].order_item_id` | `int` | One of id/sku | Order item id. |
| `items[].sku` | `string` | One of id/sku | SKU; resolved to order item. |
| `items[].qty` | `float` | **Yes** | Positive qty to ship for that item. The same item may appear several times with different sources; the total must not exceed the qty left to ship. |
| `items[].source_code` | `string` | No | MSI source this item's qty deducts from. Overrides top-level `source_code`. `"auto"` → Source Selection Algorithm. |
| `source_code` | `string` | No | Default source for items without their own `source_code`. Omit/`"auto"` → resolved automatically from product availability (Source Selection Algorithm, may split one item across sources). Single-source mode → default source. |
| `tracks` | `array` | No | Tracking rows. |
| `tracks[].track_number` | `string` | Yes (per track) | Tracking number. Alias: `number`. |
| `tracks[].title` | `string` | No | Track title (carrier label / free text). |
| `tracks[].carrier_code` | `string` | No | Carrier code. Default `custom` — title/number treated as custom value. |
| `tracks[].source_code` | `string` | No | Put this track only on the shipment of that source. Omit → track added to **every** shipment created. Must be a source that items ship from. |
| `comment` | `string` | No | Comment added to shipment. |
| `notify` | `bool` | No | Send shipment email + mark comment notified. Default `false`. |
| `visible_on_front` | `bool` | No | Comment visible in customer account. Default `false`. |

---

## Response

```json
{
  "success": true,
  "message": "2 shipments created for order #100000123 (one per source)",
  "data": {
    "order_id": 456,
    "increment_id": "100000123",
    "shipment_id": 78,
    "shipment_increment_id": "300000045",
    "total_qty": 2,
    "source_code": "hamburg",
    "tracks": [
      { "track_id": 12, "track_number": "1Z999AA10123456784", "title": "UPS Express", "carrier_code": "custom" }
    ],
    "shipments": [
      {
        "shipment_id": 78,
        "shipment_increment_id": "300000045",
        "source_code": "hamburg",
        "total_qty": 2,
        "items": [{ "order_item_id": 123, "sku": "RUG-100", "qty": 2 }],
        "tracks": [
          { "track_id": 12, "track_number": "1Z999AA10123456784", "title": "UPS Express", "carrier_code": "custom" }
        ]
      },
      {
        "shipment_id": 79,
        "shipment_increment_id": "300000046",
        "source_code": "hamburg_outlet",
        "total_qty": 1,
        "items": [{ "order_item_id": 124, "sku": "RUG-001", "qty": 1 }],
        "tracks": [
          { "track_id": 13, "track_number": "JD014600003SE", "title": "DHL", "carrier_code": "custom" }
        ]
      }
    ],
    "comment": "Shipped via courier, expect 3-5 days",
    "state": "complete",
    "status": "complete",
    "notified": true
  }
}
```

`shipments[]` lists every shipment created. The top-level `shipment_id`,
`shipment_increment_id`, `total_qty`, `source_code` and `tracks` describe the **first**
shipment (unchanged single-shipment shape for existing callers).

### Error

```json
{ "error": "Order #100000123 cannot be shipped. State: complete, status: complete" }
```

Returned with HTTP `500`. Common causes: order not found, order not shippable
(no pending qty), missing `track_number`, SKU not in order, requested qty above
qty left to ship, source not found / disabled / not in the order's stock, SKU not
assigned to source, track `source_code` without items, or not enough stock:

```json
{ "error": "Not all of your products are available in the requested quantity. Requested — hamburg: RUG-100 x2 | hamburg_outlet: RUG-001 x1" }
```

On any error **no** shipment is created and no stock is deducted.

### Auto source — multi-source split

With `"auto"` (or no source), the Source Selection Algorithm allocates qty by source
priority. If it needs several sources (e.g. `hamburg` has 1, `hamburg_outlet` has 3 for
qty 4), one shipment per source is created automatically.

> **Changed in 1.1:** previously this case was rejected with
> `"Items span multiple sources … ship per source"`. It now splits.

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

### Ship items from different sources (split)

```http
POST /ixpfsyncer/V1/Shipments/Create?id=456
```
```json
{
  "items": [
    { "sku": "RUG-100", "qty": 2, "source_code": "hamburg" },
    { "sku": "RUG-001", "qty": 1, "source_code": "hamburg_outlet" }
  ],
  "tracks": [
    { "track_number": "1Z999AA10123456784", "title": "UPS", "source_code": "hamburg" },
    { "track_number": "JD014600003SE", "title": "DHL", "source_code": "hamburg_outlet" }
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
