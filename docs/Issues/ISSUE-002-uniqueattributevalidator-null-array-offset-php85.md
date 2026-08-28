# ISSUE-002 — `UniqueAttributeValidator` null array offset (PHP 8.5) on product import

| Field | Value |
|---|---|
| **Status** | 🟡 WORKAROUND IN PLACE — data cleanup; core defect unpatched |
| **First observed** | 2026-08-12 |
| **Last re-checked** | 2026-08-12 |
| **Affects** | `magento/product-community-edition` 2.4.9, `magento/module-catalog-import-export` 101.1.9 |
| **Requires** | PHP 8.5+ (not reproducible on PHP ≤ 8.4) — same root family as [ISSUE-001](ISSUE-001-scopecoderesolver-null-array-offset-php85.md) |
| **Origin** | Adobe/Magento core — **not** CarpetAvenue/IXPF/Evalent code |
| **Trigger data** | A `NULL` `value` row in `catalog_product_entity_varchar` for a product attribute with `is_unique=1`. On this install: `ean_barcode` (attribute_id 330). |
| **Fix applied here** | Deleted the stray `NULL` row (data cleanup). Core left unpatched; composer patch is the correct fix but patch tooling is not installed. |
| **Upstream ticket** | None linked. Search `magento/magento2` for `UniqueAttributeValidator` + `null array offset` before each upgrade. |

---

## 1. Symptom

Importing a product CSV that carries a **unique** attribute column (`ean_barcode`) emits:

```text
Deprecated Functionality: Using null as an array offset is deprecated, use an empty string instead in /var/www/html/vendor/magento/module-catalog-import-export/Model/Import/Product/UniqueAttributeValidator.php on line 85
```

Repro: run a product import (`carpient_csv.csv`) while at least one row exists in
`catalog_product_entity_varchar` with `value IS NULL` for a unique attribute.

