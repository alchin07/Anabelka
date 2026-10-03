<?php

class ProductImageProcessing
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        ProductImage::ensureTable();

        $db = Database::connect();

        $db->exec("
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
                KEY idx_product_image_processing_status
                    (status, updated_at),
                CONSTRAINT fk_product_image_processing_image
                    FOREIGN KEY (image_id)
                    REFERENCES product_gallery_images(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $column = $db->query("
            SHOW COLUMNS
            FROM product_image_processing
            LIKE 'normalization_json'
        ")->fetch(PDO::FETCH_ASSOC);

        if (!$column) {
            $db->exec("
                ALTER TABLE product_image_processing
                ADD COLUMN normalization_json TEXT NULL
                AFTER thumb_bytes
            ");
        }

        self::$schemaReady = true;
    }


    public static function find($imageId)
    {
        self::ensureSchema();

        $stmt = Database::connect()->prepare("
            SELECT *
            FROM product_image_processing
            WHERE image_id = :image_id
            LIMIT 1
        ");
        $stmt->execute([
            'image_id' => (int) $imageId
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? self::normalize($row) : null;
    }


    public static function forImageIds(array $imageIds)
    {
        self::ensureSchema();

        $imageIds = array_values(array_unique(array_filter(
            array_map('intval', $imageIds),
            static function ($id) {
                return $id > 0;
            }
        )));

        if (empty($imageIds)) {
            return [];
        }

        $placeholders = implode(
            ',',
            array_fill(0, count($imageIds), '?')
        );
        $stmt = Database::connect()->prepare("
            SELECT *
            FROM product_image_processing
            WHERE image_id IN ({$placeholders})
        ");
        $stmt->execute($imageIds);
        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $normalized = self::normalize($row);
            $result[(int) $normalized['image_id']] = $normalized;
        }

        return $result;
    }


    public static function markProcessing($imageId, $sourcePath)
    {
        self::ensureSchema();

        $stmt = Database::connect()->prepare("
            INSERT INTO product_image_processing
            (
                image_id,
                source_path,
                status,
                last_error
            )
            VALUES
            (
                :image_id,
                :source_path,
                'processing',
                NULL
            )
            ON DUPLICATE KEY UPDATE
                source_path = VALUES(source_path),
                status = 'processing',
                last_error = NULL
        ");
        $stmt->execute([
            'image_id' => (int) $imageId,
            'source_path' => (string) $sourcePath
        ]);
    }


    public static function markReady($imageId, array $data)
    {
        self::ensureSchema();

        $stmt = Database::connect()->prepare("
            UPDATE product_image_processing
            SET source_path = :source_path,
                status = 'ready',
                job_id = :job_id,
                processor_version = :processor_version,
                source_sha256 = :source_sha256,
                source_bytes = :source_bytes,
                source_width = :source_width,
                source_height = :source_height,
                original_path = :original_path,
                master_path = :master_path,
                master_width = :master_width,
                master_height = :master_height,
                master_bytes = :master_bytes,
                thumb_path = :thumb_path,
                thumb_width = :thumb_width,
                thumb_height = :thumb_height,
                thumb_bytes = :thumb_bytes,
                normalization_json = :normalization_json,
                last_error = NULL,
                processed_at = NOW()
            WHERE image_id = :image_id
        ");
        $stmt->execute([
            'source_path' => (string) $data['source_path'],
            'job_id' => (string) $data['job_id'],
            'processor_version' => (string) $data['processor_version'],
            'source_sha256' => (string) $data['source_sha256'],
            'source_bytes' => (int) $data['source_bytes'],
            'source_width' => (int) $data['source_width'],
            'source_height' => (int) $data['source_height'],
            'original_path' => (string) $data['original_path'],
            'master_path' => (string) $data['master_path'],
            'master_width' => (int) $data['master_width'],
            'master_height' => (int) $data['master_height'],
            'master_bytes' => (int) $data['master_bytes'],
            'thumb_path' => (string) $data['thumb_path'],
            'thumb_width' => (int) $data['thumb_width'],
            'thumb_height' => (int) $data['thumb_height'],
            'thumb_bytes' => (int) $data['thumb_bytes'],
            'normalization_json' => json_encode(
                is_array($data['normalization'] ?? null)
                    ? $data['normalization']
                    : [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'image_id' => (int) $imageId
        ]);
    }


    public static function markError($imageId, $sourcePath, $message)
    {
        self::ensureSchema();

        $message = trim((string) $message);
        $message = function_exists('mb_substr')
            ? mb_substr($message, 0, 500, 'UTF-8')
            : substr($message, 0, 500);

        $stmt = Database::connect()->prepare("
            INSERT INTO product_image_processing
            (
                image_id,
                source_path,
                status,
                last_error
            )
            VALUES
            (
                :image_id,
                :source_path,
                'error',
                :last_error
            )
            ON DUPLICATE KEY UPDATE
                source_path = VALUES(source_path),
                status = 'error',
                last_error = VALUES(last_error)
        ");
        $stmt->execute([
            'image_id' => (int) $imageId,
            'source_path' => (string) $sourcePath,
            'last_error' => $message !== ''
                ? $message
                : 'Невідома помилка обробки.'
        ]);
    }


    private static function normalize(array $row)
    {
        foreach (
            [
                'image_id',
                'source_bytes',
                'source_width',
                'source_height',
                'master_width',
                'master_height',
                'master_bytes',
                'thumb_width',
                'thumb_height',
                'thumb_bytes'
            ] as $key
        ) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = max(0, (int) $row[$key]);
            }
        }

        $normalization = [];

        if (!empty($row['normalization_json'])) {
            $decoded = json_decode(
                (string) $row['normalization_json'],
                true
            );

            if (is_array($decoded)) {
                $normalization = $decoded;
            }
        }

        $row['normalization'] = $normalization;

        return $row;
    }
}
