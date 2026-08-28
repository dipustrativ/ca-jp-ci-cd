# Capacity planning

Sizing for catalog growth. Current production is **3 vCPU / 10 GB / 99 GB disk**
with a 527 MB database and ~2 000 products — comfortably idle (load 0.18).

Two target scenarios:

| | **A** | **B** |
|---|---|---|
| Active products | 30 000 | 30 000 |
| Disabled / total | 50 000 disabled → **80 000 total** | **150 000 total** |
| Store views | 1 | 20 |
| Search index docs | ~30 000 | ~600 000 (30 k × 20) |
| Price index rows | ~30 k × customer groups | ~600 k × customer groups |

Store views are the multiplier that hurts. Magento builds **one OpenSearch index
per store view** and price/category index rows per store. Scenario B is ~20× the
index work of A on the same catalog.

---

## Summary

| Resource | Now | Scenario A | Scenario B |
|---|---|---|---|
| vCPU | 3 | **6** | **12–16** |
| RAM | 10 GB | **16 GB** | **48–64 GB** |
| Disk (SSD/NVMe) | 99 GB | **250 GB** | **500 GB–1 TB** |
| MySQL `innodb_buffer_pool_size` | 128 MB | **6 GB** | **24 GB** |
| OpenSearch heap | 1 GB | **4 GB** | **16 GB** |
| Valkey cache `maxmemory` | 1 GB | **4 GB** | **12 GB** |
| PHP-FPM `pm.max_children` | 20 | **30** | **60** (split across nodes) |
| Media storage | 118 MB | ~15–25 GB | ~30–50 GB |

Scenario B should not be a single box. Split roles: app nodes behind the load
balancer, a dedicated DB host, a dedicated OpenSearch host.

---

## MySQL

Database size scales roughly with **total** products (disabled rows still occupy
EAV tables), while index tables scale with **active × store views**.

| Setting | Now | A | B | Why |
|---|---|---|---|---|
| `innodb_buffer_pool_size` | 128 MB | **6 GB** | **24 GB** | The single most important value. Target ≥ the working set; ~70–75 % of a dedicated DB host's RAM. Estimated DB size: A ~8–12 GB, B ~40–60 GB |
| `innodb_buffer_pool_instances` | 1 | 6 | 24 | 1 per GB of pool, cap ~16 |
| `innodb_log_file_size` | default | 1 GB | 2 GB | Reindex and import are write-heavy; small logs cause constant checkpoint flushing |
| `innodb_flush_log_at_trx_commit` | 1 | 2 | 2 | 2 is safe behind a UPS/managed disk and much faster for bulk index writes |
| `innodb_io_capacity` / `_max` | default | 2000 / 4000 | 4000 / 8000 | NVMe can absorb far more than the 200 default |
| `max_connections` | 151 | 300 | 600 | ≥ `pm.max_children` × app nodes + cron + consumers + headroom |
| `tmp_table_size` / `max_heap_table_size` | default | 256 MB | 512 MB | Layered-nav and report queries build large temp tables |
| `slow_query_log` / `long_query_time` | **OFF / 10** | **ON / 1** | **ON / 0.5** | Currently blind to anything under 10 s. Turn this on before you need it |

**Watch:** `Innodb_buffer_pool_reads / Innodb_buffer_pool_read_requests`. Today
it is 99.97 % hit. If it drops below ~99 %, the pool is undersized.

**Indexers must be `Schedule` (MVIEW)**, never `Save`, at this scale — they
already are. Verify after every upgrade: `bin/magento indexer:status`.

---

## OpenSearch

One index per store view. This is what makes scenario B expensive.

| Setting | Now | A | B |
|---|---|---|---|
| Heap (`OPENSEARCH_JAVA_OPTS`) | 1 GB | **4 GB** | **16 GB** |
| Container `mem_limit` | 1500 m | 8 GB | 32 GB |
| `number_of_replicas` | **0** | 0 | 0 single-node, 1 if you add a second node |
| `number_of_shards` | 1 | 1 | 1 per index (each is small; 20 indices already parallelise) |
| `refresh_interval` | 1 s | 30 s during reindex | 30 s during reindex |

Heap must stay **≤ 50 % of container RAM** and under ~31 GB (compressed-oops
boundary). Leave the rest for the filesystem cache.

Keep `number_of_replicas: 0` on a single node — replicas can never be assigned
and the cluster sits yellow forever. The `magento-single-node` index template
already enforces this; drop it if you add nodes.

---

## PHP-FPM

Sizing rule: `pm.max_children ≈ available RAM for PHP ÷ average process size`.
Measure the real average rather than guessing — Magento is typically 80–150 MB
per worker here.

| Setting | Now | A | B |
|---|---|---|---|
| `pm` | dynamic | `static` | `static` |
| `pm.max_children` | 20 | **30** | **60** across app nodes |
| `pm.max_requests` | 1000 | 1000 | 1000 |
| `memory_limit` | 2048 M | 2048 M | 2048 M (CLI needs it for reindex/import; the web pool could be 768 M) |
| `opcache.memory_consumption` | 512 MB | 512 MB | 768 MB |
| `opcache.max_accelerated_files` | 100 000 | 100 000 | 130 000 |
| `realpath_cache_size` / `_ttl` | 10 M / 86400 | 10 M / 86400 | 16 M / 86400 |

