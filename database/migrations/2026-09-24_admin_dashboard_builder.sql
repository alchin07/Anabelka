-- Admin dashboard builder foundation.
-- MariaDB 10.4 / InnoDB / utf8mb4_unicode_ci.
-- This migration creates layout metadata only and does not modify service data.

CREATE TABLE IF NOT EXISTS admin_dashboard_blocks
(
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_dashboard_blocks_order
        (is_active, sort_order, id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_dashboard_links
(
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    block_id INT UNSIGNED NOT NULL,
    service_key VARCHAR(80) NOT NULL,
    label_override VARCHAR(120) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_dashboard_links_block_service
        (block_id, service_key),
    KEY idx_admin_dashboard_links_block_order
        (block_id, is_active, sort_order, id),
    KEY idx_admin_dashboard_links_service
        (service_key),
    CONSTRAINT fk_admin_dashboard_links_block
        FOREIGN KEY (block_id)
        REFERENCES admin_dashboard_blocks(id)
        ON DELETE CASCADE
        ON UPDATE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
