# Customer Create API

**Version:** 1.0
**Last Updated:** 2026-08-24

## Overview

Creates a Magento customer from an IXPF payload and, optionally, one or more
address-book entries in the same call. Uses the customer service contracts, so
password hashing, duplicate-email checks and website scoping all happen the
Magento way.

## Configuration

| Setting | Admin Path | Config Path |
|---------|-----------|-------------|
| Enable | Stores → Configuration → IXPF NG → Customer → Customer Create Configuration → Enable | `ixpfng_customer/customer_create/enabled` |

Endpoint returns `500` with `Customer create is disabled in config` when the toggle is off.

## Authentication

Header required on every request:

```
X-Auth-Token: Bearer <token>
```

## API Endpoint

| | |
|---|---|
| **Method** | `POST` |
| **URL** | `/ixpfsyncer/V1/Customers/Create` |
| **Content-Type** | `application/json` |

## Request Body

```json
{
  "email": "john.doe@example.com",
  "firstname": "John",
  "lastname": "Doe",
  "website_id": 1,
  "store_id": 1,
  "group_id": 1,
  "password": "S3cret!23",
  "prefix": "Mr",
  "middlename": "A",
  "suffix": "Jr",
  "dob": "1990-05-20",
  "taxvat": "GB123456789",
  "gender": 1,
  "phone_number": "+441234567890",
  "is_builder_account": 0,
  "addresses": [
    {
      "firstname": "John",
      "lastname": "Doe",
      "company": "Acme Ltd",
      "street": ["221B Baker Street"],
      "city": "London",
      "region": "Greater London",
      "region_id": 0,
      "postcode": "NW1 6XE",
      "country_id": "GB",
      "telephone": "+441234567890",
      "is_default_billing": true,
      "is_default_shipping": true
    }
  ]
}
```

### Customer Fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | **Yes** | Must be unique per website. |
| `firstname` | string | **Yes** | |
| `lastname` | string | **Yes** | |
| `website_id` | int | No | Defaults to current store's website. |
| `store_id` | int | No | |
| `group_id` | int | No | Defaults to Magento's default group. |
| `password` | string | No | Hashed on save. Omit → account has no password (cannot log in). |
| `prefix`, `middlename`, `suffix` | string | No | |
| `dob` | string | No | `YYYY-MM-DD`. |
| `taxvat` | string | No | |
| `gender` | int | No | 1 = Male, 2 = Female (per store config). |
| `phone_number` | string | No | Custom attribute. |
| `is_builder_account` | bool/int | No | Custom attribute. |

### Address Fields

Provide `addresses` (array) or a single `address` (object). Addresses optional — omit for a customer with no address book.

| Field | Type | Description |
|-------|------|-------------|
| `firstname`, `lastname`, `company` | string | |
| `street` | string \| string[] | String split on newline into lines. |
| `city`, `postcode`, `telephone`, `fax`, `vat_id` | string | |
| `country_id` | string | ISO-2, e.g. `GB`. |
| `region_id` | int | Numeric region id (for countries with fixed regions). |
| `region` | string | Free-text region name. |
| `region_code` | string | e.g. `CA`. |
| `is_default_billing` | bool | |
| `is_default_shipping` | bool | |

## Response

### Success — HTTP 200

```json
{
  "success": true,
  "data": {
    "customer_id": 42,
    "email": "john.doe@example.com",
    "firstname": "John",
    "lastname": "Doe",
    "website_id": 1,
    "group_id": 1,
    "addresses": [
      {
        "id": 7,
        "firstname": "John",
        "lastname": "Doe",
        "street": ["221B Baker Street"],
        "city": "London",
        "postcode": "NW1 6XE",
        "country_id": "GB",
        "telephone": "+441234567890",
        "is_default_billing": true,
        "is_default_shipping": true
      }
    ]
  }
}
```

### Error — HTTP 500

```json
{
  "success": false,
  "error": "Customer with email 'john.doe@example.com' already exists on this website"
}
```

Common errors: `Field 'email' is required`, `Invalid email`, `Empty payload`, `Unauthorized`, `Customer create is disabled in config`.

## Examples

### Minimal (required fields + one address)

```bash
curl -X POST 'https://<domain>/ixpfsyncer/V1/Customers/Create' \
  -H 'X-Auth-Token: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{
    "email": "jane@example.com",
    "firstname": "Jane",
    "lastname": "Roe",
    "address": {
      "street": ["10 Downing St"],
      "city": "London",
      "postcode": "SW1A 2AA",
      "country_id": "GB",
      "telephone": "+441111111111",
      "is_default_billing": true,
      "is_default_shipping": true
    }
  }'
```

### Full payload (all fields + address book)

```bash
curl -X POST 'https://<domain>/ixpfsyncer/V1/Customers/Create' \
  -H 'X-Auth-Token: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{
    "email": "john.doe@example.com",
    "firstname": "John",
    "lastname": "Doe",
    "website_id": 1,
    "store_id": 1,
    "group_id": 1,
    "password": "S3cret!23",
    "prefix": "Mr",
    "middlename": "A",
    "suffix": "Jr",
    "dob": "1990-05-20",
    "taxvat": "GB123456789",
    "gender": 1,
    "phone_number": "+441234567890",
    "is_builder_account": 0,
    "addresses": [
      {
        "firstname": "John",
        "lastname": "Doe",
        "company": "Acme Ltd",
        "street": ["221B Baker Street"],
        "city": "London",
        "region": "Greater London",
        "region_id": 0,
        "postcode": "NW1 6XE",
        "country_id": "GB",
        "telephone": "+441234567890",
        "is_default_billing": true,
        "is_default_shipping": true
      }
    ]
  }'
```

## Logging

- Daily log: `var/log/ixpf/customers/customer-YYYY-MM-DD.log`
- Activity: `ixpf_order_api_activity` table (`activity_type = customer_create`).

## Developer Reference

| Component | Class |
|-----------|-------|
| Controller | `IXPF\Syncer\Controller\V1\Customers\Create` |
| Service | `IXPF\Syncer\Services\Customer\CreateCustomer` |
| Config gate | `IXPF\Syncer\Helper\Config::isCustomerCreateEnabled()` |
| Config path | `ixpfng_customer/customer_create/enabled` |
