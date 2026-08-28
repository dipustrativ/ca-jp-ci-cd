# CarpetAvenue — Fully automated build & deploy

**Merge to `main` → both images build on Server A → push to the registry →
Dokploy redeploys Server B.** No laptop, no manual step, no GitHub Actions
minutes billed.

Follow the steps in order. Every step is numbered and states what "done" looks
like. Nothing here is optional unless marked so.

Related: `README.md` (deploy guide + env reference), `BACKLOG.md`.

---

## What you get

| Requirement | How |
|---|---|
| Build-based deployment (Docker image) | Images built once, pushed to `registry.carpetavenue.net`, pulled by Server B |
| Build on **our** hardware, not GitHub's | Self-hosted runner on Server A. **Self-hosted runners cost zero Actions minutes** — GitHub only schedules the job |
| Both images | `carpient` (Magento) **and** `carpient-nginx`, every run |
| `latest` **and** version tags | Each image gets `latest` + immutable `v<N>` |
| Automatic | Push to `main` fires it; `workflow_dispatch` for manual runs |

### Flow

```
git push main
   ↓
GitHub schedules the job (no GitHub compute used)
   ↓
Self-hosted runner on SERVER A
   ├── build docker/Dockerfile        → carpient:latest       + carpient:v<N>
   └── build docker/Dockerfile.nginx  → carpient-nginx:latest  + carpient-nginx:v<N>
   ↓
push both to registry.carpetavenue.net  (Server A → its own registry)
   ↓
POST the Dokploy webhook
   ↓
SERVER B pulls :latest and restarts the stack
```

### Machines

| Machine | Runs | Role here |
|---|---|---|
| **Server A** | Dokploy panel, registry, **runner** | Builds and pushes both images |
| **Server B** | Docker / Dokploy agent | Pulls `:latest`, runs the five-role stack |
| **Database** | MySQL 8.4 | untouched |

### Tag scheme

`v<N>` is `github.run_number` — monotonic, never reused, never moved. Both
images share the same `N` because both are built every run.

> This supersedes the "independent sequences" note in `README.md` §1. The nginx
> image is `nginx:1.29-alpine` plus three `COPY`s — seconds to build, negligible
> registry cost — and matching numbers remove the "which nginx pairs with which
> app" guesswork. `carpient:v41` always pairs with `carpient-nginx:v41`.

Re-running a workflow produces a **new** `N`. Tags stay immutable.

---

# PART 1 — Server A: install the runner

A "self-hosted runner" is a small agent that polls GitHub for jobs and runs them
on your hardware.

### 1. Check capacity

```bash
free -g && df -h /var/lib/docker && nproc
```

The Magento build peaks around **~4 GB RAM**, wants 2+ cores, and leaves several
GB of build cache. Server A also runs the registry and the Dokploy control
panel — a build that OOMs can take the panel down with it. Do not proceed
without headroom.

### 2. Get a runner registration token

GitHub → repo **indexon/carpient** → **Settings → Actions → Runners → New
self-hosted runner** → **Linux**.

GitHub shows a download URL and a `./config.sh --token <TOKEN>` line. **The token
expires in one hour** — use it now.

