# Stock Reservations API Documentation

**Version:** 1.0
**Module:** IXPF_Syncer
**Feature:** Two-way stock reservation sync between Magento and IXPF
**Last Updated:** September 17, 2026

---

## Table of Contents

- [Overview](#overview)
- [Prerequisites](#prerequisites)
- [Configuration](#configuration)
- [Outbound — Magento to IXPF](#outbound--magento-to-ixpf)
- [Acknowledgement — IXPF to Magento](#acknowledgement--ixpf-to-magento)
- [Inbound — IXPF to Magento](#inbound--ixpf-to-magento)
- [The ixpf_reservations table](#the-ixpf_reservations-table)
- [Field Reference](#field-reference)
- [Troubleshooting](#troubleshooting)

---

## Overview

Two independent flows, one table.

**Outbound.** When an order is placed, Magento works out which warehouse each
line will come from and tells IXPF to hold that stock. It then reports every
later transition of that reservation — completed, cancelled, refunded.

**Inbound.** IXPF pushes a signed quantity adjustment for a SKU at a source.
Magento applies it to `inventory_source_item`; salable quantity moves
immediately.

Everything in both directions lands in `ixpf_reservations`, which is append-only
and visible in the admin at **System → IXPF Syncer → Stock Reservations**.

### Why Magento has to choose the source

MSI does not decide which source fulfils an order until a shipment is created —
`inventory_reservation` carries only a `stock_id`, never a `source_code`. The JP
website maps to the *Tokyo* stock, which aggregates `tokyo` (priority 3) and
`hamburg` (priority 4), and both hold real quantity. So at placement time the
source is genuinely ambiguous, and the outbound payload needs one.

Magento therefore runs the **Source Selection Algorithm** at placement and sends
what it returns. One order line can split across several sources, which arrives
as several `products` entries for the same SKU.

### Lifecycle events never re-run source selection

By the time an order is cancelled or refunded, stock has moved. Re-running the
algorithm would name a *different* warehouse, so IXPF would release a hold it
never took while the real one stayed stuck forever.

Every lifecycle event instead **replays the `(sku, source, qty)` rows recorded at
placement**. Rows marked `skipped` are excluded — they record a placement that
never reached IXPF and carry the placeholder source `unresolved`, so replaying
one would name a warehouse that does not exist.

**If there is no placement batch at all**, the event does not stay silent. The
source is resolved again at event time and the batch is sent anyway, with a note
in `information` saying the source was inferred rather than replayed. That covers
orders placed before the feature was switched on, or while it was off — telling
IXPF the order moved on with a probably-right warehouse beats telling it nothing.

Only when both routes come up empty — no placement batch *and* no source can be
resolved now — is a `skipped` row written instead.

---

## Prerequisites

- A Magento integration access token (`X-Auth-Token: Bearer <token>`).
- `ixpfng/general/shop_object_id`, `ixpfng/product/webhook_url` and
  `ixpfng/product/secret_code` set — the reservation webhook reuses the product
  webhook endpoint.
- The SKUs involved must already be assigned to the sources involved. Neither
  direction creates a source item; use
  [AssignSource](../../SyncerMsi/docs/AssignSource.md) for that.

---

## Configuration

**Stores → Configuration → IXPF NG → Reservations**

| Setting | Config Path | Default | Description |
|---|---|---|---|
| Send Reservations to IXPF | `ixpfng_reservation/general/enabled` | `0` | Master switch for the outbound flow, placement and lifecycle |
| Accept Adjustments from IXPF | `ixpfng_reservation/general/inbound_enabled` | `0` | Allows the inbound endpoint to write source item quantities |
| Max Send Attempts | `ixpfng_reservation/general/max_attempts` | `10` | Attempts before a batch is given up on |
| Batches Per Cron Run | `ixpfng_reservation/general/dispatch_limit` | `50` | Batches one cron tick will send |

The two switches are deliberately independent: IXPF can be allowed to push
adjustments before Magento starts emitting reservations, or the other way round.
**Both default to off** — nothing happens until each is turned on.

Endpoint and credentials come from the existing product webhook group:

| Setting | Config Path |
|---|---|
| Shop ID | `ixpfng/general/shop_object_id` |
| Webhook Url | `ixpfng/product/webhook_url` |
| Secret | `ixpfng/product/secret_code` — sent as a `?token=` query param, not a header |

---

## Outbound — Magento to IXPF

### The customer never waits for IXPF

```
customer clicks Place Order
  → order saves, ixpf_reservations rows written (pending, same transaction)
  → batch claimed (processing), request written to the socket, socket closed
       ↑ no reply is read — at most a 1s handshake, never an ERP round trip
  → success page / OSC JSON sent to the browser        ← customer is done here
  ─────────── later, on IXPF's own schedule ───────────
  → IXPF POSTs {success, acknowledged_id, message} to the Acknowledge endpoint
  → batch moves from processing to success or failed
```

The same applies to an admin shipping, cancelling or refunding an order: the
observers run inside the order save, so anything they block on blocks the person
who clicked the button.

**The send never throws out of the observer.** If the socket cannot be opened the
batch is marked `failed` and `ixpf_reservation_pending_dispatch` carries it.
Degradation is "IXPF hears about it within a minute", never "the customer waits".

An earlier revision called `fastcgi_finish_request()` from the observer instead.
That closed the connection *during* the save — before the controller had returned
its redirect — so admin shipment creation answered `200` with an empty body and
rendered a blank page. Do not reintroduce it: the observers run inside the save,
not after the response.

### Why fire-and-forget, and what it costs

Guzzle cannot do this: every transport it has reads the response before it
returns, which is exactly the waiting being avoided. So the async path writes a
raw HTTP/1.1 request to a socket and closes it — connect, write, hang up. The
only part that can block is the TCP (and, on https, TLS) handshake, capped at
**1 second**.

The cost is that the send carries no outcome. Two mechanisms cover it:

| Situation | What settles the batch |
|---|---|
| IXPF answers | The Acknowledge endpoint, by `acknowledged_id` |
| IXPF never answers | `ReservationRetry` releases the batch after `STUCK_MINUTES` (15) back to `pending`; `ReservationDispatch` re-sends it **blocking**, reads the reply, and settles it inline |

So a batch cannot sit in `processing` forever, and a silent IXPF costs at most
15 minutes before a blocking re-send gets a real answer.

"The socket opened" is **not** "IXPF received it". Under Docker Desktop the
userland proxy completes the TCP handshake before it knows whether the upstream
is alive, so a send to a dead IXPF still looks successful from here — verified
locally against an unreachable host, where the write returned in 14ms and the
batch went to `processing` with no error. That is the point of the release: the
blocking re-send is what finds out.

`attempts` is incremented by the fire-and-forget send itself, not by the
acknowledgement — otherwise a batch IXPF ignores would be re-sent forever without
ever reaching the attempt cap.

### acknowledged_id

Every outbound payload carries `acknowledged_id`: the `entity_id` of the batch's
first row in `ixpf_reservations`. A batch is several rows — one per SKU line —
but IXPF acknowledges it as a whole, so one id stands for all of them. Rows are
read `ORDER BY entity_id ASC`, so a re-send always quotes the same id.

### Triggers

| Magento event | `status` sent | Lines sent |
|---|---|---|
| `sales_model_service_quote_submit_success` | `pending` | Source Selection Algorithm result |
| `sales_order_save_after`, state becomes `complete` | `completed` | every placement line |
| `order_cancel_after` | `cancelled` | every placement line |
| `sales_order_creditmemo_save_after` | `refunded` | only the credited items, at the credited qty |

Placement hooks `sales_model_service_quote_submit_success` rather than
`sales_order_save_after` because it fires exactly once per order and covers
storefront checkout, one-step checkout, admin order create and the IXPF
`Quote/Submit` API alike.

Magento has no order state-change event, so `completed` is detected by comparing
`getOrigData('state')` with the saved state.

**Deduplication.** `completed` and `cancelled` are guarded on
`(order_id, reservation_status)` and send at most once per order. Refunds are
guarded on the credit memo id instead, because several partial refunds against
one order are legitimate and each must send.

**A partial refund** maps credited quantities back onto the placement rows
source by source, in the order they were recorded. Refunding 2 against a
placement of `tokyo:1 + hamburg:2` produces `tokyo:1 + hamburg:1`. Quantities
beyond what was reserved are dropped, never invented.

### Payload

`POST <webhook_url>?token=<secret>`

```json
{
  "shop_object_id": "caStageJP",
  "type": "reservation",
  "status": "pending",
  "acknowledged_id": 1212,
  "order_id": 101,
  "products": [
    { "sku": "sku101", "qty": 1, "source": "hamburg" }
  ]
}
```

| Field | Type | Description |
|---|---|---|
| `shop_object_id` | `string` | From `ixpfng/general/shop_object_id` |
| `type` | `string` | Always `reservation` |
| `status` | `string` | `pending`, `completed`, `cancelled` or `refunded` |
| `acknowledged_id` | `int` | `ixpf_reservations.entity_id` of the batch's first row. **Quote this back on the Acknowledge endpoint** |
| `order_id` | `int` | **`sales_order.entity_id`**, not the increment id |
| `products[].sku` | `string` | |
| `products[].qty` | `float` | **Always positive.** Direction is carried by `status`. The table stores it signed |
| `products[].source` | `string` | `inventory_source.source_code` |

One SKU may appear more than once, with a different `source` each time, when the
algorithm splits the line.

### Expected response

**The observers' send reads no reply at all** — it writes and hangs up. Anything
IXPF returns on that connection is discarded, so the outcome must come back on
the [Acknowledge endpoint](#acknowledgement--ixpf-to-magento). The batch stays
`processing` until it does.

Only the **cron** sends block and read the reply inline:

```json
{ "success": true, "message": "" }
```

| Reply | Recorded as |
|---|---|
| `{"success": true}` | `request_status = success` |
| `{"success": false, "message": "..."}` | `request_status = failed`, `message` copied into `information` |
| non-2xx | `failed`, `information = "HTTP <code>"` |
| connection error / timeout | `failed`, with the error in `information` |
| unparseable body | `failed`, `information = "Unparseable IXPF response"` |
| socket would not open (async path only) | `failed`, `information = "Could not open a connection to IXPF"` |

Failures are retried by `ixpf_reservation_retry` every minute until `attempts`
reaches Max Send Attempts, and each failure raises a Slack alert.

**Once `attempts` reaches the cap the batch is never sent again**, even if IXPF
recovers. There is no Retry button on the grid yet, so reviving one means
resetting its row:

```sql
UPDATE ixpf_reservations SET attempts = 0, request_status = 'pending'
WHERE batch_id = '<batch_id>';
```

### Cron jobs

| Job | Schedule | Claims |
|---|---|---|
| `ixpf_reservation_pending_dispatch` | `*/1 * * * *` | `request_status = pending` |
| `ixpf_reservation_retry` | `*/1 * * * *` | `request_status = failed`, and `processing` rows stale over 15 minutes |

Both run every minute, so a failed batch exhausts Max Send Attempts in roughly
that many minutes. Raise `max_attempts` if you want the retry window to span a
longer IXPF outage — at the default 10 the window is about 10 minutes.

A batch is claimed (`pending` → `processing`) before it is sent, so an observer
and a cron tick can never POST the same reservation twice.

---

## Acknowledgement — IXPF to Magento

How IXPF reports the outcome of a reservation it was sent. Without this call a
fire-and-forget batch stays `processing` until `ReservationRetry` releases it.

### Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1_Reservations/Acknowledge` |
| **Content-Type** | `application/json` |
| **Header** | `X-Auth-Token: Bearer <token>` |

> **Note the underscore**, same as `V1_Reservations/Apply`. The slash form 302s
> and never reaches a controller.

```json
{
  "success": true,
  "acknowledged_id": 1212,
  "message": ""
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `acknowledged_id` | `int` | yes | The value Magento sent in the reservation payload |
| `success` | `bool` | yes | `true` accepts the reservation, anything else rejects it |
| `message` | `string` | no | Stored in `information`. On rejection this is the reason |

### What it changes

Every row of the batch the `acknowledged_id` belongs to:

| `success` | `request_status` | `information` |
|---|---|---|
| `true` | `success` | `message`, or left empty when blank |
| `false` / missing | `failed` | `message`, or `"IXPF rejected the reservation"` |

The raw body is stored in `response`. `attempts` is **not** incremented — the send
already counted itself.

A rejected batch is picked up by `ixpf_reservation_retry` on the next minute,
like any other failure, until `attempts` hits Max Send Attempts.

### Responses

| Code | Body | When |
|---|---|---|
| `200` | `{"success": true, "acknowledged_id": 1212, "batch_id": "res_…", "request_status": "success"}` | Recorded |
| `404` | `{"success": false, "message": "No outbound reservation found for acknowledged_id 1212."}` | Unknown or inbound-only id |
| `422` | `{"success": false, "message": "Payload must contain a numeric acknowledged_id."}` | Malformed body |
| `422` | `{"success": false, "message": "Reservations are disabled in configuration."}` | `ixpfng_reservation/general/enabled` is off |
| `500` | `{"success": false, "message": "<error>"}` | Anything unhandled; the exception is logged |

Note that `200` means "we recorded your answer", including when your answer was a
rejection. The `request_status` field says which way it went.

### Example

```bash
curl -sk -X POST 'https://<store-domain>/ixpfsyncer/V1_Reservations/Acknowledge' \
  -H 'X-Auth-Token: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{"success": true, "acknowledged_id": 1212, "message": ""}'
```

---

## Inbound — IXPF to Magento

### Request

| Property | Value |
|---|---|
| **Method** | `POST` |
| **URL** | `https://<store-domain>/ixpfsyncer/V1_Reservations/Apply` |
| **Content-Type** | `application/json` |

> **Note the underscore.** Magento's router consumes exactly three path segments
> and maps `_` to `\`, so `/ixpfsyncer/V1/Reservations/Apply` never reaches the
> controller — it returns 404. Some older docs in this module show the slash
> form; they are wrong.

### Authentication

```http
X-Auth-Token: Bearer <your_magento_integration_token>
```

### Request Body

```json
{
  "products": [
    { "sku": "sku101", "qty": -1, "source": "hamburg" }
  ]
}
```

| Field | Type | Required | Description |
|---|---|---|---|
| `products` | `array` | yes | Must be non-empty |
| `products[].sku` | `string` | yes | Must already be assigned to `source` |
| `products[].qty` | `float` | yes | **Signed and relative.** Negative removes stock, positive returns it. Must not be zero |
| `products[].source` | `string` | yes | Must exist and be enabled |

> **This endpoint is delta-adjust**: `new = current + qty`. Note the two other
> conventions in this codebase — `SyncerMsi` `TransferSource` writes absolute
> totals and `AssignSource` writes `0`.

### What it changes

The signed quantity is added to `inventory_source_item.quantity` via
`SourceItemsSaveInterface`. **No reindex call is made and none is needed.**
`cataloginventory/indexer/strategy` defaults to `sync`, so the save runs
`ReindexAfterSourceItemsSavePlugin` → `SourceItemIndexer` → `Strategy\Sync`,
which rebuilds the stock index directly and bypasses mview. Salable quantity is
correct by the time the response is written.

### Validation

Each line is checked before anything is written. A line failing any check is
reported in `failed` and **does not stop the others**.

| Check | Failure reason |
|---|---|
| Source exists and is enabled | `Source '<code>' not found` |
| SKU has a source item on that source | `SKU not assigned to source '<code>'` |
| `current + qty >= 0` | `Adjustment would drive quantity negative (...)` |
| `qty != 0` | `qty must not be zero` |
| `sku` and `source` present | `sku and source are required` |

A negative result is **rejected, not clamped**. A negative physical quantity is a
data bug, and flooring it at zero would hide the divergence between the two
systems instead of surfacing it.

### Response

**All lines applied — HTTP 200**

```json
{
  "success": true,
  "message": "",
  "batch_id": "res_68c9f1a2b3c4d5.67890123",
  "summary": { "total": 1, "applied": 1, "failed": 0 },
  "applied": [
    { "sku": "sku101", "source": "hamburg", "qty": -1, "qty_before": 10, "qty_after": 9 }
  ],
  "failed": []
}
```

**Any line failed — HTTP 422**

```json
{
  "success": false,
  "message": "1 of 2 lines failed.",
  "batch_id": "res_68c9f1a2b3c4d5.67890123",
  "summary": { "total": 2, "applied": 1, "failed": 1 },
  "applied": [
    { "sku": "sku101", "source": "hamburg", "qty": 1, "qty_before": 8, "qty_after": 9 }
  ],
  "failed": [
    { "sku": "sku101", "source": "nosuch", "reason": "Source 'nosuch' not found" }
  ]
}
```

**Disabled in configuration — HTTP 422**, **bad or missing token / unexpected
error — HTTP 500**, both as `{"success": false, "message": "..."}`.

Note that a 422 can still mean part of the batch was applied — check `summary`.
Each recorded row carries its own outcome, so a part-applied batch shows some
rows `applied` and some `failed` rather than one status for the lot.

### Example

```bash
curl -sk -X POST "https://<store-domain>/ixpfsyncer/V1_Reservations/Apply" \
  -H 'X-Auth-Token: Bearer <token>' \
  -H 'Content-Type: application/json' \
  -d '{"products":[{"sku":"sku101","qty":-1,"source":"hamburg"}]}'
```

---

## The ixpf_reservations table

Append-only. Nothing is updated in place except a row's own delivery outcome, so
the table doubles as the audit trail.

**Two status columns — do not conflate them.**

`reservation_status` is the *business* stage, and is what goes out as `status` in
the payload:

| Value | Meaning |
|---|---|
| `pending` | Placed and held. Also used for inbound rows |
| `completed` | Order completed, hold consumed |
| `cancelled` | Order cancelled, stock returned |
| `refunded` | Credit memo issued, stock returned |

`request_status` is *delivery* — whether the HTTP call worked:

| Value | Direction | Meaning |
|---|---|---|
| `pending` | both | Not sent yet |
| `processing` | outbound | Claimed by a sender |
| `success` | outbound | IXPF replied `success: true` |
| `applied` | inbound | Source item written |
| `failed` | both | Tried and it did not work. **Retried** by cron |
| `skipped` | outbound | Nothing was sent and nothing ever will be. **Never retried** |

An order accumulates one batch at placement plus one per lifecycle event.

---

## Field Reference

| Column | Type | Description |
|---|---|---|
| `entity_id` | `bigint` | Primary key |
| `batch_id` | `varchar(40)` | Groups every row of one request |
| `direction` | `varchar(16)` | `outbound` = sent to IXPF, `inbound` = received from IXPF |
| `order_id` | `bigint` | `sales_order.entity_id`. Null for inbound |
| `sku` | `varchar(64)` | |
| `stock_source` | `varchar(64)` | `inventory_source.source_code` |
| `qty` | `decimal(12,4)` | **Signed.** Negative removes stock |
| `reservation_type` | `varchar(16)` | `decrement` or `increment`, derived from the sign |
| `reservation_status` | `varchar(16)` | Lifecycle stage — see above |
| `event_ref` | `varchar(64)` | Dedup key for the triggering event, e.g. credit memo id |
| `request_status` | `varchar(16)` | Delivery state — see above |
| `information` | `text` | IXPF's message on rejection, or the local error |
| `payload` | `text` | JSON body sent or received |
| `response` | `text` | Raw IXPF response, outbound only |
| `attempts` | `int` | Send attempts so far |

---

## Troubleshooting

Logs: `var/log/ixpf/reservation/reservation-<date>.log`, exceptions under
`var/log/ixpf/exception/`.

| Symptom | Cause |
|---|---|
| Nothing recorded at all | `ixpfng_reservation/general/enabled` is off |
| Rows stuck at `pending` | Cron is not running. Check `cron_schedule` for `ixpf_reservation_pending_dispatch` — an empty table means Magento cron has never run in that environment |
| Rows stuck at `processing` | IXPF received the send but never called Acknowledge. `ReservationRetry` releases them after 15 minutes — if they never move, cron is not running either |
| `information` says "Could not open a connection to IXPF" | The fire-and-forget socket could not connect within 1s. IXPF is down, or the host is unroutable from the php-fpm container — check with `curl --connect-timeout 3 <webhook_url>` from inside it |
| Acknowledge returns 404 | The `acknowledged_id` is not an outbound row. Confirm it against `select entity_id, batch_id from ixpf_reservations where entity_id = <id>` |
| `information` says "Webhook configuration missing" | `shop_object_id`, `webhook_url` or `secret_code` is blank |
| Row with source `unresolved` and status `skipped` | The warehouse could not be determined, so nothing was sent. `information` says why. SKU and qty are still recorded |
| `skipped` + "No inventory source has enough stock" | The algorithm could not cover the order — the SKU has no source items, or stock is genuinely short |
| `skipped` + "Placement reservation never reached IXPF" | The order was never reserved, so completing/cancelling/refunding it has nothing to release. Expected, not an error |
| `completed` never sent | The order never reached state `complete` |

| Inbound returns 404 | Slash form of the URL — use `V1_Reservations` |
| Salable qty did not move after an inbound write | Check `cataloginventory/indexer/strategy` is `sync`, not `async` |

The webhook secret is stripped from stored error messages and Slack alerts, so
`?token=***` in `information` is expected.
