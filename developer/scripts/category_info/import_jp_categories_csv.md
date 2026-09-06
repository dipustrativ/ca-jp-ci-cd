# Import JP category content from `catalog_category_entity.csv`

**Use when** you have a CSV export of category content (name, description, image,
thumbnail) and need to populate the **JP store view (store 1)** of this install
from it.

Script: `developer/scripts/category_info/import_jp_categories_csv.php`

## What it does

For every CSV row:

1. **Matches by name.** The row's `category_name_default` is matched against a
   category's **store-0** (`admin`/default) `name` in this DB. Category IDs
   differ between the export and this install, so the match is by NAME, not id.
2. **Updates only — never creates a category.** On a match it writes the target
   store view (default `1` = `jp`), upserting the store-scoped value (creates the
   store-1 value if absent, updates it if present):
   - `name`        ← `category_name`
   - `description` ← `description`  (only when the CSV cell has one)
   - `image`       ← `image` filename (only when present)
   - `thumbnail`   ← `thumbnail` filename (only when present)
3. **Downloads media.** For `image`/`thumbnail` the CSV holds a bare filename
   (e.g. `Persian.png`). The file is fetched from
   `https://cdn.carpetavenue.net/media/catalog/category/<filename>` into
   `pub/media/catalog/category/<filename>`, and the attribute is set to the bare
   filename. An existing local file is reused unless `--force-download`.

Writes go straight to the EAV value tables (`insertOnDuplicate` on
`entity_id + attribute_id + store_id`), bypassing the model save. No `url_key`
is touched, so no URL-rewrite rebuild is needed — a `cache:flush` is enough.

## CSV format

Header required, columns in this order:

```
category_id, category_path, category_name_default, category_name, description, image, thumbnail
```

Empty cells are the literal token `NULL` (unquoted), as the export produces.
Descriptions are HTML and may span multiple lines — kept intact by the CSV
parser. `category_id` / `category_path` are ignored (install-specific).

## Run it

Runs inside the phpfpm container, from the Magento root. Container names here are
`ca-jp-*`.

### 1. Get the CSV into the container

```bash
docker cp catalog_category_entity.csv \
  ca-jp-phpfpm-1:/var/www/html/catalog_category_entity.csv
```

The script ships in the repo, so it is already in the container after a
`copytocontainer developer` (or `docker cp` of the script).

### 2. Dry run — writes nothing, downloads nothing

```bash
docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && \
  php developer/scripts/category_info/import_jp_categories_csv.php"
```

Add `--check-urls` to HEAD-check every media URL and print its HTTP status —
catch 404s before applying:

```bash
docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && \
  php developer/scripts/category_info/import_jp_categories_csv.php --check-urls"
```

Read the report. Continue only if the counts and the SKIPPED / CONFLICT
sections look right (see **Report** below).

### 3. Back up, then apply

```bash
docker exec ca-jp-db-1 sh -c "mariadb-dump -umagento -pmagento magento \
  catalog_category_entity_varchar catalog_category_entity_text \
  > /tmp/pre_jp_cat_import.sql"

docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && \
  php developer/scripts/category_info/import_jp_categories_csv.php --apply"
```

### 4. Flush cache

```bash
docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:flush"
```

### 5. Check

```bash
# store-1 names now Japanese
docker exec ca-jp-db-1 mariadb -umagento -pmagento magento -N -B -e "
  SELECT ccev.entity_id, ccev.value
  FROM catalog_category_entity_varchar ccev
  JOIN eav_attribute ea ON ea.attribute_id=ccev.attribute_id AND ea.attribute_code='name'
  JOIN eav_entity_type et ON et.entity_type_id=ea.entity_type_id AND et.entity_type_code='catalog_category'
  WHERE ccev.store_id=1 LIMIT 10;"

# media files present
docker exec ca-jp-phpfpm-1 sh -c "ls /var/www/html/pub/media/catalog/category/ | head"
```

Then open a JP category page on the storefront — the name, description and
thumbnail should be the translated / imported values. Resolve the host from
`base_url` first (differs per machine):

```bash
docker exec ca-jp-db-1 mariadb -umagento -pmagento magento -N -B \
  -e "select value from core_config_data where path='web/secure/base_url';"
```

## Rollback

The dump from step 3 lives at `/tmp/pre_jp_cat_import.sql` inside the db
container. Restore it:

```bash
docker exec ca-jp-db-1 sh -c "mariadb -umagento -pmagento magento < /tmp/pre_jp_cat_import.sql"
docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:flush"
```

Downloaded media files stay in `pub/media/catalog/category/` — harmless; delete
by hand if you want them gone. Re-running `--apply` is safe: the write is an
upsert and existing media is reused.

## Options

```
--csv=PATH          CSV file (default <root>/catalog_category_entity.csv)
--store=ID          target store view id (default 1 = jp)
--apply             actually write + download (default is dry run)
--dry-run           force dry run; always beats --apply
--no-images         skip image/thumbnail entirely (only name + description)
--check-urls        in dry run, HEAD every media URL and report its status
--force-download    re-download media even if the file already exists locally
--media-base=URL    CDN prefix (default https://cdn.carpetavenue.net/media/catalog/category/)
--media-dir=PATH    local target dir (default <root>/pub/media/catalog/category)
--self-test         run the pure-helper assertions and exit (no DB)
```

`--dry-run` always beats `--apply`.

## Report

Printed on screen and saved to `var/log/jp_category_csv_import_report.txt`.

| Section | Meaning |
|---|---|
| matched cats | rows whose default name matched a local category |
| Wrote / Would write | attribute value rows, per attribute |
| media downloaded/reused / to fetch | media files handled |
| default names with no local match | prod-only categories — SKIPPED (a set of these is normal) |
| default names matching an ambiguous local name | two local categories share that store-0 name — SKIPPED (currently none; local names are unique) |
| matched Root/Default (level<2) | `Root Catalog` / `Default Category` — SKIPPED, not translated |
| **CONFLICT** | the same default name appears twice in the CSV with different values. They collapse onto the one local category; the first row won, the second was skipped. If the wrong one won, the name needs parent/path disambiguation — name alone can't tell them apart |
| media DOWNLOAD/REUSE FAILED | the file could not be fetched; the attribute was left unset rather than pointed at a missing file |
| media URL HEAD status | only with `--check-urls`; HTTP status per media URL |

## Notes

- All four attributes (`name`, `description`, `image`, `thumbnail`) are
  store-scoped in this install (`is_global=0`), so the store-1 value overrides
  the English store-0 value on the JP storefront.
- Nothing here runs at request time. CLI one-off; the CSV is a few hundred rows,
  loaded into memory and written in batches of 500.
- Related: `import_store_values.php` in this directory imports **many** store
  views from a keyed TSV/JSON export; this script is the single-store, CSV-driven
  variant that also downloads media.
