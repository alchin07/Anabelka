-- VIP price watermark / view journal
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS vip_price_view_log
(
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    rank_id INT UNSIGNED NOT NULL,
    price_amount DECIMAL(12,2) NOT NULL,
    surface VARCHAR(40) NOT NULL,
    view_code CHAR(6) NOT NULL,
    session_hash CHAR(64) NULL,
    viewed_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vip_price_user_date (user_id, viewed_at),
    KEY idx_vip_price_product_date (product_id, viewed_at),
    KEY idx_vip_price_code (view_code)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