Per [ISSUE-001 §2.4](ISSUE-001-scopecoderesolver-null-array-offset-php85.md) the
core `ErrorHandler` converts `E_DEPRECATED` into a **thrown `\Exception`** in every
deploy mode (no ini sets `error_reporting`, so PHP's default `E_ALL` applies). During
import this surfaces as a failed uniqueness validation rather than a silent notice.

---

## 2. Root cause (verified against on-disk 2.4.9 source)

`vendor/magento/module-catalog-import-export/Model/Import/Product/UniqueAttributeValidator.php`

`isValid()` caches, per unique attribute, a map of `value => [entity ids]` built by
`load()`:

```php
// isValid() — line 43-46
$cacheKey = strtolower($attributeCode);
if (!isset($this->cache[$cacheKey])) {
    $this->cache[$cacheKey] = $this->load($context, $attributeCode);
}
```

`load()` reads **every** existing row for that attribute out of its backend table and
indexes them by `value`:

```php
// load() — line 82-87
$result = [];
foreach ($connection->fetchAll($select, ['attribute_id' => $attributeObject->getId()]) as $row) {
    $result[$row['value']][] = $row[$idField];   // line 85 — $row['value'] can be NULL
}
return $result;
```

When any existing DB row has `value = NULL`, `$result[null][]` executes `$array[null]`.
That has always silently coerced to `$array[""]`; PHP 8.5 emits `E_DEPRECATED` for it
(see ISSUE-001 §2.3 for the `php -r` proof and why 8.3/8.4 were fine). The read side
never suffers because callers pass a typed `string $value`; only the **load** of pre-existing
NULL rows trips it.

### Why a NULL row existed

A varchar EAV row holding `NULL` is meaningless — an empty attribute should have **no**
row. On this install exactly one such row existed:

```text
value_id=184873  entity_id=2229  sku=HL1184-1  attribute_id=330 (ean_barcode)  store_id=0  value=NULL
```

1 NULL row out of 2208 for attribute 330. Origin is a prior save/import that wrote NULL
instead of skipping the empty value.

---

## 3. Confirmed NOT caused by local code

```console
$ grep -rlE "UniqueAttributeValidator|entity_varchar.*ean_barcode" app/code app/design
(no matches)
```

No CarpetAvenue / IXPF / Evalent module, plugin, or preference touches the import
uniqueness validator. The trigger is pre-existing DB data + core code; local code is
exonerated.

---

## 4. Fix chosen for this project — delete the stray NULL row

The offending row carries no information (empty ean = no row), so deleting it removes the
trigger without side effects. `''` was **not** substituted: for an `is_unique` attribute an
empty-string value becomes a real key, and multiple empty-ean products would then collide as
"duplicate". Absence of a row is the correct state.

```bash
# identify NULL rows for unique varchar attributes (ean_barcode = 330 here)
docker exec carpient-db-1 mariadb -umagento -pmagento magento -N -B -e "
  SELECT v.value_id, e.sku FROM catalog_product_entity_varchar v
  JOIN catalog_product_entity e ON e.entity_id=v.entity_id
  WHERE v.attribute_id=330 AND v.value IS NULL;"

# delete them
docker exec carpient-db-1 mariadb -umagento -pmagento magento -e "
  DELETE FROM catalog_product_entity_varchar WHERE attribute_id=330 AND value IS NULL;"
```

> This silences the deprecation for the read of existing data. It is **not** a permanent fix:
> any future save/import that writes a NULL row for a unique attribute reintroduces the
> trigger. Re-run the query above whenever the deprecation returns. The permanent fix is §5.1.

---

## 5. Alternatives NOT taken (recorded for when the data cleanup stops being enough)

### 5.1 Composer patch — the correct fix

Coerce NULL to `''` at the array write, matching the deprecation's own advice:

```diff
--- a/vendor/magento/module-catalog-import-export/Model/Import/Product/UniqueAttributeValidator.php
+++ b/vendor/magento/module-catalog-import-export/Model/Import/Product/UniqueAttributeValidator.php
@@ -82,7 +82,7 @@
         $result = [];
         foreach ($connection->fetchAll($select, ['attribute_id' => $attributeObject->getId()]) as $row) {
-            $result[$row['value']][] = $row[$idField];
+            $result[$row['value'] ?? ''][] = $row[$idField];
         }
         return $result;
```

Blocked the same way as ISSUE-001 §5.1: `cweagans/composer-patches` is **absent** from
`composer.json` (verified 2026-08-12), so any hand edit to `vendor/` dies on the next
`composer install`. Install the patch tooling first if this is adopted.

### 5.2 DI preference — viable here, unlike ISSUE-001

The bug sits in the **private** `load()`, so a plugin can't reach it; a full-class
`preference` would be required. Unlike ISSUE-001 (where the target was a hot per-request
read path), `UniqueAttributeValidator` runs **only during import**, so an override costs
nothing in steady state. Rejected for now only because the one-row data cleanup (§4) was
sufficient and keeps `vendor` behaviour authoritative. Adopt this if NULL rows keep
reappearing between imports:

```xml
<!-- app/code/<Vendor>/<Module>/etc/di.xml -->
<preference for="Magento\CatalogImportExport\Model\Import\Product\UniqueAttributeValidator"
            type="<Vendor>\<Module>\Model\Import\Product\UniqueAttributeValidator"/>
```

with the subclass reimplementing `load()` using `$row['value'] ?? ''`.

### 5.3 Muting `E_DEPRECATED` — rejected

Same reasoning as ISSUE-001 §5.3: hides every other PHP 8.5 deprecation during the 8.5
migration.

---

## 6. Re-check procedure after a Magento upgrade

```bash
# 1. is the array write still unguarded?
docker exec carpient-phpfpm-1 sh -c \
  "grep -n \"result\[\\\$row\['value'\]\]\[\] =\" /var/www/html/vendor/magento/module-catalog-import-export/Model/Import/Product/UniqueAttributeValidator.php"
#    STILL BROKEN if line reads  $result[$row['value']][] = ...
#    FIXED      if it reads      $result[$row['value'] ?? ''][] = ...  (or a null guard precedes it)

# 2. version check — record in the header table
docker exec carpient-phpfpm-1 sh -c "cd /var/www/html && php -r '\$l=json_decode(file_get_contents(\"composer.lock\"),true);
foreach(\$l[\"packages\"] as \$p){ if(in_array(\$p[\"name\"],[\"magento/product-community-edition\",\"magento/module-catalog-import-export\"])) echo \$p[\"name\"],\" \",\$p[\"version\"],PHP_EOL; }'"

# 3. data check — any NULL rows for unique attributes left?
docker exec carpient-db-1 mariadb -umagento -pmagento magento -N -B -e "
  SELECT a.attribute_code, SUM(v.value IS NULL) AS null_rows
  FROM eav_attribute a
  JOIN catalog_product_entity_varchar v ON v.attribute_id=a.attribute_id
  WHERE a.is_unique=1 GROUP BY a.attribute_code HAVING null_rows>0;"
#    empty result = no trigger present

# 4. functional check — re-run the import; the deprecation must not appear
```

If step 1 shows the coercion/guard landed: flip **Status** to 🟢 FIXED, record the version,
and drop the data-cleanup note from any import runbook.

---

## 7. Verification log

| Date | Magento | PHP | Result |
|---|---|---|---|
| 2026-08-12 | 2.4.9 / catalog-import-export 101.1.9 | 8.5.6 | 🟡 Reproduced on `carpient_csv.csv` import. Root cause confirmed at `UniqueAttributeValidator.php:85`. Local code cleared. 1 stray NULL `ean_barcode` row (sku `HL1184-1`, value_id 184873) deleted → deprecation cleared for existing-data read. Core unpatched. |
