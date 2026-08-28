# Invoice Bulk Action API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Bulk invoice PDF actions through a single endpoint
**Last Updated:** June 23, 2026

---

## Overview

One route, two print actions over the given `invoice_ids`. Mirrors the admin
invoice grid mass actions:

- `pdf_invoices` — admin **"PDF Invoices"**. Renders the standard invoice PDF.
- `pdf_canceled_invoices` — admin **"PDF Canceled Invoices"** (url
  `sales/invoice/pdfinvoices/canceled/1`). Renders the **"Cancellation invoice"**
  layout. Every selected invoice **must be canceled** — if any is not, the whole
  request fails with `The invoice <increment_id> is not canceled`.

Both return **one combined PDF** (base64) covering all selected invoices.

The cancellation variant is driven by the `canceled` request param, read by the
project PDF renderer (`Evalent\InvoicePaymentMods` override of
`Magento\Sales\Model\Order\Pdf\Invoice`) — the same mechanism the admin grid uses.

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Invoice/BulkAction` |
| **Content-Type** | `application/json` |

### Authentication

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

### Request Body

```json
{
  "action": "pdf_invoices",
  "invoice_ids": [101, 102, 103]
}
```

| Field | Type | Description |
|---|---|---|
| `action` | `string` | `pdf_invoices` or `pdf_canceled_invoices` |
| `invoice_ids` | `int[]` | Invoice entity IDs. Also accepts CSV string `"101,102"` |

`action` and `invoice_ids` may also be passed as query params.

---

## Success Response (HTTP 200)

```json
{
  "success": true,
  "action": "pdf_invoices",
  "count": 3,
  "missing": [],
  "file_name": "invoices_bulk.pdf",
  "file_content": "<base64 PDF>",
  "mime_type": "application/pdf"
}
```

| Field | Description |
|---|---|
| `count` | Number of invoice documents rendered into the PDF |
| `missing` | Requested `invoice_ids` not found / not loaded |
| `file_name` | `invoices_bulk.pdf` or `canceled_invoices_bulk.pdf` |
| `file_content` | Base64-encoded PDF bytes |
| `mime_type` | Always `application/pdf` |

---

## Error Response (HTTP 400)

```json
{
  "success": false,
  "error": "No invoices found for the selected ids"
}
```

Common errors:

| Message | Cause |
|---|---|
| `Unauthorized` / `Unauthorized token` | Missing/invalid bearer token |
| `Invoice action is disabled in configuration` | `ixpfng_order/invoice_update/enabled` is off |
| `action is required` | Empty `action` |
| `Invalid action '<x>'. Allowed: pdf_invoices, pdf_canceled_invoices` | Unknown action |
| `invoice_ids is required (non-empty array of invoice IDs)` | No ids supplied |
| `No invoices found for the selected ids` | None of the ids resolved to an invoice |
| `The invoice <increment_id> is not canceled` | `pdf_canceled_invoices` with a non-canceled invoice in the set |

---

## Config Paths

| Path | Gates |
|---|---|
| `ixpfng_order/invoice_update/enabled` | both actions |

---

## Class Reference

| Class | Role |
|---|---|
| `IXPF\Syncer\Controller\V1\Invoice\BulkAction` | Endpoint — auth, validate, delegate, log |
| `IXPF\Syncer\Services\Invoice\BulkPrint` | Loads invoices, renders combined PDF, toggles `canceled` flag |
| `Magento\Sales\Model\Order\Pdf\Invoice` (Evalent override) | Actual PDF rendering |
