# Order Update API Documentation

**Version:** 1.0  
**Module:** IXPF_Syncer  
**Feature:** Order Update via API  
**Last Updated:** October 8, 2026

---

## Table of Contents

1. [Overview](#overview)
2. [Prerequisites](#prerequisites)
3. [Configuration](#configuration)
4. [API Endpoints](#api-endpoints)
   - [Order Update](#order-update-endpoint)
     - [Request](#request)
     - [Authentication](#authentication)
     - [Request Body](#request-body)
     - [Updatable Fields](#updatable-fields)
     - [Response](#response)
   - [Order Metadata](#order-metadata-endpoint)
     - [Metadata Request](#metadata-request)
     - [Metadata Response](#metadata-response)
5. [Field Reference](#field-reference)
   - [Status Update](#status-update)
   - [Billing Address](#billing-address)
   - [Shipping Address](#shipping-address)
   - [Extra Shipping Instructions](#extra-shipping-instructions)
   - [Attachment](#attachment)
6. [Examples](#examples)
   - [Update Order Status](#update-order-status)
   - [Update Billing Address](#update-billing-address)
   - [Update Shipping Address](#update-shipping-address)
   - [Attach PDF from Bucket](#attach-pdf-from-bucket)
   - [Remove Attachment](#remove-attachment)
   - [Full Payload Example](#full-payload-example)
   - [Fetch Metadata](#fetch-metadata)
7. [Error Handling](#error-handling)
8. [Logging](#logging)
9. [Developer Reference](#developer-reference)
   - [Class Reference](#class-reference)
   - [Service: OrderUpdate](#service-orderupdate)
   - [Configuration Path](#configuration-path)

---

## Overview

The **Order Update API** allows the IXPF external system to update Magento orders programmatically. It supports updating:

- **Order status** — including cancellation with automatic stock restoration
- **Billing address** — overwrites fields on the order's billing address
- **Shipping address** — overwrites fields on the order's shipping address and optionally syncs the linked customer address
- **Extra shipping instructions** — the admin "Extra shipping instructions" toggle (`CarpetAvenue_OrderAttachment`)
- **Attachment** — the admin "Attach new file" PDF, downloaded from the Google bucket

The endpoint is synchronous: the response confirms which fields were updated.

---

## Prerequisites

- Magento 2.4.x or higher
- `IXPF_Syncer` module installed and enabled
- A valid Magento integration token (Bearer token)
- Order Update feature enabled in the admin configuration

---

## Configuration

### Enable Order Update

1. Navigate to **Stores → Configuration → IXPF Syncer → Order**
2. Set **Enable Order Update** to `Yes`
3. Click **Save Config**

The underlying configuration path is:

| Setting | Config Path |
|---|---|
| Enable Order Update | `ixpfng_order/order_update/enabled` |

---

## API Endpoints

### Order Update Endpoint

#### Request

| Property | Value                                                              |
|---|--------------------------------------------------------------------|
| **Method** | `POST`                                                             |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/orders/update/id/<order_id>` |
| **Content-Type** | `application/json`                                                 |

### Authentication

The endpoint uses a Bearer token passed via the `X-Auth-Token` header.

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

The token is validated against Magento's OAuth integration tokens. An invalid or missing token returns a `500` error with `"Unauthorized"`.

### Request Body

Send a JSON body containing the fields you want to update. Only the keys you include will be processed — omitted keys are ignored.

```json
{
  "status": "string",
  "billing_address": { ... },
  "shipping_address": { ... },
  "extra_shipping_instructions": true,
  "attachment": { "bucket_url": "string" }
}
```

### Updatable Fields

| Field | Type | Description |
|---|---|---|
| `status` | `string` | New order status code (must exist in Magento) |
| `billing_address` | `object` | Address fields to overwrite on the billing address |
| `shipping_address` | `object` | Address fields to overwrite on the shipping address (also syncs customer address if linked) |
| `extra_shipping_instructions` | `bool` | Sets the order's "Extra shipping instructions" flag |
| `attachment` | `object\|null` | `{ "bucket_url": "..." }` attaches a PDF from the Google bucket; `null` removes the current attachment |

### Response

**Success — HTTP 200**

```json
{
  "success": true,
  "message": "Order #<id> updated successfully",
  "updated_fields": {
    "updated_fields": ["status", "billing_address", "shipping_address", "customer_address"],
    "order_id": 123
  }
}
```

The `updated_fields` array will only contain the fields that were actually processed.

When `attachment` / `extra_shipping_instructions` are sent, the inner object also carries their final value:

```json
"updated_fields": {
  "updated_fields": ["extra_shipping_instructions", "attachment"],
  "order_id": 123,
  "attachment": "persistent/order_attachments/123/shipping-instructions.pdf",
  "extra_shipping_instructions": true
}
```

**Error — HTTP 422** (invalid `extra_shipping_instructions` / `attachment` input, see [Error Handling](#error-handling))

**Error — HTTP 500**

```json
{
  "error": "<error message>"
}
```

---

### Order Metadata Endpoint

Returns all available order statuses, order states, and customer groups. Use this endpoint to discover valid values before calling the Order Update endpoint.

#### Metadata Request

| Property | Value |
|---|---|
| **Method** | `GET` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Orders/Metadata` |
| **Content-Type** | `application/json` |

**Authentication** — same `X-Auth-Token: Bearer <token>` header as the Update endpoint.

#### Metadata Response

**Success — HTTP 200**

```json
{
  "success": true,
  "data": {
    "statuses": [
      { "status": "pending", "label": "Pending" },
      { "status": "processing", "label": "Processing" },
      { "status": "complete", "label": "Complete" },
      { "status": "canceled", "label": "Canceled" }
    ],
    "states": [
      { "state": "new", "title": "New" },
      { "state": "pending_payment", "title": "Pending Payment" },
      { "state": "processing", "title": "Processing" },
      { "state": "complete", "title": "Complete" },
      { "state": "closed", "title": "Closed" },
      { "state": "canceled", "title": "Canceled" },
      { "state": "holded", "title": "Holded" }
    ],
    "customer_groups": [
      { "customer_group_id": "0", "customer_group_code": "NOT LOGGED IN" },
      { "customer_group_id": "1", "customer_group_code": "General" },
      { "customer_group_id": "2", "customer_group_code": "Wholesale" },
      { "customer_group_id": "3", "customer_group_code": "Retailer" }
    ]
  }
}
```

**Response fields:**

| Field | Source Table | Description |
|---|---|---|
| `statuses` | `sales_order_status` | All order status codes and their labels |
| `states` | `sales_order_status_state` (distinct) | All distinct order states with human-readable titles |
| `customer_groups` | `customer_group` | All customer groups with their IDs and codes |

**Error — HTTP 500**

```json
{
  "success": false,
  "message": "<error message>"
}
```

---

## Field Reference

### Status Update

Pass a valid Magento order status code in lowercase. The status must exist under **Stores → Order Status** in the admin.

```json
{
  "status": "processing"
}
```

**Special case — Cancellation:**

When `status` is set to `"canceled"`, the API uses Magento's native `Order::cancel()` method, which:
- Restores reserved stock (via MSI or legacy CatalogInventory)
- Sets `qty_canceled` on each order item
- Triggers the `sales_order_cancel_after` event
- Adds a history comment: *"Order cancelled via IXPF API"*

If the order cannot be cancelled in its current state, an error is returned.

For all other statuses, the API:
1. Looks up the `state` mapped to the status code in `sales_order_status_state`
2. Sets `order.state` and `order.status`
3. Adds a history comment: *"Status updated to '{status}' (state: {state}) via IXPF API"*

---

### Billing Address

```json
{
  "billing_address": {
    "firstname": "John",
    "lastname": "Doe",
    "company": "Acme Corp",
    "street": "123 Main St\nSuite 4",
    "city": "Oslo",
    "region": "Oslo",
    "region_id": 0,
    "postcode": "0150",
    "country_id": "NO",
    "telephone": "+4712345678",
    "fax": ""
  }
}
```

Only include the fields you want to change. Omitted fields remain unchanged.

**Street field:** Can be passed as a newline-delimited string (`"line1\nline2"`) or as an array (`["line1", "line2"]`).

---

### Shipping Address

The `shipping_address` object accepts the same fields as `billing_address`.

```json
{
  "shipping_address": {
    "firstname": "Jane",
    "lastname": "Doe",
    "street": "456 Elm Street",
    "city": "Bergen",
    "postcode": "5003",
    "country_id": "NO",
    "telephone": "+4798765432"
  }
}
```

**Customer address sync:** If the order has a linked customer address (`customer_address_id` is set on the shipping address), the corresponding customer address record is also updated and marked as the default shipping address. The `customer_address` key will appear in the `updated_fields` response array when this occurs.

---

### Extra Shipping Instructions

```json
{
  "extra_shipping_instructions": true
}
```

Writes `sales_order.ca_attachment_check_extra` (`1` / `0`) — the same toggle shown in the admin order view under **Attachment**. Accepts `true`/`false`, `1`/`0`, `"true"`/`"false"`, `"yes"`/`"no"`. The `GET /V1/orders/:orderId/attachment` REST endpoint only returns the PDF when this flag is `1`.

---

### Attachment

```json
{
  "attachment": {
    "bucket_url": "orders/100000123/shipping-instructions.pdf"
  }
}
```

The PDF is downloaded from the Google bucket configured under **IXPF Syncer → Product → Google Bucket Config** (same bucket and service account as the product gallery) and stored at:

```
var/persistent/order_attachments/<order_entity_id>/<filename>.pdf
```

`sales_order.ca_attachment_file` is set to the path relative to `var/` — identical to the admin "Attach new file" upload, so the admin download link and `GET /V1/orders/:orderId/attachment` work unchanged.

**Accepted `bucket_url` formats:**

| Format | Example |
|---|---|
| Object path | `orders/100000123/shipping-instructions.pdf` |
| gs:// URI | `gs://<bucket>/orders/100000123/shipping-instructions.pdf` |
| Public URL | `https://storage.googleapis.com/<bucket>/orders/100000123/shipping-instructions.pdf` |
| Virtual-host URL | `https://<bucket>.storage.googleapis.com/orders/100000123/shipping-instructions.pdf` |

The bucket in a URI/URL must match the configured bucket.

**Rules:**
- Only `.pdf`; content must start with `%PDF-`
- Maximum size **10 MB**
- Filename is sanitised (`[^A-Za-z0-9_.-]` → `_`)
- Sending a new attachment replaces the old one; the old file is deleted only after the order is saved
- `"attachment": null` removes the current attachment and deletes its file
- Attachment is processed before status/address changes — a failed download leaves the order untouched

---

## Examples

### Update Order Status

```http
POST /ixpfsyncer/V1/Orders/Update?id=100000123
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "status": "complete"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Order #100000123 updated successfully",
  "updated_fields": {
    "updated_fields": ["status"],
    "order_id": 100000123
  }
}
```

---

### Update Billing Address

```http
POST /ixpfsyncer/V1/Orders/Update?id=100000123
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "billing_address": {
    "firstname": "John",
    "lastname": "Smith",
    "street": "789 New Road",
    "city": "Trondheim",
    "postcode": "7010",
    "country_id": "NO",
    "telephone": "+4711223344"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Order #100000123 updated successfully",
  "updated_fields": {
    "updated_fields": ["billing_address"],
    "order_id": 100000123
  }
}
```

---

### Update Shipping Address

```http
POST /ixpfsyncer/V1/Orders/Update?id=100000123
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "shipping_address": {
    "street": "10 Delivery Lane\nApt 2",
    "city": "Stavanger",
    "postcode": "4006",
    "country_id": "NO"
  }
}
```

**Response (with customer address sync):**
```json
{
  "success": true,
  "message": "Order #100000123 updated successfully",
  "updated_fields": {
    "updated_fields": ["shipping_address", "customer_address"],
    "order_id": 100000123
  }
}
```

---

### Attach PDF from Bucket

```http
POST /ixpfsyncer/V1/Orders/Update?id=100000123
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "extra_shipping_instructions": true,
  "attachment": {
    "bucket_url": "orders/100000123/shipping-instructions.pdf"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Order #100000123 updated successfully",
  "updated_fields": {
    "updated_fields": ["extra_shipping_instructions", "attachment"],
    "order_id": 100000123,
    "attachment": "persistent/order_attachments/100000123/shipping-instructions.pdf",
    "extra_shipping_instructions": true
  }
}
```

---

### Remove Attachment

```json
{
  "attachment": null,
  "extra_shipping_instructions": false
}
```

---

### Full Payload Example

```http
POST /ixpfsyncer/V1/Orders/Update?id=100000123
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "status": "processing",
  "billing_address": {
    "firstname": "John",
    "lastname": "Doe",
    "company": "Carpet Avenue AS",
    "street": "Karl Johans gate 1",
    "city": "Oslo",
    "postcode": "0154",
    "country_id": "NO",
    "telephone": "+4712345678"
  },
  "shipping_address": {
    "firstname": "John",
    "lastname": "Doe",
    "street": "Karl Johans gate 1",
    "city": "Oslo",
    "postcode": "0154",
    "country_id": "NO",
    "telephone": "+4712345678"
  }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Order #100000123 updated successfully",
  "updated_fields": {
    "updated_fields": ["status", "billing_address", "shipping_address", "customer_address"],
    "order_id": 100000123
  }
}
```

---

### Fetch Metadata

```http
GET /ixpfsyncer/V1/Orders/Metadata
X-Auth-Token: Bearer abc123token
```

**Response:**
```json
{
  "success": true,
  "data": {
    "statuses": [
      { "status": "pending", "label": "Pending" },
      { "status": "processing", "label": "Processing" }
    ],
    "states": [
      { "state": "new", "title": "New" },
      { "state": "processing", "title": "Processing" }
    ],
    "customer_groups": [
      { "customer_group_id": "0", "customer_group_code": "NOT LOGGED IN" },
      { "customer_group_id": "1", "customer_group_code": "General" }
    ]
  }
}
```

---

## Error Handling

| Scenario | Error Message |
|---|---|
| Missing `id` query parameter | `Order ID is required` |
| Empty or invalid JSON body | `Empty order information` |
| Order update disabled in config | `Order update is disabled` |
| Order not found | `Order #<id> not found` |
| Invalid/missing Bearer token | `Unauthorized` |
| Order cannot be cancelled | `Order #<increment_id> cannot be cancelled in its current state '<state>'.` |
| Status code does not exist | `Order status '<code>' does not exist in Magento. Please create it under Stores > Order Status first.` |

**Attachment / extra shipping instructions — HTTP 422:**

| Scenario | Error Message |
|---|---|
| Non-boolean flag | `'extra_shipping_instructions' must be a boolean.` |
| Wrong `attachment` shape | `'attachment' must be null or an object with a 'bucket_url' string.` |
| Empty `bucket_url` | `'attachment.bucket_url' is required.` |
| Not a `.pdf` filename | `Attachment must be a .pdf file, got '<name>'.` |
| Non-GCS URL host | `Unsupported attachment URL host '<host>'. Use a Google bucket path.` |
| Bucket mismatch | `Attachment bucket '<bucket>' does not match the configured bucket.` |
| `..` in path | `Invalid attachment bucket path '<url>'.` |
| Object missing / no access / bucket not configured | `Attachment download failed: <detail>` |
| Empty file / over 10 MB / not a PDF | `Attachment file is empty.` / `Attachment is <n> MB, maximum allowed is 10 MB.` / `Attachment is not a valid PDF file.` |

GCS 5xx / network failures return HTTP `500`.

All other errors return HTTP `500` with the body:
```json
{
  "error": "<error message>"
}
```

All errors are also written to the order log (see [Logging](#logging)).

---

## Logging

All requests and errors are logged by `IXPF\Syncer\Helper\Logger\OrderLogger` using a daily rotating log.

**Logged events:**
- Incoming request: `"Received order update request for Order ID: <id>"`
- Full decoded payload (JSON)
- Any exception message on failure

Attachment actions (`[order_attachment]` tag — bucket path, stored path, size, replaced/deleted file) are written by `IXPF\Syncer\Helper\Logger\OrderActionLogger` to `var/log/ixpf/orders/order_action_<date>.log`.

After a successful update the controller dispatches `ixpf_syncer_after_order_update` with `order_id`, `payload` and `result`.

Log files are located at:
```
var/log/ixpf_order_<YYYY-MM-DD>.log
```

---

## Developer Reference

### Class Reference

| Class | Responsibility |
|---|---|
| `IXPF\Syncer\Controller\V1\Orders\Update` | HTTP controller — validates token, parses request, delegates to service |
| `IXPF\Syncer\Controller\V1\Orders\Metadata` | HTTP controller — returns available order statuses, states, and customer groups |
| `IXPF\Syncer\Services\Order\OrderUpdate` | Business logic — status update, address update, customer address sync |
| `IXPF\Syncer\Services\Order\OrderAttachment` | Extra shipping instructions flag, attachment download / validation / storage / removal |
| `IXPF\Syncer\Services\PhotoStudio\ProcessGoogleBucketImages` | `downloadObject()` — GCS download with the gallery service account |
| `IXPF\Syncer\Helper\Config` | Reads `ixpfng_order/order_update/enabled` config flag |
| `IXPF\Syncer\Helper\TokenValidator` | Validates the Bearer token against Magento OAuth tokens |
| `IXPF\Syncer\Helper\Logger\OrderLogger` | Daily-rotating log writer for order events |

### Service: OrderUpdate

**Method:** `process(int $orderId, array $payload): array`

Returns:
```php
[
    'updated_fields' => ['status', 'billing_address', ...],
    'order_id'       => 123,
]
```

**Address field map** (applies to both billing and shipping):

| Payload Key | Setter |
|---|---|
| `firstname` | `setFirstname` |
| `lastname` | `setLastname` |
| `company` | `setCompany` |
| `street` | `setStreet` |
| `city` | `setCity` |
| `region` | `setRegion` |
| `region_id` | `setRegionId` |
| `postcode` | `setPostcode` |
| `country_id` | `setCountryId` |
| `telephone` | `setTelephone` |
| `fax` | `setFax` |

### Configuration Path

| Config | XML Path |
|---|---|
| Enable Order Update | `ixpfng_order/order_update/enabled` |
| Google bucket name (attachment) | `ixpfng_product/google_bucket_config/bucket_name` |
| Google service-account JSON (attachment) | `ixpfng_product/google_bucket_config/json_file` |
