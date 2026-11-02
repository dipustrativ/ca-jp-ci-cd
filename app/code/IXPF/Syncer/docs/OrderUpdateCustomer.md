# Order Customer Update API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Update customer name / email on an order
**Last Updated:** June 11, 2026

---

## Overview

Updates the customer name and/or email stored **on the order** (`customer_firstname`, `customer_lastname`, `customer_email`). The customer account itself is not modified. When `email` is sent, every address in the order's address collection (`sales_order_address` — billing/shipping) gets the same email. Billing/shipping address names are untouched — use the [Order Update endpoint](OrderUpdate.md) for address changes.

Every change adds a status history note, e.g.:

> Customer info updated by IXPF API — firstname: 'John' → 'Jane', email: 'old@x.com' → 'new@x.com'

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1/Orders/UpdateCustomer?id=<order_id>` |
| **Content-Type** | `application/json` |

### Authentication

Bearer token via the `X-Auth-Token` header (same as all Syncer endpoints):

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

### Request Body

At least one field is required. Omitted fields remain unchanged.

```json
{
  "firstname": "Jane",
  "lastname": "Doe",
  "email": "jane.doe@example.com"
}
```

| Field | Type | Description |
|---|---|---|
| `firstname` | `string` | New customer first name (non-empty) |
| `lastname` | `string` | New customer last name (non-empty) |
| `email` | `string` | New customer email (must be valid) |

---

## Response

**Success — HTTP 200**

```json
{
  "success": true,
  "message": "Order #100000123 customer info updated",
  "data": {
    "order_id": 123,
    "increment_id": "100000123",
    "firstname": "Jane",
    "lastname": "Doe",
    "email": "jane.doe@example.com"
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
| Empty body / no known fields | `At least one of 'firstname', 'lastname' or 'email' is required` |
| Empty `firstname` / `lastname` | `'firstname' cannot be empty` / `'lastname' cannot be empty` |
| Invalid email | `'<email>' is not a valid email address` |
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
POST /ixpfsyncer/V1/Orders/UpdateCustomer?id=74288
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "email": "corrected@example.com"
}
```

---

## Developer Reference

| Class | Responsibility |
|---|---|
| `IXPF\Syncer\Controller\V1\Orders\UpdateCustomer` | HTTP controller — validates token + payload, updates order customer fields, saves |
| `IXPF\Syncer\Helper\TokenValidator` | Validates the Bearer token against Magento OAuth tokens |
| `IXPF\Syncer\Helper\Logger\OrderLogger` | Daily-rotating log writer for order events |
