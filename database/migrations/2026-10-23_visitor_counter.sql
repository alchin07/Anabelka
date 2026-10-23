-- VC-01: aggregate storefront page views only; apply once during deployment.
-- Idempotent and compatible with MariaDB 10.4. No runtime schema changes.
CREATE TABLE IF NOT EXISTS site_page_view_daily (
    stat_date DATE NOT NULL,
    page_type VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    view_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (stat_date, page_type, entity_id),
    KEY idx_site_page_view_type_date (page_type, stat_date, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
