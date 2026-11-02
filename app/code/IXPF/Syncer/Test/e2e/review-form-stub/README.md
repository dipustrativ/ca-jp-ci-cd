# Local review-form edge

Production never points a storefront at the review application directly:

```
review-stage.carpetavenue.com   (Cloudflare DNS)
  -> GCP load balancer
    -> ixpf-ng-stage.elastycloud.net   (the ixpf-ng Go app)
```

This container is that edge locally, so the local setup has the same shape instead of skipping
it:

```
https://review-m246.carpetavenue.devs:8443   (this nginx, TLS)
  -> http://host.docker.internal:8091        (ixpf-ng)
```

It proxies to **8091**, not the Vite dev server on 3000: Vite binds `[::1]` only, so a container
cannot reach it, and `/r/*` is rendered by Go anyway — Vite only proxies it through.

`hostile.html` is the one thing served locally rather than proxied: a page on a non-allowed
origin, so the deny path can be observed on purpose.

## Compose service

`docker-compose.yaml` is gitignored (`magento/*.*` in the root `.gitignore`), so this service
does not ship with the repo — paste it into your own copy:

```yaml
  review-stub:
    image: nginx:alpine
    container_name: review_form_stub
    ports:
      - "8443:443"   # 443 belongs to magento_backend
    volumes:
      - ./ssl:/etc/nginx/ssl:ro
      # The directory is mounted twice rather than the .conf file individually: a single-file
      # bind mount is by inode, so editing nginx.conf detaches it and the reload fails.
      # conf.d only globs *.conf, so hostile.html sitting there is ignored.
      - ./app/code/IXPF/Syncer/Test/e2e/review-form-stub:/etc/nginx/conf.d:ro
      - ./app/code/IXPF/Syncer/Test/e2e/review-form-stub:/usr/share/nginx/html:ro
```

## Run

```bash
# 1. Cert. ssl/localhost.crt is EXPIRED, and Chrome's interstitial for it looks exactly like a
#    framing refusal. mkcert's CA is already trusted, so this needs no sudo. Gitignored.
mkcert -cert-file ssl/review-stub.crt -key-file ssl/review-stub.key \
       localhost review-m246.carpetavenue.devs 127.0.0.1

# 2. Edge.
docker compose up -d review-stub

# 3. The application, from the ixpf-ng checkout. It needs GCS credentials and reaches MongoDB
#    Atlas at startup, so run it in your own shell where that already works:
#      cd ../IXPF/Workspace/ixpf-ng && air        (or: go run ./cmd)
#    config.json must list the storefronts allowed to embed it:
#      "ReviewFrameAncestors": ["https://*.carpetavenue.devs", "http://*.carpetavenue.devs"]
#    It logs the effective list at startup: `review form frame-ancestors: …`

# 4. Magento.
bin/magento config:set ixpf_reviews/form/enabled 1
bin/magento config:set ixpf_reviews/form/base_url https://review-m246.carpetavenue.devs:8443
bin/magento cache:clean config layout full_page
```

Add `127.0.0.1 review-m246.carpetavenue.devs` to `/etc/hosts` once. `https://localhost:8443`
works without that and is covered by the same certificate, but it is deliberately **not** in
`ReviewFrameAncestors`, so use the `.devs` name for the happy path.

Then open:

```
http://de-m246.carpetavenue.devs/reviews?token=<token>&sku=VAN211640&rating=4
```

The store URL is **http** because the local stores are configured with http base URLs. An https
child inside an http parent is fine; the reverse would be blocked as mixed content. That is also
why the local `ReviewFrameAncestors` lists both schemes.

## Checks

| Check | Expected |
|---|---|
| `curl -skI https://review-m246.carpetavenue.devs:8443/r/<token>` | `frame-ancestors` lists the storefront; **no** `x-frame-options` |
| Load the test URL | Form renders inside store chrome, no console error |
| Frame height on first paint | Shrinks from the 900px CSS fallback to the form's real height — proves the request/response handshake beat the load race |
| Pick a rating, upload a photo | Frame grows and shrinks with the content (`ResizeObserver`) |
| Submit | Thank-you renders *inside* the frame, parent URL unchanged, frame shrinks to it |
| `https://localhost:8443/hostile.html` | Its frame stays blank — a non-allowed origin cannot embed |
| Tamper `parent=` in the iframe URL | App stops emitting `data-parent`, no height posted, frame sits at the fallback |

## What local cannot prove

`review-m246.carpetavenue.devs` and `de-m246.carpetavenue.devs` share the registrable domain
`carpetavenue.devs`, so the frame is **same-site** here. In production `review.carpetavenue.com`
is same-site with the `en` store on `carpetavenue.com` but *cross-site* with `.de`, `.se`, `.fr`
and the rest. That only matters for cookies, and the form deliberately sets none — its CSRF is
an HMAC over the token hash. Re-check in Safari if that ever changes.
