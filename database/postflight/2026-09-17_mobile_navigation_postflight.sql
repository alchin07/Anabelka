-- Anabelka mobile navigation — read-only postflight
-- Date: 2026-09-17
-- Run manually after the approved migration. This file changes no data/schema.

SELECT
    DATABASE() AS database_name,
    VERSION() AS database_version;

-- Required table presence.
SELECT
    TABLE_NAME,
    ENGINE,
    TABLE_COLLATION
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN (
      'mobile_navigation_items',
      'mobile_navigation_item_translations'
  )
ORDER BY TABLE_NAME;

-- Column/type verification, including unsigned item identifiers.
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

-- Index verification.
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

-- FK verification: expected DELETE CASCADE / UPDATE RESTRICT.
SELECT
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME,
    UPDATE_RULE,
    DELETE_RULE
FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
  AND CONSTRAINT_NAME = 'fk_mobile_navigation_translation_item';

-- First-rollout seed check. After administrators edit/delete items this result is
-- informational only and must not be treated as an invariant for later deploys.
SELECT
    id,
    name_uk,
    url,
    is_external,
    is_active,
    sort_order
FROM mobile_navigation_items
ORDER BY sort_order, id;

SELECT
    SUM(url = '/Anabelka/discounts') AS discounts_seed,
    SUM(url = '/Anabelka/new') AS new_seed,
    SUM(url = '/Anabelka/delivery-payment') AS delivery_payment_seed,
    SUM(url = '/Anabelka/contacts') AS contacts_seed,
    COUNT(*) AS total_items
FROM mobile_navigation_items;

-- No orphan translation rows should exist.
SELECT COUNT(*) AS orphan_translation_count
FROM mobile_navigation_item_translations AS t
LEFT JOIN mobile_navigation_items AS i
    ON i.id = t.item_id
WHERE i.id IS NULL;

-- Translation rows are expected to be empty immediately after first migration;
-- RU/EN and future active-language rows are created through the translation workflow.
SELECT
    item_id,
    language_code,
    name,
    source,
    status
FROM mobile_navigation_item_translations
ORDER BY item_id, language_code;
