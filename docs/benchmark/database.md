# Benchmark — Database engine

| | |
|---|---|
| **Date** | 2026-08-21 |
| **Question** | Should local dev stay on MariaDB 11.8, move to MySQL 8.4 LTS, or move to MariaDB 12.3? |
| **Verdict** | 🟢 **Move local dev to MySQL 8.4** — production already runs it, and the `sql_mode` gap is the only real risk found. MariaDB 12.3 is safe but buys nothing. |
| **Method** | Live app, not a synthetic harness. Real dump imported into each engine, `env.php` repointed, Magento driven end to end. |

Re-run the procedure at the bottom after any Magento, PHP, or engine upgrade.

## Test setup

Same Magento install, same PHP 8.5-fpm container, developer mode, `full_page` and
`block_html` cache **off** so every page hit really touches the DB. All three engines run
with `innodb_buffer_pool_size=128M`, binlog off, `innodb_flush_log_at_trx_commit=1`, on
the same Docker host (11.74 GB).

Dataset: 541 tables, ~1.73 M rows, 431 FKs, 108 mview triggers, 1951 products,
29 595 url rewrites, 4 orders, 0 customers.

| | current | candidate A | candidate B |
|---|---|---|---|
| engine | MariaDB **11.8.8** | MySQL **8.4.11** | MariaDB **12.3.2** |
| runs today | local dev (`../compose.versions.yaml`) | **production**, external endpoint (`docker/.env.production.example:24`, `docker/README.md` "Certified stack") | nowhere |
| Magento 2.4.9 support | yes | yes | yes |
| release status | LTS, EOL Jun 2028 | LTS, premier support to Apr 2029 (extended 2032) | LTS, maintenance to Jun 2029 |

Magento's supported-engine list is `SqlVersionProvider.supportedVersionPatterns` in
`vendor/magento/magento2-base/app/etc/di.xml` — `^8\.0\.`, `^8\.4\.`, `^5\.7\.`,
`^10\.[2-6]\.`, `^10\.11\.`, `^11\.[4|8]\.`, `^12\.[2-3]\.`. Anything outside it throws
`Magento\Framework\DB\Adapter\ConnectionException` at boot. All three tested versions match.

## Migration mechanics

One `mariadb-dump` (172 MB) from the current DB, imported **unmodified** into both
candidates.

| | MariaDB 11.8 | MySQL 8.4 | MariaDB 12.3 |
|---|---|---|---|
| import errors | (source) | **0** | **0** |
| import time | — | 65 s | 52 s |
| dump time | 4.7 s | 3.9 s | 4.1 s |
| tables / triggers / FKs after | 541 / 108 / 431 | 541 / 108 / 431 | 541 / 108 / 431 |

No rewriting needed. The schema uses only `utf8mb4_general_ci` (1060 columns, 475 tables)
and `utf8mb4_unicode_ci` (65 tables) — both exist in MySQL 8.4. The dump carries no
`utf8mb3`, no MariaDB `uca1400` collations, and no generated columns, which is what
normally breaks a MariaDB → MySQL move.

> **Correction, 2026-09-03 — that held for the DB dumped here, not for every DB.**
> A `jp_db_backup.sql` taken from an 11.4 server carried 10 tables on
> `utf8mb4_uca1400_ai_ci`, which does not exist in MySQL 8.4, so the import aborts.
> All 10 were `sequence_{order,invoice,shipment,creditmemo,proforma}_{0,1}` — sales
> sequences are the one thing declarative schema does not own, so they are created with
> no `COLLATE` (`vendor/magento/framework/DB/Ddl/Sequence.php:28`) and inherit the
> database default, which MariaDB 11.4+ sets to `uca1400`. One set per store view.
>
> Check a dump before trusting it against MySQL, and repair it in place:
>
> ```bash
> grep -c uca1400 dump.sql   # expect 0
> sed -i '' 's/utf8mb4_uca1400_ai_ci/utf8mb4_general_ci/g' dump.sql
> ```
>
> Safe: those tables hold one `int unsigned AUTO_INCREMENT` column and no character data.

## Functional parity

60-URL crawl: every category, 15 products, CMS pages, sorted / paged / filtered PLPs, SEO
filter paths, search hit + miss, cart, login, register, wishlist, contact.

| check | MariaDB 11.8 | MySQL 8.4 | MariaDB 12.3 |
|---|---|---|---|
| non-2xx | 1 (`/sitemap.xml`, never generated) | same 1 | same 1 |
| `var/log/exception.log` | empty | empty | empty |
| `var/report/` crashes | 0 | 0 | 0 |
| SQL errors (1055 / 1364 / 1292 / SQLSTATE) | none | none | none |
| `setup:db:status` | up to date | up to date | up to date |
| declarative-schema DDL round-trip | pass | pass | pass |
| 108 mview triggers fire | pass | pass | pass |
| `ProductRepository::save()` round-trip | pass | pass | pass |

