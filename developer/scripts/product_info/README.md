# Rebuild product URLs

## Why

This install has **no URL-rewrite indexer** — neither `catalog_url_rewrite_product`
nor `catalog_url_rewrite_category` appears in `bin/magento indexer:status`.
Product rewrites are built when a product is *saved*. Anything that writes
`url_key` without a save — a raw SQL import, a DB migration, a bulk API update —
leaves `url_rewrite` pointing at the old path.

Categories have the same problem and their own script:
`developer/scripts/category_info/regenerate_category_urls.php`.

## How it works

It calls the one core service Magento's own save observer
(`ProductProcessUrlRewriteSavingObserver`) calls, once per product:

```php
AppendUrlRewritesToProducts::execute([$product], $storeIds);
```

That service reads each store's `url_key` override, regenerates `url_path`, runs
`ProductUrlRewriteGenerator` and persists through `UrlPersistInterface::replace()`.
Visibility rules, rewrite history (`catalog/seo/save_rewrites_history`) and the
category-path setting (`catalog/seo/generate_category_product_rewrites`) are
handled by core, not by this script. There is no custom URL logic here.

`seo_category_id` is not consulted. Rewrites come from `url_key` only.

## Run it

Dry run is the default: **each product** is generated inside its own
transaction and rolled back, so the report shows real numbers and nothing is
written.

Per product, not per run — `UrlPersist::replace()` opens its own transaction,
and Magento's adapter poisons an outer one as soon as an inner rollback happens
(a URL clash, a deadlock retry). With one transaction around the whole run, the
first bad product would fail every product after it. Per-product scope keeps a
failure local: it is reported and the run carries on.

**Local:**

```bash
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && \
  php developer/scripts/product_info/regenerate_product_urls.php" 2>/dev/null

docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && \
  php developer/scripts/product_info/regenerate_product_urls.php --apply" 2>/dev/null

docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:flush"
```

**Production** (Dokploy → app → Terminal, from `/var/www/html`):

```bash
php developer/scripts/product_info/regenerate_product_urls.php
php developer/scripts/product_info/regenerate_product_urls.php --apply
php bin/magento cache:flush
```

Back up first — this rewrites rows:

```bash
docker exec carpient-db-1 mariadb-dump -umagento -pmagento magento url_rewrite > ~/pre_product_urls.sql
```

**Run it in a maintenance window.** One product load and one transaction per
product, ~2k products, a few minutes.

## Report

```
products: 2229 processed, 1360 changed, 0 failed, 2229 total

  store    rewrites
  en       2229
  de       2229
```

`changed` counts products whose `url_rewrite` rows are not byte-identical
before and after — re-running turns that to 0. `rewrites` is how many rows each
store ends up with. Failures (no url_key, duplicate path) are listed underneath
with the product id and core's own error message.

**One caveat:** a product core gives no rewrite at all (Not Visible
Individually) keeps whatever rows it already has. Core deletes those on save;
this does not. Nothing links to them.

## Options

```
--dry-run                always beats --apply
--skus=EPR1006,EPR1005   limit to products
```

Safe to re-run: `replace()` is idempotent.

## Verify

Zero drift between `url_rewrite` and `url_key` in every store:

```bash
docker exec carpient-db-1 mariadb -umagento -pmagento magento -e "
SET @pt=(SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code='catalog_product');
SET @uk=(SELECT attribute_id FROM eav_attribute WHERE attribute_code='url_key' AND entity_type_id=@pt);
SELECT r.store_id, COUNT(*) rewrites,
  SUM(CASE WHEN r.request_path <> COALESCE(sv.value,dv.value) THEN 1 ELSE 0 END) out_of_sync
FROM url_rewrite r
LEFT JOIN catalog_product_entity_varchar sv ON sv.entity_id=r.entity_id AND sv.attribute_id=@uk AND sv.store_id=r.store_id
LEFT JOIN catalog_product_entity_varchar dv ON dv.entity_id=r.entity_id AND dv.attribute_id=@uk AND dv.store_id=0
WHERE r.entity_type='product' AND r.redirect_type=0
GROUP BY r.store_id ORDER BY r.store_id;"
```

`out_of_sync` must be 0 for every store. Then open a translated product URL and
confirm the old one 301s to it.

## Rollback

```bash
docker exec -i carpient-db-1 mariadb -umagento -pmagento magento < ~/pre_product_urls.sql
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:flush"
```
