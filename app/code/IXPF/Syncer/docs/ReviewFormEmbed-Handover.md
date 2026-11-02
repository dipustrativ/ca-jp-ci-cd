# Review form embed — IXPF handover

Customers click a review invite and land on a CarpetAvenue storefront page — normal header,
navigation, search, footer — with the IXPF review form in an iframe. The form keeps running on
its own origin, so submits stay inside the frame and no review data passes through Magento.

Magento is built and verified end-to-end against the real app. This is what IXPF has to change.

```
Email link       https://carpetavenue.de/reviews?token=<token>&sku=VAN211640&rating=4
                                    │  Magento validates, rebuilds
Iframe src       https://review.carpetavenue.com/r/<token>?sku=VAN211640&rating=4&parent=https://carpetavenue.de
```

Every part of the iframe URL earns its place:

| Part | Why it must be there |
|---|---|
| `<token>` | The iframe is a **different origin** — no Magento session, no shared cookie. The token is the only credential the form has, and the only thing identifying which order and which product this invite is for. Magento cannot authenticate to IXPF for the customer, so it forwards the token untouched |
| `sku`, `rating` | Preselects the product and the star the customer clicked in the email. Optional — the form works without them |
| `parent` | The form must postMessage its height to **one concrete origin**. It cannot work that out for itself: reading the parent's location is blocked cross-origin, and IXPF's own `Referrer-Policy: no-referrer` strips the referrer. So the embedder declares it and the app verifies it against `ReviewFrameAncestors` before replying — declared by the embedder, verified by the app. An unrecognised value is dropped, not echoed |

The customer-facing review host is never the application hostname. Stage and production go
Cloudflare DNS → GCP load balancer → app; locally an nginx container does the same job. The
proxying is server-side, so the browser never resolves or connects to `*.elastycloud.net` —
URL bar, page source, network tab and DNS all show only `review.carpetavenue.com`. §5 is the
one place that still breaks.

| | Storefront (parent) | Review host (iframe) | IXPF app |
|---|---|---|---|
| **Local** | `http://*-m246.carpetavenue.devs` | `https://localhost:8443` | `http://localhost:8091` |
| **Stage** | `https://*-m246.carpetavenue.net` | `https://review-stage.carpetavenue.com` | `https://ixpf-ng-stage.elastycloud.net` |
| **Prod** | the 16 storefront domains | `https://review.carpetavenue.com` | `https://ixpf-ng.elastycloud.net` |

---

# ⚙ Configuration to set

Four values. Nothing works until 1 and 2 are set; photo upload breaks silently without 3.

### 1. `ReviewFrameAncestors` — who may frame the form

`config.json` (gitignored, so per deployment) or the `REVIEW_FRAME_ANCESTORS` env var,
comma-separated. Entries must be plain `scheme://host[:port]`. A wildcard covers **one label**:
`*.carpetavenue.net` matches `de-m246.carpetavenue.net`, not `a.b.carpetavenue.net`. Scheme is
part of an origin, so http and https are separate entries.

```jsonc
// local — local stores run on http, so both schemes
"ReviewFrameAncestors": ["https://*.carpetavenue.devs", "http://*.carpetavenue.devs"]

// stage
"ReviewFrameAncestors": ["https://*-m246.carpetavenue.net"]

// production — all 16, from Magento core_config_data web/secure/base_url.
// A missing one is a blank frame for that country only, with no error anywhere.
"ReviewFrameAncestors": ["https://carpetavenue.de", "https://carpetavenue.se", …]
```

Empty or unset keeps today's behaviour: `frame-ancestors 'none'` + `X-Frame-Options: DENY`.
The app logs the effective list at startup — `review form frame-ancestors: …`.

### 2. Shop `StorefrontHost` — new per-shop field

`ShopReviewSettings` (`internal/models/shops/types.go:52`) has one host today. Two are needed,
because the review link and the unsubscribe link now live on different domains:

| Field | Value | Used by |
|---|---|---|
| `PublicHost` — **keep as is** | `https://review.carpetavenue.com` | unsubscribe, and the origin the iframe loads |
| `StorefrontHost` — **new** | `https://carpetavenue.de` (one per shop) | the review link in the email |

Only the review form is embedded. Unsubscribe is a plain link a customer opens directly and has
no reason to route through Magento, so it stays on the review host. Needs the code change in §1.

### 3. Bucket CORS — origin of the page uploading photos

The browser PUTs review photos straight to GCS, so the bucket must allow the **new** public
host. Renaming from `ixpf-ng-stage.elastycloud.net` breaks the existing entry.

```json
[{
  "origin": ["https://review.carpetavenue.com", "https://review-stage.carpetavenue.com"],
  "method": ["PUT"],
  "responseHeader": ["Content-Type", "X-Goog-Content-Length-Range"],
  "maxAgeSeconds": 3600
}]
```