Switch `pm` to `static` once traffic is steady — it removes fork churn. Keep
`opcache.validate_timestamps=0` (already set); it is why a code change needs a
container restart, not just a cache flush.

`opcache.max_accelerated_files` must exceed the real file count. Check with
`opcache_get_status()['opcache_statistics']['num_cached_scripts']` — if it
approaches the limit, raise it.

---

## Valkey

| | Now | A | B |
|---|---|---|---|
| Cache `maxmemory` | 1 GB | **4 GB** | **12 GB** |
| Session `maxmemory` | 256 MB | 1 GB | 4 GB |
| Policy | `allkeys-lru` (cache) / `volatile-lru` (session) | same | same |
| Persistence | none | none | none |

FPC size scales with **cacheable URLs × store views** — categories × filter
combinations × pagination × 20 store views in scenario B is a very large key
space. Watch `evicted_keys`: it is 0 today; any sustained growth means the cache
is thrashing and `maxmemory` is too low.

Keep persistence off. This is a cache, not a datastore — RDB/AOF only adds fork
pauses.

In scenario B, put sessions on a **separate Valkey instance** from the cache
(already the case) so an FPC eviction storm cannot drop carts.

---

## Disk

| | A | B |
|---|---|---|
| Database | 12 GB | 60 GB |
| Media originals + resize cache | 20 GB | 40 GB |
| OpenSearch | 5 GB | 60 GB |
| `var/`, logs, backups | 20 GB | 40 GB |
| **Total with headroom** | **250 GB** | **500 GB–1 TB** |

Media is the sleeper: every product image is stored once as the original plus
one resized derivative **per display area per resize-param set**. Run
`bin/magento catalog:images:resize` after bulk import, and
`CarpetAvenue_Utilities`' orphan-cache cleanup periodically —
`CleanupOrphanCacheService` exists for exactly this.

NVMe, not spinning disk or network storage. InnoDB and Lucene are both
latency-sensitive.

---

## Application settings that matter more at scale

- **Category page size.** Cost is `~110 ms + 2.9 ms × products` (measured). At
  80/page that is ~330 ms uncached. Keep the default at 24–40.
- **Layered navigation** does a facet-count query per filterable attribute — 10
  queries on a plain listing, 23 with one filter applied. Keep the filterable
  attribute count tight; each one is a query on every listing render.
- **Disabled products still cost.** They occupy EAV rows and are filtered at
  query time. 50 000 disabled products inflate the DB and every full reindex.
  Archive rather than disable if they will never be re-enabled.
- **Cache warming** becomes mandatory at scale. Uncached category renders are
  ~200 ms and FPC entries are invalidated on every reindex; without a warmer,
  real users pay that cost after every catalog update.
- **Async indexing / bulk operations** run through RabbitMQ consumers started by
  `cron:run` (`cron_consumers_runner.cron_run = true`, `max_messages = 1000`). At
  scenario B, raise `multiple_processes` and consider running consumers as
  dedicated long-lived containers instead of via cron.

---

## Two gaps in the current deployment

Found 2026-08-21 while auditing. Neither hurts at today's scale; both will at A or B.

**1. `docker/docker-compose.dokploy.yml` in this repo is not what runs.**
Dokploy keeps its own copy at
`/etc/dokploy/compose/carpient-app-fj43zo/code/docker-compose.yml`, pasted through
the UI. They have already drifted:

| | repo | actually deployed |
|---|---|---|
| `OPENSEARCH_JAVA_OPTS` | `-Xms2g -Xmx2g` | `-Xms1g -Xmx1g` |
| `VALKEY_MAXMEMORY` default | `2gb` | `1gb` |

Treat the repo file as documentation, not source of truth, and change resource
settings **in the Dokploy UI**. Same applies to `IMAGE_TAG`: editing
`code/.env` on disk deploys immediately, but Dokploy regenerates that file from
its own environment settings, so the UI value must be updated too or the next
redeploy rolls back.

**2. No memory or CPU limits on any production container.**
`mem_limit=0`, `nanocpus=0` on every service. The box also runs MySQL, Dokploy,
Postgres and Traefik on 3 vCPU / 10 GB, so an OpenSearch or MySQL spike can
starve php-fpm with nothing to stop it. `docker-compose.local.yml` *does* set
limits (app 4 g, search 1500 m, db 4 g) — the deployed stack should too:

```yaml
app:     { mem_limit: 4g }
search:  { mem_limit: 8g }    # heap must stay <= 50% of this
cache:   { mem_limit: 1500m } # maxmemory 1g + overhead
```

## Order of work when you grow

1. Raise `innodb_buffer_pool_size` first — it is the largest single lever and
   costs nothing but RAM.
2. Turn on the slow query log (`long_query_time = 1`) **before** you need it.
3. Raise OpenSearch heap in step with store-view count, not product count.
4. Split MySQL and OpenSearch onto their own hosts before adding app nodes.
5. Add app nodes behind the load balancer last — PHP is the easiest tier to
   scale horizontally and the least likely to be the bottleneck.
