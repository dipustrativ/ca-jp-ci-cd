# CarpetAvenue Deployment — Backlog

Status: **LIVE** at https://carpient.carpetavenue.net (manual build + Dokploy pull).
Stack proven end-to-end: image build → registry → Dokploy → nginx → php-fpm →
Magento → external MySQL + Valkey + RabbitMQ + OpenSearch. TLS via Traefik.

Deferred work, prioritized. Nothing here blocks the site running today.

---

## 1. Automatic deployment (CI/CD) — deferred

**Now:** manual. Build + push from a machine with the repo, then Dokploy → Redeploy.
```bash
docker buildx build --secret id=composer_auth,src=auth.json \
  --secret id=env_php_scd,src=docker/env.php.scd \
  -f docker/Dockerfile -t registry.carpetavenue.net/carpient:latest --push .
```

**Goal:** push to `main` → auto build → push → Dokploy repull. Pieces already exist:
- `.github/workflows/build-deploy.yml` (workflow)
- `docker/github-runner.compose.yml` (runner as a Dokploy container)

**Blocker hit:** the GitHub PAT couldn't see the org repo (`indexon`) — SSO / org PAT policy.
**Next actions:**
1. Fix the token: Settings → Tokens → **Configure SSO → Authorize** for `indexon`
   (classic PAT), OR use a fine-grained token with resource owner `indexon`,
   repo `carpient`, permission **Administration: R/W**.
2. Deploy `github-runner.compose.yml` in Dokploy with `GH_PAT`.
3. Add GitHub secrets: `REGISTRY_USER/PASS`, `COMPOSER_AUTH`, `ENV_PHP_SCD`,
   `DOKPLOY_WEBHOOK` (app → Deployments → Webhook URL).
Full steps: [CI-CD.md](CI-CD.md).

---

## 2. Media storage & backup — **media has no running backup**

**Now (verified 2026-08-22):** `magento_media` is a **named volume**
(`/var/lib/docker/volumes/carpient-app-fj43zo_magento_media/_data`), populated
with **27 GB**. Host disk is 99 GB, 51 % used.

⚠️ The compose declares an `offen/docker-volume-backup:v2` service, but **no
backup container is running** — `docker ps -a` shows none. Media is the only
non-reproducible data in the stack. Fix this before anything else in this file.

**Then pick a tier:**
- **Tier 1 (single server, recommended):** bind-mount `/opt/carpient/media` →
  `/var/www/html/pub/media` (rw for app/cron, ro for nginx). Visible on disk,
  easy to back up. Plus a daily `restic`/`rsync` off-box.
- **Tier 2 (multi-server / host-loss-proof):** Magento `remote_storage` → an
  S3-compatible bucket (R2 / MinIO / S3). What Adobe Commerce Cloud does. Needs
  bucket + keys + `remote_storage` config + `bin/magento remote_storage:sync`.

At 27 GB, moving to Tier 1 also gets the media off the Docker volume tree, where
it is easy to destroy with a stray `down -v`.

---

## 3. Performance & stability — largely resolved

Storefront performance is now measured, not guessed. Origin renders cached in
**16–18 ms**; uncached 101 ms home, 120 ms product detail, ~200 ms category.
Numbers, method and the optimisations that were measured and *rejected*:
[../docs/performance/README.md](../docs/performance/README.md).

**Done:** all 17 indexers are on **Update by Schedule** (verified 2026-08-22).
FPC and block cache confirmed hitting Valkey — 92 % hit rate, zero evictions.

**Still open:**
- Container resource limits/reservations for `app` and `search` in the compose —
  nothing is capped today.
- Cache warming after a deploy or invalidation. This matters more than shaving
  the MISS path: uncached renders only happen after invalidation.
- `pm.max_children` in `docker/php/www.conf` (currently 50) — size to host RAM
  (`RAM_for_php / ~400MB`).
- OpenSearch heap (`-Xms1g -Xmx1g`) vs catalog size.
- Cloudflare: cache rules for static + media at the edge; SSL mode **Full
  (strict)**; the cert-renewal trap before **2026-10-21**; WAF skip for
  `carpient-se.carpetavenue.net` (still 403 at the edge).
- The 5 s `catalog_product_index_price_history` INSERT; enable `slow_query_log`
  with `long_query_time=1`.

---

## 4. Security & housekeeping — deferred

- **Traefik `api.insecure: true`** in `/etc/dokploy/traefik/traefik.yml` — exposes the
  dashboard. Lock down or disable.
- Host sysctls (persist in `/etc/sysctl.d/`): `vm.max_map_count=262144` (OpenSearch,
  done), `vm.overcommit_memory=1` (Valkey warning).
- **Rotate the server root password** — it was pasted into a chat transcript.
- Rotate registry + DB + `MAGE_CRYPT_KEY` handling; keep `MAGE_CRYPT_KEY` stored with
  the DB it decrypts.
- Backups: external MySQL (DBA) + media (item #2). The only non-reproducible data.

---

## Done (for reference)
- Multi-stage image, slimmed 1.59GB → 1.15GB, PHP tuned to Adobe prod values.
- One image / five roles (app, upgrade, static, cron, nginx).
- Runtime env.php from template (`getenv()`), no secrets in image.
- Registry large-push 504 fixed (Traefik `responseHeaderTimeout`). README §10.
- nginx: dynamic fpm resolution (no boot crash), code-less routing, healthcheck.
- Dokploy: app/upgrade/cron on `dokploy-network` to reach the internal MySQL.
- All indexers on Update by Schedule; FPC verified serving from Valkey.
- Per-domain websites: `carpient-se.carpetavenue.net` → `ca_se` via
  `MAGE_RUN_CODE` ([../docs/runbooks/add-website.md](../docs/runbooks/add-website.md)).
