# Shipment Delete Track API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Delete tracking number(s) from an existing shipment
**Last Updated:** October 8, 2026

---

## Overview

Deletes one or more tracks from an **existing** shipment. Same result as the admin
**"Delete"** action on a track row in the shipment view page.

- Tracks are identified by **`track_number` + `carrier_code`** — the same values sent
  to `ShipmentAddTrack` / `CreateShipment`. No Magento track ID needed.
- A track is deleted only when **both** match. `track_number` is exact;
  `carrier_code` is case-insensitive. If several tracks on the shipment share the same
  number + carrier, all of them are deleted.
- **Scoped to the shipment** — tracks on other shipments are never touched.
- Rows with no matching track are reported under `not_found`.
- **At least one deleted** → `200`, `success: true`. **Nothing matched** → `404`,
  `success: false` (e.g. wrong `carrier_code`, or already deleted on retry).
- No customer email is sent.

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Shipments/DeleteTrack?id=<shipment_id>` |
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
    { "track_number": "1Z999AA10123456784", "carrier_code": "custom" },
    { "track_number": "JD014600003SE", "carrier_code": "dhl" }
  ]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `shipment_id` | `int` | No | Shipment entity ID when not passed in query. |
| `tracks` | `array` | Yes | Non-empty list of tracks to delete. |
| `tracks[].track_number` | `string` | Yes | Tracking number. Alias: `number`. |
| `tracks[].carrier_code` | `string` | Yes | Carrier code of the track (`custom` when added without one). |

---

## Response

### Success — `200 OK`

```json
{
  "success": true,
  "shipment_id": 45,
  "shipment_increment_id": "000000045",
  "order_id": 123,
  "deleted": [
    { "track_id": 12, "track_number": "1Z999AA10123456784", "title": "UPS Express", "carrier_code": "custom" }
  ],
  "not_found": [
    { "track_number": "JD014600003SE", "carrier_code": "dhl" }
  ],
  "tracks": []
}
```

| Field | Description |
|---|---|
| `deleted` | Tracks removed by this request. |
| `not_found` | Requested rows with no matching track on this shipment. |
| `tracks` | Tracks remaining on the shipment. |

### Nothing matched — `404 Not Found`

```json
{
  "success": false,
  "shipment_id": 45,
  "shipment_increment_id": "000000045",
  "order_id": 123,
  "deleted": [],
  "not_found": [{ "track_number": "23232", "carrier_code": "dhl" }],
  "tracks": [{ "track_id": 12, "track_number": "23232", "title": "DHL", "carrier_code": "custom" }],
  "error": "No matching track found on shipment #000000045 — track_number and carrier_code must both match"
}
```

Check `tracks` for the stored `carrier_code` — tracks added without one are stored as `custom`
(the carrier name is in `title`).

### Errors

```json
{ "error": "tracks[0].carrier_code is required" }
```

| HTTP | When |
|---|---|
| `401` | Missing/invalid `X-Auth-Token`. |
| `404` | Shipment not found, or no requested track matched (`success: false`, see above). |
| `422` | Invalid JSON, missing `shipment_id`, empty/invalid `tracks`, blank `track_number` or `carrier_code`. |
| `500` | Shipment update disabled in config, or delete failure. |

---

## Example

```bash
curl -X POST "https://<store-domain>/ixpfsyncer/V1/Shipments/DeleteTrack?id=45" \
  -H "X-Auth-Token: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{"tracks":[{"track_number":"JD014600003SE","carrier_code":"dhl"}]}'
```

---

## Event

After at least one track is deleted, dispatches `ixpf_syncer_after_shipment_delete_track`
with data `shipment` (`Magento\Sales\Model\Order\Shipment`) and `tracks`
(array of deleted track rows: `track_id`, `track_number`, `title`, `carrier_code`).

---

## Class Reference

| Class | Role |
|---|---|
| `IXPF\Syncer\Controller\V1\Shipments\DeleteTrack` | Auth, parse, validate, respond. |
| `IXPF\Syncer\Services\Shipment\DeleteTrack` | Payload validation, number + carrier matching, delete, event. |

## Configuration

| Path | Description |
|---|---|
| `ixpfng_order/shipment_update/enabled` | Must be `1`, else request fails. |

## Logs

- `OrderActionLogger` daily log, tag `[shipment_delete_track]` — raw payload, result, errors.
- Order API activity grid — type `shipment_delete_track`.
