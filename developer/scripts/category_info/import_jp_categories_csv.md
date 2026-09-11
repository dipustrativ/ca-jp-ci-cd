# Import JP category content from `category_export.csv`

**Use when** you have a CSV export of category content (name, description,
thumbnail, image, url_key) and need to populate the **JP store view (store 1)**
of this install from it.

Script: `developer/scripts/category_info/import_jp_categories_csv.php`

## What it does

For every CSV row:

1. **Matches by name.** The row's `name_store0` is matched against a category's
   **store-0** (`admin`/default) `name` in this DB. Category IDs differ between
   the export and this install, so the match is by NAME, not id — the CSV
   `entity_id` column is kept only for reference.
2. **Updates only — never creates a category.** On a match it writes the target
   store view (default `1` = `jp`), upserting the store-scoped value (creates the
   store-1 value if absent, updates it if present):
   - `name`        ← `name_store17`
   - `description` ← `description_store17`  (only when the CSV cell has one)
   - `image`       ← `image` filename       (only when present)
   - `thumbnail`   ← `thumbnail` filename   (only when present)
   - `url_key`     ← `url_key_store17`      (only when present and valid)
3. **Downloads media.** For `image`/`thumbnail` the CSV holds a bare filename
   (e.g. `Persian.png`). The file is fetched from
   `https://cdn.carpetavenue.net/media/catalog/category/<filename>` into
   `pub/media/catalog/category/<filename>`, and the attribute is set to the bare
   filename. An existing local file is reused unless `--force-download`.
4. **Guards `url_key`.** A key is written only if it is a valid slug
   (`[a-z0-9][a-z0-9_-]*`) and does not duplicate another category's effective
   url_key **under the same parent** — a duplicate would produce clashing URL
   rewrites. Lowest `entity_id` claims a key first; later claimants are skipped
   and reported. A key already effective at the target store is not rewritten.

Writes go straight to the EAV value tables (`insertOnDuplicate` on
`entity_id + attribute_id + store_id`), bypassing the model save.

**`url_key` writes do not regenerate `url_path` or `url_rewrite`.** After
`--apply` with url_key writes > 0, run the sibling script (the script prints the
command when it wrote any):

```bash
php developer/scripts/category_info/regenerate_category_urls.php --stores=jp --apply
php bin/magento cache:flush
```

Pass `--no-url-key` for a content-only run — then a `cache:flush` alone is enough.

## CSV format

Header required, columns in this order (note `thumbnail` comes **before**
`image`, as the export writes it):

```
entity_id, name_store0, name_store17, description_store17, thumbnail, image, url_key_store17
```

Empty cells are the literal token `NULL` (unquoted), as the export produces.
Descriptions are HTML and may span multiple lines — kept intact by the CSV
parser. `entity_id` is ignored for matching (install-specific).

The default CSV path is `developer/scripts/category_info/category_export.csv`,
i.e. the file next to the script — no `docker cp` of the CSV needed once it is in
the repo directory (override with `--csv=`).

## Run it

Runs inside the phpfpm container, from the Magento root. Container names here are
`ca-jp-*`.

### 1. Get the script + CSV into the container

```bash
docker cp developer/scripts/category_info/import_jp_categories_csv.php \
  ca-jp-phpfpm-1:/var/www/html/developer/scripts/category_info/import_jp_categories_csv.php
docker cp developer/scripts/category_info/category_export.csv \
  ca-jp-phpfpm-1:/var/www/html/developer/scripts/category_info/category_export.csv
```

(`../bin/copytocontainer developer` does the same thing when the wrapper works.)

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

Read the report. Continue only if the counts and the SKIPPED / CONFLICT /
COLLISION sections look right (see **Report** below).

### 3. Back up, then apply

```bash
docker exec ca-jp-db-1 sh -c "mariadb-dump -umagento -pmagento magento \
  catalog_category_entity_varchar catalog_category_entity_text \
  catalog_url_rewrite_product_category url_rewrite \
  > /tmp/pre_jp_cat_import.sql"

docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && \
  php developer/scripts/category_info/import_jp_categories_csv.php --apply"
```

