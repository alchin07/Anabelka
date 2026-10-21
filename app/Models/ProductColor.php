<?php

class ProductColor
{
    private static $schemaReady = false;


    public static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS product_colors
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id INT UNSIGNED NOT NULL,
                color_key VARCHAR(120) NOT NULL,
                color_name VARCHAR(100) NOT NULL,
                color_hex VARCHAR(7) NULL DEFAULT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_product_color
                    (product_id, color_key),
                KEY idx_product_colors_product
                    (product_id, sort_order, id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function forProducts(array $productIds)
    {
        self::ensureTable();
        $productIds = self::productIds($productIds);

        if (empty($productIds)) {
            return [];
        }

        $placeholders = implode(
            ',',
            array_fill(0, count($productIds), '?')
        );
        $stmt = Database::connect()->prepare("
            SELECT
                product_id,
                color_key,
                color_name,
                color_hex,
                sort_order
            FROM product_colors
            WHERE product_id IN ({$placeholders})
            ORDER BY product_id ASC, sort_order ASC, id ASC
        ");
        $stmt->execute($productIds);
        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int) ($row['product_id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $result[$productId][] = [
                'name' => (string) ($row['color_name'] ?? ''),
                'hex' => (string) ($row['color_hex'] ?? ''),
                'key' => (string) ($row['color_key'] ?? '')
            ];
        }

        return $result;
    }


    public static function forProduct($productId)
    {
        $map = self::forProducts([(int) $productId]);

        return $map[(int) $productId] ?? [];
    }


    public static function editorColorsForProducts(array $productIds)
    {
        $productIds = self::productIds($productIds);

        if (empty($productIds)) {
            return [];
        }

        $result = [];
        $seen = [];
        $explicit = self::forProducts($productIds);

        foreach ($explicit as $productId => $colors) {
            foreach ($colors as $color) {
                self::appendEditorColor(
                    $result,
                    $seen,
                    (int) $productId,
                    $color['name'] ?? '',
                    $color['hex'] ?? ''
                );
            }
        }

        $matrix = ProductVariantStock::forProducts($productIds);

        foreach ($matrix as $productId => $rows) {
            foreach ($rows as $row) {
                self::appendEditorColor(
                    $result,
                    $seen,
                    (int) $productId,
                    $row['color_name'] ?? '',
                    $row['color_hex'] ?? ''
                );
            }
        }

        $imageVariants = ProductImage::colorVariantsForProducts($productIds);

        foreach ($imageVariants as $productId => $variants) {
            foreach ($variants as $variant) {
                self::appendEditorColor(
                    $result,
                    $seen,
                    (int) $productId,
                    $variant['name'] ?? '',
                    $variant['hex'] ?? ''
                );
            }
        }

        return $result;
    }


    public static function variantsForProducts(array $productIds)
    {
        $productIds = self::productIds($productIds);

        if (empty($productIds)) {
            return [];
        }

        $result = [];
        $index = [];
        $explicit = self::forProducts($productIds);

        foreach ($explicit as $productId => $colors) {
            foreach ($colors as $color) {
                self::appendVariant(
                    $result,
                    $index,
                    (int) $productId,
                    $color['name'] ?? '',
                    $color['hex'] ?? '',
                    '',
                    0
                );
            }
        }

        $matrix = ProductVariantStock::forProducts($productIds);

        foreach ($matrix as $productId => $rows) {
            foreach ($rows as $row) {
                self::appendVariant(
                    $result,
                    $index,
                    (int) $productId,
                    $row['color_name'] ?? '',
                    $row['color_hex'] ?? '',
                    '',
                    0
                );
            }
        }

        $imageVariants = ProductImage::colorVariantsForProducts($productIds);

        foreach ($imageVariants as $productId => $variants) {
            foreach ($variants as $variant) {
                self::appendVariant(
                    $result,
                    $index,
                    (int) $productId,
                    $variant['name'] ?? '',
                    $variant['hex'] ?? '',
                    $variant['path'] ?? '',
                    (int) ($variant['image_id'] ?? 0)
                );
            }
        }

        return $result;
    }


    public static function syncForProduct($productId, array $colors)
    {
        self::ensureTable();
        $productId = (int) $productId;

        if ($productId <= 0) {
            return;
        }

        $normalized = [];
        $seen = [];

        foreach ($colors as $color) {
            if (!is_array($color)) {
                continue;
            }

            $name = self::limitedName($color['name'] ?? '');

            if ($name === '') {
                continue;
            }

            $key = self::textKey($name);

            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = [
                'key' => $key,
                'name' => $name,
                'hex' => self::normalizeHex($color['hex'] ?? '')
            ];

            if (count($normalized) >= 60) {
                break;
            }
        }

        $db = Database::connect();
        $db->prepare("
            DELETE FROM product_colors
            WHERE product_id = :product_id
        ")->execute(['product_id' => $productId]);

        if (empty($normalized)) {
            return;
        }

        $insert = $db->prepare("
            INSERT INTO product_colors
            (
                product_id,
                color_key,
                color_name,
                color_hex,
                sort_order
            )
            VALUES
            (
                :product_id,
                :color_key,
                :color_name,
                :color_hex,
                :sort_order
            )
        ");
        $sortOrder = 0;

        foreach ($normalized as $color) {
            $sortOrder += 10;
            $insert->execute([
                'product_id' => $productId,
                'color_key' => $color['key'],
                'color_name' => $color['name'],
                'color_hex' => $color['hex'],
                'sort_order' => $sortOrder
            ]);
        }
    }


    public static function duplicateForProduct($sourceId, $targetId)
    {
        self::ensureTable();
        $sourceId = (int) $sourceId;
        $targetId = (int) $targetId;

        if ($sourceId <= 0 || $targetId <= 0) {
            return;
        }

        Database::connect()->prepare("
            INSERT INTO product_colors
            (
                product_id,
                color_key,
                color_name,
                color_hex,
                sort_order
            )
            SELECT
                :target_id,
                color_key,
                color_name,
                color_hex,
                sort_order
            FROM product_colors
            WHERE product_id = :source_id
            ORDER BY sort_order ASC, id ASC
        ")->execute([
            'target_id' => $targetId,
            'source_id' => $sourceId
        ]);
    }


    private static function appendEditorColor(
        array &$result,
        array &$seen,
        $productId,
        $name,
        $hex
    ) {
        $productId = (int) $productId;
        $name = self::limitedName($name);
        $key = self::textKey($name);

        if (
            $productId <= 0
            || $key === ''
            || isset($seen[$productId][$key])
        ) {
            return;
        }

        $seen[$productId][$key] = true;
        $result[$productId][] = [
            'name' => $name,
            'hex' => self::normalizeHex($hex) ?: '#b8b0bd'
        ];
    }


    private static function appendVariant(
        array &$result,
        array &$index,
        $productId,
        $name,
        $hex,
        $path,
        $imageId
    ) {
        $productId = (int) $productId;
        $name = self::limitedName($name);
        $logicalKey = self::textKey($name);

        if ($productId <= 0 || $logicalKey === '') {
            return;
        }

        $hex = self::normalizeHex($hex);
        $path = trim((string) $path);
        $imageId = (int) $imageId;

        if (isset($index[$productId][$logicalKey])) {
            $position = $index[$productId][$logicalKey];
            $existing = &$result[$productId][$position];

            if (
                trim((string) ($existing['hex'] ?? '')) === ''
                && $hex !== null
            ) {
                $existing['hex'] = $hex;
            }

            if (
                trim((string) ($existing['path'] ?? '')) === ''
                && $path !== ''
            ) {
                $existing['path'] = $path;
                $existing['image_id'] = $imageId;
            }
            unset($existing);
            return;
        }

        $index[$productId][$logicalKey] =
            count($result[$productId] ?? []);
        $result[$productId][] = [
            'name' => $name,
            'hex' => $hex ?: '#b8b0bd',
            'path' => $path,
            'image_id' => $imageId
        ];
    }


    private static function productIds(array $productIds)
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            function ($id) {
                return $id > 0;
            }
        )));
    }


    private static function limitedName($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        return function_exists('mb_substr')
            ? mb_substr($name, 0, 100, 'UTF-8')
            : substr($name, 0, 100);
    }


    private static function normalizeHex($hex)
    {
        $hex = strtolower(trim((string) $hex));

        return preg_match('/^#[0-9a-f]{6}$/', $hex)
            ? $hex
            : null;
    }


    private static function textKey($value)
    {
        $value = trim((string) $value);

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
