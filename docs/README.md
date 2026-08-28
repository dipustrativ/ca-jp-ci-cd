# CarpetAvenue knowledge base

Start here. Every cross-cutting document in this repo is linked below exactly
once. If something is not in this index, it is not findable — add the row.

## I want to…

| Task | Go to |
|---|---|
| Build the application image | [runbooks/build-and-deploy.md](runbooks/build-and-deploy.md) §1 |
| Deploy to production | [runbooks/build-and-deploy.md](runbooks/build-and-deploy.md) §2 |
| Roll back a bad deploy | [runbooks/build-and-deploy.md](runbooks/build-and-deploy.md) §4 |
| Add a new website (own domain) | [runbooks/add-website.md](runbooks/add-website.md) |
| Add a new store view (`/xx/`) | [runbooks/add-store-view.md](runbooks/add-store-view.md) |
| Work on LESS / theme styles | [runbooks/frontend-less-grunt.md](runbooks/frontend-less-grunt.md) |
| Fix a cache that will not cache | [../docker/CACHE-RUNBOOK.md](../docker/CACHE-RUNBOOK.md) |
| Run a Magento CLI command in production | [../docker/UTILITIES.md](../docker/UTILITIES.md) |
| Work locally (containers, CLI, static, tests) | [../CLAUDE.md](../CLAUDE.md) |
| Understand an error that lives in `vendor/` | [Issues/README.md](Issues/README.md) — check before debugging |
| Know why the site is/isn't fast | [performance/README.md](performance/README.md) |
| Check what version of anything we run | [architecture/stack.md](architecture/stack.md) |

## Chapters

### 1. Runbooks — do a task

Imperative, copy-pasteable, no theory. Each opens with **Use when**.

| Doc | Covers |
|---|---|
| [build-and-deploy.md](runbooks/build-and-deploy.md) | Build both images, tag, push, deploy on Dokploy, verify, roll back |
| [add-website.md](runbooks/add-website.md) | New website on its own domain: nginx map, Dokploy, Cloudflare, base URLs, verification |
| [add-store-view.md](runbooks/add-store-view.md) | New `/xx/` store view: create, locale, static locales, verify |
| [frontend-less-grunt.md](runbooks/frontend-less-grunt.md) | grunt watch with source maps instead of redeploying static content |

### 2. Architecture — how it is built and why

| Doc | Covers |
|---|---|
| [stack.md](architecture/stack.md) | Every version in use — Magento, PHP, services, tooling — and where local dev deliberately differs from production |
| [multistore.md](architecture/multistore.md) | Path-based store views via `web/url/use_store`, the rules that are easy to get wrong, layouts that were tried and rejected, nginx file layout |
| [multistore-setup.php](architecture/multistore-setup.php) | Idempotent script that applies the whole multistore config. Dry-run by default |
| [theme-manual.html](architecture/theme-manual.html) | Theme manual for content editors |
| [../CLAUDE.md](../CLAUDE.md) | Module ecosystem, vendors, DI gotchas, local commands |
| [../docker/README.md](../docker/README.md) | Production image design: one image, five roles; the three deploy paths |
| [../docker/IMAGE-SIZE.md](../docker/IMAGE-SIZE.md) | Where the bytes go and which strips are verified safe |
| [../docker/CI-CD.md](../docker/CI-CD.md) | Self-hosted runner → registry → Dokploy webhook |

### 3. Performance — measurements and decisions

Numbers or it did not happen. Several "obvious" optimisations here were measured
and rejected.

| Doc | Covers |
|---|---|
| [performance/README.md](performance/README.md) | Standing facts, settings deliberately left off, measured non-bottlenecks |
| [performance/2026-08-21-frontend-performance-audit.md](performance/2026-08-21-frontend-performance-audit.md) | Full baseline audit and a production incident post-mortem |
| [performance/capacity-planning.md](performance/capacity-planning.md) | Sizing for 30 k active users, and the order to scale in |
| [benchmark/storefront-response-times.md](benchmark/storefront-response-times.md) | Every page type × 4 languages, cached vs uncached, where the time goes |
| [benchmark/database.md](benchmark/database.md) | MariaDB vs MySQL 8.4, with a re-run procedure |

### 4. Known issues — upstream defects only

Adobe/Magento core, third-party vendor, or PHP defects that affect this install
and cannot be fixed in `app/code`. **Read this chapter before debugging any error
whose stack trace is entirely inside `vendor/`.**

| Doc | Covers |
|---|---|
| [Issues/README.md](Issues/README.md) | Index, status legend, file conventions |

Bugs in CarpetAvenue / IXPF / Evalent code do **not** go here — fix them and let
git history record it.

### 5. Module documentation — lives with the module

| Where | Covers |
|---|---|
| `app/code/<Vendor>/<Module>/README.md` | That module only |
| `app/code/IXPF/Syncer/docs/` | One file per ERP REST endpoint. Read the relevant one before touching an endpoint |
| `app/code/CarpetAvenue/Productify/README.md` | The whole catalog surface: variants, pricing, layered navigation, infinite scroll |

### 6. Not documentation

| File | What it is |
|---|---|
| [../CHANGELOG.md](../CHANGELOG.md) | Release history |
| [../SECURITY.md](../SECURITY.md) | Vulnerability reporting |
| [../docker/BACKLOG.md](../docker/BACKLOG.md) | Infrastructure to-do list, with verified state |

## Rules

Keeping this useful costs almost nothing if these hold.

1. **One home per fact.** Cross-link, never copy. A number repeated in two files
   will disagree within a month.
2. **Every doc is in exactly one chapter and reachable from here in one hop.**
   Either a row in this index, or a row in a chapter index that is itself listed
   here (`Issues/README.md`, `performance/README.md`). New doc → add the row in
   the same commit.
3. **Docs live next to what they describe when that thing is a directory of
   code** — module READMEs in the module, infrastructure docs in `docker/`.
   `docs/` holds only what is cross-cutting. Index both from here.
4. **Runbooks are imperative and complete.** Open with **Use when**, then
   numbered steps that can be pasted without editing anything but placeholders.
   No background — link to the architecture doc for that.
5. **Claims carry evidence.** A performance claim carries its number and the date
   it was measured. A known issue carries the vendor `file:line`. "It is faster"
   with no number gets deleted.
6. **Absolute dates.** "2026-08-22", never "last week".
7. **Delete what stopped being true.** A wrong doc is worse than no doc. When a
   fix lands, update the doc in the same commit — including the ones that recorded
   the wrong theory.
8. **Precise over long.** If a section can lose half its words and still be
   followed exactly, it should.
