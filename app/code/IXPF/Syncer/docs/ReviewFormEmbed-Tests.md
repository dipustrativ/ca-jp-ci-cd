# Review form embed — test checklist

Companion to `ReviewFormEmbed-Handover.md`. Run A and B on every environment; C only when the
review host or the storefront list changes.

Set once per shell:

```bash
STORE=http://de-m246.carpetavenue.devs        # any storefront
REVIEW=https://localhost:8443                 # ixpf_reviews/form/base_url
TOKEN=<a live invite token>
```

A live token is needed for A4–A7. Tokens are single-use and short-lived: the raw value is never
stored (`internal/reviews/requests.go:50-62` keeps only a SHA-256), so it cannot be recovered
from the database — issue a fresh one. **A7 consumes it.**

---

## A. Magento landing page

| # | Command / action | Expected |
|---|---|---|
| A1 | `curl -so/dev/null -w '%{http_code}\n' "$STORE/reviews?token=$TOKEN"` | `200` |
| A2 | `for t in short "../../etc/passwd" "" "$(printf 'a%.0s' {1..200})"; do curl -so/dev/null -w '%{http_code}\n' --get --data-urlencode "token=$t" "$STORE/reviews"; done` | `404` ×4 |
| A3 | `curl -s "$STORE/reviews?token=$TOKEN&sku=VAN211640&rating=4&junk=1&rating=9" \| grep -o '<iframe[^>]*src="[^"]*"'` | src is `$REVIEW/r/<token>?sku=…&rating=4&parent=$STORE` — no `junk`, single `rating` |
| A4 | Browser: open `$STORE/reviews?token=$TOKEN&sku=<sku>&rating=4` | Header, nav, search and footer render; form fills the body; **no page title above it** |
| A5 | Console in that tab | No `Refused to display … frame-ancestors` |
| A6 | DevTools → select the iframe → check `style.height` | An explicit px value, **not** the 900px CSS fallback; no scrollbar inside the frame |
| A7 | Fill and submit the form | Thank-you renders **inside** the frame; parent URL unchanged; frame **shrinks** |

`curl -sI "$STORE/reviews?token=$TOKEN" \| grep -i cache-control` → must contain `no-store`
(a token-bearing URL must never enter FPC or Varnish).

Also confirm `<meta name="robots" content="NOINDEX,NOFOLLOW">` is present.

A6 is the one that regresses quietly: a frame that only grows, or sits at 900px forever, is a
working page with a bad layout — nothing errors. Both known causes are in the handover doc, §3.

---

## B. Review host headers

```bash
curl -sI "$REVIEW/r/$TOKEN" | grep -iE 'frame-ancestors|x-frame-options|set-cookie|cache-control'
```

| Expected | Why |
|---|---|
| `frame-ancestors` lists the storefront origins | Only the framed document can grant this |
| **no** `x-frame-options` | Any value blocks the embed; `ALLOW-FROM` is dead in all browsers |
| **no** `set-cookie` | A cookie here is third-party: Safari blocks it, and the review host is cross-site with 15 of the 16 stores |
| `cache-control: no-store` | |

`frame-ancestors 'none'` means the change is not deployed. The app logs the effective list at
startup: `review form frame-ancestors: …`.

### B2. Vendor hostname must not appear in what the customer receives

```bash
curl -s "$STORE/reviews?token=$TOKEN" | grep -oiE 'ixpf[a-z0-9._/-]*|elastycloud[a-z0-9._-]*'
curl -s "$REVIEW/r/$TOKEN"            | grep -oiE 'ixpf[a-z0-9._/-]*|elastycloud[a-z0-9._-]*'
```

Both must print nothing. The Magento page is clean; the form currently is not — the signed GCS
thumbnail URL carries the bucket, the tenant folder and the service account. Handover §5.

---

## C. Deny paths — run these, not just the happy path

A `frame-ancestors` typo renders fine in dev and blocks in production.

| # | Command / action | Expected |
|---|---|---|
| C1 | Browser: `$REVIEW/hostile.html` (origin deliberately absent from the list) | Frame stays **blank**; console shows `Refused to display …` |
| C2 | `curl -s "$REVIEW/r/$TOKEN?parent=https://evil.tld" \| grep -o 'data-parent="[^"]*"'` | `data-parent=""` |
| C3 | same with `parent=https://evil-carpetavenue.devs` | `data-parent=""` — suffix match, not origin match, would pass this |
| C4 | same with `parent=http://a.b.carpetavenue.devs` | `data-parent=""` — a CSP wildcard covers one label only |
| C5 | same with a listed origin | that origin echoed back |
| C6 | Edit `parent=` in the iframe src via devtools, reload | No height posted; frame stays at the CSS fallback |

C2–C5 are covered by `go test ./internal/reviews/public/` (`security_frame_test.go`). Run it
with an **absolute** `CONFIG` path — otherwise `loadConfig()` calls `os.Exit(0)` and `go test`
prints `ok` with zero tests run:

```bash
CONFIG=$PWD/config.json GOOGLE_APPLICATION_CREDENTIALS=$PWD/google_bucket_config.json \
  go test ./internal/reviews/public/
```

---

## D. Magento unit assertions

```bash
docker exec -i -w /var/www/html magento_backend \
  php app/code/IXPF/Syncer/Test/e2e/form-embed.php
```

26 assertions over the URL builder: token shapes, `sku` and `rating` filtering, unknown
parameters, non-https `base_url`, feature off, port preserved in the postMessage origin.
(`Test/Unit/ViewModel/Reviews/FormEmbedTest.php` is the same set for PHPUnit, which does not
currently boot in this project.)

---

## E. Before production

- [ ] All 16 storefront origins in `ReviewFrameAncestors` — pull from `core_config_data`,
      `web/secure/base_url`. A missing one is a blank frame for that country only.
- [ ] Bucket CORS allows `PUT` from `https://review.carpetavenue.com` with `Content-Type` and
      `X-Goog-Content-Length-Range` — the host rename invalidates the old entry and photo
      upload fails silently without it.
- [ ] A4–A7 repeated in **Safari** — the strictest third-party-frame behaviour.
- [ ] A4 and A6 on a phone viewport — the case auto-resize exists for.
- [ ] `bin/magento config:set ixpf_reviews/form/base_url https://review.carpetavenue.com`

## Local setup

`Test/e2e/review-form-stub/README.md` — nginx on `:8443` terminating TLS and proxying to the Go
app on `:8091`, mirroring Cloudflare → GCP LB → app so the edge is not skipped in testing.
