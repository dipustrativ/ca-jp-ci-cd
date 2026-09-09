# CA_JP — shared infrastructure (MySQL · OpenSearch · RabbitMQ)

MySQL, OpenSearch and RabbitMQ are **not** in this repo and **not** in this app's
compose file. They are shared with the CarpetAvenue (carpient) store and live in:

```
Workspace/DOCKER-GLOBAL/          <- compose files, env templates, full guide
Workspace/DOCKER-GLOBAL/README.md <- read this for deploying them, backups, tunnels
```

One MySQL server (a database per app), one broker (a vhost per app), one search
cluster (an index prefix per app). Deploy those three **before** this app's stack;
this stack has no healthcheck gate on them, so a missing one shows up as a runtime
error rather than a failed deploy.

## This application's resources

| Resource | Value |
|---|---|
| Domain | `https://playground.carpetavenue.jp` |
| Database | `carpetavenue_jp` |
| DB user | `root` (as agreed for all apps on this server) |
| RabbitMQ vhost | `/ca_jp` |
| RabbitMQ user | `ca_jp` |
| OpenSearch index prefix | `ca_jp` |
| Valkey cache id_prefix | `cajp_` (carpient is `69d_`) |

Create the database and the vhost once — instructions in
`DOCKER-GLOBAL/README.md` §5. Nothing here needs a redeploy for that.

## Environment (this app's Dokploy stack)

`docker/.env.production.example` in this repo is the authoritative list and is
already filled in with the values above. `DOCKER-GLOBAL/.env.production.ca_jp.example`
holds the same shared-infra keys as a cross-check that both apps agree.

```
DB_HOST=mysql:3306                  # container name, NOT the loopback port 3308
DB_NAME=carpetavenue_jp
DB_USER=root
DB_PASSWORD=<the mysql stack's MYSQL_ROOT_PASSWORD>

RABBITMQ_HOST=rabbitmq
RABBITMQ_PORT=5672
RABBITMQ_USER=ca_jp
RABBITMQ_PASSWORD=<this app's own password>
RABBITMQ_VHOST=/ca_jp

OPENSEARCH_HOST=opensearch
OPENSEARCH_PORT=9200
OPENSEARCH_INDEX_PREFIX=ca_jp
```

Valkey stays local to this app's stack — do not point it at carpient's containers.
If one Valkey is ever shared, the apps must use different db numbers.

`MAGE_CRYPT_KEY` is tied to the database it decrypts. Per app, never shared.

## Index prefix

`env.php.template` reads `OPENSEARCH_INDEX_PREFIX` and applies it in **both**
places Magento reads a prefix from — the `search` connection and the locked
`system/default/catalog/search` block. The second one overrides
`core_config_data`, so missing it leaves the prefix silently unchanged.

The prefix used to be hardcoded `ca_jp_stage`. On an install that already has
`ca_jp_stage_*` indices, switching to `ca_jp` abandons them:

```bash
docker exec carpetavenue-jp-app php bin/magento indexer:reindex
docker exec opensearch curl -s "localhost:9200/_cat/indices?v"
docker exec opensearch curl -XDELETE "localhost:9200/ca_jp_stage_*"
```

**Why it matters:** one cluster with the same prefix in both installs means they
overwrite each other's indices, and each reindex wipes the other store's catalog.

## Verify after deploying

```bash
docker exec carpetavenue-jp-app getent hosts mysql opensearch rabbitmq
docker exec opensearch curl -s localhost:9200/_cat/indices?v      # prefixes must not overlap
docker exec rabbitmq rabbitmqctl list_permissions -p /ca_jp
```