DDL round-trip = drop `CATALOG_PRODUCT_ENTITY_SKU`, confirm `setup:db:status` reports
drift, confirm `setup:upgrade` recreates it. Trigger test = flip `has_options` on one
product, confirm `catalogsearch_fulltext_cl` and `catalogrule_product_cl` advance.

## Performance

### Page latency (ms, min of 2 interleaved rounds x 9 requests)

| page | MariaDB 11.8 | MySQL 8.4 | MariaDB 12.3 |
|---|---|---|---|
| `/` | 149 | 159 | 156 |
| `/all-carpets` | 191 | 191 | 200 |
| `/all-carpets/oriental-carpets` | 191 | 198 | 190 |
| `/all-carpets/production_type-handmade` | 178 | 177 | 180 |
| PDP | 153 | 152 | 165 |
| `/catalogsearch/result/?q=carpet` | 215 | 217 | 218 |
| **total** | **1077** | **1084** | **1090** |

Within 1.2 % — noise. `dev:query-log` across those six pages recorded **616 queries
totalling 126 ms**: the DB is ~5 % of request time at this catalog size, the other 95 % is
PHP. **Engine choice does not move page speed here.** That conclusion is size-dependent —
re-measure if the catalog grows an order of magnitude.

### Full reindex, 17 indexers (5 runs each)

| | min | median |
|---|---|---|
| MariaDB 11.8 | **30.9 s** | 37.0 s |
| MySQL 8.4 | 37.7 s | 41.8 s |
| MariaDB 12.3 | 33.4 s | **35.0 s** |

MySQL 8.4 is consistently slowest, ~15-20 % on this workload. The only reproducible gap
in the whole benchmark.

### Other timings

- **No-op `setup:upgrade`** (2 runs) — 11.8: 26.1 / 24.9 s · 8.4: 22.9 / 17.4 s ·
  12.3: 21.0 / 18.8 s. MySQL slightly ahead, inside the noise band.
- **Query replay**, 40 heaviest real Magento SELECTs, 5 passes, best of 10 — 11.8: 71 ms ·
  8.4: **58 ms** · 12.3: 65 ms. MySQL wins the round-trip-bound set.
- **Real EAV pivot** (3 attribute tables, full result set) — 163 / 161 / 170 ms. Identical.

One synthetic full-join `GROUP BY` diverged: MariaDB 62 ms vs MySQL 106 ms. `EXPLAIN`
shows MySQL driving from `catalog_product_entity_int` (26 047 rows, `Using temporary`)
where MariaDB drives from `catalog_product_entity` (1951 rows). Not a query Magento
actually runs — but the plan-shape difference is worth remembering if a custom report
query goes slow after a switch.

## Footprint

| | MariaDB 11.8 | MySQL 8.4 | MariaDB 12.3 |
|---|---|---|---|
| container RSS (same 128 M pool) | 408 MB | **924 MB** | 409 MB |
| data + index after fresh import | 543 MB (live, fragmented) | 481 MB | 452 MB |
| datadir on disk | 1.3 G | 1.3 G | 1.2 G |
| docker image | 364 MB | 812 MB | 361 MB |

MySQL 8.4 costs ~500 MB more RSS at identical buffer-pool size. Irrelevant on a dev
machine; relevant when sizing the external production endpoint.

## The one real difference: `sql_mode`

| | value |
|---|---|
| MariaDB 11.8 / 12.3 | `STRICT_TRANS_TABLES, ERROR_FOR_DIVISION_BY_ZERO, NO_AUTO_CREATE_USER, NO_ENGINE_SUBSTITUTION` |
| MySQL 8.4 | `ONLY_FULL_GROUP_BY, STRICT_TRANS_TABLES, NO_ZERO_IN_DATE, NO_ZERO_DATE, ERROR_FOR_DIVISION_BY_ZERO, NO_ENGINE_SUBSTITUTION` |

MySQL adds **`ONLY_FULL_GROUP_BY`**, **`NO_ZERO_IN_DATE`** and **`NO_ZERO_DATE`**. That is
the classic source of "works on dev, 500s on prod" in Magento: a `GROUP BY` missing a
selected column throws SQLSTATE 42000 / 1055, a `'0000-00-00'` insert throws 1292.

Production runs MySQL 8.4; local dev runs MariaDB 11.8. **Every local test today runs on a
laxer `sql_mode` than the code ships against.**

The 60-URL crawl found no violation, but it does **not** cover:

