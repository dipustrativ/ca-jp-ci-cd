# Build and deploy

**Use when** shipping code to production, or rolling back.

Two images, independent version sequences:

| Image | Rebuild when | Roles it runs as |
|---|---|---|
| `registry.carpetavenue.net/carpient` | any PHP, theme, config or `composer.json` change | app, upgrade, static, cron |
| `registry.carpetavenue.net/carpient-nginx` | only when nginx config changes | nginx |

Tags are **immutable** — never reuse a `v<N>`. Always push `latest` too; that is
what a fresh server pulls.

Background on the image design: [../../docker/README.md](../../docker/README.md).

## 0. Preflight

```bash
ls -la auth.json docker/env.php.scd     # both required, gitignored, never in a layer
git status --short                      # the WORKING TREE ships, not HEAD
```

Uncommitted changes go into the image. Say so before building.

Next free tag:

```bash
CREDS=$(echo "registry.carpetavenue.net" | docker-credential-osxkeychain get)
U=$(echo "$CREDS" | python3 -c "import sys,json;print(json.load(sys.stdin)['Username'])")
P=$(echo "$CREDS" | python3 -c "import sys,json;print(json.load(sys.stdin)['Secret'])")
curl -s -u "$U:$P" https://registry.carpetavenue.net/v2/carpient/tags/list       --max-time 25
curl -s -u "$U:$P" https://registry.carpetavenue.net/v2/carpient-nginx/tags/list --max-time 25
```

Credentials are in the macOS keychain (`credsStore: osxkeychain`), not
`~/.docker/config.json`. Not logged in → `docker login registry.carpetavenue.net`.

## 1. Build and push

Application image — 10–20 min, ~4 GB RAM, run it in the background:

```bash
docker buildx build \
  --secret id=composer_auth,src=auth.json \
  --secret id=env_php_scd,src=docker/env.php.scd \
  -f docker/Dockerfile \
  -t registry.carpetavenue.net/carpient:latest \
  -t registry.carpetavenue.net/carpient:v<N> \
  --push .
```

nginx image — seconds, no secrets, **only if nginx config changed**:

```bash
docker buildx build -f docker/Dockerfile.nginx \
  -t registry.carpetavenue.net/carpient-nginx:latest \
  -t registry.carpetavenue.net/carpient-nginx:v<M> \
  --push .
```

Done = `pushing manifest` for both tags, exit 0.

`MAGENTO CLI ERROR (Production Mode) … No such file` during `di:compile` is
**expected** — there is no database at build time. `di:compile` continues.

## 2. Deploy

Authoritative path — **Dokploy UI → the compose service → Environment**:

```
IMAGE_TAG=v<N>
NGINX_TAG=v<M>
```

then **Redeploy**. Dokploy regenerates `.env` on disk from these values, so
editing the file over SSH is overwritten by the next UI action. Set it here or it
will roll back.

Same thing over SSH, when the UI is not an option — still set the UI values
afterwards:

```bash
ssh magento@<origin-ip>
sudo sh -c 'cd /etc/dokploy/compose/carpient-app-fj43zo/code \
  && sed -i "s/^IMAGE_TAG=.*/IMAGE_TAG=v<N>/; s/^NGINX_TAG=.*/NGINX_TAG=v<M>/" .env \
  && docker compose -p carpient-app-fj43zo pull app nginx cron \
  && docker compose -p carpient-app-fj43zo up -d'
```

`carpient-upgrade` runs once, holds the others until it exits, and puts the site
in maintenance mode while it runs.

## 3. Verify

```bash
docker logs carpient-upgrade 2>&1 | tail -5      # "Upgrade completed successfully." + "Disabled maintenance mode"
docker ps --format "{{.Names}}\t{{.Image}}\t{{.Status}}" | grep carpient
```

Then measure the origin, **from inside the container network** so the number is
render time and not your round-trip:

```bash
NET=carpient-app-fj43zo_default
for i in 1 2 3; do docker run --rm --network $NET curlimages/curl \
  -s -o /dev/null -w "%{http_code} %{time_total}s\n" \
  -H "Host: carpient.carpetavenue.net" -H "X-Forwarded-Proto: https" \
  http://carpient-nginx/; done
```

Expect first ~100 ms, then ~18 ms. `X-Forwarded-Proto: https` is required or
Magento 302s to the secure base URL. Reference numbers per page type:
[../benchmark/storefront-response-times.md](../benchmark/storefront-response-times.md).

Public check goes through Cloudflare, so it is slower by your RTT:

```bash
curl -sk -o /dev/null -w "%{http_code} %{time_total}s\n" https://carpient.carpetavenue.net/
```

## 4. Roll back

Set `IMAGE_TAG` to the previous `v<N>` in the Dokploy UI → Redeploy. That is the
whole rollback; images are immutable, so the old tag is byte-identical to what
was running.

## Gotchas

- **Never run `bin/magento setup:di:compile` in the live container.** It wipes
  `generated/` and 500s the site. Hot-patch by shipping a new image.
- **Every step `CACHED` and the new tag has the same image ID** = nothing in the
  build context changed. Check the edit is not in a `.dockerignore`d path
  (`vendor/`, `pub/static/`, `dev/`, most of `var/`) before blaming the cache.
- **A `499`/`504` mid-push** is a Traefik timeout on the laptop→registry hop, not
  a bad image. Retry.
- **New locale?** It must be in `STATIC_LOCALES` in `docker/Dockerfile` *before*
  the build — see [add-store-view.md](add-store-view.md).
- **No secrets in the image.** `entrypoint.sh` renders `app/etc/env.php` at
  container start, so a config change is an env var plus a restart, never a
  rebuild.
