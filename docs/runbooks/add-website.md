# Add a website on its own domain

> ⚠️ JP is single-website (`ca_jp`). The `ca_se` example below is `carpient`'s.

**Use when** a country needs its own domain, cart, or base currency. If it only
needs its own language on a `/xx/` path, you want
[add-store-view.md](add-store-view.md) instead — that is a store view, needs no
webserver work, and keeps the shared cart.

A website is the only scope Magento cannot resolve from the URL path. The
webserver must tell it, via `MAGE_RUN_TYPE` / `MAGE_RUN_CODE`.

Worked example throughout: `se.playground.carpetavenue.jp` → website `ca_se`.

**Do not reorder.** Step 6 makes the store unreachable until 4 and 5 are done.

## 1. Create the website in Admin

Stores → All Stores. Create in this order — each needs the one above:

1. **Website** — code `ca_se`. This code is what nginx will send.
2. **Store group** — assign the root category.
3. **Store view** — code `se`, set Enabled.

Magento core has no `store:create` CLI; this step is manual.

```bash
bin/magento config:set --scope=stores --scope-code=se general/locale/code sv_SE
```

## 2. Map the hostname in nginx

`docker/nginx/nginx.conf` — one line, any hostname, no naming convention:

```nginx
map $http_host $MAGE_RUN_CODE {
    default                       "";
    se.playground.carpetavenue.jp  "ca_se";
}
```

`$MAGE_RUN_TYPE` is derived from it below, so this is the only edit. Unlisted
hostnames get `""` and resolve as the base website.

## 3. Ship the nginx image

```bash
docker buildx build -f docker/Dockerfile.nginx \
  -t registry.carpetavenue.net/carpetavenue-jp-nginx:latest \
  -t registry.carpetavenue.net/carpetavenue-jp-nginx:v<M> --push .
```

Then set `NGINX_TAG=v<M>` in the Dokploy UI and redeploy —
[build-and-deploy.md](build-and-deploy.md) §2.

Verify the map before going further:

```bash
docker exec carpetavenue-jp-nginx sh -c 'cat /etc/nginx/fastcgi_extra/*.conf; nginx -t'
```

## 4. Route the domain — Dokploy → Domains

Add the hostname → service **nginx**, port **80**, HTTPS on.

Traefik routes by an explicit ``Host(`…`)`` label. Without this the origin
answers **404** for the new hostname no matter what Magento is configured to do.

## 5. Let it through Cloudflare

A new hostname inherits the bot challenge and returns **403 "Just a moment…"**.
Add the same WAF skip the apex host has.

Let's Encrypt HTTP-01 must also reach the origin. If issuance fails behind the
orange cloud, grey-cloud the record until the certificate is issued, or use
DNS-01.

## 6. Point Magento at the domain

```bash
bin/magento config:set --scope=websites --scope-code=ca_se \
  web/unsecure/base_url https://se.playground.carpetavenue.jp/
bin/magento config:set --scope=websites --scope-code=ca_se \
  web/secure/base_url   https://se.playground.carpetavenue.jp/
bin/magento cache:flush config full_page
```

This also **moves that website's store views off the main domain**:
`https://playground.carpetavenue.jp/se/…` starts redirecting to
`https://se.playground.carpetavenue.jp/se/…`. Expected, but it is a live URL
change. Other websites are untouched.

## 7. Verify

Before step 6 the new host should **redirect to the main domain** — that alone
proves Traefik, TLS and `MAGE_RUN_CODE` all work. Bypass Cloudflare while
testing:

```bash
curl -sI --resolve se.playground.carpetavenue.jp:443:<origin-ip> \
  https://se.playground.carpetavenue.jp/ | head -1
```

After step 6, all four must pass:

```bash
NET=carpetavenue-jp-app-fj43zo_default
run() { docker run --rm --network $NET curlimages/curl -s "$@" --max-time 40; }
H='-H Host:se.playground.carpetavenue.jp -H X-Forwarded-Proto:https'

# a. right language
run $H http://carpetavenue-jp-nginx/ | grep -o '<html lang="[a-z-]*"'          # sv

# b. the store cookie must NOT change it
run $H -H 'Cookie: store=de' http://carpetavenue-jp-nginx/ | grep -o '<html lang="[a-z-]*"'   # sv

# c. cacheable: ~100 ms then ~18 ms. Flat ~100 ms = store is in the vary string
for i in 1 2 3; do run $H -o /dev/null -w "%{time_total}s\n" http://carpetavenue-jp-nginx/; done

# d. the main domain now redirects that prefix
run -H Host:playground.carpetavenue.jp -H X-Forwarded-Proto:https \
    -o /dev/null -w "%{http_code} %{redirect_url}\n" http://carpetavenue-jp-nginx/se/
```

(c) failing is the trap documented in
[../Issues/ISSUE-004-fpc-store-vary-collapse-use-store-in-url.md](../Issues/ISSUE-004-fpc-store-vary-collapse-use-store-in-url.md).

## 8. If the store view uses a new locale

Static content is baked at build time. Add the locale to `STATIC_LOCALES` in
`docker/Dockerfile`, rebuild and redeploy the **application** image. A missing
locale is a broken storefront for that store view — there is no fallback to
`en_US`.

## Also worth doing

- Regenerate sitemaps; check hardcoded links and analytics for the new host.
- Currency, tax and shipping are website-scope — set them if this website differs.
