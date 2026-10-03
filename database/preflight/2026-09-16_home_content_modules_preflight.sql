-- Anabelka home content modules: read-only preflight
-- Target: MariaDB 10.4.x / InnoDB / utf8mb4_unicode_ci
--
-- Run this before database/migrations/2026-09-16_home_content_modules.sql.
-- This file is read-only. It does not create, alter, update, or delete anything.
--
-- Expected state before a first migration run:
--   migration_state = not_started
--   products.id, users.id and admin_users.id exist and are INT UNSIGNED
--   all three target tables are absent
--
-- If migration_state = partial_state, STOP. Do not rerun the whole migration.
-- Inspect the existing target table(s), compare their columns/indexes/FKs with
-- the migration, and apply only the missing CREATE TABLE statement(s) manually
-- after a verified backup.

SELECT
    VERSION() AS server_version,
    DATABASE() AS database_name;

SELECT
    TABLE_NAME,
    ENGINE,
    TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'products',
      'users',
      'admin_users',
      'site_news',
      'site_news_translations',
      'product_reviews'
  )
ORDER BY TABLE_NAME;

SELECT
    CASE
        WHEN COUNT(*) = 0 THEN 'not_started'
        WHEN COUNT(*) = 3 THEN 'all_tables_present'
        ELSE 'partial_state'
    END AS migration_state,
    COALESCE(SUM(TABLE_NAME = 'site_news'), 0) AS site_news_present,
    COALESCE(SUM(TABLE_NAME = 'site_news_translations'), 0)
        AS site_news_translations_present,
    COALESCE(SUM(TABLE_NAME = 'product_reviews'), 0)
        AS product_reviews_present
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'site_news',
      'site_news_translations',
      'product_reviews'
  );

SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_KEY,
    EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      (TABLE_NAME = 'products' AND COLUMN_NAME = 'id')
      OR (TABLE_NAME = 'users' AND COLUMN_NAME = 'id')
      OR (TABLE_NAME = 'admin_users' AND COLUMN_NAME = 'id')
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- Existing target-table structure, if any. Empty result is correct before the
-- first migration run; rows here require inspection before proceeding.
SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_KEY,
    EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'site_news',
      'site_news_translations',
      'product_reviews'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'site_news',
      'site_news_translations',
      'product_reviews'
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

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
  AND kcu.TABLE_NAME IN (
      'site_news_translations',
      'product_reviews'
  )
ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION;
