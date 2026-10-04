# Transfer Source API (V2) Documentation

**Version:** 2.0
**Module:** IXPF_SyncerMsi
**Feature:** Move a given MSI source-item quantity per SKU, per source pair
**Last Updated:** October 4, 2026

---

## Overview

Moves a **given quantity** of each SKU from one MSI source to another. Unlike
[V1](TransferSource.md) (one source pair, full quantity), every product line
carries its own `from_source`, `to_source`, `sku` and `qty`.

For each line: from-source qty `-=` qty, destination qty `+=` qty. Example:
from-source has `5`, line qty `1` → from-source becomes `4`, destination gains `1`.

Rules per line:

- SKU has no source item on the from-source → `failed`.
- `qty` above what the from-source holds → `failed` (no partial move).
- Destination has no source item → **created** (status in stock). Existing
  destination status is preserved, and so is the from-source status (also at qty 0).
- Lines are applied **in order against the running quantity**. A SKU sent twice
  from the same source, or chained (`default` → `hamburg`, then `hamburg` →
  `berlin`), sees the qty left by the earlier lines.
- Only the source-item quantity counts. Reservations are not deducted.

Input handling:

- Source codes and SKUs are trimmed and matched regardless of case. The response
  and saved rows use the codes as stored in Magento.
- `qty` may be a number or numeric string, must be above 0. Decimals are kept to
  4 places.

> **Limit: 100 product lines per request.** More than 100 → HTTP 500, nothing is
> processed. Split into batches of 100.

> **HTTP 200 does not mean every line moved.** Per-line problems come back as
> `200` with `success: true` and the line listed in `failed[]`. Callers must read
> `failed[]` (match on `index`) before recording a line as moved.

---

## Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/v2_inventory/transfersource` |
| **Content-Type** | `application/json` |

> Path segment is `v2_inventory` (folder `Controller/V2/Inventory`), same pattern
> as V1's `v1_inventory`.

### Authentication

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

### Request Body

```json
{
  "products": [
    {
      "from_source": "default",
      "to_source": "hamburg",
      "sku": "VAN224566",
      "qty": 1
    }
  ]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `products` | `object[]` | yes | 1–100 lines |
| `products[].from_source` | `string` | yes | Source code to move quantity from |
| `products[].to_source` | `string` | yes | Source code to move quantity to (different source) |
| `products[].sku` | `string` | yes | SKU |
| `products[].qty` | `number` | yes | Quantity to move, above 0 |

---

## Response

**Success — HTTP 200**

```json
{
  "success": true,
  "summary": {
    "total": 2,
    "transferred": 1,
    "failed": 1
  },
  "transferred": [
    {
      "index": 0,
      "sku": "VAN224566",
      "from_source": "default",
      "to_source": "hamburg",
      "quantity": 1,
      "from_after": 4,
      "to_after": 3
    }
  ],
  "failed": [
    {
      "index": 1,
      "sku": "VAN000001",
      "from_source": "default",
      "to_source": "hamburg",
      "reason": "Insufficient quantity on from source (available 0, requested 1)"
    }
  ]
}
```

| Field | Type | Description |
|---|---|---|
| `index` | `int` | 0-based position of the line in `products` |
| `transferred[].quantity` | `float` | Amount moved |
| `transferred[].from_after` / `to_after` | `float` | Qty right after this line was applied |
| `failed[]` | `object[]` | `{index, sku, from_source, to_source, reason}` |

**Too many products — HTTP 500**

```json
{
  "success": false,
  "message": "Too many products: maximum 100 per request, received 150. Split the request into batches of 100."
}
```

**Validation error — HTTP 422**

```json
{
  "success": false,
  "message": "products is required (array of {from_source, to_source, sku, qty})"
}
```

---

## Error Handling

| Scenario | HTTP | Message |
|---|---|---|
| Empty / invalid JSON body | 422 | `Empty or invalid payload` |
| Missing / empty / non-list `products` | 422 | `products is required (...)` |
| More than 100 lines | 500 | `Too many products: maximum 100 per request, received <n>. ...` |
| `X-Auth-Token` missing / invalid | 500 | `Unauthorized` / `Unauthorized token` |
| Per-line: not an object | 200 | `failed[]` — `Product line must be an object` |
| Per-line: field missing | 200 | `failed[]` — `<field> is required` |
| Per-line: bad qty | 200 | `failed[]` — `qty is required and must be numeric` / `qty must be greater than 0` |
| Per-line: unknown source | 200 | `failed[]` — `from_source '<code>' not found` / `to_source '<code>' not found` |
| Per-line: same source (`hamburg` / `Hamburg`) | 200 | `failed[]` — `from_source and to_source must be different` |
| Per-line: not on from-source | 200 | `failed[]` — `SKU not assigned to from source` |
| Per-line: qty too high | 200 | `failed[]` — `Insufficient quantity on from source (available x, requested y)` |
| Per-line: save error | 200 | `failed[]` — `Save error: <detail>` |

> All touched source items are saved in one batch. If that save throws, every line
> that would have moved is reported in `failed[]` and nothing is written.

### Concurrent calls

Not locked per SKU. Unlike V1, V2 writes `stored qty − qty`, so two calls moving
the same SKU at the same moment can both read the same starting qty and one
move can be lost. Do not send overlapping transfers for one SKU in parallel.

---

## Logging

Same daily file as the other MSI endpoints, tag `transfer_source_v2`:

```
var/log/ixpf/inventory/inventory-YYYY-MM-DD.log
```

```
[365cf99a] [transfer_source_v2] >> {"products":[{"from_source":"default","to_source":"hamburg","sku":"VAN224566","qty":1}]}
[365cf99a] [transfer_source_v2] << 200 {"success":true,"summary":{...},...}
```

Unexpected errors (500 after a valid token) also write a stack trace to
`var/log/ixpf/exception/inventory-YYYY-MM-DD.log`. See [V1 logging](TransferSource.md#logging).

```bash
grep '\[transfer_source_v2\] << [45]' var/log/ixpf/inventory/inventory-*.log
```

---

## Developer Reference

| Class | Responsibility |
|---|---|
| `IXPF\SyncerMsi\Controller\V2\Inventory\TransferSource` | Token auth, payload shape, 100-line limit, request/response logging, delegates |
| `IXPF\SyncerMsi\Services\Inventory\V2\TransferSource` | Per-line validation, source resolution, running-qty move, one batch save, result. `MAX_PRODUCTS` const holds the limit |
| `IXPF\Syncer\Helper\Logger\InventoryLogger` | Daily inventory log |
| `IXPF\Syncer\Helper\TokenValidator` | Bearer token validation |