`X-Goog-Content-Length-Range` is how the signed URL enforces the 10 MB cap — omit it and every
upload fails. Already tracked as infra in `docs/plans/product-reviews-plan.md:190`.

### 4. Magento

```bash
bin/magento config:set ixpf_reviews/form/enabled 1
bin/magento config:set ixpf_reviews/form/base_url https://review-stage.carpetavenue.com
```

---

# Changes needed in IXPF

## Why the framing fix cannot go at the edge

The form is unembeddable anywhere today: `frame-ancestors 'none'` and `X-Frame-Options: DENY`.

Neither is fixable at Cloudflare or the load balancer. When a response carries more than one
`Content-Security-Policy` header the browser enforces their **intersection**, so a permissive
`frame-ancestors` added at the edge still resolves to `'none'`. The edge would have to strip and
rewrite the app's header, which the GCP LB cannot do reliably. And `X-Frame-Options` has no
allowlist form — `ALLOW-FROM` is dead in every current browser — so its mere presence blocks the
embed regardless of what `frame-ancestors` says.

## 1. Emit the storefront URL — **blocking**

`SubmissionURL()` (`internal/reviews/requests.go:64`) returns `PublicHost + "/r/" + raw`. It must
return `StorefrontHost + "/reviews?token=" + raw`.

Two other places break as a consequence. Both are string concatenation onto a URL that used to
end in a path segment and now ends in a query string.

**`internal/reviews/suppression.go:25`** — the worse one, because it produces no error at all:

```go
func UnsubscribeURL(submissionURL string) string {
    return submissionURL + "/unsubscribe"          // → …/reviews?token=X/unsubscribe
}
```

The token becomes `X/unsubscribe`, which fails Magento's token pattern — every unsubscribe link
in every review email 404s. It has to stop deriving from the submission URL and build from the
review host instead, which is why `PublicHost` stays:

```go
func UnsubscribeURL(settings shopmodels.ShopReviewSettings, raw string) string {
    return strings.TrimRight(settings.PublicHost, "/") + "/r/" + raw + "/unsubscribe"
}
```

Caller: `requests_auto.go:282`.

**`internal/reviews/emails.go:133`** — the star links:

```go
fmt.Sprintf(`<a href="%s?sku=%s&amp;rating=%d" …`, submissionURL, url.QueryEscape(item.SKU), n)
```

Appending `?sku=` to a URL that already has `?token=` gives `…?token=X?sku=Y` — two `?`, dead
link. The join becomes `&amp;`.

`handlers_requests.go:76,108` and `requests_auto.go:278` need **no change** — they pass the URL
through unmodified. `handlers_requests.go:250` validates `PublicHost` against
`publicHostPattern`; the new field wants the same check.

Why a query parameter and not `/reviews/r/<token>`: Magento derives a PHP class name from the
path. `ActionList::get()` lowercases the whole path and turns `_` into a namespace separator, so
a base64url token arrives case-folded and split. That path shape needs a custom router on every
request — judged not worth it.

## 2. Allow the storefronts to frame the form — **blocking**

`frame-ancestors` from config; send `X-Frame-Options` only when the list is empty. Config in §1
above. Code written, see below.

## 3. Report the form's height — **needed for usable UX**

A cross-origin frame cannot be measured from outside, so the storefront sizes the iframe from
what the form reports. Without it the frame keeps a fixed fallback height: a scrollbar inside a
scrollbar on mobile, a large blank gap under the short thank-you step.

```
storefront  ──▶  {type: 'review-height-request'}         on iframe load, on window resize
form        ──▶  {type: 'review-height', height: <px>}   on load, on resize, after each step
```

Four things that broke during implementation:

1. **Answer the request, do not only announce.** The storefront's listener loads asynchronously
   via RequireJS, so a message sent at the form's `load` arrives before anything is listening.
2. **Measure `document.body`, not `documentElement.scrollHeight`.** The parent sets the frame's
   height, so `documentElement` is at least that tall — measuring it reports the frame back to
   itself and the frame can then only grow, never shrink.
3. **Keep the height code out of the form logic's scope.** `review.js` does
   `if (!fileInput || !window.fetch) return;`, which fires on every page with no upload input —
   the done and gone pages, exactly the short ones that need shrinking.
4. **Post to one concrete origin, never `'*'`.** A `'*'` target hands these messages to whatever
   page managed to frame the form.

The form cannot discover its parent by itself: reading the parent's location is blocked
cross-origin, and IXPF's own `Referrer-Policy: no-referrer` strips the referrer. Magento declares
it in `?parent=<origin>`; the app validates it against the same allowlist before replying.
Declared by the embedder, verified by the app.

## 4. Keep the form cookie-free — **already true, do not regress**

