-- Category manager read-only preflight for MariaDB 10.4.x.
-- This file contains SELECT/SHOW statements only. It changes no data/schema.

SELECT VERSION() AS server_version, DATABASE() AS database_name;

SELECT
    TABLE_NAME,
    ENGINE,
    TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'departments',
      'categories',
      'products',
      'category_translations'
  )
ORDER BY TABLE_NAME;

SHOW CREATE TABLE departments;
SHOW CREATE TABLE categories;
SHOW CREATE TABLE products;
SHOW CREATE TABLE category_translations;

-- Must return zero rows. SHOW CREATE TABLE departments should additionally
-- show UNIQUE KEY `slug` (`slug`), which makes canonical department lookup
-- deterministic even under the case-insensitive production collation.
SELECT slug, COUNT(*) AS duplicate_count
FROM departments
GROUP BY slug
HAVING COUNT(*) > 1;

-- Must return zero rows before this migration is applied.
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'categories'
  AND COLUMN_NAME = 'is_adult';

-- Must return zero rows before this one-time migration. A returned row means
-- the migration (or an equivalent manual change) has already been applied.
SELECT
    INDEX_NAME,
    SEQ_IN_INDEX,
    COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'categories'
  AND INDEX_NAME = 'idx_categories_tree'
ORDER BY SEQ_IN_INDEX;

-- Must return zero rows before step 1. This catches both the approved name and
-- an equivalent pre-existing category ownership FK under another name.
SELECT
    kcu.CONSTRAINT_NAME,
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_NAME,
    kcu.REFERENCED_COLUMN_NAME,
    rc.UPDATE_RULE,
    rc.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE kcu
INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
    ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
   AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
   AND rc.TABLE_NAME = kcu.TABLE_NAME
WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
  AND kcu.TABLE_NAME = 'category_translations'
  AND kcu.COLUMN_NAME = 'category_id'
  AND kcu.REFERENCED_TABLE_NAME = 'categories';

-- Must return exactly the two approved current rules before step 2:
-- fk_categories_department/CASCADE and fk_categories_parent/SET NULL.
SELECT
    kcu.CONSTRAINT_NAME,
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_NAME,
    rc.UPDATE_RULE,
    rc.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE kcu
INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
    ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
   AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
   AND rc.TABLE_NAME = kcu.TABLE_NAME
WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
  AND kcu.TABLE_NAME = 'categories'
  AND kcu.CONSTRAINT_NAME IN (
      'fk_categories_department',
      'fk_categories_parent'
  )
ORDER BY kcu.CONSTRAINT_NAME;

-- Must return zero rows: adding the translation FK never deletes orphans.
SELECT
    ct.category_id,
    ct.language_code
FROM category_translations ct
LEFT JOIN categories c
    ON c.id = ct.category_id
WHERE c.id IS NULL
ORDER BY ct.category_id, ct.language_code;

-- Must return zero rows: every child must stay in its parent's department.
SELECT
    child.id AS child_id,
    child.department_id AS child_department_id,
    parent.id AS parent_id,
    parent.department_id AS parent_department_id
FROM categories child
INNER JOIN categories parent
    ON parent.id = child.parent_id
WHERE child.department_id <> parent.department_id
ORDER BY child.id;

-- Must return zero rows: defensive verification of the existing unique key.
SELECT
    department_id,
    slug,
    COUNT(*) AS duplicate_count
FROM categories
GROUP BY department_id, slug
HAVING COUNT(*) > 1;

-- Informational: every returned slug is ambiguous for the legacy
-- /catalog/{slug} address across departments. This is allowed by the schema;
-- the legacy resolver must not guess between multiple visible matches.
SELECT
    slug,
    COUNT(*) AS category_count,
    COUNT(DISTINCT department_id) AS department_count,
    GROUP_CONCAT(
        department_id
        ORDER BY department_id
        SEPARATOR ','
    ) AS department_ids
FROM categories
GROUP BY slug
HAVING COUNT(DISTINCT department_id) > 1
ORDER BY slug;

-- Must return zero rows: slashes cannot be represented as one router segment.
SELECT 'department' AS entity_type, id, slug
FROM departments
WHERE TRIM(slug) = '' OR slug LIKE '%/%'
UNION ALL
SELECT 'category' AS entity_type, id, slug
FROM categories
WHERE TRIM(slug) = '' OR slug LIKE '%/%';

