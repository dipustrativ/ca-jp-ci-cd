-- Recon before the real export. READ-ONLY.
--
-- phpMyAdmin: pick the Magento DB -> "SQL" tab -> paste ONE query at a time
-- (R1, R2, R3, R4 below) and Go. Results are meant to be read on screen, no
-- export needed. No session variables are used, so nothing breaks if
-- phpMyAdmin splits statements.
--
-- Shell alternative:  mysql -u<user> -p <db> < export_category_recon.sql
--
-- Purpose: see the prod category tree and how much translated content exists,
-- so name-path matching against carpient can be sanity-checked before importing.


-- ==========================================================================
-- R1. Full prod category tree with the store-0 name path the importer matches on.
-- ==========================================================================
SELECT e.entity_id, e.parent_id, e.level, e.path,
       (SELECT GROUP_CONCAT(v2.value ORDER BY e2.level SEPARATOR '/')
        FROM catalog_category_entity e2
        JOIN catalog_category_entity_varchar v2
          ON v2.entity_id = e2.entity_id AND v2.store_id = 0
         AND v2.attribute_id = (SELECT attribute_id FROM eav_attribute
                                WHERE attribute_code = 'name'
                                  AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type
                                                        WHERE entity_type_code = 'catalog_category'))
        WHERE FIND_IN_SET(e2.entity_id, REPLACE(e.path, '/', ',')) > 0 AND e2.level >= 2) AS name_path
FROM catalog_category_entity e
ORDER BY e.path;


-- ==========================================================================
-- R2. Which store views carry category overrides, and how many rows.
--     A store code missing here has nothing to migrate.
-- ==========================================================================
SELECT s.code, a.attribute_code, 'varchar' AS backend_type, COUNT(*) AS rows_ct
FROM catalog_category_entity_varchar v
JOIN eav_attribute a ON a.attribute_id = v.attribute_id
 AND a.entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
JOIN store s ON s.store_id = v.store_id
WHERE v.store_id > 0 GROUP BY s.code, a.attribute_code
UNION ALL
SELECT s.code, a.attribute_code, 'text', COUNT(*)
FROM catalog_category_entity_text t
JOIN eav_attribute a ON a.attribute_id = t.attribute_id
 AND a.entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
JOIN store s ON s.store_id = t.store_id
WHERE t.store_id > 0 GROUP BY s.code, a.attribute_code
UNION ALL
SELECT s.code, a.attribute_code, 'int', COUNT(*)
FROM catalog_category_entity_int i
JOIN eav_attribute a ON a.attribute_id = i.attribute_id
 AND a.entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
JOIN store s ON s.store_id = i.store_id
WHERE i.store_id > 0 GROUP BY s.code, a.attribute_code
UNION ALL
SELECT s.code, a.attribute_code, 'decimal', COUNT(*)
FROM catalog_category_entity_decimal d
JOIN eav_attribute a ON a.attribute_id = d.attribute_id
 AND a.entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
JOIN store s ON s.store_id = d.store_id
WHERE d.store_id > 0 GROUP BY s.code, a.attribute_code
UNION ALL
SELECT s.code, a.attribute_code, 'datetime', COUNT(*)
FROM catalog_category_entity_datetime dt
JOIN eav_attribute a ON a.attribute_id = dt.attribute_id
 AND a.entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
JOIN store s ON s.store_id = dt.store_id
WHERE dt.store_id > 0 GROUP BY s.code, a.attribute_code
ORDER BY 1, 2;


-- ==========================================================================
-- R3. Duplicate name paths on prod. ANY ROW HERE IS A PROBLEM -- that key is
--     not unique and the importer will skip those categories. Expect empty.
-- ==========================================================================
SELECT name_path, COUNT(*) AS dupes FROM (
    SELECT (SELECT GROUP_CONCAT(v2.value ORDER BY e2.level SEPARATOR '/')
            FROM catalog_category_entity e2
            JOIN catalog_category_entity_varchar v2
              ON v2.entity_id = e2.entity_id AND v2.store_id = 0
             AND v2.attribute_id = (SELECT attribute_id FROM eav_attribute
                                    WHERE attribute_code = 'name'
                                      AND entity_type_id = (SELECT entity_type_id FROM eav_entity_type
                                                            WHERE entity_type_code = 'catalog_category'))
            WHERE FIND_IN_SET(e2.entity_id, REPLACE(e.path, '/', ',')) > 0 AND e2.level >= 2) AS name_path
    FROM catalog_category_entity e WHERE e.level >= 2
) x
WHERE name_path IS NOT NULL
GROUP BY name_path HAVING COUNT(*) > 1;


-- ==========================================================================
-- R4. Category attributes holding an ID into another table. These do NOT carry
--     across installs (CMS block ids, theme ids differ) -- the importer skips
--     them unless --include-risky.
-- ==========================================================================
SELECT a.attribute_code, a.frontend_input, a.backend_type, a.source_model
FROM eav_attribute a
WHERE a.entity_type_id = (SELECT entity_type_id FROM eav_entity_type WHERE entity_type_code = 'catalog_category')
  AND (a.attribute_code IN ('landing_page', 'custom_design')
       OR a.source_model LIKE '%Cms%' OR a.source_model LIKE '%Design%');


-- ==========================================================================
-- R5. Store map. Confirms which codes exist on prod (ids will differ from carpient).
-- ==========================================================================
SELECT store_id, code, name, website_id, group_id, is_active FROM store ORDER BY store_id;