In an iframe any cookie is a third-party cookie: Safari blocks it, Chrome restricts it. And
`review.carpetavenue.com` is same-site with the `en` store on `carpetavenue.com` but cross-site
with `.de`, `.se`, `.fr` and the rest — a cookie-backed form passes QA on one store and fails on
fifteen. The form sets none today; its CSRF is an HMAC over the token hash.

## 5. Image URLs leak the vendor hostname — **the point of the review host**

The whole reason for `review.carpetavenue.com` is that customers never see the vendor. The page
itself is clean, but the product thumbnail is a signed GCS URL rendered straight into the HTML:

```
https://storage.googleapis.com/iximg-stage.elastycloud.net/ixpfStageCaStageMagento/products/
  txa541068/images/thumbnail/….webp
  ?Expires=…&GoogleAccessId=ixpf-ng-local@peak-matter1.iam.gserviceaccount.com&Signature=…
```

Three things a customer can read from view-source or "copy image address": the bucket
`iximg-stage.elastycloud.net`, the tenant folder `ixpfStageCaStageMagento`, and the service
account `ixpf-ng-local@peak-matter1…`, which also names the GCP project. Review photos will
carry the same once they render.

Options, cheapest first:

1. **Proxy images through the review host** — `/r/img/<opaque>`, app fetches from GCS
   server-side. Total hiding, and CSP tightens to `img-src 'self'`. A handful of small images
   per page, so the bandwidth is negligible.
2. **Custom domain in front of the bucket** (`img.carpetavenue.com`) with public-read objects —
   no signing, so no `GoogleAccessId`. Loses the expiry.
3. Accept it, and drop the neutral-host requirement as partly cosmetic.

## 6. Token hygiene

Tokens travel in a URL, so they reach nginx access logs on both sides and the storefront's
GTM/GA page-path payload. Single-use, short TTL, revoked on submit bounds that.

## Already correct, no action

`cache-control: no-store`, `referrer-policy: no-referrer`, `form-action 'self'`,
`X-Content-Type-Options: nosniff`, per-IP/token/global rate limiting, 404 on unknown tokens.

---

# Files to change

Paths relative to the `ixpf-ng` repo root.

A working implementation of §2 and §3 already exists in the `ixpf-ng` checkout — `go build`,
`go vet` and `gofmt` clean, four new tests pass, **not deployed**. Default behaviour is
unchanged while `ReviewFrameAncestors` is unset, so it is inert until configured.

```
html/reviews/review.js
internal/config/sysconfig.go
internal/reviews/public/handlers.go
internal/reviews/public/routes.go
internal/reviews/public/security.go
internal/reviews/public/security_frame_test.go   (new)
templates/review-done.gohtml
templates/review-form.gohtml
```

## §1 — not written, do this

Nine edits. Nothing here has been touched; every one is for the IXPF developer.

**1. `internal/models/shops/types.go:52`** — add the field next to `PublicHost`, and correct the
struct comment above it, which currently claims the IXPF host never appears in a customer email:

```go
PublicHost     string `json:"public_host" bson:"public_host"`
StorefrontHost string `json:"storefront_host" bson:"storefront_host"`
```

**2. `internal/reviews/requests.go:64`** — `SubmissionURL()` builds from the new field and the
new path. Keep the scheme default and the trailing-slash trim:

```go
host := strings.TrimSpace(strings.TrimRight(settings.StorefrontHost, "/"))
if host == "" {
    return "", ErrStorefrontHostUnset       // new sentinel next to ErrPublicHostUnset
}
if !strings.HasPrefix(host, "http") {
    host = "https://" + host
}
return host + "/reviews?token=" + raw, nil
```

`raw` is base64url, so it needs no escaping — but `url.QueryEscape(raw)` is free insurance if
the token alphabet ever changes.

**3. `internal/reviews/constants.go:73`** — add `ErrStorefrontHostUnset` beside
`ErrPublicHostUnset`.

**4. `internal/reviews/suppression.go:25`** — stop deriving from the submission URL. Signature
changes, so this is the one that will not compile until every caller is updated — good.

```go
func UnsubscribeURL(settings shopmodels.ShopReviewSettings, raw string) string {
    return strings.TrimRight(settings.PublicHost, "/") + "/r/" + raw + "/unsubscribe"
}
```

**5. `internal/reviews/requests_auto.go:282`** — the only caller:
`UnsubscribeURL(settings, raw)`.

**6. `internal/reviews/emails.go:133`** — the star links join with `&amp;`, not `?`:

```go
stars += fmt.Sprintf(`<a href="%s&amp;sku=%s&amp;rating=%d" …`, submissionURL, …)
```

**7. `internal/reviews/requests_auto.go:244`** — `emailSendBlocked()` gates on `PublicHost == ""`
today. Add `StorefrontHost == ""` and extend the message. Without it the cron sends mail whose
review links are relative, and the pre-flight the admin button shares stays green.

