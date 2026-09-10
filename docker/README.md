# CarpetAvenue JP — Deploy Guide

Magento 2.4.9 in Docker. **One image, many roles.** How it works, the local
dry-run, every deploy path, rollback and image economics. Read top to bottom the
first time.

Certified stack: PHP 8.5 · MySQL 8.4 (external) · OpenSearch 3 · Valkey 9 ·
RabbitMQ 4.2 · nginx 1.29.

| Also in this folder | |
|---|---|
| **[INFRA-CI.md](INFRA-CI.md)** | Shared MySQL/OpenSearch/RabbitMQ · CI/CD setup · server ops · open items |
| **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)** | Every symptom → cause → fix |

> **Path B uses shared infrastructure** — MySQL, OpenSearch and RabbitMQ live in
> `Workspace/DOCKER-GLOBAL/`, shared with carpient. **Deploy them first**;
> values and one-time steps: [INFRA-CI §1](INFRA-CI.md#part-1--shared-infrastructure).
> Paths A/C and local dev still run their own `queue`/`search` containers.

---

## 0. How it works (read once)

**Roles are separate on purpose.** Build + registry + Dokploy control plane
share one server; the application runs on another:

| Machine | Job |
|---|---|
| **Server A** — build/registry | Builds the image, pushes to the **local** registry, hosts the CI runner + Dokploy panel |
| **Server B** — application | Runs the stack (app/nginx/cron/valkey; on Path B, MySQL/OpenSearch/RabbitMQ are separate shared stacks on the same host). Pulls from A. **Never builds** |
| **Database** | MySQL 8.4, its own endpoint. Path B: the shared `mysql` stack, reached as `mysql:3306` |

Build and registry on the same host means the CI push is a **localhost push** —
fast, and it never touches Traefik (so the large-layer 504 in
[TROUBLESHOOTING §4](TROUBLESHOOTING.md#4-registry-push-499--504) cannot bite CI).

> Simpler variant: if Dokploy runs the app on its **own** host, A and B are the
> same machine. Everything below still applies — just one server.

**One image, five roles.** `carpetavenue-jp` is built once and run five ways;
the command differs, the image does not:

| Service | Command | Purpose |
|---|---|---|
| `app` | `php-fpm` | serves requests (nginx talks to `carpetavenue-jp-fpm:9000`) |
| `upgrade` | `setup:upgrade` then exits | run-once per deploy: schema + module updates |
| `static` | copies `pub/static` → volume | publishes compiled assets to the shared volume |
| `cron` | `cron -f` | Magento cron; also drives message-queue consumers |
| `nginx` | (separate `carpetavenue-jp-nginx` image) | web front; serves static/media, proxies to `app` |

**No secrets in the image.** `entrypoint.sh` renders `app/etc/env.php` from
`env.php.template` on every container start, reading all config through
`getenv()`. Change a value = change the env var + restart, never a rebuild. The
two build-time secrets (`auth.json`, `env.php.scd`) are passed as BuildKit
secrets — which read from the *host path*, not the build context — and never
land in a layer. Both are **also** in `.dockerignore`; that is what stops
`COPY . .` copying them anyway. Do not remove those two lines.

**What ships vs. what doesn't.** Code, `vendor` (prod only), compiled DI and
`pub/static` are baked in. Secrets, DB and all hostnames are injected at
runtime. Media and `var` live on named volumes.

**Already production-hardened, no action needed:** `MAGE_MODE=production`,
OpCache tuned with `validate_timestamps=0`, JIT off, Valkey sessions/cache
(db0/1/2, igbinary), FPM worker recycling + slowlog, healthchecks on
cache/sessions/nginx (plus queue/search on Paths A/C), `upgrade` gating
`app`/`cron`/`static`, TLS terminated at Traefik with nginx HTTP behind it, and
an immutable `v<N>` rollback anchor per build. ⚠️ Path B **cannot** gate on the
shared stacks — separate Compose projects — so verify those are up first.
First-deploy and operator to-dos: [INFRA-CI Parts 3–4](INFRA-CI.md#part-3--server-ops).

---

## 1. Build the images (Server A, or your laptop)

Two secret files must be present in the repo root (both gitignored):

- `auth.json` — Composer Marketplace keys
- `docker/env.php.scd` — throwaway env.php used only during static-content deploy

### 1.1 First: which build applies to you?

The image architecture is decided by the machine whose **Docker daemon** runs
the build, not by the Dockerfile. Ask it:

```bash
docker version -f 'build host: {{.Server.Os}}/{{.Server.Arch}}'
```

| It prints | You are on | Use |
|---|---|---|
| `linux/amd64` | Server A, or any Intel/AMD Linux / WSL2 / Intel Mac | **[A — native](#12-a--native-amd64-build-server-a-the-normal-path)**, the normal path |
| `linux/arm64` | Apple Silicon Mac, or an ARM server/VM | **[B — cross-build](#13-b--building-from-apple-silicon-arm64-host--amd64-image)** — or better, push the code and build on Server A |

**The deploy targets are always amd64** (`ssh <server-b> "docker info -f '{{.Architecture}}'"`
→ `x86_64`). An arm64 image there dies at container start, in all five roles,
before a line of Magento runs —
[TROUBLESHOOTING §1](TROUBLESHOOTING.md#1-exec-format-error--wrong-image-architecture).

### 1.2 A — native amd64 build (Server A; the normal path)

No `--platform` flag: the daemon is already amd64, so it builds amd64.

```bash
docker login registry.carpetavenue.net

# 1) The Magento image (app/upgrade/static/cron all use this one)
docker buildx build \
  --secret id=composer_auth,src=auth.json \
  --secret id=env_php_scd,src=docker/env.php.scd \
  -f docker/Dockerfile \
  -t registry.carpetavenue.net/carpetavenue-jp:latest \
  -t registry.carpetavenue.net/carpetavenue-jp:v1 \
  --push .

# 2) The nginx image (config baked in; rebuild only when nginx config changes)
docker buildx build \
  -f docker/Dockerfile.nginx \
  -t registry.carpetavenue.net/carpetavenue-jp-nginx:latest \
  -t registry.carpetavenue.net/carpetavenue-jp-nginx:v5 \
  --push .
```

Then **[verify the arch (§1.5)](#15-verify-the-arch-before-you-deploy)**.

### 1.3 B — building from Apple Silicon (arm64 host → amd64 image)

Identical commands **plus `--platform linux/amd64` on both** — omit it on either
and that image is arm64 and unrunnable.

```bash
docker login registry.carpetavenue.net

docker buildx build --platform linux/amd64 \
  --secret id=composer_auth,src=auth.json \
  --secret id=env_php_scd,src=docker/env.php.scd \
  -f docker/Dockerfile \
  -t registry.carpetavenue.net/carpetavenue-jp:latest \
  -t registry.carpetavenue.net/carpetavenue-jp:v1 \
  --push .

docker buildx build --platform linux/amd64 \
  -f docker/Dockerfile.nginx \
  -t registry.carpetavenue.net/carpetavenue-jp-nginx:latest \
  -t registry.carpetavenue.net/carpetavenue-jp-nginx:v5 \
  --push .
```

⚠️ **This is emulated (QEMU), and slow.** `composer install`, `setup:di:compile`
and `setup:static-content:deploy` are all CPU-bound PHP — expect roughly 3–5×
the native time, plus ~1.2 GB pushed over your home link. It is the fallback,
not the routine. Prefer [CI](INFRA-CI.md#part-2--cicd) or a manual build on
Server A ([INFRA-CI Appendix C](INFRA-CI.md#appendix-c--emergency-manual-build)).

Docker Desktop ships the amd64 binfmt handler already. On a bare arm64 Linux
host, install it once and confirm:

```bash
docker run --privileged --rm tonistiigi/binfmt --install amd64   # bare arm64 Linux only
docker buildx ls        # the active builder's PLATFORMS must include linux/amd64
```

### 1.4 C — local dry-run only (never pushed)

For §2's dry-run, build **native** and `--load` it — no `--platform`, no
registry. On Apple Silicon that yields an arm64 image, which is correct: it runs
on your laptop and is never pushed. Commands in [§2](#2-local-dry-run-prove-it-before-any-server).

**Multi-arch manifests** (`--platform linux/amd64,linux/arm64`) are deliberately
not used: every deploy target is amd64, so the second arch doubles build time
and registry size for nothing.

### 1.5 Verify the arch before you deploy

Run this every time you push from a laptop:

```bash
docker buildx imagetools inspect registry.carpetavenue.net/carpetavenue-jp:latest       | grep -i platform
docker buildx imagetools inspect registry.carpetavenue.net/carpetavenue-jp-nginx:latest | grep -i platform
```

✅ Both must print `Platform: linux/amd64`. `linux/arm64` will fail on Server B
— rebuild with §1.3 before touching Dokploy.

### 1.6 Tags, cache and size (all paths)

Build context is `.` (the repo root **is** `src/`); the Dockerfile is
`docker/Dockerfile`.

**Tag strategy:** always push `latest` (what servers pull) **and** an immutable
`v<N>` (never re-used → your rollback target). Same image, two tags, one push —
for **both** images, or nginx has no rollback target. Manual builds keep two
independent `v<N>` sequences (nginx is rebuilt far less often, so
`carpetavenue-jp:v13` may pair with `carpetavenue-jp-nginx:v5`); CI instead
builds both every run and gives them the same `N`
([INFRA-CI](INFRA-CI.md#part-2--cicd)).

**Which tag the servers pull** is per-environment: `IMAGE_TAG` →
`carpetavenue-jp`, `NGINX_TAG` → `carpetavenue-jp-nginx`. Both default to
`latest` when unset. Bump the matching variable in Dokploy → Environment after
pushing a new `v<N>`, or the server keeps running the old one.

**Everything came back `CACHED`?** Usually correct, not a broken build. BuildKit
keys layers on *content*, not timestamps, so if nothing in the context changed,
`COPY . .` hits cache and `composer install`, `setup:di:compile` and
`setup:static-content:deploy` are all reused — the new `v<N>` then resolves to
the *same image ID* (`docker images | grep carpetavenue-jp`). Before blaming the
cache, confirm your edit is even in the context: `.dockerignore` excludes
`vendor/`, `pub/static/`, `dev/` and most of `var/`. To force it:

```bash
--no-cache-filter builder   # re-runs composer install, di:compile, SCD;
                            # keeps the base image + apt/php-ext layers
--no-cache                  # nukes everything, apt/php-ext included (slow)
```

✅ Check: the build ends with both tags pushed, no error, and
[§1.5](#15-verify-the-arch-before-you-deploy) reports `linux/amd64` for both.

---

## 2. Local dry-run (prove it before any server)

Runs the exact production flow on your laptop. `docker/.env.local` points at the
**local dev DB** (`ca_jp-db-1` via `host.docker.internal:3306`). Site answers on
`http://carpetavenue-jp.devs` / `http://localhost`, plain HTTP.

⚠️ Free the two clashing host ports — but **leave `ca_jp-db-1` running**, it is
the DB this connects to (`docker compose -f ../compose.yaml down` would take it
with everything else):

```bash
docker stop ca_jp-app-1 ca_jp-rabbitmq-1   # :80 and :15672
```

`ca_jp-db-1` (:3306) only clashes if you add `--profile localdb`, which replaces
the dev DB with a throwaway one — then stop it too and set `DB_HOST=db`.

```bash
# 2.1 build a local tag. --load keeps it here: no registry, no login.
#     No --platform on purpose — native is correct for a laptop-only image (§1.4).
docker buildx build \
  --secret id=composer_auth,src=auth.json \
  --secret id=env_php_scd,src=docker/env.php.scd \
  -f docker/Dockerfile -t registry.carpetavenue.net/carpetavenue-jp:local --load .
# (local uses a plain nginx with mounted config — no nginx image build needed)

# 2.2 boot the full stack
IMAGE_TAG=local docker compose \
  -f docker/docker-compose.local.yml --env-file docker/.env.local up -d

# 2.3 watch the run-once upgrade finish
docker compose -f docker/docker-compose.local.yml logs -f upgrade
#   wait for:  "Upgrade completed successfully."   then it exits 0

# 2.4 load the site
open http://carpetavenue-jp.devs            # or http://localhost
#   RabbitMQ UI: http://localhost:15672

# 2.5 teardown (-v also drops the volumes = clean slate)
docker compose -f docker/docker-compose.local.yml down -v
```

✅ Check: `upgrade` exits 0 with "Upgrade completed successfully."; the
storefront renders **with CSS**; `docker compose ps` shows `app`, `nginx`,
`cron` up and `cache`/`queue`/`search` healthy.

Need to see what env the container actually got? Set `DEBUG_ENV=true` and re-up
— the entrypoint prints every resolved variable.

---

## 3. Deploy — pick ONE path

| Path | Use when | File |
|---|---|---|
| **A. Plain server** | a normal Docker VPS, you own the TLS proxy | `docker-compose.yml` |
| **B. Dokploy-pull** ⭐ | Dokploy host, image built by CI/build server | `docker-compose.dokploy.yml` |
| **C. Dokploy-build** | Dokploy host, no build server/registry | `docker-compose.build.yml` |

**B is recommended.** All paths first need, on the hosting server:

```bash
sudo sysctl -w vm.max_map_count=262144
echo 'vm.max_map_count=262144' | sudo tee /etc/sysctl.d/99-opensearch.conf   # persist
```

### Path A — plain Docker server (pull image)

```bash
cp docker/.env.production.example docker/.env    # fill REAL values (§6)
docker login registry.carpetavenue.net
docker compose -f docker/docker-compose.yml --env-file docker/.env pull
docker compose -f docker/docker-compose.yml --env-file docker/.env up -d
docker compose -f docker/docker-compose.yml logs -f upgrade   # success + exit 0
```

nginx publishes **host port 80**. Put TLS in front (Caddy, a host
nginx/Traefik, or Cloudflare) pointing at `http://<host>:80`.

✅ Check: `upgrade` exits 0; `curl -H 'Host: <domain>' http://<host>/` returns
Magento HTML.

### Path B — Dokploy, pull a pre-built image ⭐

**First:** deploy the three shared stacks and create this app's database +
RabbitMQ vhost/user — [INFRA-CI §1](INFRA-CI.md#part-1--shared-infrastructure).
Nothing here can gate on them, so a missing one deploys green and fails at
runtime.

Then in the Dokploy UI:

1. **Settings → Registries** → add `registry.carpetavenue.net` (user/pass).
2. **Create → Compose** → paste `docker/docker-compose.dokploy.yml`.
3. **Environment** → paste `docker/.env.production.example`, fill real values
   (`DB_HOST=mysql:3306`, `DB_PASSWORD` = the mysql stack's root password).
4. **Domains** → `playground.carpetavenue.jp` → service **`nginx`**, port **80**,
   HTTPS **on** (Dokploy/Traefik injects the cert + labels; the container stays
   HTTP).
5. **Deploy.** Then app → **Deployments** tab → copy the **Webhook URL** — that
   is `DOKPLOY_WEBHOOK` for CI.

✅ Check: the `upgrade` service logs show success + exit; the domain serves over
HTTPS with working static assets.

### Path C — Dokploy builds from git (no registry)

Same UI steps as B, but paste `docker/docker-compose.build.yml` and connect the
Git source; Dokploy clones and runs `docker compose build`.

⚠️ The two build secrets are gitignored, so they are **not** in the clone.
Before deploying, place `auth.json` at the clone root and `docker/env.php.scd`
into the build context on the Dokploy host — **Advanced → Files**, or `scp`.
(This friction is exactly why Path B is recommended.)

### Co-hosting with `carpient` on one server

Nothing collides. Containers are `carpetavenue-jp-*` vs `carpient-*`; networks
and volumes are Dokploy-project-prefixed; `docker-compose.dokploy.yml` publishes
no host port (Traefik routes to `nginx`); the registry is shared with a separate
repo; `vm.max_map_count` is host-wide and already set.

On Path B both stacks **share** one MySQL, one OpenSearch and one RabbitMQ,
namespaced per app. That is what makes two installs on one host affordable — the
~4.3 GB of search + broker is paid once.

**RAM is the only real constraint:** ~20 GB for both apps + infra ⇒ **24 GB
host**. Full budget: `DOCKER-GLOBAL/README.md` §2, the one place that counts
every stack. Playground knobs: `VALKEY_MAXMEMORY=512mb`,
`VALKEY_SESSION_MAXMEMORY=128mb`, `pm.max_children=8` (baked into
`docker/php/www.conf`); `OPENSEARCH_HEAP` belongs to the opensearch stack, not
here. Before deploying: `free -g` ≥ 24, `df -h` ~10 GB free. After:
`valkey-cli info stats | grep evicted_keys` stays 0 on **both** stacks.

Two more same-host details:

- **Path A only** publishes host `:80` — set `HTTP_PORT` on the second stack.
  Path B publishes nothing; Traefik routes by domain.
- **`docker/host/magento` installs as `magento-jp` / `magerun-jp`**, not plain
  `magento` — carpient's copy of the same script owns that name.

Benign but worth knowing: both stacks join the external `dokploy-network`, so
the service aliases `app`, `cron`, `upgrade`, `nginx` exist twice on it. Nothing
resolves those names — on that network Magento only resolves `mysql`,
`opensearch` and `rabbitmq`; `cache`/`sessions` stay on the private
`magento_net`. Don't add a service that resolves a bare `app` there.

---

## 4. Redeploy (new code)

⚠️ **CI is currently OFF** for this repo — no runner is registered, so the
`push:` trigger is commented out and **every** deploy is the manual path below
([INFRA-CI Part 2](INFRA-CI.md#part-2--cicd) turns it on).

- **Paths B/C once CI is on:** merge to `main`. Done.
- **Path A, or anything today:** rebuild + push (§1) with a new `v<N>`, then
  either Dokploy → Environment → bump `IMAGE_TAG` → Redeploy, or on the host:

```bash
docker compose -f docker/docker-compose.yml --env-file docker/.env pull
docker compose -f docker/docker-compose.yml --env-file docker/.env up -d
```

`upgrade` re-runs `setup:upgrade` automatically each time.

---

## 5. Rollback (fast)

Every build left an immutable `v<N>` in the registry, for **both** images.
Rolling back is a config change, not a rebuild:

- **Dokploy:** Environment → `IMAGE_TAG=v<previous>` **and**
  `NGINX_TAG=v<previous>` → Redeploy.
- **Plain server:** the same variables in `docker/.env`, then `pull` + `up -d`.

CI builds give both images the same `N`, so use one number for both. Manual
builds may not — check the registry:

```bash
curl -su '<user>:<pass>' https://registry.carpetavenue.net/v2/carpetavenue-jp/tags/list
```

✅ Check: `docker inspect <container> --format '{{.Config.Image}}'` shows the old
tag.

⚠️ **Set both back to `latest` once the rollback is no longer needed** — a pinned tag
means the next build (manual redeploy or, once CI is on, the webhook) succeeds
and deploys nothing.

---

## 6. Environment variables (runtime)

Set in Dokploy → Environment, or `docker/.env` for a plain server. Template:
`docker/.env.production.example`.

| Var | Notes |
|---|---|
| `REGISTRY_HOST` | default `registry.carpetavenue.net` |
| `IMAGE_TAG` / `NGINX_TAG` | `latest`, or `v<N>` to pin/rollback |
| `DOMAIN` | storefront domain |
| `DB_HOST` `DB_NAME` `DB_USER` `DB_PASSWORD` | MySQL 8.4. Path B: `mysql:3306`, db `carpetavenue_jp`, user `root` |
| `VALKEY_HOST` `VALKEY_PORT` `VALKEY_PASSWORD` | cache db0 / page_cache db1 / sessions db2 |
| `VALKEY_MAXMEMORY` `VALKEY_SESSION_MAXMEMORY` | defaults `2gb` / `256mb`; playground `512mb` / `128mb` |
| `RABBITMQ_HOST` `_PORT` `_USER` `_PASSWORD` `_VHOST` | Path B: `rabbitmq`, user `ca_jp`, vhost `/ca_jp` |
| `OPENSEARCH_HOST` `OPENSEARCH_PORT` | Path B: `opensearch:9200` |
| `OPENSEARCH_INDEX_PREFIX` | **must be unique per install** — `ca_jp` here, `carpient` there. Same prefix on one cluster = each reindex wipes the other store |
| `OPENSEARCH_HEAP` | Paths A/C only (in-stack `search`). Path B sets it in the shared opensearch stack |
| `COMPOSE_PROFILES` | leave **empty**. `media-backup` starts a full-tar media upload |
| `HTTP_PORT` | Path A only — host port for nginx, default `80` |
| `MAGE_CRYPT_KEY` | **must equal** the `crypt/key` of the DB it decrypts |
| `MAGE_GRAPHQL_ID_SALT` | GraphQL id salt |
| `DEBUG_ENV` | `true` prints resolved env at startup (debug only) |

⚠️ `MAGE_CRYPT_KEY` mismatch = garbled config / failed decryption. It is tied to
the **database**, not the environment — copy it from the DB you point at.

---

## 7. Image size — where the bytes go

> Measured on the **`carpient`** image `:v21` (arm64, 12 locales), not JP's. JP
> ships 2 locales, so absolute numbers are lower; ratios and every "safe to
> strip" conclusion hold. Re-run [Measuring](#measuring--re-checking) after the
> first JP build.

| | Before | After |
|---|---:|---:|
| `/var/www/html` tree | 1.9 GB | **684 MB** |
| ↳ `pub/static` | 1.2 GB | **78 MB** |
| ↳ `vendor` | 497 MB | 480 MB |
| ↳ `generated` | 89 MB | 80 MB |
| ↳ `app` | 45 MB | 31 MB |
| ↳ `var` | 21 MB | 16 KB |
| Image (`docker images`) | 2.14 GB | **≈ 1.1 GB** |
| `magento_static` volume | ~1.2 GB | ~78 MB |

The remaining floor is ~513 MB base image (`php:8.5-fpm-bookworm` — Debian 97 MB
+ the `PHPIZE_DEPS` toolchain 299 MB + PHP 102 MB, all in one layer that is not
ours to shrink) + ~480 MB `vendor` + ~80 MB `generated`.

### The dominant cause: SCD duplicates every locale

`setup:static-content:deploy` writes a complete copy of every asset for every
locale — jQuery, Knockout, images, fonts, payment JS included. At 12 locales,
`pub/static` held **510 MB of files but ~78 MB of distinct content**: ~85 %
byte-identical duplication.

Hardlinking those duplicates reclaims **415 MiB** — more than every deletion
combined, and nothing is removed; every path still resolves. Four things had to
be true, all verified by building test images rather than by reasoning:

1. **BuildKit's `COPY --from` preserves hardlinks** — a 12×20 MB fixture went
   337 MB → 117 MB with link count 12 intact in the final stage.
2. **`COPY --chown=www-data:www-data` preserves them too** — count stayed 12,
   ownership applied to the shared inode.
3. **The `static` service's `cp -a` preserves them** (GNU `cp --preserve=all`
   includes `links`), so the `magento_static` volume shrinks by the same 415 MB.
4. **`--ignore-time` is required** — SCD stamps a different mtime per locale, so
   the default run finds only 189 MiB. mtime is not used for cache-busting
   (`pub/static/deployed_version.txt` is).

`--ignore-mode` / `--ignore-owner` would buy ~53 MB more. **Not used** — that
can link an executable to a byte-identical non-executable twin and silently drop
`+x` somewhere in `vendor/bin`. 415 MiB for zero risk is the better trade.

The one hard constraint: **hardlinking must be the last step in the builder
stage.** A later step rewriting a static file in place would mutate every locale
sharing that inode. Production mode never writes `pub/static` at runtime, so the
running container is unaffected.

**Locales are pinned to `STATIC_LOCALES="en_US ja_JP"`** in `docker/Dockerfile`.
Auto-discovery (`STATIC_LOCALES=""`) resolves per *area*
(`Deploy\Package\LocaleResolver::getUsedPackageLocales`) and on this DB yields a
ja_JP-only storefront plus an en_US-only admin; the pinned list gives both
locales to both areas, and thanks to the hardlink pass the second locale costs
~1 MB, not ~49 MB. Trade-off: a store view on a new locale needs that `ARG`
edited. ⚠️ Production mode never builds static on demand, so a missing locale is
a **broken storefront** for that store view, not a fallback to `en_US`.

### Verified-safe strips

| Stripped | Raw | After dedup¹ | Why it is safe |
|---|---:|---:|---|
| `pub/static/frontend/Magento/blank` | 530 MB | ~40 MB | See below |
| frontend `hugerte` | 99 MB | ~8 MB | HugeRTE is the **admin** WYSIWYG; SCD copies `lib/web/hugerte` into every area. `grep wysiwygAdapter\|tinymce\|hugerte` across every `view/frontend` in `app/code` and `vendor` → **0 matches**. It appears in `requirejs-config.js` and `js/bundle/bundle1.js` only as a RequireJS **path map/shim** — the library source is not bundled, and RequireJS resolves paths lazily. The `adminhtml/Magento/backend` copy (8.3 MB) stays |
| `*.map` source maps | 59 MB | ~5 MB | 50 files, 27 MB of it Adyen (`adyen.js.map` 2.2 MB × 12). Only fetched with DevTools open. 39 deployed JS files carry a `sourceMappingURL` while only 2 `.map` exist per locale — 37 refs were **already** dangling |
| `var/import*` | 22 MB | 22 MB | `var/import_history`, `var/importexport`, nine `importexport_<hash>` temp dirs, stray `var/*.csv` — someone's local runs riding in via `COPY . .`. Now excluded in `.dockerignore` **and** stripped in a `RUN` (BuildKit can reuse a cached COPY layer). Magento recreates them on demand |

¹ Strips and dedup overlap — most of what the strips delete is itself duplicated
across locales, so the standalone figures do not sum. Measured together, strips
+ dedup take the tree 1.9 GB → 684 MB. The strips still earn their keep: they
remove content the site never reads, and shrink the dedup work.

**Why `Magento/blank` is safe**, strongest argument first:

- **The build decides which themes exist.** SCD runs with an explicit include
  list — `--theme carpetavenue/rugs --theme Magento/backend`. Whatever the
  database says, those are the only two deployed, so `blank` is present *solely*
  as `rugs`' ancestor. This holds independently of store config.
- **`blank` is a strict subset of `rugs`.** `blank/en_US` = 2863 files,
  `rugs/en_US` = 2933, and `comm -23` of the two lists is **empty**. Theme
  fallback resolves at *deploy* time and is flattened into the child's output;
  runtime asset URLs only ever name the active theme.
- **No store view resolves to it** (prod, verified 2026-08-22):
  `design/theme/theme_id` exists only at default scope, value `6` =
  `carpetavenue/rugs`, covering all 12 store views across 3 websites. ⚠️
  `theme_id` values differ per environment — never carry an ID between them. The
  first two bullets are what the strip actually rests on.

⚠️ **One caveat, fixed in the Dockerfile.** `rugs/*/css/styles-{l,m}.css`
contained exactly one cross-theme reference —
`url(../../../../../frontend/Magento/blank/en_US/Mageplaza_Core/css/owl.video.play.png)`
— from a Mageplaza LESS `@import` emitting an absolute-from-`pub/static` path.
The identical 4976-byte PNG already exists at
`rugs/<locale>/Mageplaza_Core/css/`, so a locale-agnostic `sed` repoints the URL
before the `rm`. Re-check after any Mageplaza or theme upgrade.

### Kept deliberately

| Kept | Size | Why |
|---|---:|---|
| `n98-magerun2.phar` | 6.9 MB | Operational tooling, wanted inside the running container |
| `*.sample` configs | ~40 KB | Reference copies; noise-level cost |
| `docs/` | 76 KB | Not read at runtime, not worth a Dockerfile line |
| `prototype/` | 11 MB → ~1 MB | Legacy JS, absent from the RequireJS path map — but a third-party module could `<script>` it directly. Needs a storefront smoke test first |
| `generated/` | 89 MB | `setup:di:compile` output. Required — production never generates on demand |

### Unverified candidates (not applied)

| Candidate | Size | Blocker |
|---|---:|---|
| **Final stage from `debian:bookworm-slim`** instead of `php:*-fpm-bookworm` | **~300 MB** | The official PHP image ships `PHPIZE_DEPS` (gcc, g++, make, autoconf, dpkg-dev) in a 299 MB layer of the *runtime* base. Layers are additive — an `apt-get purge` only adds a whiteout. Fix is to stop inheriting: `FROM debian:bookworm-slim` + `COPY --from=builder /usr/local /usr/local` (PHP itself is 65 MB), reproducing `www-data`, `PHP_INI_DIR`, `docker-php-entrypoint` and the fpm config tree by hand. Biggest remaining win, moderate risk |
| **`vendor/mpdf/mpdf/ttfonts`** | up to **80 MB** of 88 MB | 83 font files; `Sun-ExtA` (22 MB) + `Sun-ExtB` (17 MB) + `UnBatang` (6.7 MB) + Aegyptus/Aegean/Akkadian/Jomolhari/Quivira/XB Riyaz are CJK-extension and ancient-script fallbacks. Both consumers (`CarpetAvenue_Proforma`, `Evalent_ProductCertificate`) set `'default_font' => 'dejavusans'` and the templates name only dejavusans/Helvetica/Arial/`YasashisaGothicBold-V2`/`cid0jp` — and `cid0jp` is CID-keyed, served from `vendor/mpdf/mpdf/data` (2.8 MB), so Japanese output does not depend on `ttfonts`. **But** mPDF's `useSubstitutions`/autoFont silently falls back through `ttfonts`, and these are customer-facing invoices and certificates. Before pruning: generate a proforma **and** a certificate for a Japanese name/address and a European accented name, and diff against current output |
| **`vendor/aws/aws-sdk-php/src/data`** | ~**40 MB** of 47 MB | Per-service AWS API model JSON, loaded lazily by service name. Only consumer is `magento/module-aws-s3`, and `remote_storage.driver` is `'file'`. Keeping `s3`, `sts` and core models reclaims ~40 MB — but it edits a vendor package's payload, so first check no other AWS client is referenced anywhere |
| `frontend/*/Magento_Swagger` | 19 MB → ~2 MB | `module-swagger`'s `etc/frontend/routes.xml` declares a real frontend route — `/swagger` is a live storefront URL. Largely moot after dedup |
| `pub/static` as a volume | ~78 MB | Was the obvious lever at 1.2 GB. After dedup it costs more in compose changes, upgrade sequencing and rollback semantics than it saves. **Dropped** |

### Measuring & re-checking

```bash
docker images registry.carpetavenue.net/carpetavenue-jp
docker history registry.carpetavenue.net/carpetavenue-jp:latest \
  --no-trunc --format '{{.Size}}\t{{.CreatedBy}}' | head -20

docker run --rm --entrypoint sh registry.carpetavenue.net/carpetavenue-jp:latest -c '
  du -sh /var/www/html/* | sort -hr | head -12
  du -sh /var/www/html/pub/static/frontend/*/* /var/www/html/pub/static/adminhtml/*/*'
```

**After any Magento, theme or Mageplaza upgrade — any output from 1–3 means the
`Magento/blank` strip is no longer safe as written:**

```bash
docker run --rm --entrypoint sh registry.carpetavenue.net/carpetavenue-jp:latest -c '
  # 1. New cross-theme references into a stripped theme?  Expected: empty
  grep -rho "[^ (\"]*frontend/Magento/blank/[^ )\"]*" \
    /var/www/html/pub/static/frontend/carpetavenue 2>/dev/null | sort -u

  # 2. Blank still a strict subset of rugs?  Expected: empty
  cd /var/www/html/pub/static/frontend
  ( cd Magento/blank/en_US   && find . -type f | sort ) > /tmp/b
  ( cd carpetavenue/rugs/en_US && find . -type f | sort ) > /tmp/r
  comm -23 /tmp/b /tmp/r

  # 3. Did a frontend module start using the WYSIWYG?  Expected: empty
  cd /var/www/html && grep -rl "wysiwygAdapter\|tinymce\|hugerte" \
    app/code/*/*/view/frontend vendor/*/*/view/frontend 2>/dev/null

  # 4. Did the hardlink dedup survive into the shipped image?
  stat -c "links=%h" /var/www/html/pub/static/frontend/carpetavenue/rugs/en_US/jquery.js
  du -sh /var/www/html/pub/static'
# 4 expects links = locales+1 and pub/static ~78M. links=1 means a BuildKit or
# base-image change broke hardlink preservation across COPY --from. The in-build
# assert (stat -c %h ... > 1) should have failed the build first.
```

Re-measure the true dedup ceiling without changing anything:

```bash
docker run --rm --entrypoint sh registry.carpetavenue.net/carpetavenue-jp:latest -c '
  apt-get update >/dev/null 2>&1 && \
  apt-get install -y --no-install-recommends hardlink >/dev/null 2>&1
  hardlink --dry-run --ignore-time /var/www/html 2>&1 | grep Saved'
```

The build also asserts `hardlink` reported its saving and that
`require.min.js` / `styles.min.css` exist, so both classes of drift fail the
build rather than the deploy. An empty `pub/static/frontend/Magento/` directory
is left behind — harmless.

After any strip, smoke-test the built site with the browser console open,
watching for `/static/` 404s: home, a category, a product, cart, checkout, admin.

---

## 8. File map

```
docker/
  Dockerfile                 Magento image (multi-stage, BuildKit secrets)
  Dockerfile.nginx           nginx image (config baked in)
  entrypoint.sh              renders env.php from template at start; also dumps
                             env to /etc/magento-env.sh for cron
  env.php.template           runtime config, all via getenv()
  env.php.build              build-time env.php (file cache, no network)
  env.php.scd                SECRET: env.php for static-content deploy (gitignored)
  docker-compose.local.yml   local dry-run (HTTP :80, optional localdb)
  docker-compose.yml         Path A — plain server (pull, publish :80)
  docker-compose.dokploy.yml Path B — Dokploy pull (recommended), shared infra
  docker-compose.build.yml   Path C — Dokploy builds from git
  github-runner.compose.yml  CI runner as a Dokploy container
  .env.production.example    runtime env template (copy to .env)
  .env.local                 SECRET: local dry-run env (gitignored)
  host/magento               host-side CLI wrapper; install as magento-jp (§3)
  nginx/                     nginx.conf, local conf, prod template
  php/                       php.ini, php-cli.ini, www.conf
  cron/magento-cron          cron table
  README.md                  this file
  INFRA-CI.md                shared infra, CI/CD, server ops, open items
  TROUBLESHOOTING.md         symptom → cause → fix
.github/workflows/build-deploy.yml   CI
```

Named volumes: `magento_media`, `magento_var`, `magento_static`, `valkey_data`,
`valkey_sessions`, plus `rabbitmq_data` / `opensearch_data` on Paths A and C only
(Path B uses the shared stacks' own). Compose prefixes them per project, so
carpient's same-named volumes are separate objects. `magento_static` is written
by the `static` service and read-only in nginx; `magento_media` is read-write
for app/cron, read-only in nginx.