- admin grids,
- IXPF/Syncer REST endpoints (`/ixpfsyncer/...`),
- checkout / order placement,
- proforma + certificate PDF generation and the bulk-PDF actions.

Those are exactly where custom `GROUP BY` and date handling live. **Open risk — not yet
exercised.**

Server default collation also differs (`utf8mb4_0900_ai_ci` vs `utf8mb4_uca1400_ai_ci`),
but it is irrelevant here: Magento pins `SET NAMES utf8mb4 COLLATE utf8mb4_general_ci` per
connection and every table carries an explicit collation.

## MariaDB optimizer flags still earn their keep

`../compose.yaml` passes `--optimizer_use_condition_selectivity=1
--optimizer_switch=rowid_filter=off` to MariaDB. Still measurable on 12.3 — PLP
price-index query, 20x: **68 ms with the flags, 96 ms without**. Keep them on any MariaDB
version. MySQL has no equivalent knob and does not need one.

## Recommendation

**MariaDB 12.3 vs 11.8** — functionally identical, marginally better on reindex and disk,
same memory, longer support runway (Jun 2029 vs Jun 2028). Free and low-risk, but it buys
nothing today and widens the gap to production.

**MySQL 8.4 — the one that matters.** The import is clean, everything passed, the only
costs are ~500 MB RSS and ~18 % on full reindex, both irrelevant on a dev box. Switching
puts dev on the same optimizer *and* the same `sql_mode` as production, so the bugs above
surface locally instead of in production.

Add to `../compose.override.yaml` — the load order documented in `../compose.versions.yaml`
puts `compose.override.yaml` last, so it wins and a `bin/update` of the docker-magento
stack cannot silently revert it:

```yaml
services:
  db:
    image: mysql:8.4
    command:
      --max_allowed_packet=64M
      --log_bin_trust_function_creators=1
```

Then reimport (proven clean above):

```bash
docker compose down db
docker volume rm carpient_dbdata
docker compose up -d db
docker exec -i carpient-db-1 mysql -uroot -pmagento magento < magento.sql
```

**If local dev stays on MariaDB**, close the actual risk instead by forcing production's
strict modes onto the local server. Cheaper than the switch, but leaves the optimizer
difference in place:

```yaml
services:
  db:
    command:
      --max_allowed_packet=64M
      --optimizer_use_condition_selectivity=1
      --optimizer_switch="rowid_filter=off"
      --log_bin_trust_function_creators=1
      --sql_mode=ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
```

## Re-run procedure

Nothing here is destructive to the live DB except step 5, which restores itself. Back up
first anyway.

```bash
# 1. dump the current DB
docker exec carpient-db-1 sh -c 'mariadb-dump -umagento -pmagento --single-transaction \
  --quick --routines --triggers --events --no-tablespaces --hex-blob \
  --default-character-set=utf8mb4 magento' > magento.sql

# 2. stand up the candidate on the same docker network, a free port, matched settings
docker run -d --name carpient-db-mysql84 --network carpient_default \
  -e MYSQL_ROOT_PASSWORD=magento -e MYSQL_DATABASE=magento \
  -e MYSQL_USER=magento -e MYSQL_PASSWORD=magento -p 3316:3306 mysql:8.4 \
  --max_allowed_packet=64M --skip-log-bin --innodb_buffer_pool_size=128M

# 3. import, and count the errors (expect zero)
docker exec -i carpient-db-mysql84 mysql -uroot -pmagento --force magento < magento.sql

# 4. repoint the app: app/etc/env.php -> db.connection.default.host = carpient-db-mysql84
#    php-fpm runs opcache.validate_timestamps=0, so the restart is mandatory
docker restart carpient-phpfpm-1 carpient-phpfpm-xdebug-1
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:flush"

# 5. functional gate — all four must pass
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento setup:db:status"
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento indexer:reindex"
#   crawl every category/product/CMS URL from url_rewrite, assert 200
#   then assert var/log/exception.log and var/report/ are empty

# 6. compare sql_mode against production before trusting the result
docker exec carpient-db-mysql84 mysql -umagento -pmagento -N -B magento -e "select @@sql_mode;"

# 7. restore app/etc/env.php from backup, restart php-fpm, drop the candidate
docker rm -f carpient-db-mysql84
```

## Verification log

| Date | Engines compared | Outcome |
|---|---|---|
| 2026-08-21 | MariaDB 11.8.8 · MySQL 8.4.11 · MariaDB 12.3.2 | All three functionally clean. MySQL 8.4 ~18 % slower on full reindex, +500 MB RSS, stricter `sql_mode`. Recommended local dev move to MySQL 8.4 to match production. Admin / Syncer / checkout / PDF paths **not** yet exercised under `ONLY_FULL_GROUP_BY`. |