> **No PAT needed.** This is the point of installing natively rather than via
> `docker/github-runner.compose.yml`: the container re-registers on every restart
> and therefore needs a long-lived Personal Access Token, which currently 404s on
> `indexon/carpient` because of the org's SSO policy. A native install writes its
> credentials to disk once and survives restarts. See
> [Appendix C](#appendix-c--container-runner-alternative) if you want the
> container anyway.

### 3. Install it

On Server A, as a user in the `docker` group (verify with `docker ps` — it must
work without `sudo`):

```bash
sudo mkdir -p /opt/actions-runner && sudo chown "$USER" /opt/actions-runner
cd /opt/actions-runner

curl -o actions-runner.tar.gz -L <URL GitHub shows>
tar xzf actions-runner.tar.gz

./config.sh --url https://github.com/indexon/carpient \
  --token <TOKEN from step 2> \
  --name carpient-build-1 \
  --labels carpient-build \
  --unattended
```

The label **`carpient-build`** must match exactly — the workflow targets it with
`runs-on: [self-hosted, carpient-build]`.

### 4. Run it as a service

```bash
sudo ./svc.sh install "$USER"
sudo ./svc.sh start
sudo ./svc.sh status
```

Now it survives reboots.

### 5. Log Server A into the registry

```bash
docker login registry.carpetavenue.net
```

The workflow logs in too, but doing it once by hand proves the credentials and
the network path before a 20-minute build depends on them.

### 6. ✅ Verify

GitHub → **Settings → Actions → Runners** shows **carpient-build-1** as
**● Idle** (green) with the label `carpient-build`.

Not idle → `sudo ./svc.sh status` and `journalctl -u actions.runner.* -n 50`.

---

# PART 2 — GitHub: repository secrets

The workflow reads five secrets. Two of them are files that are **gitignored** —
they are not in the checkout, so this is the only way they reach the build.

### 7. Get the Dokploy webhook URL

Dokploy → your **Compose** app (the one running the Magento stack) →
**Deployments** tab → **Webhook URL** → Copy.

Depending on version it may sit under **General**, or behind **Deploy ▾ → Copy
Webhook URL**.

Test it by hand first — it should trigger a visible deploy in Dokploy:

```bash
curl -fsSL -X POST "<the webhook URL>"
```

### 8. Add the secrets

GitHub → repo → **Settings → Secrets and variables → Actions → New repository
secret**. Add all five:

| Secret | Value |
|---|---|
| `REGISTRY_USER` | registry username (same as `docker login`) |
| `REGISTRY_PASS` | registry password |
| `COMPOSER_AUTH` | the **entire contents** of `auth.json` |
| `ENV_PHP_SCD` | the **entire contents** of `docker/env.php.scd` |
| `DOKPLOY_WEBHOOK` | the URL from step 7 |

Paste the two file secrets whole, newlines and all. GitHub secrets handle
multi-line values natively — no quoting, no base64, no escaping.

Copy them cleanly from your laptop:

```bash
pbcopy < auth.json              # → COMPOSER_AUTH
pbcopy < docker/env.php.scd     # → ENV_PHP_SCD
```

They become BuildKit secrets (`--secret id=composer_auth` /
`id=env_php_scd`), consumed at `docker/Dockerfile:49` and `:94`. They never land
in an image layer.

### 9. ✅ Verify

Settings → Secrets and variables → Actions lists exactly five secrets. Values
are write-only; you cannot read them back, so re-paste if unsure.

---

# PART 3 — First run

### 10. Confirm Dokploy pulls `latest`

Dokploy → Compose app → **Environment**. Both must be `latest` or unset:

```
IMAGE_TAG=latest
NGINX_TAG=latest
```

If either is pinned to a `v<N>`, the webhook will redeploy that pinned tag and
your new build will never go live. Pinning is for rollback only
([Appendix A](#appendix-a--rollback)).

### 11. Trigger it manually

GitHub → **Actions → build-deploy → Run workflow**.

Do not start with a push to `main`. Prove the pipeline on a manual run first.

### 12. Watch it

The job runs on your runner. Expect **10–20 minutes** — the Magento image is
~1.2 GB and the `vendor` + `pub/static` layer is ~800 MB.

Step order: checkout → buildx setup → registry login → Magento image →
nginx image → webhook → summary.

### 13. ✅ Verify the push

The job summary lists both images and both tags. Confirm in the registry:

```bash
curl -su '<user>:<pass>' https://registry.carpetavenue.net/v2/carpient/tags/list
curl -su '<user>:<pass>' https://registry.carpetavenue.net/v2/carpient-nginx/tags/list
```

Both must show `latest` and the new `v<N>`.

### 14. ✅ Verify the deploy

Dokploy → Compose app → **Logs**.

- `upgrade` exits 0 with `Upgrade completed successfully.`
- Storefront renders **with CSS**
- Admin panel renders **with CSS** — proves `--theme Magento/backend` deployed

> The pull on Server B does **not** appear in the GitHub log. A green workflow
> and a briefly unchanged site is normal.

### 15. Go live

Merge to `main`. From here, **merge = deploy**.

Branch flow stays `develop → staging → main`. CI triggers on `main` only.

---

# PART 4 — Cleanup jobs

Two different disks. One is handled; the other is not, and it is the one that
will fill up.

### 16. Build host — enable Docker Cleanup

Dokploy → **Settings → Server → Docker Cleanup.** Daily at `0 0 * * *`; prunes
unused images, stopped containers, unused volumes, and build cache.

> [Issue #3973](https://github.com/Dokploy/dokploy/issues/3973): `docker image prune -a --force`
> was deleting images for Compose services that are not continuously running.
> Fixed in PR #4166 — stay on a recent Dokploy. Your `upgrade` and `static`
> services do exit, but they share the image with the long-running `app`/`cron`,
> so it stays referenced. Low risk here.

### 17. Registry — schedule garbage collection

**Dokploy never touches registry storage.** Every run overwrites `latest` and
`buildcache` on both images, orphaning the previous manifests. At ~1.2 GB a
build this fills the disk silently.

`REGISTRY_STORAGE_DELETE_ENABLED=true` is already set on the registry, which is
the prerequisite. The sweep is separate:

```bash
docker exec <registry-container> \
  registry garbage-collect --delete-untagged /etc/docker/registry/config.yml
```

The config path must match the bind mount in the registry compose
(`/etc/docker/registry/config.yml`), **not** `registry:3`'s default
`/etc/distribution/config.yml`. Dry-run first: drop `--delete-untagged`, add
`--dry-run`.

Wire it up as **Dokploy → Schedule Jobs**, weekly.

> ⚠️ **Never run GC while a build is pushing.** A blob uploaded mid-collect can
> be deleted, silently corrupting that image. Pick an hour you never build, or
> put the registry in read-only mode for the run.

`--delete-untagged` only removes orphaned manifests. Your `v<N>` tags are tagged,
so they are never collected — that is what makes rollback safe.

### 18. Delete the leftovers

If the Dokploy-native build experiment ran, its repository is dead weight:

```
carpient/carpient-build-liah5s     ~1.2 GB, nothing uses it
hello                              test artifact
```

Remove them from the registry, then run step 17.

---

# Appendix A — Rollback

Every run left an immutable `v<N>` for **both** images. Rolling back is a config
change, not a rebuild.

Dokploy → Compose app → **Environment**:

```
IMAGE_TAG=v<previous>
NGINX_TAG=v<previous>
```

→ **Redeploy**.

Because both images share the same `N`, use the same number for both.

Plain server (`README.md` Path A): same variables in `docker/.env`, then
`docker compose pull && docker compose up -d`.

✅ Verify:

```bash
docker inspect <container> --format '{{.Config.Image}}'
```

**Set both back to `latest` afterwards**, or the next CI run will build fine and
deploy nothing.

Find the `v<N>` for a given commit in the workflow run's job summary, or:

```bash
curl -su '<user>:<pass>' https://registry.carpetavenue.net/v2/carpient/tags/list
```

---

# Appendix B — What the workflow does

`.github/workflows/build-deploy.yml`. You should not need to edit it.

| Part | Why |
|---|---|
| `on: push: branches: [main]` | merge = deploy |
| `on: workflow_dispatch` | manual "Run workflow" button |
| `concurrency: cancel-in-progress` | two pushes in a row → only the newer build runs; no registry race |
| `runs-on: [self-hosted, carpient-build]` | pins to your runner. Zero GitHub minutes |
| `VERSION: v${{ github.run_number }}` | the immutable tag, shared by both images |
| `context: .` | repo root **is** the Magento root (`src/`), filtered by `.dockerignore` |
| `secrets:` on the Magento build | `auth.json` + `env.php.scd` as BuildKit secrets, never in a layer |
| `cache-from`/`cache-to … :buildcache` | layer cache in the registry, so a code-only change skips the `vendor` rebuild |
| nginx step has no `secrets:` | `Dockerfile.nginx` needs none |
| `Trigger Dokploy deploy` | POSTs the webhook; Server B pulls |
| `Summary` | writes both tags + commit to the run summary — your rollback lookup |

No `--platform` flag: Server A is amd64 and builds natively. That flag is only
needed when building from an Apple Silicon laptop
([Appendix D](#appendix-d--emergency-manual-build)).

### If you want nginx built only when its config changes

Default is to build it every run. To make it conditional, gate the nginx step:

```yaml
      - name: Did nginx config change?
        id: nginx
        run: |
          git diff --quiet HEAD~1 HEAD -- docker/Dockerfile.nginx docker/nginx nginx.conf \
            && echo "changed=false" >> "$GITHUB_OUTPUT" \
            || echo "changed=true"  >> "$GITHUB_OUTPUT"
```

then add `if: steps.nginx.outputs.changed == 'true'` to the nginx build step.
The cost is that the two images' `v<N>` sequences drift apart again — you go back
to tracking which nginx pairs with which app. Not recommended.

---

# Appendix C — Container runner alternative

`docker/github-runner.compose.yml` runs the runner as a Dokploy container
instead. Dokploy manages restarts and logs, and there is no OS-level install.

**It requires a long-lived PAT** because it re-registers on every start. That PAT
currently 404s on `indexon/carpient` due to org SSO. To use this path, first fix
the token:

- Classic PAT with `repo` scope → **Configure SSO → Authorize `indexon`**, or
- Fine-grained token: resource owner `indexon`, repo `carpient`, permission
  **Administration: Read/Write**

Then Dokploy → **Create → Compose** → paste the file → Environment:
`GH_PAT=<token>` → Deploy.

⚠️ It mounts `/var/run/docker.sock` — root-equivalent access to Server A's
Docker. Fine on your own build host, never on a shared one.

---

# Appendix D — Emergency manual build

When CI is down and you need an image now.

On Server A (native amd64, pushes over the local network):

```bash
cd /opt/carpient-build && git pull       # clone it there first if absent

docker buildx build --progress=plain \
  --secret id=composer_auth,src=auth.json \
  --secret id=env_php_scd,src=docker/env.php.scd \
  -f docker/Dockerfile \
  -t registry.carpetavenue.net/carpient:latest \
  -t registry.carpetavenue.net/carpient:v<N> \
  --push .

docker buildx build -f docker/Dockerfile.nginx \
  -t registry.carpetavenue.net/carpient-nginx:latest \
  -t registry.carpetavenue.net/carpient-nginx:v<N> \
  --push .
```

Pick `<N>` **above** the current `github.run_number` so CI does not later collide
with it. Check both:

```bash
curl -su '<user>:<pass>' https://registry.carpetavenue.net/v2/carpient/tags/list
```

The two secret files are gitignored, so `scp` them up once:

```bash
scp auth.json          <server-a>:/opt/carpient-build/auth.json
scp docker/env.php.scd <server-a>:/opt/carpient-build/docker/env.php.scd
ssh <server-a> 'chmod 600 /opt/carpient-build/auth.json /opt/carpient-build/docker/env.php.scd'
```

Run under `tmux`/`nohup` — 10–20 minutes, and an SSH drop kills it.

From an Apple Silicon laptop, add `--platform linux/amd64` or the servers get an
image they cannot run (`exec format error`).

The build uses the **working tree**, not HEAD. `git status --short` first.

---

# Appendix E — Approaches rejected, and why

Recorded so nobody re-evaluates them from scratch.

| Approach | Why not |
|---|---|
| **Dokploy Application build** | Pushes `:latest` only — the tag is hardcoded as `${appName}:latest` in [`cluster/upload.ts`](https://github.com/Dokploy/dokploy/blob/canary/packages/server/src/utils/cluster/upload.ts). Path is always `<host>/<prefix>/<appName>`, so a flat `…/carpient:tag` is impossible. One Application builds one Dockerfile, so nginx needs a second. No `v<N>` = no rollback. |
| **Dokploy Build Server** | Applications-only ([#4148](https://github.com/Dokploy/dokploy/issues/4148) closed as not planned). Same tag limits. Also pointless here — Dokploy already builds on Server A because that is its own host. |
| **Dokploy Compose build** (`docker-compose.build.yml`) | Builds on the runtime host and never pushes to a registry, so there is no image to roll back to. |
| **Dokploy Server Job** | Correct tags, but cron-only with no documented manual trigger. No git-push trigger. |
| **GitHub-hosted runners** | Bills Actions minutes and would push ~1.2 GB from GitHub's network into your registry. |
| **Building on a laptop** | arm64 cross-build via QEMU, ~1.2 GB pushed over a home link, and the working tree ships instead of the committed code. |

---

# Appendix F — Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Job stuck "Waiting for a runner" | Runner offline, or label mismatch | Step 6; label must be exactly `carpient-build` |
| `permission denied … docker.sock` | Runner user not in `docker` group | `sudo usermod -aG docker $USER`, restart the service |
| `unauthorized` on push | `REGISTRY_USER`/`REGISTRY_PASS` wrong | Step 8; verify with `docker login` on Server A |
| `composer install` auth failure | `COMPOSER_AUTH` truncated or malformed | Re-paste all of `auth.json` |
| SCD step dies with no DB | `ENV_PHP_SCD` truncated | Re-paste all of `docker/env.php.scd` |
| Push 499 / 504 | Traefik timeout on the ~800 MB layer | `README.md` §10 — `responseHeaderTimeout: 900s`, restart Traefik |
| Build OOMs; Dokploy panel dies | ~4 GB peak on a shared Server A | Step 1; add RAM or move the runner |
| Green workflow, site unchanged | `IMAGE_TAG` pinned to an old `v<N>` | Step 10 — set both back to `latest` |
| Green workflow, site unchanged, tags are `latest` | Webhook not firing | Test the URL by hand (step 7) |
| `exec format error` on Server B | arm64 image from a laptop build | Build on Server A, or `--platform linux/amd64` |
| Storefront has no CSS | `static` service or SCD failed | Compose logs for `static` |
| Registry disk full | GC never scheduled | Step 17 |
| Two pushes, only one build ran | `cancel-in-progress` — by design | Re-run manually if you needed both |

---

# Checklist

**Server A**
- [ ] 1 — RAM/disk/cores headroom confirmed
- [ ] 3–4 — runner installed with label `carpient-build`, running as a service
- [ ] 5 — `docker login registry.carpetavenue.net` succeeds
- [ ] 6 — GitHub shows **carpient-build-1 ● Idle**

**GitHub**
- [ ] 7 — webhook URL tested by hand, triggers a Dokploy deploy
- [ ] 8 — all five secrets added
- [ ] 9 — exactly five listed

**First run**
- [ ] 10 — `IMAGE_TAG` and `NGINX_TAG` are `latest`
- [ ] 11–12 — manual `Run workflow` completes green
- [ ] 13 — both repos show `latest` + the new `v<N>`
- [ ] 14 — `upgrade` exits 0; storefront **and** admin render with CSS
- [ ] 15 — merged to `main`, push-triggered run also green

**Ongoing**
- [ ] 16 — Docker Cleanup on
- [ ] 17 — registry GC scheduled, off build hours
- [ ] 18 — leftover repos deleted
- [ ] Rollback rehearsed once (Appendix A) — then both tags set back to `latest`
