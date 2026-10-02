-- Anabelka: product image processing metadata
-- Safe additive migration. Does not alter source product image files.

CREATE TABLE IF NOT EXISTS product_image_processing
(
    image_id BIGINT UNSIGNED NOT NULL,
    source_path VARCHAR(500) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    job_id CHAR(32) NULL,
    processor_version VARCHAR(40) NULL,
    source_sha256 CHAR(64) NULL,
    source_bytes BIGINT UNSIGNED NULL,
    source_width INT UNSIGNED NULL,
    source_height INT UNSIGNED NULL,
    original_path VARCHAR(500) NULL,
    master_path VARCHAR(500) NULL,
    master_width INT UNSIGNED NULL,
    master_height INT UNSIGNED NULL,
    master_bytes BIGINT UNSIGNED NULL,
    thumb_path VARCHAR(500) NULL,
    thumb_width INT UNSIGNED NULL,
    thumb_height INT UNSIGNED NULL,
    thumb_bytes BIGINT UNSIGNED NULL,
    normalization_json TEXT NULL,
    last_error VARCHAR(500) NULL,
    processed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (image_id),
    KEY idx_product_image_processing_status (status, updated_at),
    CONSTRAINT fk_product_image_processing_image
        FOREIGN KEY (image_id)
        REFERENCES product_gallery_images(id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
