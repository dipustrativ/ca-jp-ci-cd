# Product Attribute Mass Update

## Overview
Bulk-updates catalog product attributes, **per product**. Unlike `BulkUpdate`
(one attribute set applied to many product ids), this endpoint accepts a list of
products where **each product carries its own attribute map**.

Every attribute code is validated against the allow-list config
`ixpfng_product/product_update_config/allow_attributes` before it is written.
Invalid product ids and not-allowed attributes are reported per product — valid
work still applies for the rest (partial success).

## URL
```
POST /ixpfsyncer/V1/Products/AttributeMassUpdate
```

## Query Params
| Param   | Required | Description                                  |
|---------|----------|----------------------------------------------|
| `store` | no       | Store view id. Default `0` (global/default). |

## Auth
Header required:
```
X-Auth-Token: Bearer <token>
```

## Request Body
```json
{
  "products": [
    {
      "product_id": 124,
      "attributes": {
        "price": 110,
        "online": 1
      }
    },
    {
      "product_id": 124,
      "attributes": {
        "warp": 2323
      }
    }
  ]
}
```

### Value guards
| Attribute       | Value `0` behaviour                              |
|-----------------|-------------------------------------------------|
| `price`         | Ignored (skipped) — prevents accidental 0 price. |
| `special_price` | Set to `null` — clears the special price.        |

## Success Response (`200`)
Returned when at least one product updated. Per-row status included.
```json
{
  "success": false,
  "message": "Mass attribute update processed. 1 updated, 1 with errors.",
  "summary": { "total": 2, "updated": 1, "errors": 1 },
  "results": [
    {
      "product_id": 124,
      "success": true,
      "updated_attributes": ["price", "online"],
      "invalid_attributes": [],
      "message": "Updated successfully"
    },
    {
      "product_id": 124,
      "success": false,
      "updated_attributes": [],
      "invalid_attributes": ["warp"],
      "message": "No valid attributes. Not allowed: warp"
    }
  ]
}
```
`success` at top level is `true` only when every row succeeded.

## Error Responses
All rows failed (`422`) — same body shape, `summary.updated = 0`.

Validation error (`422`):
```json
{ "error": "Missing or invalid \"products\" array" }
```

Config disabled / server failure (`500`):
```json
{ "error": "Product update configuration disabled" }
```

### Per-row error messages
| Message                                   | Cause                              |
|-------------------------------------------|------------------------------------|
| `Missing product_id`                      | Row has no `product_id`.           |
| `Empty attributes`                        | Row has empty/invalid `attributes`.|
| `Product not found`                       | `product_id` not in catalog.       |
| `No valid attributes. Not allowed: <..>`  | All attrs outside allow-list.      |

## Class Reference
| Layer      | Class                                                             |
|------------|------------------------------------------------------------------|
| Controller | `IXPF\Syncer\Controller\V1\Products\AttributeMassUpdate`         |
| Service    | `IXPF\Syncer\Services\Products\AttributeMassUpdate`              |
| Config     | `IXPF\Syncer\Helper\Helpers::getProductUpdateAllowAttributes()`  |
| Logger     | `IXPF\Syncer\Helper\Logger\ProductUpdateLogger` (daily)         |

## Config Paths
| Setting                   | Path                                                  |
|---------------------------|-------------------------------------------------------|
| Enable Product Update     | `ixpfng_product/product_update_config/enable`         |
| Allowed Update Attributes | `ixpfng_product/product_update_config/allow_attributes` |
