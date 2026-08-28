# Add a store view

**Use when** adding a language on a `/xx/` path of an existing domain. Cart,
customer accounts and base currency stay shared. If it needs its own domain,
cart or currency, it is a website — [add-website.md](add-website.md).

No webserver configuration is involved. Why it works this way:
[../architecture/multistore.md](../architecture/multistore.md).

## 1. Create it

Admin → Stores → All Stores → **Create Store View**: code, website, store group,
Enabled. Magento core has no `store:create` CLI.

Codes must not collide with a Magento front name (`admin`, `setup`, `static`,
`media`, `pub`, `errors`, `rest`, `graphql`, `soap`, `health_check`) or any
root-level category `url_key`. Store code ≠ locale — Sweden is code `se`, locale
`sv_SE`.

## 2. Set the locale

```bash
bin/magento config:set --scope=stores --scope-code=<code> general/locale/code <locale>
bin/magento cache:flush
```

A language pack is a separate `composer require`. Setting the locale alone
changes date and number formatting but does not translate the frontend.

## 3. Audit — no URL row may exist at store scope

```bash
bin/mysql -e "SELECT scope,scope_id,path,value FROM core_config_data \
  WHERE scope='stores' AND path LIKE 'web/%url%';"     # expect zero rows
```

Any row here produces doubled prefixes like `/de/de/`. `base_link_url` is a
separate config path from `base_url` and is the one usually missed.

## 4. Bake the locale into the image — production only

`docker/Dockerfile`:

```dockerfile
ARG STATIC_LOCALES="en_US de_DE sv_SE"
```

Every locale used by an active store view must be listed, then the application
image rebuilt and redeployed ([build-and-deploy.md](build-and-deploy.md)).
Production mode does not build static content on demand, and running
`setup:static-content:deploy` inside a live container does not reach the shared
volume. A missing locale is a broken storefront for that store view.

## 5. Verify

```bash
for u in "" en/ de/ <code>/ <code>/all-carpets admin; do
  printf "%-24s " "/$u"
  curl -sk -o /tmp/o.html -w "HTTP %{http_code}  " "https://<host>/$u"
  grep -o 'lang="[a-zA-Z-]*"' /tmp/o.html | head -1
done

curl -sk https://<host>/<code>/ | grep -c '<host>/<code>/<code>/'   # 0 — doubled prefix
curl -sk https://<host>/<code>/ | grep -c '<host>/<code>/static/'   # 0 — static must stay at root
```

Flush the cache before trusting the store switcher — its links live in
`block_html` and the FPC, and stale entries look exactly like a config bug.

Each store view has its own translated URL keys, so an English path under `/de/`
correctly 404s. That is not a routing bug.
