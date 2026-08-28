# Credit Memo Bulk Action API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Bulk credit memo PDF action through a single endpoint
**Last Updated:** June 23, 2026

---

## Overview

One route, one print action over the given `creditmemo_ids`. Mirrors the admin
credit memo grid mass action:

- `pdf_creditmemos` — admin **"PDF Credit Memos"** (url
  `sales/creditmemo/pdfcreditmemos`). Renders one combined PDF (base64) across
  all selected credit memos.

> Renders with the core `Magento\Sales\Model\Order\Pdf\Creditmemo` renderer —
> the project's `Evalent\CreditmemoMods` override is registered for the
> **adminhtml** area only, matching the existing `Orders/BulkAction`
> `print_creditmemo` behaviour.

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/CreditMemo/BulkAction` |
| **Content-Type** | `application/json` |

### Authentication

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

### Request Body

```json
{
  "action": "pdf_creditmemos",
  "creditmemo_ids": [201, 202, 203]
}
```

| Field | Type | Description |
|---|---|---|
| `action` | `string` | `pdf_creditmemos` |
| `creditmemo_ids` | `int[]` | Credit memo entity IDs. Also accepts CSV string `"201,202"` |

`action` and `creditmemo_ids` may also be passed as query params.

---

## Success Response (HTTP 200)

```json
{
  "success": true,
  "action": "pdf_creditmemos",
  "count": 3,
  "missing": [],
  "file_name": "creditmemos_bulk.pdf",
  "file_content": "<base64 PDF>",
  "mime_type": "application/pdf"
}
```

| Field | Description |
|---|---|
| `count` | Number of credit memo documents rendered into the PDF |
| `missing` | Requested `creditmemo_ids` not found / not loaded |
| `file_name` | `creditmemos_bulk.pdf` |
| `file_content` | Base64-encoded PDF bytes |
| `mime_type` | Always `application/pdf` |

---

## Error Response (HTTP 400)

```json
{
  "success": false,
  "error": "No credit memos found for the selected ids"
}
```

Common errors:

| Message | Cause |
|---|---|
| `Unauthorized` / `Unauthorized token` | Missing/invalid bearer token |
| `Credit memo action is disabled in configuration` | `ixpfng_order/creditmemo_update/enabled` is off |
| `action is required` | Empty `action` |
| `Invalid action '<x>'. Allowed: pdf_creditmemos` | Unknown action |
| `creditmemo_ids is required (non-empty array of credit memo IDs)` | No ids supplied |
| `No credit memos found for the selected ids` | None of the ids resolved to a credit memo |

---

## Config Paths

| Path | Gates |
|---|---|
| `ixpfng_order/creditmemo_update/enabled` | the action |

---

## Class Reference

| Class | Role |
|---|---|
| `IXPF\Syncer\Controller\V1\Creditmemo\BulkAction` | Endpoint — auth, validate, delegate, log |
| `IXPF\Syncer\Services\Creditmemo\BulkPrint` | Loads credit memos, renders combined PDF |
| `Magento\Sales\Model\Order\Pdf\Creditmemo` | Actual PDF rendering |
