# CA_JP — shared infrastructure

MySQL, OpenSearch and RabbitMQ are **not** in this repo and **not** in this app's
compose file. One MySQL (a database per app), one broker (a vhost per app), one
search cluster (an index prefix per app), shared with carpient:

| | |
|---|---|
| Compose files, env templates, full guide | `Workspace/DOCKER-GLOBAL/README.md` |
| Deploy walkthrough (this app is first) | `DOCKER-GLOBAL/START-HERE-CA_JP-FIRST.md` |

Deploy those three **before** this stack. It has no healthcheck gate on them —
they are separate Compose projects — so a missing one deploys green and fails at
runtime.

## This app's resources

| | |
|---|---|
| Domain | `https://playground.carpetavenue.jp` |
| Database | `carpetavenue_jp` |
| DB user | `root` (all apps on this server) |
| RabbitMQ vhost / user | `/ca_jp` / `ca_jp` |
| OpenSearch prefix | `ca_jp` (carpient: `carpient`) |
| Valkey cache prefix | `cajp_` (carpient: `69d_`) |

Database and vhost are created by hand, no redeploy — `DOCKER-GLOBAL/README.md`
§4 and §5.

## Environment

`docker/.env.production.example` is authoritative and already filled in:

```
DB_HOST=mysql:3306          # container name, NOT loopback 3308
DB_NAME=carpetavenue_jp
DB_USER=root
DB_PASSWORD=<mysql stack's MYSQL_ROOT_PASSWORD>
RABBITMQ_HOST=rabbitmq
RABBITMQ_USER=ca_jp
RABBITMQ_VHOST=/ca_jp
OPENSEARCH_HOST=opensearch
OPENSEARCH_INDEX_PREFIX=ca_jp
```

Valkey stays local to this stack — never point it at carpient's containers. If one
Valkey is ever shared, the apps must use different db numbers.

`MAGE_CRYPT_KEY` is tied to the database it decrypts. Per app, never shared.

## Index prefix

`env.php.template` applies `OPENSEARCH_INDEX_PREFIX` in **both** places Magento
reads a prefix — the `search` connection and the locked
`system/default/catalog/search` block. The second overrides `core_config_data`, so
missing it leaves the prefix silently unchanged.

🚨 Same prefix in both installs = they overwrite each other's indices and each
reindex wipes the other store's catalog.

Was hardcoded `ca_jp_stage`. On an install still holding those indices:

```bash
docker exec carpetavenue-jp-app php bin/magento indexer:reindex
docker exec opensearch curl -XDELETE "localhost:9200/ca_jp_stage_*"
```

## Verify after deploying

```bash
docker exec carpetavenue-jp-app getent hosts mysql opensearch rabbitmq
docker exec opensearch curl -s "localhost:9200/_cat/indices?v"     # prefixes must not overlap
docker exec rabbitmq rabbitmqctl list_permissions -p /ca_jp
```
