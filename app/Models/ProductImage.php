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

        $db->exec("
            CREATE TABLE IF NOT EXISTS product_image_colors
            (
                image_id BIGINT UNSIGNED NOT NULL,
                color_name VARCHAR(100) NOT NULL,
                color_hex VARCHAR(7) NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (image_id),
                KEY idx_product_image_colors_name (color_name)
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

        /*
         * Раніше колір зберігався однією текстовою характеристикою
         * товару. Переносимо її на головне фото, щоб уже внесені дані
         * не загубилися. Надалі кожен колір прив'язаний до свого фото.
         */
        $db->exec("
            INSERT IGNORE INTO product_image_colors
            (
                image_id,
                color_name,
                color_hex
            )
            SELECT
                gallery.id,
                values_list.value,
                NULLIF(TRIM(values_list.color_hex), '')
            FROM product_gallery_images AS gallery
            JOIN product_attributes AS product_values
                ON product_values.product_id = gallery.product_id
            JOIN attribute_values AS values_list
                ON values_list.id = product_values.attribute_value_id
            JOIN attributes AS attributes_list
                ON attributes_list.id = values_list.attribute_id
            WHERE gallery.is_main = 1
              AND attributes_list.slug = 'color'
              AND TRIM(values_list.value) <> ''
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
                gallery.id,
                gallery.product_id,
                gallery.path,
                gallery.is_main,
                gallery.sort_order,
                colors.color_name,
                colors.color_hex
            FROM product_gallery_images AS gallery
            LEFT JOIN product_image_colors AS colors
                ON colors.image_id = gallery.id
            WHERE gallery.product_id IN ({$placeholders})
            ORDER BY
                gallery.product_id ASC,
                gallery.is_main DESC,
                gallery.sort_order ASC,
                gallery.id ASC
        ");
        $stmt->execute($productIds);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int) $row['product_id'];
            $row['id'] = (int) $row['id'];
            $row['product_id'] = $productId;
            $row['is_main'] = (int) $row['is_main'];
            $row['sort_order'] = (int) $row['sort_order'];
            $row['color_name'] = trim((string) ($row['color_name'] ?? ''));
            $row['color_hex'] = $row['color_name'] === ''
                ? ''
                : self::resolvedColorHex(
                    $row['color_name'],
                    $row['color_hex'] ?? ''
                );
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


    public static function syncColors($productId, array $colorsByImageId)
    {
        self::ensureTable();

        $productId = (int) $productId;

        if ($productId <= 0) {
            return;
        }

        $db = Database::connect();
        $imageStmt = $db->prepare("
            SELECT id
            FROM product_gallery_images
            WHERE product_id = :product_id
        ");
        $imageStmt->execute(['product_id' => $productId]);
        $allowedImageIds = array_map(
            'intval',
            $imageStmt->fetchAll(PDO::FETCH_COLUMN)
        );

        if (!empty($allowedImageIds)) {
            $placeholders = implode(
                ',',
                array_fill(0, count($allowedImageIds), '?')
            );
            $delete = $db->prepare("
                DELETE FROM product_image_colors
                WHERE image_id IN ({$placeholders})
            ");
            $delete->execute($allowedImageIds);
        }

        if (empty($allowedImageIds) || empty($colorsByImageId)) {
            return;
        }

        $allowedMap = array_fill_keys($allowedImageIds, true);
        $insert = $db->prepare("
            INSERT INTO product_image_colors
            (
                image_id,
                color_name,
                color_hex
            )
            VALUES
            (
                :image_id,
                :color_name,
                :color_hex
            )
            ON DUPLICATE KEY UPDATE
                color_name = VALUES(color_name),
                color_hex = VALUES(color_hex)
        ");

        foreach ($colorsByImageId as $imageId => $color) {
            $imageId = (int) $imageId;
            $color = is_array($color) ? $color : [];
            $name = trim((string) ($color['name'] ?? ''));

            if ($imageId <= 0 || !isset($allowedMap[$imageId]) || $name === '') {
                continue;
            }

            $name = function_exists('mb_substr')
                ? mb_substr($name, 0, 100, 'UTF-8')
                : substr($name, 0, 100);
            $insert->execute([
                'image_id' => $imageId,
                'color_name' => $name,
                'color_hex' => self::resolvedColorHex(
                    $name,
                    $color['hex'] ?? ''
                )
            ]);
        }
    }


    public static function colorVariantsForProducts(array $productIds)
    {
        $imagesByProduct = self::forProducts($productIds);
        $result = [];

        foreach ($imagesByProduct as $productId => $images) {
            $seen = [];

            foreach ($images as $image) {
                $name = trim((string) ($image['color_name'] ?? ''));
                $path = trim((string) ($image['path'] ?? ''));

                if ($name === '' || $path === '') {
                    continue;
                }

                $nameKey = function_exists('mb_strtolower')
                    ? mb_strtolower($name, 'UTF-8')
                    : strtolower($name);
                $hex = self::resolvedColorHex(
                    $name,
                    $image['color_hex'] ?? ''
                );
                $key = $nameKey . '|' . $hex;

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $result[(int) $productId][] = [
                    'name' => $name,
                    'hex' => $hex,
                    'path' => $path,
                    'image_id' => (int) ($image['id'] ?? 0)
                ];
            }
        }

        return $result;
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
            SELECT id, path
            FROM product_gallery_images
            WHERE product_id = ?
              AND id IN ({$placeholders})
        ");
        $select->execute($params);
        $ownedImages = $select->fetchAll(PDO::FETCH_ASSOC);
        $ownedImageIds = [];
        $paths = [];

        foreach ($ownedImages as $image) {
            $ownedImageIds[] = (int) $image['id'];
            $paths[] = (string) $image['path'];
        }

        if (empty($ownedImageIds)) {
            return [];
        }

        $colorPlaceholders = implode(
            ',',
            array_fill(0, count($ownedImageIds), '?')
        );
        $deleteColors = $db->prepare("
            DELETE FROM product_image_colors
            WHERE image_id IN ({$colorPlaceholders})
        ");
        $deleteColors->execute($ownedImageIds);

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

        $copyColors = $db->prepare("
            INSERT INTO product_image_colors
            (
                image_id,
                color_name,
                color_hex
            )
            SELECT
                target_gallery.id,
                colors.color_name,
                colors.color_hex
            FROM product_gallery_images AS source_gallery
            JOIN product_image_colors AS colors
                ON colors.image_id = source_gallery.id
            JOIN product_gallery_images AS target_gallery
                ON target_gallery.product_id = :target_id
               AND target_gallery.path = source_gallery.path
            WHERE source_gallery.product_id = :source_id
            ON DUPLICATE KEY UPDATE
                color_name = VALUES(color_name),
                color_hex = VALUES(color_hex)
        ");
        $copyColors->execute([
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


    private static function resolvedColorHex($name, $hex)
    {
        $hex = strtolower(trim((string) $hex));

        if (preg_match('/^#[0-9a-f]{6}$/', $hex)) {
            return $hex;
        }

        $name = trim((string) $name);
        $name = function_exists('mb_strtolower')
            ? mb_strtolower($name, 'UTF-8')
            : strtolower($name);
        $knownColors = [
            '#f5efe3' => ['молоч', 'крем', 'айвор', 'ivory', 'cream'],
            '#ffffff' => ['білий', 'белый', 'white'],
            '#171717' => ['чорний', 'черный', 'black'],
            '#f2a6b8' => ['рожев', 'розов', 'pink'],
            '#d83d4d' => ['червон', 'красн', 'red'],
            '#d9c2a3' => ['беж', 'beige'],
            '#d5a485' => ['тілес', 'телес', 'нюд', 'nude'],
            '#376bc2' => ['синій', 'синий', 'blue'],
            '#79b9e1' => ['блакит', 'голуб', 'light blue'],
            '#438c5d' => ['зелен', 'green'],
            '#8c8c91' => ['сірий', 'серый', 'grey', 'gray'],
            '#765044' => ['коричн', 'brown'],
            '#7d4ab1' => ['фіолет', 'фиолет', 'purple'],
            '#e3c340' => ['жовт', 'желт', 'yellow'],
            '#e58a36' => ['помаранч', 'оранж', 'orange'],
            '#7f233a' => ['бордов', 'бургунд', 'bordeaux', 'burgundy']
        ];

        foreach ($knownColors as $knownHex => $needles) {
            foreach ($needles as $needle) {
                if (strpos($name, $needle) !== false) {
                    return $knownHex;
                }
            }
        }

        return '#b8b0bd';
    }
}
