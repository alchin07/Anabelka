-- Anabelka category manager schema migration
-- Target: MariaDB 10.4.34 / InnoDB / utf8mb4_unicode_ci
--
-- Run database/preflight/category_manager_preflight.sql first and take a
-- verified backup. Do not run this file while category/product writes are in
-- progress. ALTER TABLE causes implicit commits in MariaDB, so this migration
-- intentionally does not pretend to be wrapped in a transaction.
-- If step 1 already exists or succeeds before step 2 fails, keep the new
-- translation FK in place and run step 2 only after inspecting the schema.
-- Do not blindly rerun the whole file because this is a named, one-time
-- migration. Do not run either ALTER on a database that already passes the
-- postflight below.
--
-- Preconditions expected by this one-time migration:
--   * category_translations contains no orphan category_id values;
--   * category trees contain no cycles or cross-department parent links;
--   * products.department_id matches its category's department_id;
--   * categories.is_adult and idx_categories_tree do not exist yet;
--   * the source FK names are fk_categories_department and
--     fk_categories_parent, matching the approved pre-migration schema;
--   * the replacement *_restrict FK names do not exist yet.

-- 1. Preserve translation ownership. Existing orphan rows make this ALTER fail
--    without deleting or changing any translation data.
ALTER TABLE category_translations
    ADD CONSTRAINT fk_category_translations_category
        FOREIGN KEY (category_id)
        REFERENCES categories (id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT;

-- 2. Add the explicit adult flag and tree index, then replace destructive
--    department/parent delete rules with manager-controlled RESTRICT rules.
--    DEFAULT 0 intentionally classifies every existing category as non-adult;
--    there is no name/slug heuristic backfill.
--    The replacement constraints intentionally use new symbols. MariaDB
--    10.4/InnoDB may reject dropping and recreating a foreign key under the
--    same symbol in one ALTER TABLE with errno 121.
ALTER TABLE categories
    ADD COLUMN is_adult TINYINT(1) NOT NULL DEFAULT 0
        AFTER is_active,
    ADD KEY idx_categories_tree
        (department_id, parent_id, sort_order, id),
    DROP FOREIGN KEY fk_categories_department,
    DROP FOREIGN KEY fk_categories_parent,
    ADD CONSTRAINT fk_categories_department_restrict
        FOREIGN KEY (department_id)
        REFERENCES departments (id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_categories_parent_restrict
        FOREIGN KEY (parent_id)
        REFERENCES categories (id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT;

-- Read-only postflight. These statements are included for manual verification.
SHOW CREATE TABLE categories;
SHOW CREATE TABLE category_translations;
SHOW INDEX FROM categories;

-- Must return exactly the three approved rows:
--   fk_categories_department_restrict: RESTRICT / RESTRICT
--   fk_categories_parent_restrict: RESTRICT / RESTRICT
--   fk_category_translations_category: RESTRICT / CASCADE
SELECT
    kcu.TABLE_NAME,
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
  AND (
      (
          kcu.TABLE_NAME = 'categories'
          AND kcu.CONSTRAINT_NAME IN (
              'fk_categories_department',
              'fk_categories_parent',
              'fk_categories_department_restrict',
              'fk_categories_parent_restrict'
          )
      )
      OR (
          kcu.TABLE_NAME = 'category_translations'
          AND kcu.CONSTRAINT_NAME = 'fk_category_translations_category'
      )
  )
ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME;

SELECT
    COUNT(*) AS adult_category_count
FROM categories
WHERE is_adult <> 0;

SELECT
    p.id AS product_id,
    p.department_id AS product_department_id,
    p.category_id,
    c.department_id AS category_department_id
FROM products p
INNER JOIN categories c
    ON c.id = p.category_id
WHERE p.department_id <> c.department_id;
