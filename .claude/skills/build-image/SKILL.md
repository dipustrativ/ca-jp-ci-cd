---
name: build-image
description: Build and push the CarpetAvenue Magento production image (registry.carpetavenue.net/carpetavenue-jp) with the correct next v<N> tag. Use when asked to "build image", "build and push", "push v<N>", "release image", "build nginx image", or to do a local dry-run of the production stack. Skips re-reading docker/README.md.
---

# Build & push the carpetavenue-jp image

One image, five roles (app/upgrade/static/cron). Build context is the repo root
(`src/`). Full background: `docker/README.md` — do **not** read it for a routine
build; everything needed is here.

## 1. Preflight (run once per build, one command)

```bash
ls -la auth.json docker/env.php.scd && docker version --format '{{.Server.Version}}'
```

Both secret files must exist. They are gitignored **and** in `.dockerignore`;
BuildKit reads them from the host path, so they never land in a layer.
Missing → stop and tell the user; they cannot be regenerated from the repo.

## 2. Pick the tag

Tags are immutable — never reuse a `v<N>`. If user gave no number, list existing:

```bash
CREDS=$(echo "registry.carpetavenue.net" | docker-credential-osxkeychain get)
U=$(echo "$CREDS" | python3 -c "import sys,json;print(json.load(sys.stdin)['Username'])")
P=$(echo "$CREDS" | python3 -c "import sys,json;print(json.load(sys.stdin)['Secret'])")
curl -s -u "$U:$P" https://registry.carpetavenue.net/v2/carpetavenue-jp/tags/list --max-time 25
```

Next tag = highest `vN` + 1. (Creds live in the macOS keychain, not
`~/.docker/config.json` — `credsStore: osxkeychain`. Python's urllib fails cert
verification here; use `curl`.)

Not logged in → `docker login registry.carpetavenue.net`.

## 3. Build + push (run in background — takes ~10-20 min)

```bash
docker buildx build --platform linux/amd64 \
  --secret id=composer_auth,src=auth.json \
  --secret id=env_php_scd,src=docker/env.php.scd \
  -f docker/Dockerfile \
  -t registry.carpetavenue.net/carpetavenue-jp:latest \
  -t registry.carpetavenue.net/carpetavenue-jp:v<N> \
  --push .
```

Always push **both** `latest` (what servers pull) and the immutable `v<N>`
(rollback anchor). Use `run_in_background: true`; poll the log tail, do not
stream it into context.

⚠️ **`--platform linux/amd64` is mandatory on every `--push` build** — this
laptop is arm64, the servers are amd64, and the pushed manifest is
single-platform. Miss it and the server exits 255 with
`exec /entrypoint.sh: exec format error`; because `latest` is mutable, the bad
arch poisons the next deploy too. Cross-building is slow; prefer Server A.

After the push, verify what actually landed — per tag:

```bash
docker buildx imagetools inspect registry.carpetavenue.net/carpetavenue-jp:v<N> \
  --format '{{.Image.OS}}/{{.Image.Architecture}}'   # must print linux/amd64
```

Force a rebuild (only when asked — an all-`CACHED` build is normally correct,
see Gotchas):

```bash
--no-cache-filter builder   # re-runs composer install, di:compile, static-content:deploy
--no-cache                  # everything, incl. apt/php-ext layers (slow)
```

✅ Done = final lines show `pushing manifest` for both tags, exit 0.
Image ~1.2 GB; the vendor+static layer is ~800 MB.

## 4. nginx image — only when nginx config changed

Separate image, no secrets, fast. Skip it for normal app builds.

```bash
docker buildx build --platform linux/amd64 -f docker/Dockerfile.nginx \
  -t registry.carpetavenue.net/carpetavenue-jp-nginx:latest \
  -t registry.carpetavenue.net/carpetavenue-jp-nginx:v<M> --push .
```

Same `--platform` rule and same post-push arch check as §3 — nginx tags carry
their own `v<M>` sequence.

## 5. Local dry-run (only if asked to test before deploying)

```bash
# --load, not --push: local tag stays on this machine, no registry, no login.
docker buildx build --secret id=composer_auth,src=auth.json \
  --secret id=env_php_scd,src=docker/env.php.scd \
  -f docker/Dockerfile -t registry.carpetavenue.net/carpetavenue-jp:local --load .

# ⚠️ stop the dev stack first — it holds host :80 and :3306
docker compose -f ../compose.yaml down

IMAGE_TAG=local docker compose -f docker/docker-compose.local.yml \
  --env-file docker/.env.local up -d
docker compose -f docker/docker-compose.local.yml logs -f upgrade   # want: "Upgrade completed successfully." exit 0
# teardown (-v drops volumes)
docker compose -f docker/docker-compose.local.yml down -v
```

Site: `http://carpetavenue-jp.devs` / `http://localhost` (plain HTTP, no TLS locally).

## 6. Deploy after push

Not part of the build. Dokploy (Path B) pulls `latest` on redeploy; CI does this
via webhook on merge to `main`. Rollback = set `IMAGE_TAG=v<previous>` in the
Dokploy environment → Redeploy. Only touch the server if the user asks.

## Gotchas

- Build uses the **working tree**, not HEAD — uncommitted changes ship in the
  image. Run `git status --short` and tell the user what's included.
- Site up but every generated `*.min.js`/`*.min.css` 404s (a few vendor-shipped
  `.min` files still 200, `static.php` 404s) = minification mismatch between the
  SCD DB and the production DB. Both flags are locked in `app/etc/config.php`;
  the SCD step asserts `require.min.js` exists. Detail: `docker/README.md` §8.
- Every step `CACHED` = nothing in the context changed; BuildKit keys on content,
  not mtime, so `COPY . .` hits cache and di:compile/SCD are skipped. The new
  `v<N>` is then the same image ID as the last one (`docker images | grep
  carpetavenue-jp`). Check the edit isn't in a `.dockerignore`d path (`vendor/`,
  `pub/static/`, `dev/`, most of `var/`) before blaming the cache.
- `docker buildx build` on the laptop pushes over Traefik; a `499`/`504` mid-push
  is a Traefik timeout, not a bad image (fix in `docker/README.md` §10). CI on
  Server A pushes over localhost and never hits this.
- Build peaks around ~4 GB RAM.
- No secrets in the image — `entrypoint.sh` renders `app/etc/env.php` from
  `env.php.template` at container start. Config change = env var + restart,
  never a rebuild.
