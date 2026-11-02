# CarpetAvenue JP — Infrastructure & CI/CD

The things around the app: the **shared** MySQL / OpenSearch / RabbitMQ stacks,
the automated build pipeline, day-to-day server ops, and what is still open.

Build & deploy procedure: **[README.md](README.md)**.
Symptom → fix: **[TROUBLESHOOTING.md](TROUBLESHOOTING.md)**.

---

# PART 1 — Shared infrastructure

MySQL, OpenSearch and RabbitMQ are **not** in this repo and **not** in this
app's compose file. One MySQL (a database per app), one broker (a vhost per
app), one search cluster (an index prefix per app) — shared with `carpient`.

| | |
|---|---|
| Compose files, env templates, full guide | `Workspace/DOCKER-GLOBAL/README.md` |
| Deploy walkthrough (this app is first) | `DOCKER-GLOBAL/START-HERE-CA_JP-FIRST.md` |
| Total RAM budget across every stack | `DOCKER-GLOBAL/README.md` §2 |

Deploy those three **before** this stack. It has no healthcheck gate on them —
separate Compose projects — so a missing one deploys green and fails at runtime.

## This app's resources

| | |
|---|---|
| Domain | `https://playground.carpetavenue.jp` |
| Database | `carpetavenue_jp` |
| DB user | `root` (all apps on this server) |
| RabbitMQ vhost / user | `/ca_jp` / `ca_jp` |
| OpenSearch prefix | `ca_jp` (carpient: `carpient`) |
| Valkey cache prefix | `cajp_` (carpient: `69d_`) |

Database and vhost are created **by hand**, no redeploy —
`DOCKER-GLOBAL/README.md` §4 and §5.

⚠️ The shared stacks' env values (`MYSQL_DATABASE`, `RABBITMQ_DEFAULT_*`)
bootstrap an **empty volume only** and are inert afterwards. So whether this
app's vhost and user already exist depends on whether `rabbitmq_data` survived.
Don't guess — check, and use the idempotent create either way:

```bash
docker exec rabbitmq rabbitmqctl -q list_vhosts
docker exec rabbitmq rabbitmqctl -q list_users
```

## Environment

`docker/.env.production.example` is authoritative and already filled in. The
values that differ from a standalone deploy:

```
DB_HOST=mysql:3306          # container name, NOT loopback 3308
DB_NAME=carpetavenue_jp
DB_USER=root
DB_PASSWORD=<the mysql stack's MYSQL_ROOT_PASSWORD>
RABBITMQ_HOST=rabbitmq
RABBITMQ_USER=ca_jp
RABBITMQ_VHOST=/ca_jp
OPENSEARCH_HOST=opensearch
OPENSEARCH_INDEX_PREFIX=ca_jp
```

Valkey stays **local to this stack** — never point it at carpient's containers.
If one Valkey is ever shared, the apps must use different db numbers.
`MAGE_CRYPT_KEY` is tied to the database it decrypts: per app, never shared.

## Index prefix — the one that destroys data

🚨 Same prefix in both installs = they overwrite each other's indices, and each
reindex wipes the other store's catalog.

`env.php.template` applies `OPENSEARCH_INDEX_PREFIX` in **both** places Magento
reads a prefix — the `search` connection *and* the locked
`system/default/catalog/search` block. The second overrides `core_config_data`,
so missing it leaves the prefix silently unchanged.

Was hardcoded `ca_jp_stage`. On an install still holding those indices:

```bash
docker exec carpetavenue-jp-app php bin/magento indexer:reindex
docker exec opensearch curl -XDELETE "localhost:9200/ca_jp_stage_*"
```

## ✅ Verify after deploying

