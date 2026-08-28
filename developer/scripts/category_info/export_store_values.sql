-- Export ALL per-store-view (translated / store-overridden) CATEGORY attribute
-- values from the LIVE carpetavenue DB, for import into carpient by
-- developer/scripts/category_info/import_store_values.php.
--
-- READ-ONLY. Nothing here writes.
--
-- ==========================================================================
-- HOW TO RUN IT -- phpMyAdmin (no server login needed)
-- ==========================================================================
--   1. phpMyAdmin -> pick the Magento database -> "SQL" tab.
--   2. Paste this whole file. Go.
--   3. Under the result grid click "Export".
--   4. Format: JSON.  Do NOT pick CSV -- category descriptions contain
--      newlines and CSV will corrupt them.
--   5. Save as category_store_values.json.
--
-- No SET / session variables are used, so it is a single statement and
-- phpMyAdmin cannot split it in a way that breaks it.
--
-- ==========================================================================
-- HOW TO RUN IT -- shell (alternative)
-- ==========================================================================
--   mysql --batch -u<user> -p <db> < export_store_values.sql > category_store_values.tsv
--
--   --batch, and NOT --raw. --batch escapes newlines/tabs inside values, which
--   the importer reverses. --raw switches that escaping OFF and the multi-line
--   descriptions break apart. The default boxed output is rejected outright.
--
-- ==========================================================================
-- OUTPUT COLUMNS
-- ==========================================================================
--   name_path        store-0 name path, level 2+, e.g. 'All Carpets/Oriental Carpets/Nain'
--   store_code       'de', 'fr', ... -- NOT store_id, ids differ between the two installs
--   attribute_code
--   backend_type     varchar | text | int | decimal | datetime
--   value
--
-- Every attribute with a store_id > 0 row is exported. The importer decides
-- what to write and skips attributes whose value is an ID into another table
-- (landing_page -> CMS block id, custom_design -> theme id); those ids do not
-- carry across installs.
--
-- Store views absent from carpient (dk, jp, no, ch_de, ch_fr) are exported too;
-- the importer skips and reports unknown store codes.
--
-- name_path excludes levels 0-1 (Root Catalog / Default Category) on purpose:
-- root category ids and names differ between installs. Levels 2+ are the
-- shared, meaningful part of the tree.
--   e.path '1/2/3/11/12' -> 'All Carpets/Carpient/Oriental Carpets'

SELECT
    (SELECT GROUP_CONCAT(v2.value ORDER BY e2.level SEPARATOR '/')
     FROM catalog_category_entity e2
     JOIN catalog_category_entity_varchar v2
       ON v2.entity_id = e2.entity_id AND v2.store_id = 0
      AND v2.attribute_id = (SELECT attribute_id FROM eav_attribute
                             WHERE attribute_code = 'name'
                               AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type
                                                     WHERE entity_type_code = 'catalog_category'))
     WHERE FIND_IN_SET(e2.entity_id, REPLACE(e.path, '/', ',')) > 0 AND e2.level >= 2) AS name_path,
    s.code AS store_code, a.attribute_code AS attribute_code,
    'varchar' AS backend_type, v.value AS value
FROM catalog_category_entity_varchar v
JOIN catalog_category_entity e ON e.entity_id = v.entity_id
JOIN eav_attribute a ON a.attribute_id = v.attribute_id
 AND a.entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
JOIN store s ON s.store_id = v.store_id
WHERE v.store_id > 0 AND v.value IS NOT NULL AND v.value <> '' AND e.level >= 2

UNION ALL SELECT
    (SELECT GROUP_CONCAT(v2.value ORDER BY e2.level SEPARATOR '/')
     FROM catalog_category_entity e2
     JOIN catalog_category_entity_varchar v2
       ON v2.entity_id = e2.entity_id AND v2.store_id = 0
      AND v2.attribute_id = (SELECT attribute_id FROM eav_attribute
                             WHERE attribute_code = 'name'
                               AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type
                                                     WHERE entity_type_code = 'catalog_category'))
     WHERE FIND_IN_SET(e2.entity_id, REPLACE(e.path, '/', ',')) > 0 AND e2.level >= 2),
    s.code, a.attribute_code, 'text', t.value
FROM catalog_category_entity_text t
JOIN catalog_category_entity e ON e.entity_id = t.entity_id
JOIN eav_attribute a ON a.attribute_id = t.attribute_id
 AND a.entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
JOIN store s ON s.store_id = t.store_id
WHERE t.store_id > 0 AND t.value IS NOT NULL AND t.value <> '' AND e.level >= 2

UNION ALL SELECT
    (SELECT GROUP_CONCAT(v2.value ORDER BY e2.level SEPARATOR '/')
     FROM catalog_category_entity e2
     JOIN catalog_category_entity_varchar v2
       ON v2.entity_id = e2.entity_id AND v2.store_id = 0
      AND v2.attribute_id = (SELECT attribute_id FROM eav_attribute
                             WHERE attribute_code = 'name'
                               AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type
                                                     WHERE entity_type_code = 'catalog_category'))
     WHERE FIND_IN_SET(e2.entity_id, REPLACE(e.path, '/', ',')) > 0 AND e2.level >= 2),
    s.code, a.attribute_code, 'int', i.value
FROM catalog_category_entity_int i
JOIN catalog_category_entity e ON e.entity_id = i.entity_id
JOIN eav_attribute a ON a.attribute_id = i.attribute_id
 AND a.entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
JOIN store s ON s.store_id = i.store_id
WHERE i.store_id > 0 AND i.value IS NOT NULL AND e.level >= 2

UNION ALL SELECT
    (SELECT GROUP_CONCAT(v2.value ORDER BY e2.level SEPARATOR '/')
     FROM catalog_category_entity e2
     JOIN catalog_category_entity_varchar v2
       ON v2.entity_id = e2.entity_id AND v2.store_id = 0
      AND v2.attribute_id = (SELECT attribute_id FROM eav_attribute
                             WHERE attribute_code = 'name'
                               AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type
                                                     WHERE entity_type_code = 'catalog_category'))
     WHERE FIND_IN_SET(e2.entity_id, REPLACE(e.path, '/', ',')) > 0 AND e2.level >= 2),
    s.code, a.attribute_code, 'decimal', d.value
FROM catalog_category_entity_decimal d
JOIN catalog_category_entity e ON e.entity_id = d.entity_id
JOIN eav_attribute a ON a.attribute_id = d.attribute_id
 AND a.entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
JOIN store s ON s.store_id = d.store_id
WHERE d.store_id > 0 AND d.value IS NOT NULL AND e.level >= 2

UNION ALL SELECT
    (SELECT GROUP_CONCAT(v2.value ORDER BY e2.level SEPARATOR '/')
     FROM catalog_category_entity e2
     JOIN catalog_category_entity_varchar v2
       ON v2.entity_id = e2.entity_id AND v2.store_id = 0
      AND v2.attribute_id = (SELECT attribute_id FROM eav_attribute
                             WHERE attribute_code = 'name'
                               AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type
                                                     WHERE entity_type_code = 'catalog_category'))
     WHERE FIND_IN_SET(e2.entity_id, REPLACE(e.path, '/', ',')) > 0 AND e2.level >= 2),
    s.code, a.attribute_code, 'datetime', dt.value
FROM catalog_category_entity_datetime dt
JOIN catalog_category_entity e ON e.entity_id = dt.entity_id
JOIN eav_attribute a ON a.attribute_id = dt.attribute_id
 AND a.entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
JOIN store s ON s.store_id = dt.store_id
WHERE dt.store_id > 0 AND dt.value IS NOT NULL AND e.level >= 2;
