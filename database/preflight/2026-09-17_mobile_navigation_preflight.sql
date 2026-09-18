-- Anabelka mobile navigation — read-only preflight
-- Date: 2026-09-17
-- Safe to run before the one-time migration. This file changes no data/schema.

SELECT
    DATABASE() AS database_name,
    VERSION() AS database_version;

SELECT
    COALESCE(SUM(TABLE_NAME = 'mobile_navigation_items'), 0) AS items_table_exists,
    COALESCE(SUM(TABLE_NAME = 'mobile_navigation_item_translations'), 0) AS translations_table_exists,
    CASE
        WHEN COUNT(*) = 0
            THEN 'not_started'
        WHEN COUNT(*) = 2
            THEN 'tables_present'
        ELSE 'partial'
    END AS migration_state
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'mobile_navigation_items',
      'mobile_navigation_item_translations'
  );

-- Inspect any already-present columns without selecting from possibly absent tables.
SELECT
    TABLE_NAME,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    EXTRA
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'mobile_navigation_items',
      'mobile_navigation_item_translations'
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- Inspect existing indexes/FKs, if any. Empty result sets are expected before migration.
SELECT
    TABLE_NAME,
    INDEX_NAME,
    NON_UNIQUE,
    SEQ_IN_INDEX,
    COLUMN_NAME
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'mobile_navigation_items',
      'mobile_navigation_item_translations'
  )
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME,
    UPDATE_RULE,
    DELETE_RULE
FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND TABLE_NAME = 'mobile_navigation_item_translations';

-- Interpretation:
-- not_started    -> after backup verification, the migration may be applied once.
-- partial        -> STOP. Inspect exactly what exists; do not rerun or drop blindly.
-- tables_present -> STOP. Verify schema with postflight before deciding any action.
