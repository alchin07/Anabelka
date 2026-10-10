-- Anabelka mobile navigation — one-time manual migration
-- Date: 2026-09-17
-- IMPORTANT:
-- 1. Run the matching preflight first and verify a backup.
-- 2. This script is intentionally not idempotent: rerunning it against
--    existing tables must stop instead of silently overwriting admin data.
-- 3. MariaDB DDL auto-commits. If the first table exists but the second does
--    not, stop and inspect the partial state; do not drop/recreate blindly.
-- 4. This file is manual deployment SQL. Application runtime must not execute it.

CREATE TABLE mobile_navigation_items
(
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name_uk VARCHAR(160) NOT NULL,
    url VARCHAR(1000) NOT NULL,
    is_external TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mobile_navigation_items_order (
        is_active,
        sort_order,
        id
    )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mobile_navigation_item_translations
(
    item_id INT UNSIGNED NOT NULL,
    language_code VARCHAR(10) NOT NULL,
    name VARCHAR(160) NOT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    status VARCHAR(20) NOT NULL DEFAULT 'approved',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id, language_code),
    KEY idx_mobile_navigation_translations_language (language_code),
    CONSTRAINT fk_mobile_navigation_translation_item
        FOREIGN KEY (item_id)
        REFERENCES mobile_navigation_items (id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- Seed rows are a first-install starting point only. After administrators begin
-- editing the menu, row count/content is no longer a deployment invariant.
START TRANSACTION;

INSERT INTO mobile_navigation_items
(
    name_uk,
    url,
    is_external,
    is_active,
    sort_order
)
VALUES
    ('Знижки', '/Anabelka/discounts', 0, 1, 10),
    ('Новинки', '/Anabelka/new', 0, 1, 20),
    ('Доставка, оплата і повернення', '/Anabelka/delivery-payment', 0, 1, 30),
    ('Контакти', '/Anabelka/contacts', 0, 1, 40);

COMMIT;
