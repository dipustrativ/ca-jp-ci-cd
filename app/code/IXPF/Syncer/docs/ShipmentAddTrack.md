# Shipment Add Track API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Add tracking number(s) to an existing shipment
**Last Updated:** October 8, 2026

---

## Overview

Adds one or more tracking numbers to an **existing** shipment. Same result as the
admin **"Add Tracking Number"** action on the shipment view page.

- **Custom tracking** — `track_number`, `title`, and `carrier_code` as free/custom values
  (`carrier_code` defaults to `custom`).
- **Idempotent** — a `track_number` already on the shipment (or repeated in the same
  payload) is not added again; it is returned under `skipped`. Safe to retry.
- **Optional notify** — `notify: true` sends the shipment email with tracking info to
  the customer (same as the admin "Send Tracking Information" button), only when at
  least one new track was added.

Synchronous: the response returns the added tracks plus all tracks now on the shipment.

To create a new shipment with tracks in one call, use `CreateShipment` instead.

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Shipments/AddTrack?id=<shipment_id>` |
| **Content-Type** | `application/json` |

### Authentication

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

### Query Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `id` | `int` | Yes* | Shipment entity ID. Alias: `shipment_id`. |

\* Can also be sent as `shipment_id` in the JSON body. Query param wins.

### Request Body

```json
{
  "tracks": [
    { "track_number": "1Z999AA10123456784", "title": "UPS Express", "carrier_code": "custom" },
    { "track_number": "JD014600003SE", "title": "DHL" }
  ],
  "notify": false
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `shipment_id` | `int` | No | Shipment entity ID when not passed in query. |
| `tracks` | `array` | Yes | Non-empty list of tracks to add. |
| `tracks[].track_number` | `string` | Yes (per track) | Tracking number. Alias: `number`. |
| `tracks[].title` | `string` | No | Track title (carrier label / free text). |
| `tracks[].carrier_code` | `string` | No | Carrier code. Default `custom`. |
| `notify` | `bool` | No | Send shipment tracking email to customer. Default `false`. |

---

## Response

### Success — `200 OK`

```json
{
  "success": true,
  "shipment_id": 45,
  "shipment_increment_id": "000000045",
  "order_id": 123,
  "added": [
    { "track_id": 13, "track_number": "JD014600003SE", "title": "DHL", "carrier_code": "custom" }
  ],
  "skipped": ["1Z999AA10123456784"],
  "tracks": [
    { "track_id": 12, "track_number": "1Z999AA10123456784", "title": "UPS Express", "carrier_code": "custom" },
    { "track_id": 13, "track_number": "JD014600003SE", "title": "DHL", "carrier_code": "custom" }
  ],
  "notified": false
}
```

| Field | Description |
|---|---|
| `added` | Tracks created by this request. |
| `skipped` | Track numbers ignored as duplicates. |
| `tracks` | All tracks on the shipment after the request. |
| `notified` | `true` when the tracking email was sent. |

### Errors

```json
{ "error": "tracks[0].track_number is required" }
```

| HTTP | When |
|---|---|
| `401` | Missing/invalid `X-Auth-Token`. |
| `404` | Shipment not found. |
| `422` | Invalid JSON, missing `shipment_id`, empty/invalid `tracks`, blank `track_number`. |
| `500` | Shipment update disabled in config, or save failure. |

---

## Example

```bash
curl -X POST "https://<store-domain>/ixpfsyncer/V1/Shipments/AddTrack?id=45" \
  -H "X-Auth-Token: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"tracks":[{"track_number":"JD014600003SE","title":"DHL"}],"notify":true}'
```

---

## Event

After at least one track is saved, dispatches `ixpf_syncer_after_shipment_add_track`
with data `shipment` (`Magento\Sales\Model\Order\Shipment`) and `tracks`
(array of `Magento\Sales\Model\Order\Shipment\Track`).

---

## Class Reference

| Class | Role |
|---|---|
| `IXPF\Syncer\Controller\V1\Shipments\AddTrack` | Auth, parse, validate, respond. |
| `IXPF\Syncer\Services\Shipment\AddTrack` | Track validation, duplicate skip, save, notify, event. |

## Configuration

| Path | Description |
|---|---|
| `ixpfng_order/shipment_update/enabled` | Must be `1`, else request fails. |

## Logs

- `OrderActionLogger` daily log, tag `[shipment_add_track]` — raw payload, result, errors.
- Order API activity grid — type `shipment_add_track`.
