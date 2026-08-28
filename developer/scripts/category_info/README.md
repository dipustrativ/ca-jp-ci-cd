# Import per-language category content from carpetavenue → carpient

carpient was cloned from carpetavenue with only store 0 (default scope) data.
Category names, descriptions and meta tags are missing on every store view, so
all 11 languages show the English text. This copies them across.

Store **codes** match between the two sites; store **IDs** and category **IDs**
do not. So the export is keyed on `store_code` + the category name path
(`All Carpets/Oriental Carpets/Nain`) and the importer resolves both locally.

## Rules

- Categories are **never created**. Only existing carpient categories are updated. Prod-only categories — active or not — are skipped and listed.
- Products are out of scope.

The export carries **every** store-scoped category attribute (26 of them on this
data). The importer only writes the translatable ones:

| | attributes | written? |
|---|---|---|
| **content** | `name`, `url_key`, `description`, `short_description`, `meta_title`, `meta_description`, `meta_keywords` | **yes, by default** |
| **reference** | `landing_page`, `custom_design`, `custom_block`, `custom_bottom_block`, `additional_megamenu_data` | **never** — they hold CMS block / page / theme / attribute-option IDs, which point at different rows in the two installs |
| **everything else** | `url_path` (the indexer regenerates it), `image` / `thumbnail` / `mobile_image` (files may not exist here), `is_active`, `include_in_menu`, `display_mode`, `page_layout`, `available_sort_by`, `default_sort_by`, … | only with `--all` |

On a full export that is 3002 rows in, 1463 written. Add `--all` to write the
third group too (2689 rows — the reference ones are still refused). Or name
exactly what you want: `--attributes=name,description`.

## Files

| File | Where |
|---|---|
| `export_store_values.sql` | run on **prod** (phpMyAdmin) |
| `import_store_values.php` | run on **local**, then on **production** |
| `export_category_recon.sql` | optional look at prod before you start |
| `regenerate_category_urls.php` | run on **local** and **production** after importing `url_key` |

---

# PART A — Local (carpient dev)

### A1. Export from prod

phpMyAdmin → Magento DB → **SQL** tab → paste all of `export_store_values.sql`
→ Go → **Export** below the results → format **JSON** → save as
`category_store_values.json`.

JSON, not CSV. Descriptions contain newlines; CSV corrupts them.

### A2. Copy the file in

```bash
cd /Users/shuvo/PROJECTS/STRATIV/INDEXON/CARPETAVENUE/Workspace/CARPIENT
docker exec carpient-phpfpm-1 mkdir -p /var/www/html/var/import
docker cp ~/Downloads/category_store_values.json \
  carpient-phpfpm-1:/var/www/html/var/import/category_store_values.json
./bin/copytocontainer developer
```

### A3. Dry run — writes nothing

```bash
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && \
  php developer/scripts/category_info/import_store_values.php \
  --file=var/import/category_store_values.json" 2>/dev/null
```

Read the report it prints. See **Report sections** below. Continue only if it
looks right.

### A4. Back up, then apply

```bash
docker exec carpient-db-1 mariadb-dump -umagento -pmagento magento \
  catalog_category_entity_varchar catalog_category_entity_text \
  catalog_category_entity_int catalog_category_entity_decimal \
  catalog_category_entity_datetime > ~/pre_category_import.sql

docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && \
  php developer/scripts/category_info/import_store_values.php \
  --file=var/import/category_store_values.json --apply" 2>/dev/null
```

### A5. Rebuild URLs, then flush

**Required if `url_key` was imported.** Magento has no category URL-rewrite
indexer -- `catalog_url_rewrite_category` does not exist. Category rewrites are
built on category *save*, and the importer never saves a category, so without
this step the new keys never reach the storefront.

The script calls Magento's own `CategoryUrlPathGenerator` and
`CategoryUrlRewriteGenerator` -- the same two the save observers call. Dry run
is the default: each category is generated inside its own transaction and rolled
back, so the counts are real and nothing is written. It is a preview, not a
simulation -- for a nested category the parent's new url_path only exists in
memory during a dry run, so deep paths are only guaranteed under `--apply`.

```bash
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && \
  php developer/scripts/category_info/regenerate_category_urls.php" 2>/dev/null   # dry run
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && \
  php developer/scripts/category_info/regenerate_category_urls.php --apply" 2>/dev/null

docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento indexer:reindex catalog_category_product"
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:flush"
```

