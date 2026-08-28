← [Knowledge base index](../README.md)

# Software stack

Verified 2026-08-22 against `composer.lock`, `docker/`, and the running
production containers. Re-verify after any upgrade and update this file.

## Application

| | Version | Source of truth |
|---|---|---|
| Magento | **2.4.9** Community Edition | `magento/product-community-edition` |
| `magento/framework` | 103.0.9 | `composer.lock` |
| PHP (production) | **8.5.9** NTS, 64-bit | `docker/Dockerfile` → `php:8.5-fpm-bookworm` |
| Composer | 2 | `COPY --from=composer:2` |
| Theme | `carpient/rugs` — "Carpient Rugs", parent `Magento/blank` | `app/design/frontend/carpient/rugs/theme.xml` |
| Store views | 12 across 3 websites — `base` (en, de, es, fr, it, hu, nl, pl, pt, fi), `ca_dk` (dk), `ca_se` (se) | `store_website` / `store` |
| Domains | `carpient.carpetavenue.net` (base, path-prefixed), `carpient-se.carpetavenue.net` (`ca_se`) | [multistore.md](multistore.md) |

## Services — production

| Service | Image | Role |
|---|---|---|
| app / upgrade / static / cron | `registry.carpetavenue.net/carpient` | One image, four roles |
| nginx | `registry.carpetavenue.net/carpient-nginx` (`nginx:1.29-alpine`) | Web tier |
| Database | `mysql:8.4` | Shared Dokploy service |
| Search | `opensearchproject/opensearch:3` | Single node, `number_of_replicas=0` |
| Cache | `valkey/valkey:9-alpine` | Default + FPC |
| Sessions | `valkey/valkey:9-alpine` | Separate instance |
| Queue | `rabbitmq:4.2-management-alpine` | Async operations |
| Backup | `offen/docker-volume-backup:v2` | Volume snapshots |

Platform: Dokploy `v0.29.14`, Traefik `v3.6.7`, Dokploy's own `postgres:16`.
Host is GCP, Brussels — the correct location for a European store.

## Services — local dev

The local stack is [markshust/docker-magento](https://github.com/markshust/docker-magento)
in the **parent** directory, containers named `carpient-*`. **It is deliberately
not identical to production** — treat differences as real risk, not noise:

| | Local | Production |
|---|---|---|
| PHP | 8.4 (`markoshust/magento-php:8.4-fpm-2`) | **8.5.9** |
| Database | `mariadb:11.4` | **`mysql:8.4`** |
| Cache / sessions | `valkey:8.1-alpine` | `valkey:9-alpine` |
| nginx | `markoshust/magento-nginx:1.28-0` | `nginx:1.29-alpine` |
| Queue | `magento-rabbitmq:4.2-0` | `rabbitmq:4.2-management-alpine` |
| Mode | developer | production |
| Static content | on demand / grunt | baked into the image |

A PHP 8.5-only defect will not reproduce locally — two of the four open entries
in [../Issues/README.md](../Issues/README.md) are exactly that. The MariaDB ↔
MySQL choice and its `sql_mode` consequence is recorded in
[../benchmark/database.md](../benchmark/database.md).

Also local-only: `carpient-phpmyadmin-1`, `carpient-mailcatcher-1`,
`carpient-phpfpm-xdebug-1`.

## PHP configuration

`docker/php/php.ini`, production:

| Setting | Value | Why |
|---|---|---|
| `memory_limit` | 2048M | |
| `max_execution_time` | 300 | |
| `opcache.memory_consumption` | 512 | |
| `opcache.max_accelerated_files` | 100000 | |
| `opcache.validate_timestamps` | **0** | Edited PHP does not take effect until the container restarts — `cache:flush` is not enough |
| `opcache.enable_cli` | 0 | CLI sees new code while FPM serves the old bytecode |
| `opcache.jit` | **disable** | Measured: JIT made a category render *worse*, 197 → 221 ms. Magento is I/O and cache bound |
| `realpath_cache_size` | 10M, TTL 86400 | |

## Custom code

| Vendor | Modules |
|---|---|
| `CarpetAvenue` | B2B, Core, Developify, Orderify, Productify, Proforma, ThemeContent, Trackify, Utilities, VAT, Widget |
| `Carpient` | Filter, Theme |
| `Evalent` | AdminActivityLog, Banner, CatalogPriceHistory, DynamicSimilarProducts, InvoicePaymentMods, MagicGallery, MatrixRateMods, MegaMenu, ProductCertificate |
| `IXPF` | Syncer, SyncerMsi — ERP integration, endpoints documented in `app/code/IXPF/Syncer/docs/` |
| Third-party | `Mageplaza` (DeliveryTime, GeoIP, Osc), `BelVG` (GuestWishlist) |

What each does: [../../CLAUDE.md](../../CLAUDE.md).

## Dev tooling

`require-dev`, run inside the container. No repo-level `phpstan.neon` or
`phpcs.xml` — pass paths explicitly.

| Tool | Version |
|---|---|
| PHPStan | 1.12.33 |
| PHPUnit | 12.5.31 |
| PHP_CodeSniffer | 3.13.5 + `magento/magento-coding-standard` 40 |
| PHP-CS-Fixer | 3.95.15 — targets `*.phtml` only |
| MFTF | 6.0.1 |
| grunt | 1.6.1 (local only; not installed in the production image) |
