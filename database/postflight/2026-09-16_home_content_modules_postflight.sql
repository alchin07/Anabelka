-- Anabelka home content modules: read-only postflight
-- Run this after database/migrations/2026-09-16_home_content_modules.sql.
-- This file is read-only. It verifies the final schema and basic accessibility.

SELECT
    VERSION() AS server_version,
    DATABASE() AS database_name;

-- Must report all_tables_present with 1/1/1.
SELECT
    CASE
        WHEN COUNT(*) = 3 THEN 'all_tables_present'
        ELSE 'incomplete'
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
    ENGINE,
    TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'site_news',
      'site_news_translations',
      'product_reviews'
  )
ORDER BY TABLE_NAME;

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

-- Must include the expected named indexes and keys.
SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
      (TABLE_NAME = 'site_news' AND INDEX_NAME IN (
          'PRIMARY',
          'uq_site_news_slug',
          'idx_site_news_public'
      ))
      OR (TABLE_NAME = 'site_news_translations' AND INDEX_NAME IN (
          'PRIMARY',
          'idx_site_news_translations_language'
      ))
      OR (TABLE_NAME = 'product_reviews' AND INDEX_NAME IN (
          'PRIMARY',
          'uq_product_reviews_product_user',
          'idx_product_reviews_status_created',
          'idx_product_reviews_product'
      ))
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- Must return exactly the four expected foreign keys with these rules:
-- fk_site_news_translations_news: UPDATE RESTRICT / DELETE CASCADE
-- fk_product_reviews_product:     UPDATE RESTRICT / DELETE CASCADE
-- fk_product_reviews_user:        UPDATE RESTRICT / DELETE CASCADE
-- fk_product_reviews_admin:       UPDATE RESTRICT / DELETE SET NULL
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
  AND kcu.CONSTRAINT_NAME IN (
      'fk_site_news_translations_news',
      'fk_product_reviews_product',
      'fk_product_reviews_user',
      'fk_product_reviews_admin'
  )
ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME;

-- Basic read verification. These statements also fail loudly if a table is
-- missing or inaccessible.
SELECT COUNT(*) AS site_news_rows FROM site_news;
SELECT COUNT(*) AS site_news_translation_rows FROM site_news_translations;
SELECT COUNT(*) AS product_review_rows FROM product_reviews;
