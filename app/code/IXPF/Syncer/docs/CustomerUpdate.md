# Customer Update API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Update a customer account — all customer info
**Last Updated:** June 11, 2026

---

## Overview

Updates the **customer account entity** (and optionally its address book). All fields are optional — only fields present in the payload are changed. Existing orders are not modified — use the [Order Customer Update endpoint](OrderUpdateCustomer.md) for that.

The customer can be looked up by **ID** or by **current email**. Email lookup searches across all websites; if the same email exists on multiple websites, the request fails — use `id` instead. URL-encode special characters in the email (`+` → `%2B`).

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL (by ID)** | `https://<store-domain>/ixpfsyncer/v1_customers/update?id=<customer_id>` |
| **URL (by email)** | `https://<store-domain>/ixpfsyncer/v1_customers/update?email=<current_email>` |
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
  "email": "jane.doe@example.com",
  "prefix": "Ms.",
  "middlename": "M",
  "suffix": "Jr.",
  "dob": "1990-05-15",
  "taxvat": "SE123456789",
  "gender": 2,
  "group_id": 1,
  "website_id": 1,
  "phone_number": "+46701234567",
  "is_builder_account": true,
  "addresses": [
    {
      "id": 42,
      "telephone": "+46701234567"
    },
    {
      "firstname": "Jane",
      "lastname": "Doe",
      "company": "ACME AB",
      "street": ["Storgatan 1", "2 tr"],
      "city": "Stockholm",
      "postcode": "11122",
      "country_id": "SE",
      "region_id": 0,
      "telephone": "+46701234567",
      "vat_id": "SE123456789",
      "default_billing": true,
      "default_shipping": true
    }
  ]
}
```

#### Customer fields

| Field | Type | Description |
|---|---|---|
| `firstname` | `string` | First name (non-empty) |
| `lastname` | `string` | Last name (non-empty) |
| `email` | `string` | New email (must be valid and not used by another customer) |
| `prefix` | `string` | Name prefix (empty string clears it) |
| `middlename` | `string` | Middle name (empty string clears it) |
| `suffix` | `string` | Name suffix (empty string clears it) |
| `dob` | `string` | Date of birth, `Y-m-d` (empty string clears it) |
| `taxvat` | `string` | Tax/VAT number (empty string clears it) |
| `gender` | `int` | `0` not specified, `1` male, `2` female, `3` not specified |
| `group_id` | `int` | Customer group ID |
| `website_id` | `int` | Website ID the customer belongs to |
| `phone_number` | `string` | Customer phone number (custom attribute) |
| `is_builder_account` | `bool` | Builder account flag (custom attribute, `true`/`false` or `1`/`0`) |

#### Address objects

With `id` → updates that existing address (must belong to the customer); only the provided keys change. Without `id` → creates a new address (then `firstname`, `lastname`, `street`, `city`, `postcode`, `country_id`, `telephone` are required).

| Field | Type | Description |
|---|---|---|
| `id` | `int` | Existing address ID (omit to create a new address) |
| `firstname` / `lastname` | `string` | Address name |
| `company` | `string` | Company (empty string clears it) |
| `street` | `string` \| `string[]` | Street line(s) |
| `city` | `string` | City |
| `postcode` | `string` | Postcode |
| `country_id` | `string` | ISO-2 country code, e.g. `SE` |
| `region_id` | `int` | Magento region ID |
| `telephone` | `string` | Phone number |
| `vat_id` | `string` | VAT ID (empty string clears it) |
| `default_billing` | `bool` | Make this the default billing address |
| `default_shipping` | `bool` | Make this the default shipping address |

---

## Response

**Success — HTTP 200**

```json
{
  "success": true,
  "message": "Customer #123 updated",
  "changes": [
    "firstname: 'John' → 'Jane'",
    "email: 'old@x.com' → 'jane.doe@example.com'",
    "address #42 updated",
    "address added"
  ],
  "data": {
    "customer_id": 123,
    "email": "jane.doe@example.com",
    "firstname": "Jane",
    "lastname": "Doe",
    "prefix": "Ms.",
    "middlename": "M",
    "suffix": "Jr.",
    "dob": "1990-05-15",
    "taxvat": "SE123456789",
    "gender": 2,
    "group_id": 1,
    "website_id": 1,
    "store_id": 1,
    "phone_number": "+46701234567",
    "is_builder_account": true,
    "addresses": [
      {
        "id": 42,
        "firstname": "Jane",
        "lastname": "Doe",
        "company": "ACME AB",
        "street": ["Storgatan 1", "2 tr"],
        "city": "Stockholm",
        "postcode": "11122",
        "country_id": "SE",
        "region_id": 0,
        "telephone": "+46701234567",
        "vat_id": "SE123456789",
        "default_billing": true,
        "default_shipping": true
      }
    ]
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
| Missing `id` and `email` query parameters | `Either 'id' or 'email' query parameter is required` |
| Customer not found | `Customer #<id> not found` / `Customer with email '<email>' not found` |
| Same email on multiple websites | `Multiple customers found with email '<email>' (different websites) — use 'id' instead` |
| Empty body / no known fields | `At least one updatable field is required: ...` |
| Empty `firstname` / `lastname` | `'firstname' cannot be empty` / `'lastname' cannot be empty` |
| Invalid email | `'<email>' is not a valid email address` |
| Email already in use | Magento repository error (`A customer with the same email address already exists ...`) |
| Invalid `dob` | `'dob' must be a valid date in Y-m-d format` |
| Invalid `gender` | `'gender' must be 0, 1, 2 or 3` |
| Address ID of another customer | `Address #<id> does not belong to customer #<id>` |
| Missing required field on new address | `addresses[<i>]: '<field>' is required for a new address` |
| Invalid/missing Bearer token | `Unauthorized` |

---

## Logging

All requests and errors are logged by `IXPF\Syncer\Helper\Logger\CustomerLogger`:

```
var/log/ixpf/customers/customer_<YYYY-MM-DD>.log
```

---

## Examples

Update only email (lookup by ID):

```http
POST /ixpfsyncer/v1_customers/update?id=123
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "email": "corrected@example.com"
}
```

Update phone on an existing address (lookup by email):

```http
POST /ixpfsyncer/v1_customers/update?email=jane.doe@example.com
X-Auth-Token: Bearer abc123token
Content-Type: application/json

{
  "addresses": [
    { "id": 42, "telephone": "+46709999999" }
  ]
}
```

---

## Developer Reference

| Class | Responsibility |
|---|---|
| `IXPF\Syncer\Controller\V1\Customers\Update` | HTTP controller — validates token + payload, updates customer entity and addresses, saves |
| `IXPF\Syncer\Helper\TokenValidator` | Validates the Bearer token against Magento OAuth tokens |
| `IXPF\Syncer\Helper\Logger\CustomerLogger` | Daily-rotating log writer for customer events |