Products have the same problem and their own script:
`developer/scripts/product_info/regenerate_product_urls.php`.

### A6. Check

```bash
for c in en de fr it; do curl -sk -o /dev/null -w "$c %{http_code}\n" https://carpient.devs/$c/all-carpets; done
```

All 200, and the `/de/` page shows German category names. (Use
`https://carpient.test/` if that is this machine's host.)

**Rollback:**

```bash
docker exec -i carpient-db-1 mariadb -umagento -pmagento magento < ~/pre_category_import.sql
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:flush"
```

---

# PART B — Production

Open a shell in the `app` container: **Dokploy → app → Terminal**, or
`docker compose exec app bash`. Everything below runs from `/var/www/html`.

### B1. Back up first

Your DBA's dump, or:

```bash
mysqldump -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" \
  catalog_category_entity_varchar catalog_category_entity_text \
  catalog_category_entity_int catalog_category_entity_decimal \
  catalog_category_entity_datetime > /tmp/pre_category_import.sql
```

### B2. Get the JSON and the script into the container

The script ships in the image (it is in the repo), so only the JSON needs
moving. In the terminal:

```bash
mkdir -p var/import
cat > var/import/category_store_values.json <<'EOF'
<paste the JSON file contents here>
EOF
```

Check it arrived whole:

```bash
head -c 200 var/import/category_store_values.json; echo; wc -c var/import/category_store_values.json
```

### B3. Dry run

```bash
php developer/scripts/category_info/import_store_values.php \
  --file=var/import/category_store_values.json
```

### B4. Apply

```bash
php developer/scripts/category_info/import_store_values.php \
  --file=var/import/category_store_values.json --apply
```

### B5. Rebuild URLs, then flush

```bash
php developer/scripts/category_info/regenerate_category_urls.php            # dry run
php developer/scripts/category_info/regenerate_category_urls.php --apply

php bin/magento indexer:reindex catalog_category_product
php bin/magento cache:flush
```

Indexers are in `schedule` mode in production, so cron picks the rest up.
There is no `catalog_url_rewrite_category` indexer -- the script above is what
rebuilds category rewrites.

### B6. Check

Open `/de/` and `/fr/` category pages. Names should be in the right language.

**Rollback:**

```bash
mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < /tmp/pre_category_import.sql
php bin/magento cache:flush
```

---

## Report sections

Printed on screen and saved to `var/log/store_value_import_report.txt`.

| Section | Meaning |
|---|---|
| per store | rows written, per store code |
| name paths with no local match | prod category has no carpient twin — skipped. A few is normal |
| store codes with no local store view | `dk`, `jp`, `no`, `ch_de`, `ch_fr` — expected, those views don't exist here |
| attributes not present locally | attribute missing in carpient — create it first if it matters |
| reference attributes | `landing_page`, `custom_design`, `custom_block`, `custom_bottom_block`, `additional_megamenu_data` — never written |
| not translatable content | layout / media / behaviour attributes — skipped unless `--all` |
| CONFLICT | two prod categories share one name path and disagree. First value wins. Empty is normal |
| backend_type differs | prod and local disagree on storage type; local wins |
| ambiguous local name paths | two carpient categories share a full name path. Currently empty |

## Options

```
--dry-run                                      always beats --apply
--stores=de,fr                                 limit to store codes
--attributes=name,url_key,description          limit to attributes
--all                                          also write layout/media/behaviour attributes
--self-test                                    check the script's own logic
```

Re-running is safe — the write is an upsert on
`(entity_id, attribute_id, store_id)`.

## Performance

Nothing here runs at request time.

- **Export SQL** — read-only, runs once. A correlated subquery per row builds the name path; category tables are small (63 categories, a few thousand value rows), so it finishes in well under a second. It does not scale to product-sized tables, which is fine — it is category-only.
- **Import script** — CLI only, one-off. Loads the file into memory (a few thousand rows) and writes in batches of 500. Not on any page render path.
- **Storefront** — unchanged. No new queries, no new blocks, no new indexers.

The only runtime change made alongside this work is in
`app/code/Carpient/Filter/ViewModel/OptionImages.php`: the
`carpient_filter_option_image` query is wrapped in a `TableNotFoundException`
catch so a missing table falls back to swatch images instead of 500-ing every
category page. `try` costs nothing when nothing throws — no extra query, no
extra round trip.
