<?php

class ProductImage
{
    private static $schemaReady = false;


    public static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS product_gallery_images
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id INT UNSIGNED NOT NULL,
                path VARCHAR(500) NOT NULL,
                is_main TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_product_image (
                    product_id,
                    path(180)
                ),
                KEY idx_product_gallery_images_order (
                    product_id,
                    is_main,
                    sort_order,
                    id
                )
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        /*
         * У старих базах вже може існувати product_images з іншою
         * структурою. Тому галерея використовує окрему таблицю і не
         * змінює успадковані дані. Поточні products.main_image додаємо
         * до нової галереї автоматично.
         */
        $db->exec("
            INSERT IGNORE INTO product_gallery_images
            (
                product_id,
                path,
                is_main,
                sort_order
            )
            SELECT
                id,
                main_image,
                1,
                0
            FROM products
            WHERE main_image IS NOT NULL
              AND TRIM(main_image) <> ''
        ");

        self::$schemaReady = true;
    }


    public static function forProduct($productId)
    {
        $map = self::forProducts([(int) $productId]);

        return $map[(int) $productId] ?? [];
    }


    public static function forProducts(array $productIds)
    {
        self::ensureTable();

        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            function ($id) {
                return $id > 0;
            }
        )));

        if (empty($productIds)) {
            return [];
        }

        $db = Database::connect();
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $db->prepare("
            SELECT
                id,
                product_id,
                path,
                is_main,
                sort_order
            FROM product_gallery_images
            WHERE product_id IN ({$placeholders})
            ORDER BY
                product_id ASC,
                is_main DESC,
                sort_order ASC,
                id ASC
        ");
        $stmt->execute($productIds);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int) $row['product_id'];
            $row['id'] = (int) $row['id'];
            $row['product_id'] = $productId;
            $row['is_main'] = (int) $row['is_main'];
            $row['sort_order'] = (int) $row['sort_order'];
            $result[$productId][] = $row;
        }

        return $result;
    }


    public static function addPaths($productId, array $paths)
    {
        self::ensureTable();

        $productId = (int) $productId;

        if ($productId <= 0 || empty($paths)) {
            return [];
        }

        $db = Database::connect();
        $maxStmt = $db->prepare("
            SELECT COALESCE(MAX(sort_order), 0)
            FROM product_gallery_images
            WHERE product_id = :product_id
        ");
        $maxStmt->execute(['product_id' => $productId]);
        $sortOrder = (int) $maxStmt->fetchColumn();

        $stmt = $db->prepare("
            INSERT INTO product_gallery_images
            (
                product_id,
                path,
                is_main,
                sort_order
            )
            VALUES
            (
                :product_id,
                :path,
                0,
                :sort_order
            )
            ON DUPLICATE KEY UPDATE
                sort_order = VALUES(sort_order)
        ");

        $ids = [];

        foreach ($paths as $path) {
            $path = trim((string) $path);

            if ($path === '') {
                continue;
            }

            $sortOrder += 10;
            $stmt->execute([
                'product_id' => $productId,
                'path' => $path,
                'sort_order' => $sortOrder
            ]);

            $idStmt = $db->prepare("
                SELECT id
                FROM product_gallery_images
                WHERE product_id = :product_id
                  AND path = :path
                LIMIT 1
            ");
            $idStmt->execute([
                'product_id' => $productId,
                'path' => $path
            ]);
            $imageId = (int) $idStmt->fetchColumn();

            if ($imageId > 0) {
                $ids[] = $imageId;
            }
        }

        return $ids;
    }


    public static function deleteByIds($productId, array $imageIds)
    {
        self::ensureTable();

        $productId = (int) $productId;
        $imageIds = array_values(array_unique(array_filter(
            array_map('intval', $imageIds),
            function ($id) {
                return $id > 0;
            }
        )));

        if ($productId <= 0 || empty($imageIds)) {
            return [];
        }

        $db = Database::connect();
        $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
        $params = array_merge([$productId], $imageIds);
        $select = $db->prepare("
            SELECT path
            FROM product_gallery_images
            WHERE product_id = ?
              AND id IN ({$placeholders})
        ");
        $select->execute($params);
        $paths = $select->fetchAll(PDO::FETCH_COLUMN);

        $delete = $db->prepare("
            DELETE FROM product_gallery_images
            WHERE product_id = ?
              AND id IN ({$placeholders})
        ");
        $delete->execute($params);

        return array_values(array_filter(array_map('strval', $paths)));
    }


    public static function reorder($productId, array $imageIds)
    {
        self::ensureTable();

        $productId = (int) $productId;
        $imageIds = array_values(array_unique(array_filter(
            array_map('intval', $imageIds),
            function ($id) {
                return $id > 0;
            }
        )));

        if ($productId <= 0) {
            return;
        }

        $db = Database::connect();
        $update = $db->prepare("
            UPDATE product_gallery_images
            SET sort_order = :sort_order
            WHERE product_id = :product_id
              AND id = :id
        ");
        $sortOrder = 0;

        foreach ($imageIds as $imageId) {
            $sortOrder += 10;
            $update->execute([
                'sort_order' => $sortOrder,
                'product_id' => $productId,
                'id' => $imageId
            ]);
        }

        $remaining = $db->prepare("
            SELECT id
            FROM product_gallery_images
            WHERE product_id = :product_id
            ORDER BY sort_order ASC, id ASC
        ");
        $remaining->execute(['product_id' => $productId]);

        foreach ($remaining->fetchAll(PDO::FETCH_COLUMN) as $imageId) {
            $imageId = (int) $imageId;

            if (in_array($imageId, $imageIds, true)) {
                continue;
            }

            $sortOrder += 10;
            $update->execute([
                'sort_order' => $sortOrder,
                'product_id' => $productId,
                'id' => $imageId
            ]);
        }
    }


    public static function selectMain($productId, $requestedImageId = 0)
    {
        self::ensureTable();

        $productId = (int) $productId;
        $requestedImageId = (int) $requestedImageId;

        if ($productId <= 0) {
            return null;
        }

        $db = Database::connect();
        $selected = null;

        if ($requestedImageId > 0) {
            $stmt = $db->prepare("
                SELECT id, path
                FROM product_gallery_images
                WHERE product_id = :product_id
                  AND id = :id
                LIMIT 1
            ");
            $stmt->execute([
                'product_id' => $productId,
                'id' => $requestedImageId
            ]);
            $selected = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$selected) {
            $stmt = $db->prepare("
                SELECT id, path
                FROM product_gallery_images
                WHERE product_id = :product_id
                ORDER BY is_main DESC, sort_order ASC, id ASC
                LIMIT 1
            ");
            $stmt->execute(['product_id' => $productId]);
            $selected = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $db->prepare("
            UPDATE product_gallery_images
            SET is_main = 0
            WHERE product_id = :product_id
        ")->execute(['product_id' => $productId]);

        $path = null;

        if ($selected) {
            $db->prepare("
                UPDATE product_gallery_images
                SET is_main = 1
                WHERE product_id = :product_id
                  AND id = :id
            ")->execute([
                'product_id' => $productId,
                'id' => (int) $selected['id']
            ]);
            $path = (string) $selected['path'];
        }

        $db->prepare("
            UPDATE products
            SET main_image = :main_image
            WHERE id = :product_id
        ")->execute([
            'main_image' => $path,
            'product_id' => $productId
        ]);

        return $path;
    }


    public static function duplicateForProduct($sourceId, $targetId)
    {
        self::ensureTable();

        $sourceId = (int) $sourceId;
        $targetId = (int) $targetId;

        if ($sourceId <= 0 || $targetId <= 0) {
            return;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            INSERT INTO product_gallery_images
            (
                product_id,
                path,
                is_main,
                sort_order
            )
            SELECT
                :target_id,
                path,
                is_main,
                sort_order
            FROM product_gallery_images
            WHERE product_id = :source_id
        ");
        $stmt->execute([
            'target_id' => $targetId,
            'source_id' => $sourceId
        ]);
        self::selectMain($targetId);
    }


    public static function pathIsUsed($path)
    {
        self::ensureTable();

        $path = trim((string) $path);

        if ($path === '') {
            return false;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT
                (
                    SELECT COUNT(*)
                    FROM product_gallery_images
                    WHERE path = :gallery_path
                )
                +
                (
                    SELECT COUNT(*)
                    FROM products
                    WHERE main_image = :product_path
                ) AS usage_count
        ");
        $stmt->execute([
            'gallery_path' => $path,
            'product_path' => $path
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