-- Defensive checks for the two declared product-global unique keys.
-- Both queries must return zero rows.
SELECT slug, COUNT(*) AS duplicate_count
FROM products
GROUP BY slug
HAVING COUNT(*) > 1;

SELECT sku, COUNT(*) AS duplicate_count
FROM products
WHERE sku IS NOT NULL
GROUP BY sku
HAVING COUNT(*) > 1;

-- Must return zero rows: product department must match its category.
SELECT
    p.id AS product_id,
    p.department_id AS product_department_id,
    p.category_id,
    c.department_id AS category_department_id
FROM products p
INNER JOIN categories c
    ON c.id = p.category_id
WHERE p.department_id <> c.department_id
ORDER BY p.id;

-- Must return zero rows. The depth cap also exposes suspiciously deep paths.
WITH RECURSIVE category_ancestors AS (
    SELECT
        c.id AS origin_id,
        c.id AS current_id,
        c.parent_id,
        1 AS depth,
        CAST(CONCAT(',', c.id, ',') AS CHAR(4000)) AS visited_path,
        0 AS has_cycle
    FROM categories c

    UNION ALL

    SELECT
        tree.origin_id,
        parent.id AS current_id,
        parent.parent_id,
        tree.depth + 1,
        CONCAT(tree.visited_path, parent.id, ','),
        LOCATE(
            CONCAT(',', parent.id, ','),
            tree.visited_path
        ) > 0
    FROM category_ancestors tree
    INNER JOIN categories parent
        ON parent.id = tree.parent_id
    WHERE tree.has_cycle = 0
      AND tree.depth < 100
)
SELECT DISTINCT
    origin_id,
    depth,
    visited_path,
    CASE
        WHEN has_cycle = 1 THEN 'cycle'
        ELSE 'depth_limit'
    END AS problem
FROM category_ancestors
WHERE has_cycle = 1
   OR (depth = 100 AND parent_id IS NOT NULL)
ORDER BY origin_id;

-- Inventory every FK that points at the affected tables. Review unexpected rows.
SELECT
    kcu.TABLE_NAME,
    kcu.CONSTRAINT_NAME,
    kcu.COLUMN_NAME,
    kcu.REFERENCED_TABLE_NAME,
    kcu.REFERENCED_COLUMN_NAME,
    rc.UPDATE_RULE,
    rc.DELETE_RULE
FROM information_schema.KEY_COLUMN_USAGE kcu
INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
    ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
   AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
   AND rc.TABLE_NAME = kcu.TABLE_NAME
WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
  AND kcu.REFERENCED_TABLE_NAME IN (
      'departments',
      'categories'
  )
ORDER BY
    kcu.REFERENCED_TABLE_NAME,
    kcu.TABLE_NAME,
    kcu.CONSTRAINT_NAME;

-- Inventory triggers because legacy installations may synchronize products.
SELECT
    TRIGGER_NAME,
    EVENT_MANIPULATION,
    EVENT_OBJECT_TABLE,
    ACTION_TIMING,
    ACTION_STATEMENT
FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE()
  AND EVENT_OBJECT_TABLE IN ('categories', 'products')
ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME;

SHOW INDEX FROM categories;
SHOW INDEX FROM products;

SELECT
    c.id,
    c.department_id,
    c.parent_id,
    c.name,
    c.slug,
    c.is_active,
    c.sort_order,
    COUNT(DISTINCT child.id) AS child_count,
    COUNT(DISTINCT p.id) AS product_count,
    COUNT(DISTINCT CONCAT(
        ct.category_id,
        ':',
        ct.language_code
    )) AS translation_count
FROM categories c
LEFT JOIN categories child
    ON child.parent_id = c.id
LEFT JOIN products p
    ON p.category_id = c.id
LEFT JOIN category_translations ct
    ON ct.category_id = c.id
GROUP BY
    c.id,
    c.department_id,
    c.parent_id,
    c.name,
    c.slug,
    c.is_active,
    c.sort_order
ORDER BY c.department_id, c.parent_id, c.sort_order, c.id;