⚠️ `carpetavenue-jp-app` below is the plain-compose name. On Dokploy the app
container carries a generated app-id prefix — resolve it first with
`docker ps --filter "name=app" --format "{{.Names}}"` ([Part 3](#run-binmagento-on-production)).

```bash
docker exec carpetavenue-jp-app getent hosts mysql opensearch rabbitmq
docker exec opensearch curl -s "localhost:9200/_cat/indices?v"   # prefixes must not overlap
docker exec rabbitmq rabbitmqctl list_permissions -p /ca_jp
```

---

# PART 2 — CI/CD

> **⚠️ CI IS OFF for this repo until a runner exists.** No runner is
> registered for `indexon/carpetavenue-jp` (Server A's runner is repo-scoped to
> `indexon/carpient`). The workflow triggers **only on a pushed `v*` git tag** —
> push one before the runner exists and the job queues forever. **Builds are
> manual** until then ([README §1](README.md#1-build-the-images-server-a-or-your-laptop)).
> This part is the procedure for turning it on.

**Push a `v<N>` git tag → the Magento image builds on Server A → push to the
registry → Dokploy redeploys Server B.** Branch pushes, merges and PRs never
trigger it. No laptop, no billed Actions minutes — GitHub only *schedules* the
job; self-hosted runners cost zero minutes.

```
git tag v164 && git push origin v164
   ↓  GitHub schedules the job (no GitHub compute used)
Self-hosted runner on SERVER A
   └── docker/Dockerfile        → carpetavenue-jp:latest + :v164
   ↓  push to registry.carpetavenue.net  (Server A → its own registry)
   ↓  POST the Dokploy webhook
SERVER B pulls :latest and restarts the stack
```

**Tag scheme.** The image tag **is** the git tag name (`github.ref_name`), so
`git tag v164` → `carpetavenue-jp:v164`, and the tag points at the exact commit
built. Manual builds and CI share one sequence: pick the next number above the
highest in the registry (`curl … /tags/list`). Never re-push or move a git tag —
image tags must stay immutable (rollback depends on it); cut the next number
instead. A failed build is retried with GitHub → Actions → *Re-run jobs*.

> This supersedes the "independent `v<N>` sequences" note in
> [README §1.6](README.md#16-tags-cache-and-size-all-paths) — that applies to
> manual builds only. The nginx image is `nginx:1.29-alpine` plus three `COPY`s,
> seconds to build, so matching numbers are worth the negligible registry cost.

> **Order matters.** Do [README §3 Path B](README.md#path-b--dokploy-pull-a-pre-built-image-) first.
> The webhook in step 7 does not exist until that Dokploy app exists.

## Step 1 — Install the runner on Server A

Capacity first. The Magento build peaks around **~4 GB RAM**, wants 2+ cores,
and leaves several GB of build cache. Server A also runs the registry and the
Dokploy control panel — a build that OOMs takes the panel with it.

```bash
free -g && df -h /var/lib/docker && nproc
```

**2.** GitHub → repo **indexon/carpetavenue-jp** → **Settings → Actions →
Runners → New self-hosted runner → Linux**. It shows a download URL and a
`./config.sh --token <TOKEN>` line. **That token expires in one hour.**

> **No PAT needed** — this is why the native install is preferred over
> `docker/github-runner.compose.yml`. The container re-registers on every
> restart and so needs a long-lived PAT, which currently 404s on
> `indexon/carpetavenue-jp` because of the org's SSO policy. A native install
> writes its credentials to disk once. Container path: [Appendix B](#appendix-b--container-runner-alternative).

**3.** On Server A, as a user in the `docker` group (`docker ps` must work
without `sudo`). ⚠️ carpient's runner already owns `/opt/actions-runner` — use a
separate directory, and therefore a separate service:

```bash
sudo mkdir -p /opt/actions-runner-jp && sudo chown "$USER" /opt/actions-runner-jp
cd /opt/actions-runner-jp

curl -o actions-runner.tar.gz -L <URL GitHub shows>
tar xzf actions-runner.tar.gz

./config.sh --url https://github.com/indexon/carpetavenue-jp \
  --token <TOKEN from step 2> \
  --name carpetavenue-jp-build-1 \
  --labels carpetavenue-jp-build \
  --unattended

sudo ./svc.sh install "$USER" && sudo ./svc.sh start && sudo ./svc.sh status
docker login registry.carpetavenue.net    # prove creds before a 20-min build needs them
```

The label **`carpetavenue-jp-build`** must match exactly. The workflow currently
ships with `runs-on: ubuntu-latest` (GitHub-hosted) — switch it to
`runs-on: [self-hosted, carpetavenue-jp-build]` once this runner shows Idle.

✅ GitHub → Settings → Actions → Runners shows **carpetavenue-jp-build-1** as
**● Idle** (green) with that label. Not idle → `sudo ./svc.sh status` and
`journalctl -u actions.runner.* -n 50`.

## Step 2 — Repository secrets

First get the webhook: Dokploy → the Compose app → **Deployments** tab →
**Webhook URL** → Copy. (Some versions put it under **General**, or behind
**Deploy ▾ → Copy Webhook URL**.) POSTing it means "redeploy this app" — test by
hand before wiring it in:

```bash
curl -fsSL -X POST "<the webhook URL>"     # should trigger a visible deploy
```

Then GitHub → repo → **Settings → Secrets and variables → Actions → New
repository secret**. Add all five:

| Secret | Value |
|---|---|
| `REGISTRY_USER` | registry username (same as `docker login`) |
| `REGISTRY_PASS` | registry password |
| `COMPOSER_AUTH` | the **entire contents** of `auth.json` |
| `DOKPLOY_WEBHOOK` | the URL above |

`auth.json` is gitignored, so this is the only way it reaches the build. Paste
it whole — GitHub handles multi-line natively, no quoting, no base64.
`pbcopy < auth.json`. It becomes a BuildKit secret (`--secret id=composer_auth`)
consumed by `composer install` in `docker/Dockerfile`, and never lands in a
layer. Static-content deploy needs no secret (README §8.1).

✅ Exactly four secrets listed. Values are write-only — re-paste if unsure.

## Step 3 — First run

**Confirm Dokploy pulls `latest`:** Environment must have `IMAGE_TAG=latest` and
`NGINX_TAG=latest` (or unset). Pinned to a `v<N>` and the webhook redeploys that
pinned tag — your new build never goes live. Pinning is for rollback only
([README §5](README.md#5-rollback-fast)).

**First trigger:** push a tag from a commit you already built manually and
trust — `git tag v<N> && git push origin v<N>`.

Expect **10–20 minutes**. Step order: checkout → buildx setup → registry login →
Magento image → webhook.

✅ **The push** — the job summary lists both images and both tags:

```bash
curl -su '<user>:<pass>' https://registry.carpetavenue.net/v2/carpetavenue-jp/tags/list
curl -su '<user>:<pass>' https://registry.carpetavenue.net/v2/carpetavenue-jp-nginx/tags/list
# both must show `latest` and the new v<N>
```

✅ **The deploy** — Dokploy → Compose app → Logs: `upgrade` exits 0 with
`Upgrade completed successfully.`; the storefront renders **with CSS**; the
admin panel renders with CSS too (that is what proves `--theme Magento/backend`
deployed). Server B's pull does not appear in the GitHub log, so a green
workflow and a briefly unchanged site is normal.

**Go live:** from here, tag = deploy. Branch flow stays
`develop → staging → main`; cut the `v<N>` tag on `main` after the merge.
Rollback stays manual.

## Step 4 — Cleanup jobs

Two different disks. One is handled; the other is not, and it is the one that
fills up.

**Build host** — Dokploy → **Settings → Server → Docker Cleanup**, daily
`0 0 * * *`. Prunes unused images, stopped containers, unused volumes, build
cache.

> [Dokploy #3973](https://github.com/Dokploy/dokploy/issues/3973):
> `docker image prune -a --force` was deleting images for Compose services that
> are not continuously running. Fixed in PR #4166 — stay on a recent Dokploy.
> `upgrade` and `static` do exit, but they share the image with the
> long-running `app`/`cron`, so it stays referenced. Low risk here.

**Registry** — **Dokploy never touches registry storage.** Every run overwrites
`latest` and `buildcache` on both images, orphaning the previous manifests. At
~1.2 GB a build this fills the disk silently.
`REGISTRY_STORAGE_DELETE_ENABLED=true` is already set (the prerequisite); the
sweep is separate. Wire it up as **Dokploy → Schedule Jobs**, weekly:

```bash
docker exec <registry-container> \
  registry garbage-collect --delete-untagged /etc/docker/registry/config.yml
```

The config path must match the bind mount in the registry compose, **not**
`registry:3`'s default `/etc/distribution/config.yml`. Dry-run first: drop
`--delete-untagged`, add `--dry-run`.

> ⚠️ **Never run GC while a build is pushing.** A blob uploaded mid-collect can
> be deleted, silently corrupting that image. Pick an hour you never build, or
> put the registry in read-only mode for the run.

`--delete-untagged` only removes orphaned manifests — your `v<N>` tags are
tagged, so they are never collected. That is what makes rollback safe.

**Leftovers to delete**, if the Dokploy-native build experiment ran:
`carpetavenue-jp/carpetavenue-jp-build-liah5s` (~1.2 GB, unused) and `hello`
(test artifact). Remove them from the registry, then run the GC.

## Appendix A — What the workflow does

`.github/workflows/build-deploy.yml`. You should not need to edit it.

| Part | Why |
|---|---|
| `on: push: tags: ['v*', '*_stage_*']` | tag = deploy (`v164` prod, `1.0.1_stage_1` stage). Nothing else triggers it — no branch push, merge, PR or manual button |
| `concurrency: cancel-in-progress: false` | two tags in a row → queued one after the other; a release is never cancelled half-built |
| `runs-on: ubuntu-latest` | GitHub-hosted, bills Actions minutes, needs `registry.carpetavenue.net` reachable from the internet. Switch to `[self-hosted, carpetavenue-jp-build]` to build on Server A for zero minutes (Step 1) |
| `VERSION: ${{ github.ref_name }}` | the git tag name (`v164`) = the immutable image tag |
| `context: .` | repo root **is** the Magento root (`src/`), filtered by `.dockerignore` |
| `secrets:` on the Magento build | `auth.json` as a BuildKit secret, never in a layer |
| `cache-from`/`cache-to … :buildcache` | layer cache in the registry — a code-only change skips the `vendor` rebuild |
| nginx step has no `secrets:` | `Dockerfile.nginx` needs none |
| `Trigger Dokploy deploy` | POSTs the webhook; Server B pulls |
| `Summary` | both tags + commit in the run summary — your rollback lookup |

No `--platform` flag: the runner is on Server A, which is amd64, so CI can never
emit an arm64 image. That flag is only needed when a human builds from an arm64
machine ([README §1.1](README.md#11-first-which-build-applies-to-you)).

**Build nginx only when its config changes?** Default is every run. To gate it:

```yaml
      - name: Did nginx config change?
        id: nginx
        run: |
          git diff --quiet HEAD~1 HEAD -- docker/Dockerfile.nginx docker/nginx nginx.conf \
            && echo "changed=false" >> "$GITHUB_OUTPUT" \
            || echo "changed=true"  >> "$GITHUB_OUTPUT"
```

then `if: steps.nginx.outputs.changed == 'true'` on the nginx build step. The
cost is that the two `v<N>` sequences drift apart again and you go back to
tracking which nginx pairs with which app. **Not recommended.**

## Appendix B — Container runner alternative

`docker/github-runner.compose.yml` runs the runner as a Dokploy container
instead: Dokploy manages restarts and logs, no OS-level install.

```yaml
services:
  github-runner:
    image: myoung34/github-runner:latest
    restart: unless-stopped
    environment:
      REPO_URL:     https://github.com/indexon/carpetavenue-jp
      ACCESS_TOKEN: ${GH_PAT}          # GitHub PAT (repo scope) — Dokploy env
      RUNNER_NAME:  carpetavenue-jp-build-1
      RUNNER_SCOPE: repo
      LABELS:       carpetavenue-jp-build
    volumes:
      - /var/run/docker.sock:/var/run/docker.sock   # build+push on the host daemon
```

**It requires a long-lived PAT** because it re-registers on every start, and
that PAT currently 404s on `indexon/carpetavenue-jp` due to org SSO. Fix the
token first:

- Classic PAT with `repo` scope → **Configure SSO → Authorize `indexon`**, or
- Fine-grained token: resource owner `indexon`, repo `carpetavenue-jp`,
  permission **Administration: Read/Write**

Then Dokploy → **Create → Compose** → paste the file → Environment
`GH_PAT=<token>` → Deploy. Verify as in Step 1.

⚠️ It mounts `/var/run/docker.sock` — root-equivalent access to Server A's
Docker. Fine on your own build host, never on a shared one.

## Appendix C — Emergency manual build

When CI is down and you need an image now.

**Check where you are building first** — the image arch follows the build
daemon, and the servers only run amd64:

```bash
docker version -f 'build host: {{.Server.Os}}/{{.Server.Arch}}'
#  linux/amd64  -> the commands below as-is
#  linux/arm64  -> add --platform linux/amd64 to BOTH builds
```

Decision table: [README §1.1](README.md#11-first-which-build-applies-to-you).

On Server A (native amd64, pushes over the local network):

```bash
cd /opt/carpetavenue-jp-build && git pull       # clone it there first if absent

docker buildx build --progress=plain \
  --secret id=composer_auth,src=auth.json \
  -f docker/Dockerfile \
  -t registry.carpetavenue.net/carpetavenue-jp:latest \
  -t registry.carpetavenue.net/carpetavenue-jp:v<N> \
  --push .

docker buildx build -f docker/Dockerfile.nginx \
  -t registry.carpetavenue.net/carpetavenue-jp-nginx:latest \
  -t registry.carpetavenue.net/carpetavenue-jp-nginx:v<N> \
  --push .
```

Pick `<N>` **above** the highest tag in the registry, and push the same git
tag only if you want CI to build it — CI and manual builds share the sequence. Check both:

```bash
curl -su '<user>:<pass>' https://registry.carpetavenue.net/v2/carpetavenue-jp/tags/list
```

`auth.json` is gitignored, so `scp` it up once:

```bash
scp auth.json <server-a>:/opt/carpetavenue-jp-build/auth.json
ssh <server-a> 'chmod 600 /opt/carpetavenue-jp-build/auth.json'
```

Run under `tmux`/`nohup` — 10–20 minutes, and an SSH drop kills it. The build
uses the **working tree**, not HEAD: `git status --short` first.

## Appendix D — Approaches rejected, and why

Recorded so nobody re-evaluates them from scratch.

| Approach | Why not |
|---|---|
| **Dokploy Application build** | Pushes `:latest` only — the tag is hardcoded as `${appName}:latest` in [`cluster/upload.ts`](https://github.com/Dokploy/dokploy/blob/canary/packages/server/src/utils/cluster/upload.ts). Path is always `<host>/<prefix>/<appName>`, so a flat `…/carpetavenue-jp:tag` is impossible. One Application builds one Dockerfile, so nginx needs a second. No `v<N>` = no rollback. |
| **Dokploy Build Server** | Applications-only ([#4148](https://github.com/Dokploy/dokploy/issues/4148), closed as not planned). Same tag limits. Pointless here — Dokploy already builds on Server A, its own host. |
| **Dokploy Compose build** (`docker-compose.build.yml`) | Builds on the runtime host and never pushes to a registry, so there is no image to roll back to. |
| **Dokploy Server Job** | Correct tags, but cron-only with no documented manual trigger, and no git-push trigger. |
| **GitHub-hosted runners** | Bills Actions minutes and pushes ~1.2 GB from GitHub's network into your registry. |
| **Building on a laptop** | arm64 cross-build via QEMU, ~1.2 GB over a home link, and the working tree ships instead of the committed code. |

---

# PART 3 — Server ops

Ad-hoc recipes for the Dokploy production host.

> ⚠️ Every literal below (`fj43zo` app-id, `34.14.82.141`, OrbStack paths) is
> from the **`carpient`** deployment. Substitute, don't paste — confirm yours
> with `docker volume ls` / `docker ps`.

## Run `bin/magento` on production

There is no `bin/magento` on the host — Magento only exists inside the `app`
container. `container_name` is not pinned in `docker-compose.dokploy.yml`, so
Dokploy auto-names it with the app-id prefix:

```bash
ssh magento@34.14.82.141
docker ps --filter "name=app" --format "{{.Names}}\t{{.Image}}"
# e.g. carpetavenue-jp-app-fj43zo-app-1

docker exec <app-container> sh -c "cd /var/www/html && php bin/magento cache:flush"
docker exec -it <app-container> sh     # interactive shell
```

`app` runs as `www-data` (compose `user:`), so a bare `docker exec` is already
www-data — the same uid as php-fpm. Keep it that way. A root exec (`-u root`)
that runs `bin/magento` leaves root-owned dirs in `pub/media` / `var`; a root
`catalog:images:resize` makes `pub/media/catalog/product/cache/...` unwritable
for php-fpm, and on-demand resizes then serve the placeholder. Use `-u root`
only for OS-level work (`apt`, `chown`), never for `bin/magento`. `cron` still
starts as root and its entrypoint re-chowns any root-owned entry in
`pub/media` and `var` to www-data, so a restart of `cron` (or a redeploy)
repairs a slip:

```bash
docker restart <cron-container>
```

`cron` and `static` run the same image if you need to inspect those — same
`docker ps --filter "name=cron"` pattern. A bare exec into those is **root**:
run `bin/magento` in `app`, or pass `-u www-data`.

## Upload files into `magento_media`

`magento_media` is a named volume, physically at
`/var/lib/docker/volumes/carpetavenue-jp-app-fj43zo_magento_media/_data`. You SSH
in as `magento` (not root) and that path is root-owned, so you cannot `scp`
straight into it — stage in your home dir, then `sudo` it in. Zip first: one
fast transfer beats thousands of small files.

```bash
# from the local machine, one shot
cd /Users/shuvo/OrbStack/docker/containers/carpetavenue-jp-app-1/var/www/html/pub/media/catalog/product && \
zip -r /tmp/product-images.zip . && \
scp /tmp/product-images.zip magento@34.14.82.141:/home/magento/media-upload.zip && \
ssh -t magento@34.14.82.141 "mkdir -p /home/magento/media-upload && unzip -o /home/magento/media-upload.zip -d /home/magento/media-upload && sudo cp -r /home/magento/media-upload/. /var/lib/docker/volumes/carpetavenue-jp-app-fj43zo_magento_media/_data/catalog/product/ && sudo chown -R www-data:www-data /var/lib/docker/volumes/carpetavenue-jp-app-fj43zo_magento_media/_data/catalog/product/ && rm -rf /home/magento/media-upload /home/magento/media-upload.zip" && \
rm /tmp/product-images.zip
```

- `ssh -t` forces a pseudo-tty so the sudo password prompt works through the
  chain — you will be asked for the `magento` user's sudo password.
- `cp -r src/. dst/` (dot after `src`) copies *contents*, not a nested folder.
  If your zip has a wrapper folder inside, `cd` into it before zipping.

Follow-ups after a manual upload:

```bash
docker exec <app-container> sh -c "cd /var/www/html && php bin/magento cache:clean full_page block_html"
docker exec <app-container> sh -c "cd /var/www/html && php bin/magento catalog:images:resize"
```

⚠️ A direct filesystem write like this **bypasses Magento's media gallery DB
records**. Fine for a 1:1 replace of existing image files. If new images must
appear in the admin product image picker, use the admin upload UI or the IXPF
Syncer API instead, so the DB stays in sync.

## Do once, right after the first successful deploy

Inside the `app` container:

```bash
# 1. Indexers to schedule mode — cron does incremental reindex instead of
#    reindex-on-save. REQUIRED for production performance.
php bin/magento indexer:set-mode schedule
php bin/magento indexer:reindex          # one full index now

# 2. Confirm cron is running (drives indexers + queue consumers)
php bin/magento cron:install --force 2>/dev/null; tail -n 5 var/log/cron.log

# 3. Sanity
php bin/magento config:show 2>/dev/null | head
php bin/magento deploy:mode:show          # -> production
```

---

# PART 4 — Open items

Status: **NOT DEPLOYED YET.** Target `https://playground.carpetavenue.jp`.

## 1. Media has no running backup ⚠️ do this first

Verified 2026-08-22: `magento_media` holds **27 GB**; host disk 99 GB, 51 % used.
The compose declares an `offen/docker-volume-backup:v2` service but **no backup
container is running** (`docker ps -a` shows none), and it sits on the
`media-backup` profile deliberately — that tool writes a full tar every run and
re-uploads every unchanged byte. Media is the only non-reproducible data in the
stack; `magento_var`, `magento_static` and Valkey data are all regenerable.

The database *is* covered: the shared `mysql` stack dumps `--all-databases`
nightly to GCS with nothing set here (`DOCKER-GLOBAL/README.md` §8).
⚠️ A dump you have never restored is not a backup.

Pick a tier:

- **Tier 1 (single server, recommended):** bind-mount `/opt/carpetavenue-jp/media`
  → `/var/www/html/pub/media` (rw for app/cron, ro for nginx). Visible on disk,
  easy to back up, plus a daily `gsutil -m rsync -r` / `restic` off-box. At
  27 GB this also gets the media off the Docker volume tree, where a stray
  `down -v` destroys it.
- **Tier 2 (multi-server / host-loss-proof):** Magento `remote_storage` → an
  S3-compatible bucket (R2 / MinIO / S3). What Adobe Commerce Cloud does. Needs
  bucket + keys + `remote_storage` config + `bin/magento remote_storage:sync`.

## 2. Performance — largely resolved, these remain

Storefront performance is measured, not guessed: origin renders cached in
**16–18 ms**; uncached 101 ms home, 120 ms product detail, ~200 ms category.
Method, numbers and the optimisations that were measured and **rejected**:
[../docs/performance/README.md](../docs/performance/README.md). All 17 indexers
are on Update by Schedule (verified 2026-08-22); FPC and block cache confirmed
hitting Valkey at 92 % hit rate, zero evictions.

Still open:

- Container resource limits/reservations for `app` and `search` — nothing is
  capped today.
- **Cache warming** after a deploy or invalidation. Matters more than shaving
  the MISS path: uncached renders only happen after invalidation
  ([TROUBLESHOOTING §2.3](TROUBLESHOOTING.md#23-verify-a-fix-actually-worked)).
- `pm.max_children` in `docker/php/www.conf` (currently 50) — size to host RAM
  (`RAM_for_php / ~400MB`). Playground uses 8.
- OpenSearch heap (`-Xms1g -Xmx1g`) vs catalog size.
- Cloudflare: cache rules for static + media at the edge; SSL mode **Full
  (strict)**; the cert-renewal trap before **2026-10-21**; WAF skip for
  `se.playground.carpetavenue.jp` (still 403 at the edge).
- The 5 s `catalog_product_index_price_history` INSERT — enable
  `slow_query_log` with `long_query_time=1`.

## 3. Security & housekeeping

- **Traefik `api.insecure: true`** in `/etc/dokploy/traefik/traefik.yml` exposes
  the dashboard. Lock down or disable.
- Host sysctls, persisted in `/etc/sysctl.d/`: `vm.max_map_count=262144`
  (OpenSearch — done), `vm.overcommit_memory=1` (Valkey warning).
- **Rotate the server root password** — it was pasted into a chat transcript.
- Rotate registry + DB credentials. Keep `MAGE_CRYPT_KEY` stored *with* the DB
  it decrypts; losing it makes encrypted config unreadable.
- **Monitoring/logs.** Dokploy per-service logs + monitoring; FPM slow requests
  and cron output already go to stderr → captured by `docker logs`.
- **Scaling.** Single-app-instance layout. To scale horizontally run multiple
  `app` replicas behind nginx and keep exactly one `cron` — Valkey
  sessions/cache are already shared, so replicas are safe. `upgrade` must run
  once per deploy, never concurrently (the DB setup-lock enforces this).

## Done (so nobody re-does it)

Multi-stage image, 1.59 GB → ~1.1 GB, PHP tuned to Adobe prod values · one image
/ five roles · runtime `env.php` from template, no secrets in the image ·
registry large-push 504 fixed ([TROUBLESHOOTING §4](TROUBLESHOOTING.md#4-registry-push-499--504))
· nginx dynamic fpm resolution, code-less routing, healthcheck · app/upgrade/cron
on `dokploy-network` to reach the shared MySQL · all indexers on Update by
Schedule, FPC verified serving from Valkey · per-domain websites
(`se.playground.carpetavenue.jp` → `ca_se` via `MAGE_RUN_CODE`,
[../docs/runbooks/add-website.md](../docs/runbooks/add-website.md)).
