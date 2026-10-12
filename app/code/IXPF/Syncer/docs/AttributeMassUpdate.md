# Product Attribute Mass Update

## Overview
Bulk-updates catalog product attributes, **per product**. Unlike `BulkUpdate`
(one attribute set applied to many product ids), this endpoint accepts a list of
products where **each product carries its own attribute map**.

Every attribute code is validated against the allow-list config
`ixpfng_product/product_update_config/allow_attributes` before it is written.
Invalid product ids and not-allowed attributes are reported per product — valid
work still applies for the rest (partial success).

Each product may also carry MSI source quantities (`sources`) and stock item
settings (`stock`). Both require **Allow Stock & Source Update**
(`ixpfng_product/product_update_config/allow_stock_source`, default off).

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
    },
    {
      "product_id": 125,
      "sources": [
        { "source_code": "default", "quantity": 1, "status": 1 },
        { "source_code": "hamburg", "quantity": 0 }
      ],
      "stock": { "manage_stock": 1, "backorders": 0 }
    }
  ]
}
```
Each row needs at least one of `attributes`, `sources`, `stock`.

### `sources` — MSI source items
| Field         | Required | Description                                                       |
|---------------|----------|-------------------------------------------------------------------|
| `source_code` | yes      | Existing, enabled MSI source.                                     |
| `quantity`    | yes      | **Absolute** quantity, number `>= 0` (not a delta).               |
| `status`      | no       | `1` in stock / `0` out of stock. Omitted → `1` if `quantity > 0`. |

- **Merge**, not replace: sources not listed are left untouched. Use
  `SyncerMsi` `UnassignSource` to remove an assignment.
- A source the SKU is not assigned to yet is assigned by the write.
- Same source twice in one row → last entry wins.
- Product types without source items (configurable, bundle, grouped) are rejected.
- All rows' source items are saved in **one** batch (one sync reindex, one MSI
  webhook job). If the batch fails it is retried per product, so the failure
  lands on the row that caused it.
- Salable qty = source qty − reservations. Writing `quantity: 1` on a product
  with an open order still leaves salable qty at 0.
- `default` source qty/status is mirrored to the legacy stock item
  (`cataloginventory_stock_item`) by MSI.

### `stock` — stock item settings
Allowed fields: `manage_stock`, `backorders` (`0`/`1`/`2`), `min_qty`,
`min_sale_qty`, `max_sale_qty`, and their `use_config_*` flags.
Setting a field without its `use_config_*` flag switches that flag to `0`
(otherwise the config value would win). `qty` / `is_in_stock` are **not**
accepted — set quantity through `sources`.
Stock settings are written before source items.

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
      "updated_sources": [],
      "invalid_sources": [],
      "updated_stock": [],
      "invalid_stock": [],
      "message": "Updated successfully"
    },
    {
      "product_id": 124,
      "success": false,
      "updated_attributes": [],
      "invalid_attributes": ["warp"],
      "updated_sources": [],
      "invalid_sources": [],
      "updated_stock": [],
      "invalid_stock": [],
      "message": "No valid attributes. Not allowed: warp"
    },
    {
      "product_id": 125,
      "success": true,
      "updated_attributes": [],
      "invalid_attributes": [],
      "updated_sources": ["default"],
      "invalid_sources": [{ "source_code": "nope", "reason": "Source not found" }],
      "updated_stock": ["manage_stock", "use_config_manage_stock"],
      "invalid_stock": [],
      "message": "Updated. Source nope: Source not found"
    }
  ]
}
```
A row is `success: true` when anything in it was written. `summary.partial`
counts successful rows that still had rejected `sources`/`stock` entries.
`success` at top level is `true` only when there are no error rows and no
partial rows. (Example summary above would be `{ "total": 3, "updated": 2,
"errors": 1, "partial": 1 }`.)

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
| `Nothing to update: provide attributes, sources or stock` | Row has none of the three blocks. |
| `Product not found`                       | `product_id` not in catalog.       |
| `No valid attributes. Not allowed: <..>`  | All attrs outside allow-list.      |
| `Source <code>: Source not found` / `Source disabled` | Unknown or disabled MSI source. |
| `Source <code>: quantity must be a number >= 0` | Bad `quantity`.              |
| `Source <code>: status must be 0 or 1`    | Bad `status`.                      |
| `Source <code>: Save error: <..>`         | MSI save/validation failed.        |
| `Sources: Product type '<type>' does not support source items` | Configurable/bundle/grouped. |
| `Sources: update disabled in configuration` / `Stock update disabled in configuration` | `allow_stock_source` off. |
| `Stock <field>: not allowed` / `invalid value` | Field outside the stock allow-list or bad value. |

## Class Reference
| Layer      | Class                                                             |
|------------|------------------------------------------------------------------|
| Controller | `IXPF\Syncer\Controller\V1\Products\AttributeMassUpdate`         |
| Service    | `IXPF\Syncer\Services\Products\AttributeMassUpdate`              |
| Stock/MSI  | `IXPF\Syncer\Services\Products\StockSourceUpdater`               |
| Config     | `IXPF\Syncer\Helper\Helpers::getProductUpdateAllowAttributes()`  |
| Logger     | `IXPF\Syncer\Helper\Logger\ProductUpdateLogger` (daily)         |

## Config Paths
| Setting                   | Path                                                  |
|---------------------------|-------------------------------------------------------|
| Enable Product Update     | `ixpfng_product/product_update_config/enable`         |
| Allowed Update Attributes | `ixpfng_product/product_update_config/allow_attributes` |
| Allow Stock & Source Update | `ixpfng_product/product_update_config/allow_stock_source` (default `0`) |
