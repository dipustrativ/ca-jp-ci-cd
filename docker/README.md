# CarpetAvenue — Deploy Guide

Magento 2.4.9 in Docker. **One image, many roles.** This single file covers
everything: how it works, the local dry-run, and every deploy path
(plain server, Dokploy-pull, Dokploy-build, CI/CD), plus rollback and
troubleshooting. Read top to bottom the first time.

Certified stack: PHP 8.5 · MySQL 8.4 (external) · OpenSearch 3 · Valkey 9 ·
RabbitMQ 4.2 · nginx 1.29.

---

## 0. How it works (read once)

**Roles are separate on purpose.** In this setup the build + registry + Dokploy
control plane share one server; the application runs on another:

| Machine | Job | Runs |
|---|---|---|
| **Server A** — build/registry | Build the image, push to the **local** registry, hold the CI runner + Dokploy control panel. | Docker, registry, GitHub runner, Dokploy |
| **Server B** — application | Run the Magento stack (app/nginx/cron/valkey/queue/search). Pull the image from A's registry. **Never builds.** | Docker / Dokploy agent |
| **Database** | MySQL 8.4, its own endpoint (IP:port). | MySQL only |

Because the build and the registry sit on the same host (A), the CI push is a
**localhost push** — fast, and it never touches Traefik (so the large-layer 504
in §10 can't bite CI). Server B pulls over the network from A's registry.

> Simpler variant: if Dokploy runs the app on its **own** host, A and B are the
> same machine. Everything below still applies — just one server.

**One image, five roles.** `carpient` is built once and run as five services;
the command differs, the image does not:

| Service | Command | Purpose |
|---|---|---|
| `app` | `php-fpm` | serves requests (nginx talks to it as `carpient-fpm:9000`) |
| `upgrade` | `setup:upgrade` then exits | run-once per deploy: schema + module updates |
| `static` | copies `pub/static` → volume | publishes compiled assets to the shared volume |
| `cron` | `cron -f` | Magento cron; also drives message-queue consumers |
| `nginx` | (separate `carpient-nginx` image) | web front, serves static/media, proxies to `app` |

**No secrets in the image.** `entrypoint.sh` renders `app/etc/env.php` from
`env.php.template` on every container start, reading all config from environment
variables (`getenv()`). Change a value = change the env var + restart, never a
rebuild. Two build-time secrets (`auth.json`, `env.php.scd`) are passed as
BuildKit secrets and never land in a layer.

**What ships in the image vs. what doesn't.** Code, `vendor` (prod only),
compiled DI, and `pub/static` are baked in. Secrets, DB, and all hostnames are
injected at runtime. Media and `var` live on named volumes.

---

## 1. Build the images (Server A, or your laptop)

You need two secret files present in the repo root (both gitignored):

- `auth.json` — Composer Marketplace keys
- `docker/env.php.scd` — throwaway env.php used only during static-content deploy

```bash
docker login registry.carpetavenue.net

# 1) The Magento image (app/upgrade/static/cron all use this one)
docker buildx build \
  --secret id=composer_auth,src=auth.json \
  --secret id=env_php_scd,src=docker/env.php.scd \
  -f docker/Dockerfile \
  -t registry.carpetavenue.net/carpient:latest \
  -t registry.carpetavenue.net/carpient:v1 \
  --push .

# 2) The nginx image (config baked in; rebuild only when nginx config changes)
#    NOTE: its v<N> sequence is INDEPENDENT of the Magento image's — nginx is
#    rebuilt far less often, so e.g. carpient:v13 pairs with carpient-nginx:v5.
docker buildx build \
  -f docker/Dockerfile.nginx \
  -t registry.carpetavenue.net/carpient-nginx:latest \
  -t registry.carpetavenue.net/carpient-nginx:v5 \
  --push .
```

The build context is `.` (the repo root **is** `src/`) and the Dockerfile is
`docker/Dockerfile`. **Tag strategy:** always push `latest` (what servers pull)
**and** an immutable `v<N>` (never re-used → your rollback target). Same image,
two tags, one push. This applies to **both** images — `carpient-nginx` needs its
own `v<N>` or nginx has no rollback target.

**Which tag the servers actually pull** is set per environment:
`IMAGE_TAG` → `carpient`, `NGINX_TAG` → `carpient-nginx` (see
`docker-compose.yml` / `docker-compose.dokploy.yml`, and `.env.example`). Both
default to `latest` when unset. Bump the matching variable in Dokploy →
Environment after pushing a new `v<N>`, or the server keeps running the old one.

**Architecture:** the build server is amd64, so plain `docker buildx build` is
correct there. Building from an Apple Silicon machine produces an **arm64** image
the servers cannot run — add `--platform linux/amd64` in that case.

**Image size:** ~1.2 GB. Debian+PHP base (~400 MB) + Magento `vendor` +
compiled DI + `pub/static` (~800 MB). That floor is Magento; the Dockerfile
already strips build tools, `dev/`, and per-package tests/docs, and avoids a
duplicate `chown` layer.

**Everything came back `CACHED`?** That is usually correct, not a broken build.
BuildKit keys layers on *content*, not timestamps, so if nothing in the build
context changed since the last build, `COPY . .` hits cache and every step after
it — `composer install`, `setup:di:compile`, `setup:static-content:deploy` — is
reused. The new `v<N>` then resolves to the *same image ID* as the previous one
(check with `docker images | grep carpient`). Before assuming the cache is at
fault, confirm your edit is actually in the context: `.dockerignore` excludes
`vendor/`, `pub/static/`, `dev/` and most of `var/`, so changes there never reach
the image.

To force a rebuild anyway, add one flag:

```bash
--no-cache-filter builder   # re-runs composer install, di:compile, static-content:deploy
                            # keeps the base image + apt/php-ext layers cached
--no-cache                  # nukes everything, including the apt/php-ext layers (slow)
```

✅ Check: `docker buildx build ...` ends with both tags pushed, no error.

---

## 2. Local dry-run (prove it before any server)

Run the exact production flow on your laptop. Uses the remote **test** DB (not
prod). Site answers on `http://carpient.devs` (already in your `/etc/hosts`) and
`http://localhost`, plain HTTP — no TLS, no certs locally.

```bash
# 2.1 build + push a local tag
docker login registry.carpetavenue.net
docker buildx build \
  --secret id=composer_auth,src=auth.json \
  --secret id=env_php_scd,src=docker/env.php.scd \
  -f docker/Dockerfile -t registry.carpetavenue.net/carpient:local --push .
# (local uses a plain nginx with mounted config — no nginx image build needed)

# 2.2 boot the full stack
IMAGE_TAG=local docker compose \
  -f docker/docker-compose.local.yml --env-file docker/.env.local up -d

# 2.3 watch the run-once upgrade finish
docker compose -f docker/docker-compose.local.yml logs -f upgrade
#   wait for:  "Upgrade completed successfully."   then it exits 0
```

```bash
# 2.4 load the site
open http://carpient.devs            # or http://localhost
#   RabbitMQ UI: http://localhost:15672

# 2.5 teardown (‑v also drops the volumes = clean slate)
docker compose -f docker/docker-compose.local.yml down -v
```

✅ Check: `upgrade` exits 0 with "Upgrade completed successfully."; the
storefront renders with CSS (static served); `docker compose ps` shows `app`,
`nginx`, `cron` up and `cache`/`queue`/`search` healthy.

⚠️ If `upgrade` hangs at "Schema creation/updates" → another Magento instance
holds the DB setup-lock on the **same** database. Use a DB nothing else touches,
or start the throwaway local MySQL instead: add `--profile localdb` to 2.2 and
set `DB_HOST=db` in `docker/.env.local`.

⚠️ Need to see what env the container got? Set `DEBUG_ENV=true` and re-up — the
entrypoint prints every resolved variable.

---

## 3. Deploy — pick ONE path

| Path | Use when | File |
|---|---|---|
| **A. Plain server** | a normal Docker VPS, you own the TLS proxy | `docker-compose.yml` |
| **B. Dokploy-pull** ⭐ | Dokploy host, image built by CI/build server | `docker-compose.dokploy.yml` |
| **C. Dokploy-build** | Dokploy host, no build server/registry | `docker-compose.build.yml` |

**B is the recommended path.** All paths first need, on the hosting server:

```bash
sudo sysctl -w vm.max_map_count=262144
echo 'vm.max_map_count=262144' | sudo tee /etc/sysctl.d/99-opensearch.conf   # persist
```

### Path A — plain Docker server (pull image)

```bash
cp docker/.env.production.example docker/.env    # fill REAL values (see §7)
docker login registry.carpetavenue.net
docker compose -f docker/docker-compose.yml --env-file docker/.env pull
docker compose -f docker/docker-compose.yml --env-file docker/.env up -d
docker compose -f docker/docker-compose.yml logs -f upgrade   # wait for success + exit 0
```

nginx publishes **host port 80**. Put TLS in front (Caddy, a host
nginx/Traefik, or Cloudflare) pointing at `http://<host>:80`.

✅ Check: `upgrade` exits 0; `curl -H 'Host: <domain>' http://<host>/` returns
Magento HTML.

### Path B — Dokploy, pull a pre-built image ⭐

In the Dokploy UI:

1. **Settings → Registries** → add `registry.carpetavenue.net` (user/pass).
2. **Create → Compose** → paste `docker/docker-compose.dokploy.yml`.
3. **Environment** → paste `docker/.env.production.example`, fill real values
   (`DB_HOST` = the external MySQL IP:port).
4. **Domains** → your domain → service **`nginx`**, port **80**, HTTPS **on**
   (Dokploy/Traefik injects the cert + labels; the container stays HTTP).
5. **Deploy.** Then open the app → **Deployments** tab → copy the **Webhook
   URL** — that is the `DOKPLOY_WEBHOOK` used by CI in §6.

✅ Check: the `upgrade` service logs show success + exit; the domain serves
over HTTPS with working static assets.

### Path C — Dokploy builds from git (no registry)

Same UI steps as B, but paste `docker/docker-compose.build.yml` and connect the
Git source. Dokploy clones the repo and runs `docker compose build`.

⚠️ The two build secrets are gitignored, so they are **not** in the clone. Before
deploying, place them into the build context on the Dokploy host:
`auth.json` at the clone root and `docker/env.php.scd`. Add them via
**Dokploy → Advanced → Files**, or `scp` them onto the host. (This friction is
exactly why Path B is recommended.)

---

## 4. Redeploy (new code)

- **Paths B/C via CI (§6):** merge to `main`. Done.
- **Path A / manual:** rebuild + push (§1) with a new `v<N>`, then on the host:

```bash
docker compose -f docker/docker-compose.yml --env-file docker/.env pull
docker compose -f docker/docker-compose.yml --env-file docker/.env up -d
```

`upgrade` re-runs `setup:upgrade` automatically each time.

---

## 5. Rollback (fast)

Every build left an immutable `v<N>` in the registry. To roll back, point the
stack at the previous tag — no rebuild:

- **Dokploy:** Environment → set `IMAGE_TAG=v<previous>` → Redeploy.
- **Plain server:** `IMAGE_TAG=v<previous>` in `docker/.env`, then `pull` + `up -d`.

✅ Check: `docker compose ps` shows the containers now running the old tag
(`docker inspect ... --format '{{.Config.Image}}'`).

---

## 6. CI/CD — GitHub Actions builds & deploys (full setup)

**The flow:** push to `main` → GitHub triggers the workflow on **your build
server** (a self-hosted runner) → it builds the Magento image → pushes
`latest` + `v<run_number>` to the registry → POSTs the Dokploy webhook →
Dokploy (hosting server) repulls the image and restarts. GitHub never builds on
its own hardware; your build server does all the work.

The workflow file already exists: `.github/workflows/build-deploy.yml`. You
don't write it — you just wire up the runner + secrets below.

> **Order matters.** Do §3-B first (create the Dokploy app). The webhook in
> step 3 does not exist until that app exists.

### Step 1 — Run the self-hosted runner as a Dokploy container (on Server A)

A "self-hosted runner" is a small GitHub agent that polls GitHub for jobs and
runs them on your hardware. Run it as a **Dokploy container** on **Server A** —
no OS-level install, Dokploy manages restarts/logs, and it pushes to the local
registry over localhost. The file is `docker/github-runner.compose.yml`:

```yaml
services:
  github-runner:
    image: myoung34/github-runner:latest
    restart: unless-stopped
    environment:
      REPO_URL:     https://github.com/indexon/carpient
      ACCESS_TOKEN: ${GH_PAT}          # GitHub PAT (repo scope) — Dokploy env
      RUNNER_NAME:  carpient-build-1
      RUNNER_SCOPE: repo
      LABELS:       carpient-build      # matches runs-on: [self-hosted, carpient-build]
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock   # build+push on the host daemon
```

**Deploy it:**

1. GitHub → your avatar → **Settings → Developer settings → Personal access
   tokens → Tokens (classic)** → generate one with the **`repo`** scope. Copy it.
   (The runner uses it to auto-register and re-register across restarts — so you
   never hand-paste a short-lived registration token.)
2. Dokploy → **Create → Compose** → paste `docker/github-runner.compose.yml` →
   **Environment**: `GH_PAT=<the token>` → **Deploy**.
3. Verify: GitHub → **Settings → Actions → Runners** shows **carpient-build-1**
   as **● Idle** (green), label `carpient-build`.

**Why this shape:**
- **Cost:** self-hosted = **zero GitHub Actions minutes** (GitHub bills only its
  own hosted runners). Your hardware, free.
- **`docker.sock` mount:** lets the container build + push using Server A's
  Docker daemon. ⚠️ That's root-equivalent host access — fine on your own build
  host, never on a shared/untrusted one.
- **Resources:** the Magento build peaks around **~4 GB RAM** + heavy CPU + a few
  GB of build-cache disk. Ensure Server A has that headroom so a build doesn't
  starve the registry/Dokploy.

> Prefer a bare-metal install instead of a container? Run GitHub's
> `./config.sh --url https://github.com/indexon/carpient --token <TOKEN> --labels
> carpient-build --unattended` then `sudo ./svc.sh install && sudo ./svc.sh start`
> on Server A. Same result; the container path above is just less to maintain.

1. GitHub repo → **Settings → Actions → Runners → New self-hosted runner** →
   pick **Linux**. GitHub shows a `./config.sh ... --url ... --token ...` block.
2. On the build server, run that block, then **add the label** `carpient-build`
   when prompted (or append `--labels carpient-build`). Example shape:
   ```bash
   mkdir actions-runner && cd actions-runner
   curl -o actions-runner.tar.gz -L <URL github shows>
   tar xzf actions-runner.tar.gz
   ./config.sh --url https://github.com/indexon/carpient \
     --token <TOKEN github shows> --labels carpient-build
   sudo ./svc.sh install && sudo ./svc.sh start   # run as a background service
   ```
3. Verify: the runner shows **Idle / green** under Settings → Actions → Runners.
   Docker must be usable by the runner's user (`docker ps` works without sudo).

The workflow targets this machine via `runs-on: [self-hosted, carpient-build]`.

### Step 2 — Add the repository secrets

GitHub repo → **Settings → Secrets and variables → Actions → New repository
secret**. Add five:

| Secret | Value — where to get it |
|---|---|
| `REGISTRY_USER` | registry username (same as `docker login`) |
| `REGISTRY_PASS` | registry password |
| `COMPOSER_AUTH` | paste the **entire contents** of `auth.json` |
| `ENV_PHP_SCD` | paste the **entire contents** of `docker/env.php.scd` |
| `DOKPLOY_WEBHOOK` | see step 3 |

### Step 3 — Get the Dokploy webhook URL

In Dokploy, open the app you created in §3-B → **Deployments** tab → find
**"Webhook URL"** → **Copy**. (Depending on Dokploy version it may sit under the
app's **General** tab, or behind the **Deploy ▾** button → "Copy Webhook URL".)
Paste it as the `DOKPLOY_WEBHOOK` secret. POSTing that URL = "redeploy this app",
which is exactly what the workflow's last step does.

Test it by hand first:
```bash
curl -fsSL -X POST "<the webhook URL>"     # should trigger a deploy in Dokploy's UI
```

### Step 4 — Run it

Merge (or push) to `main`. Watch **repo → Actions**: the job runs on your
runner, builds, pushes both tags, hits the webhook. Then watch Dokploy redeploy.
Trigger manually anytime with **Actions → build-deploy → Run workflow**
(`workflow_dispatch`).

From then on: **merge to `main` = deploy.** Rollback stays manual (§5: set
`IMAGE_TAG=v<previous>` in Dokploy → Redeploy).

Branch flow: `develop → staging → main`. CI triggers on `main` only.

---

## 7. Environment variables (runtime)

Set these in Dokploy → Environment, or in `docker/.env` for a plain server.
Template: `docker/.env.production.example`.

| Var | Notes |
|---|---|
| `REGISTRY_HOST` | default `registry.carpetavenue.net` |
| `IMAGE_TAG` | `latest`, or `v<N>` to pin/rollback |
| `DOMAIN` | storefront domain |
| `DB_HOST` `DB_NAME` `DB_USER` `DB_PASSWORD` | external MySQL 8.4 (IP:port in `DB_HOST`) |
| `VALKEY_HOST` `VALKEY_PORT` `VALKEY_PASSWORD` | cache db0 / page_cache db1 / sessions db2 |
| `RABBITMQ_HOST` `_PORT` `_USER` `_PASSWORD` `_VHOST` | message queue |
| `OPENSEARCH_HOST` `OPENSEARCH_PORT` | search engine |
| `MAGE_CRYPT_KEY` | **must equal** the `crypt/key` of the DB it decrypts |
| `MAGE_GRAPHQL_ID_SALT` | GraphQL id salt |
| `DEBUG_ENV` | `true` prints resolved env at startup (debug only) |

⚠️ `MAGE_CRYPT_KEY` mismatch = garbled config / failed decryption. It is tied to
the database, not the environment — copy it from the DB you are pointing at.

---

## 8. Troubleshooting

| Symptom | Cause → Fix |
|---|---|
| nginx `cannot load certificate ...pem` | A TLS server block with no certs. Local is HTTP-only now — pull latest `magento.local.conf`. TLS belongs at the edge. |
| `upgrade` hangs at "Schema creation/updates" | DB setup-lock held by another instance on the same DB. Use an isolated DB, or `--profile localdb`. |
| Storefront loads but no CSS/JS | `static` service didn't finish, or nginx can't see the static volume. Check `docker compose logs static` and that both mount `magento_static`. |
| Admin/storefront unstyled, every `*.min.js` / `*.min.css` 404s while a few assets 200 | Minification mismatch — see below. |
| `search` container won't start | Host `vm.max_map_count` too low → set `262144` (§3). |
| Garbled config / decrypt errors | `MAGE_CRYPT_KEY` ≠ the DB's crypt key (§7). |
| Can't pull image on host | Registry not added / not logged in on that host. |
| Changed an env var, no effect | Restart the containers — env.php is rendered at start, not live. |
| `cron` logs `getaddrinfo for db failed` every minute (app is fine) | crond does not pass the container environment to the jobs it spawns, so `env.php`'s `getenv()` calls return false and fall back to the template defaults (`DB_HOST` → `db`). `entrypoint.sh` writes `/etc/magento-env.sh` and `cron/magento-cron` sources it — if it's missing, the container predates that fix: rebuild/redeploy the image. |

### Minification mismatch (SCD DB ≠ production DB)

Symptom: page loads, `health_check.php` 200, but `requirejs/require.min.js`,
`css/styles.min.css` and `<Module>/css/*.min.css` all 404 — while a few assets
still 200 and `GET /static.php` 404s too.

The 200s are the tell: those ship pre-minified in `lib/web`
(`jquery/jstree/.../style.min.css`, `images/magento-icon.svg`). Every 404 is a
file Magento has to *generate*. `static.php` 404ing is production mode refusing
to build on demand — correct, not the bug.

Cause: `dev/js/minify_files` + `dev/css/minify_files` decide both whether SCD
writes `require.min.js` or `require.js`, **and** which URL the running site asks
for. The builder read them from the SCD database, the site from the production
database — two different databases. When they disagreed, the image had
`require.js` and the page wanted `require.min.js`.

Both flags are now locked in `app/etc/config.php` — it ships in the image, and
deployment config is merged last (sortOrder 1000 vs the DB's 100 in
`systemConfigSourceAggregated`), so neither DB can win:

```bash
bin/magento config:set --lock-config dev/js/minify_files 1
bin/magento config:set --lock-config dev/css/minify_files 1
bin/magento config:set --lock-config dev/js/merge_files 0
```

Locked = admin field read-only, DB row ignored (not deleted). No DB change needed
on either side. `dev/css/merge_files` no longer exists in 2.4.9.

`dev/js/merge_files` is pinned **off** deliberately: merging writes to
`pub/static/_cache/merged` at runtime inside the `app` container, but nginx
serves `magento_static` read-only from the `static` copy job — a merged file
would 404 forever. Same symptom, different trigger.

The SCD step now asserts `require.min.js` and `styles.min.css` exist, so drift
fails the build instead of the deploy. Check any image directly:

```bash
docker run --rm --entrypoint sh registry.carpetavenue.net/carpient:v<N> \
  -c 'ls pub/static/adminhtml/Magento/backend/en_US/requirejs/'
# want: require.min.js present
```

---

## 9. File map

```
docker/
  Dockerfile                 Magento image (multi-stage, BuildKit secrets)
  Dockerfile.nginx           nginx image (config baked in)
  entrypoint.sh              renders env.php from template at start;
                             also dumps env to /etc/magento-env.sh for cron
  env.php.template           runtime config, all via getenv()
  env.php.build              build-time env.php (file cache, no network)
  env.php.scd                SECRET: env.php for static-content deploy (gitignored)
  docker-compose.local.yml   local dry-run (HTTP :80, optional localdb)
  docker-compose.yml         Path A — plain server (pull, publish :80)
  docker-compose.dokploy.yml Path B — Dokploy pull (recommended)
  docker-compose.build.yml   Path C — Dokploy builds from git
  github-runner.compose.yml  CI runner as a Dokploy container (§6)
  .env.production.example    runtime env template (copy to .env)
  nginx/                     nginx.conf, local conf, prod template
  php/                       php.ini, php-cli.ini, www.conf
  cron/magento-cron          cron table
.github/workflows/build-deploy.yml   CI (§6)
```

Named volumes: `magento_media`, `magento_var`, `magento_static`,
`valkey_data`, `rabbitmq_data`, `opensearch_data`. `magento_static` is written
by the `static` service and read-only in nginx; `magento_media` is shared
read-write by app/cron and read-only in nginx.

---

## 10. FAQ

### Pushing the image to the registry fails with `Client Closed Request` (499) or `504 Gateway Timeout`

Not an image problem. The Magento image has one large layer (~800 MB of
`vendor` + `pub/static`). Traefik in front of the registry was killing the
upload before the registry finished committing the blob — the client then sees
`499`, Traefik logs `504`.

**Fix — raise Traefik's timeouts (static config, `/etc/dokploy/traefik/traefik.yml`):**

```yaml
entryPoints:
  websecure:
    address: ":443"
    # ...existing http/tls/http3 keys...
    transport:
      respondingTimeouts:
        readTimeout:  "900s"    # time to READ the request body = the blob upload
        writeTimeout: "900s"
        idleTimeout:  "180s"

serversTransport:
  forwardingTimeouts:
    dialTimeout:            "30s"
    responseHeaderTimeout:  "900s"   # THE 504 fix: wait for the registry to commit the big blob
    idleConnTimeout:        "90s"
```

These are **static** config → Traefik must be restarted, not hot-reloaded:

```bash
docker restart dokploy-traefik
docker logs --tail 20 dokploy-traefik   # confirm it came back up (no YAML error)
```

`responseHeaderTimeout` is the key line — committing a large blob to disk takes
longer than Traefik's default wait. After the restart, full-image pushes go
through. (Confirmed working on this stack.)

---

## 11. Production readiness

What the image/compose already guarantee, and the few operator steps that make a
deploy genuinely production-grade.

### Already handled by this stack
- `MAGE_MODE=production`, OpCache tuned + `validate_timestamps=0`, JIT off, Redis
  sessions/cache on Valkey (db0/1/2, igbinary), FPM worker recycling + slowlog.
- No secrets in the image; env rendered at start from env vars.
- Healthchecks on cache/queue/search/nginx; `upgrade` gates `app`/`cron`/`static`
  (they start only after schema is current).
- TLS terminated at Traefik; nginx runs HTTP behind it.
- Rollback anchor: every CI build pushes an immutable `v<N>` tag.

### Do once, right after the first successful deploy
Run inside the `app` container (`docker compose exec app bash`, or Dokploy →
app → Terminal):

```bash
# 1. Indexers to schedule mode — cron does incremental reindex instead of
#    reindex-on-save. REQUIRED for production performance.
php bin/magento indexer:set-mode schedule
php bin/magento indexer:reindex          # one full index now

# 2. Confirm cron is actually running (drives indexers + queue consumers)
php bin/magento cron:install --force 2>/dev/null; tail -n 5 var/log/cron.log

# 3. Sanity: no config errors, correct mode
php bin/magento config:show 2>/dev/null | head
php bin/magento deploy:mode:show          # -> production
```

### Operator responsibilities (not baked into the image)
- **Backups.** External MySQL is your DBA's job (daily dump + PITR).
  `magento_media` (uploaded product images) is backed up automatically by the
  `backup` service in `docker-compose.dokploy.yml` — nightly tar (03:00),
  14-day retention, pushed to a GCS bucket via `offen/docker-volume-backup`
  using GCS's S3-interoperability mode (HMAC keys, not a service-account
  JSON). Set `BACKUP_GCS_BUCKET` / `BACKUP_GCS_HMAC_KEY` /
  `BACKUP_GCS_HMAC_SECRET` (see `.env.production.example`) — generate the HMAC
  pair at GCS Console → Cloud Storage → Settings → Interoperability. Files
  land in the bucket root as `media-YYYY-MM-DD.tar.gz`. It is the only
  stateful container data that isn't reproducible from a build — `magento_var`,
  `magento_static`, valkey/opensearch/rabbitmq data are all regenerable, not
  backed up on purpose.
  ⚠️ Restore is untested until you run one — pull a tarball, extract into a
  throwaway `magento_media` volume, confirm images render, before trusting it.
- **`MAGE_CRYPT_KEY`** is tied to the database it decrypts — store it with the DB
  credentials, rotate them together, never lose it (encrypted config becomes
  unreadable without it).
- **Monitoring/logs.** Use Dokploy's per-service logs + monitoring; FPM slow
  requests and cron output already go to stderr → captured by `docker logs`.
- **Scaling.** This is a single-app-instance layout. To scale horizontally, run
  multiple `app` replicas behind nginx and keep exactly one `cron` — Valkey
  sessions/cache are already shared, so replicas are safe. `upgrade` must run
  once per deploy, never concurrently (the DB setup-lock enforces this).
