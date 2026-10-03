-- Anabelka home page builder MVP
-- Idempotent schema + default block seed.

CREATE TABLE IF NOT EXISTS home_page_blocks
(
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    system_key VARCHAR(80) NOT NULL,
    block_type VARCHAR(60) NOT NULL,
    zone VARCHAR(30) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    settings_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_home_page_blocks_system_key (system_key),
    KEY idx_home_page_blocks_zone_order
        (zone, is_active, sort_order, id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO home_page_blocks
(system_key, block_type, zone, is_active, sort_order, settings_json)
VALUES
('hero', 'hero', 'main', 1, -30, '{}'),
('directions', 'directions', 'main', 1, -20, '{}'),
('adult_entry', 'adult_entry', 'main', 1, -10, '{}'),
('product_collection_latest', 'product_collection', 'main', 1, 10,
 '{"source":"latest","limit":8}'),
('useful', 'useful', 'main', 1, 20, '{}'),
('info_row', 'info_row', 'main', 1, 30, '{}'),
('news', 'news', 'right_rail', 1, 10, '{"limit":3}'),
('reviews', 'reviews', 'right_rail', 1, 20, '{"limit":2}'),
('gift_certificate', 'gift_certificate', 'right_rail', 1, 30, '{}');