### 4. Regenerate URLs, then flush cache

```bash
docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && \
  php developer/scripts/category_info/regenerate_category_urls.php --stores=jp --apply"
docker exec ca-jp-phpfpm-1 sh -c "cd /var/www/html && php bin/magento cache:flush"
```

Skip the regeneration step only if the report shows `url_key 0`.

### 5. Check

```bash
# store-1 names now Japanese
docker exec ca-jp-db-1 mariadb -umagento -pmagento magento -N -B -e "
  SELECT ccev.entity_id, ccev.value
  FROM catalog_category_entity_varchar ccev
  JOIN eav_attribute ea ON ea.attribute_id=ccev.attribute_id AND ea.attribute_code='name'
  JOIN eav_entity_type et ON et.entity_type_id=ea.entity_type_id AND et.entity_type_code='catalog_category'
  WHERE ccev.store_id=1 LIMIT 10;"

# store-1 url_keys
docker exec ca-jp-db-1 mariadb -umagento -pmagento magento -N -B -e "
  SELECT ccev.entity_id, ccev.value
  FROM catalog_category_entity_varchar ccev
  JOIN eav_attribute ea ON ea.attribute_id=ccev.attribute_id AND ea.attribute_code='url_key'
  JOIN eav_entity_type et ON et.entity_type_id=ea.entity_type_id AND et.entity_type_code='catalog_category'
  WHERE ccev.store_id=1 LIMIT 10;"

# media files present
docker exec ca-jp-phpfpm-1 sh -c "ls /var/www/html/pub/media/catalog/category/ | head"
```

Then open a JP category page on the storefront — the name, description,
thumbnail and URL should be the translated / imported values. Resolve the host
from `base_url` first (differs per machine):

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
--csv=PATH          CSV file (default <script dir>/category_export.csv)
--store=ID          target store view id (default 1 = jp)
--apply             actually write + download (default is dry run)
--dry-run           force dry run; always beats --apply
--no-images         skip image/thumbnail entirely
--no-url-key        skip url_key entirely (no rewrite regeneration needed)
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
| url_key already correct | the CSV key is already effective at the target store — no write |
| media downloaded/reused / to fetch | media files handled |
| default names with no local match | prod-only categories — SKIPPED (a set of these is normal) |
| default names matching an ambiguous local name | two local categories share that store-0 name — SKIPPED (currently none; local names are unique) |
| matched Root/Default (level<2) | `Root Catalog` / `Default Category` — SKIPPED, not translated. Also catches the pre-existing junk level-1 categories in this DB (`299 / €1`, `999 / €2`, `999`) left by an older broken import |
| **CONFLICT** | the same default name appears twice in the CSV with different values. They collapse onto the one local category; the first row won, the second was skipped. If the wrong one won, the name needs parent/path disambiguation — name alone can't tell them apart |
| **url_key rejected** | the cell is not a valid slug (`[a-z0-9][a-z0-9_-]*`). This script never invents a slug from a Japanese name — fix the export |
| **url_key COLLISION** | another category under the same parent already holds that key. Writing it would produce clashing rewrites, so the key was left unset |
| media DOWNLOAD/REUSE FAILED | the file could not be fetched; the attribute was left unset rather than pointed at a missing file |
| media URL HEAD status | only with `--check-urls`; HTTP status per media URL |

## Notes

- All five attributes (`name`, `description`, `image`, `thumbnail`, `url_key`)
  are store-scoped in this install (`is_global=0`), so the store-1 value
  overrides the English store-0 value on the JP storefront.
- Nothing here runs at request time. CLI one-off; the CSV is a few hundred rows,
  loaded into memory and written in batches of 500.
- Related: `import_store_values.php` in this directory imports **many** store
  views from a keyed TSV/JSON export; `update_store1_url_keys.php` is the
  url_key-only variant driven by `category_url.csv` (superseded for this export,
  which carries url_key inline); this script is the single-store, CSV-driven
  variant that also downloads media.
