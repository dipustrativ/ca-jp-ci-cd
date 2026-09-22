# Shipment Bulk Action API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Bulk shipment PDF actions through a single endpoint
**Last Updated:** June 23, 2026

---

## Overview

One route, two actions over the given `shipment_ids`. Mirrors the admin shipment
grid mass actions:

- `pdf_shipments` — admin **"PDF Shipments"** (url `sales/shipment/pdfshipments`).
  Packing-slip PDF across all selected shipments.
- `print_shipping_labels` — admin **"Print Shipping Labels"** (url
  `adminhtml/shipment/massPrintShippingLabel`). Combines the carrier label
  (`shipping_label`) of each selected shipment into one PDF. Shipments **without
  a generated label** do not contribute and are reported under `missing`.

Both return **one combined PDF** (base64) across all selected shipments.

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Shipment/BulkAction` |
| **Content-Type** | `application/json` |

### Authentication

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

### Request Body

```json
{
  "action": "pdf_shipments",
  "shipment_ids": [301, 302, 303]
}
```

| Field | Type | Description |
|---|---|---|
| `action` | `string` | `pdf_shipments` or `print_shipping_labels` |
| `shipment_ids` | `int[]` | Shipment entity IDs. Also accepts CSV string `"301,302"` |

`action` and `shipment_ids` may also be passed as query params.

---

## Success Response (HTTP 200)

```json
{
  "success": true,
  "action": "pdf_shipments",
  "count": 3,
  "missing": [],
  "file_name": "shipments_bulk.pdf",
  "file_content": "<base64 PDF>",
  "mime_type": "application/pdf"
}
```

| Field | Description |
|---|---|
| `count` | `pdf_shipments`: shipment docs rendered. `print_shipping_labels`: labels combined |
| `missing` | `pdf_shipments`: ids not found. `print_shipping_labels`: ids not found **or without a generated label** |
| `file_name` | `shipments_bulk.pdf` or `shipping_labels_bulk.pdf` |
| `file_content` | Base64-encoded PDF bytes |
| `mime_type` | Always `application/pdf` |

---

## Error Response (HTTP 400)

```json
{
  "success": false,
  "error": "No shipments found for the selected ids"
}
```

Common errors:

| Message | Cause |
|---|---|
| `Unauthorized` / `Unauthorized token` | Missing/invalid bearer token |
| `Shipment action is disabled in configuration` | `ixpfng_order/shipment_update/enabled` is off |
| `action is required` | Empty `action` |
| `Invalid action '<x>'. Allowed: pdf_shipments, print_shipping_labels` | Unknown action |
| `shipment_ids is required (non-empty array of shipment IDs)` | No ids supplied |
| `No shipments found for the selected ids` | None of the ids resolved to a shipment |
| `No shipping labels found for the selected shipments` | `print_shipping_labels` and none of the shipments has a carrier label |

---

## Config Paths

| Path | Gates |
|---|---|
| `ixpfng_order/shipment_update/enabled` | both actions |

---

## Class Reference

| Class | Role |
|---|---|
| `IXPF\Syncer\Controller\V1\Shipment\BulkAction` | Endpoint — auth, validate, delegate, log |
| `IXPF\Syncer\Services\Shipment\BulkPrint` | Loads shipments, renders packing-slip / label PDF |
| `Magento\Sales\Model\Order\Pdf\Shipment` | Packing-slip PDF rendering — preferenced to `Evalent\InvoicePaymentMods\Overwrite\Plugin\Shipment` for the Japanese font |
| `Magento\Shipping\Model\Shipping\LabelGenerator` | Combines carrier labels into one PDF |