**8. `internal/reviews/handlers_requests.go:234,250`** — trim and validate the new field exactly
like `PublicHost`: `cfg.StorefrontHost = strings.TrimSpace(cfg.StorefrontHost)`, and a
`publicHostPattern` case returning `"storefront_host must be a hostname, e.g. carpetavenue.de"`.

**9. Admin UI** — the settings dialog needs the input, or the field can never be set:

```
frontend/src/features/reviews/components/review-settings-dialog.tsx
frontend/src/features/reviews/lib/review-settings-form.ts
frontend/src/services/reviews/reviews.ts
```

### Migration

`StorefrontHost` is empty on every existing shop, so `emailSendBlocked` (7) stops all review
mail until it is filled in. That is the intended failure — the alternative is sending links that
go nowhere. Set it per shop before deploying, or accept a pause in sending.

Existing unsent requests are unaffected: the token is minted at send time, not before.

| File | Change | Why |
|---|---|---|
| `internal/config/sysconfig.go` | `ReviewFrameAncestors []string` + `REVIEW_FRAME_ANCESTORS` fallback | One list per deployment, not per shop: the middleware writing the header runs before the token is resolved, so a per-shop value costs a DB read on every page load — and framing grants nothing on its own, the token is still the only way in |
| `internal/reviews/public/security.go` | `frame-ancestors` from config; `X-Frame-Options` only when the list is empty; `isFrameAncestor()`, `allowedParent()`, `matchesWildcard()` | Values land verbatim in a header, so anything not a plain `scheme://host[:port]` is rejected rather than escaped — a malformed entry means that storefront cannot embed, never a weakened policy |
| `internal/reviews/public/handlers.go` | `render()` injects `Parent`; post-submit redirect keeps `?parent=` | `render()` is the one choke point every step goes through, so done and gone pages get it too. The redirect dropped it, leaving the frame at the form's height after submitting |
| `templates/review-form.gohtml` | `data-parent` on `<body>`; parent added to the form `action` | The form posted to `/r/{token}` with no query string, so `parent` was lost on POST |
| `templates/review-done.gohtml` | `data-parent`; loads `review.js` | The thank-you page loaded no JavaScript, so it could never report its height |
| `html/reviews/review.js` | Height reporting, `ResizeObserver`, `review-height-request` handler — **its own IIFE, first in the file** | The separate scope is load-bearing, see §3 point 3. `ResizeObserver` covers thumbnails and validation messages resizing the page after load |
| `internal/reviews/public/routes.go` | Logs the effective allowlist at startup | `'none'` means every iframe is blank — confusing from the browser, one line answers it |
| `internal/reviews/public/security_frame_test.go` | New | Silent-failure paths: a storefront not on the list shows a blank box, not an error |

```bash
CONFIG=$PWD/config.json GOOGLE_APPLICATION_CREDENTIALS=$PWD/google_bucket_config.json \
  go test ./internal/reviews/public/
```

Both variables are needed for reasons unrelated to these changes:

- `CONFIG` must be **absolute** — `loadConfig()` cannot find `config.json` from the package
  directory and calls `os.Exit(0)`, which `go test` reports as `ok` with **zero tests run**. That
  false green hid a real `matchesWildcard` bug during this work.
- `GOOGLE_APPLICATION_CREDENTIALS` is a link-time requirement, not a test one. `pkg/gcs` has a
  package-level `init()` that calls `storage.NewClient` and **panics** on failure
  (`pkg/gcs/gcslient.go:22-28`); Go runs `init()` for every package in the import graph whether
  the code is reached or not. Moving it into `GetClient()`, which already has the same
  lazy-connect logic, would let the suite run without a service-account key.

---

# Status

**Verified end-to-end against the real app** — local `ixpf-ng` behind the nginx edge stub, live
invite token, German storefront. Form renders inside full store chrome; frame auto-sizes to
content with no inner scrollbar; the short "link no longer active" page shrinks it from the
900px fallback to 294px; `?sku=` resolves the product, `?rating=` arrives pre-selected and
banked. Deny paths hold: a non-allowed origin gets a blank frame, and `evil.tld`,
`evil-carpetavenue.devs` and `a.b.carpetavenue.devs` are all rejected as `parent`. 26 Magento
URL-builder assertions and 4 Go tests pass.

**Not verified:** submitting through the frame (consumes an invite, writes to stage), Safari, a
phone viewport, photo upload. `ReviewFormEmbed-Tests.md` is the checklist.

**Stage still blocks the embed** — `frame-ancestors 'none'`, `X-Frame-Options: DENY`. §2 is not
deployed there.

**Separately:** `google_bucket_config.json`, a Google service-account private key, is committed
in the `ixpf-ng` repository.
