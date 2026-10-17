<?php

class ProductVariantStock
{
    private static $schemaReady = false;


    public static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();
        $db->exec("
            CREATE TABLE IF NOT EXISTS product_variant_stock
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id INT UNSIGNED NOT NULL,
                size_value_id INT UNSIGNED NOT NULL,
                color_key VARCHAR(220) NOT NULL,
                color_name VARCHAR(100) NOT NULL,
                color_hex VARCHAR(7) NULL DEFAULT NULL,
                stock INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_product_size_color
                    (product_id, size_value_id, color_key),
                KEY idx_variant_product (product_id),
                KEY idx_variant_size (size_value_id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
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
                stock.id,
                stock.product_id,
                stock.size_value_id,
                values_list.value AS size_name,
                stock.color_key,
                stock.color_name,
                stock.color_hex,
                stock.stock
            FROM product_variant_stock AS stock
            JOIN attribute_values AS values_list
                ON values_list.id = stock.size_value_id
            WHERE stock.product_id IN ({$placeholders})
            ORDER BY stock.product_id, values_list.id, stock.id
        ");
        $stmt->execute($productIds);

        $aggregated = [];
        $canonicalColors = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int) $row['product_id'];
            $sizeId = (int) $row['size_value_id'];
            $logicalKey = self::logicalColorKey($row['color_name'] ?? '');

            if ($logicalKey === '') {
                continue;
            }

            if (!isset($canonicalColors[$productId][$logicalKey])) {
                $canonicalColors[$productId][$logicalKey] = [
                    'color_key' => (string) $row['color_key'],
                    'color_name' => (string) $row['color_name'],
                    'color_hex' => (string) ($row['color_hex'] ?? '')
                ];
            }

            $groupKey = $sizeId . '|' . $logicalKey;

            if (!isset($aggregated[$productId][$groupKey])) {
                $color = $canonicalColors[$productId][$logicalKey];
                $aggregated[$productId][$groupKey] = [
                    'size_value_id' => $sizeId,
                    'size_name' => (string) $row['size_name'],
                    'color_key' => $color['color_key'],
                    'color_name' => $color['color_name'],
                    'color_hex' => $color['color_hex'],
                    'stock' => 0
                ];
            }

            $aggregated[$productId][$groupKey]['stock'] += max(
                0,
                (int) $row['stock']
            );
        }

        $result = [];

        foreach ($aggregated as $productId => $rows) {
            $result[(int) $productId] = array_values($rows);
        }

        return $result;
    }


    public static function forProduct($productId)
    {
        $productId = (int) $productId;

        if ($productId <= 0) {
            return [];
        }

        $map = self::forProducts([$productId]);

        return $map[$productId] ?? [];
    }


    public static function hasMatrix($productId)
    {
        self::ensureTable();
        $productId = (int) $productId;

        if ($productId <= 0) {
            return false;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT 1
            FROM product_variant_stock
            WHERE product_id = :product_id
            LIMIT 1
        ");
        $stmt->execute(['product_id' => $productId]);

        return (bool) $stmt->fetchColumn();
    }


    public static function stockFor($productId, $sizeValueId, $colorKey)
    {
        self::ensureTable();
        $productId = (int) $productId;
        $sizeValueId = (int) $sizeValueId;
        $colorKey = trim((string) $colorKey);

        if ($productId <= 0 || $sizeValueId <= 0 || $colorKey === '') {
            return 0;
        }

        $stmt = Database::connect()->prepare("
            SELECT color_key, color_name, stock
            FROM product_variant_stock
            WHERE product_id = :product_id
              AND size_value_id = :size_value_id
            ORDER BY id ASC
        ");
        $stmt->execute([
            'product_id' => $productId,
            'size_value_id' => $sizeValueId
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $logicalKey = self::logicalKeyForRequestedColor($rows, $colorKey);

        if ($logicalKey === '') {
            return 0;
        }

        $stock = 0;

        foreach ($rows as $row) {
            if (self::logicalColorKey($row['color_name'] ?? '') === $logicalKey) {
                $stock += max(0, (int) ($row['stock'] ?? 0));
            }
        }

        return $stock;
    }


    public static function colorInfo($productId, $colorKey)
    {
        self::ensureTable();
        $productId = (int) $productId;
        $colorKey = trim((string) $colorKey);

        if ($productId <= 0 || $colorKey === '') {
            return null;
        }

        $stmt = Database::connect()->prepare("
            SELECT color_key, color_name, color_hex, stock
            FROM product_variant_stock
            WHERE product_id = :product_id
            ORDER BY id ASC
        ");
        $stmt->execute(['product_id' => $productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $logicalKey = self::logicalKeyForRequestedColor($rows, $colorKey);

        if ($logicalKey === '') {
            return null;
        }

        $representative = null;
        $stock = 0;

        foreach ($rows as $row) {
            if (self::logicalColorKey($row['color_name'] ?? '') !== $logicalKey) {
                continue;
            }

            if ($representative === null || (string) $row['color_key'] === $colorKey) {
                $representative = $row;
            }

            $stock += max(0, (int) ($row['stock'] ?? 0));
        }

        if (!$representative) {
            return null;
        }

        return [
            'color_key' => (string) $representative['color_key'],
            'color_name' => (string) $representative['color_name'],
            'color_hex' => (string) ($representative['color_hex'] ?? ''),
            'stock' => $stock
        ];
    }


    public static function colors($productId)
    {
        self::ensureTable();
        $productId = (int) $productId;

        if ($productId <= 0) {
            return [];
        }

        $stmt = Database::connect()->prepare("
            SELECT color_key, color_name, color_hex, stock
            FROM product_variant_stock
            WHERE product_id = :product_id
            ORDER BY id ASC
        ");
        $stmt->execute(['product_id' => $productId]);
        $aggregated = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $logicalKey = self::logicalColorKey($row['color_name'] ?? '');

            if ($logicalKey === '') {
                continue;
            }

            if (!isset($aggregated[$logicalKey])) {
                $aggregated[$logicalKey] = [
                    'color_key' => (string) $row['color_key'],
                    'color_name' => (string) $row['color_name'],
                    'color_hex' => (string) ($row['color_hex'] ?? ''),
                    'stock' => 0
                ];
            }

            $aggregated[$logicalKey]['stock'] += max(
                0,
                (int) ($row['stock'] ?? 0)
            );
        }

        return array_values($aggregated);
    }


    public static function pruneColors($productId, array $colors)
    {
        self::ensureTable();
        $productId = (int) $productId;

        if ($productId <= 0) {
            return;
        }

        $allowed = [];

        foreach ($colors as $color) {
            if (!is_array($color)) {
                continue;
            }

            $key = self::logicalColorKey($color['name'] ?? '');

            if ($key !== '') {
                $allowed[$key] = true;
            }
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT id, color_name
            FROM product_variant_stock
            WHERE product_id = :product_id
        ");
        $stmt->execute(['product_id' => $productId]);
        $deleteIds = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = self::logicalColorKey($row['color_name'] ?? '');

            if ($key === '' || !isset($allowed[$key])) {
                $deleteIds[] = (int) ($row['id'] ?? 0);
            }
        }

        $deleteIds = array_values(array_filter($deleteIds));

        if (empty($deleteIds)) {
            return;
        }

        $placeholders = implode(
            ',',
            array_fill(0, count($deleteIds), '?')
        );
        $delete = $db->prepare("
            DELETE FROM product_variant_stock
            WHERE product_id = ?
              AND id IN ({$placeholders})
        ");
        $delete->execute(array_merge([$productId], $deleteIds));
    }


    public static function syncFromMatrix($productId, array $matrix)
    {
        self::ensureTable();
        $productId = (int) $productId;

        if ($productId <= 0) {
            return;
        }

        $db = Database::connect();
        $sizesStmt = $db->prepare("
            SELECT
                av.id,
                av.value
            FROM product_attributes AS pa
            JOIN attribute_values AS av
                ON av.id = pa.attribute_value_id
            JOIN attributes AS a
                ON a.id = av.attribute_id
            WHERE pa.product_id = :product_id
              AND a.slug = 'size'
        ");
        $sizesStmt->execute(['product_id' => $productId]);
        $sizeMap = [];

        foreach ($sizesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = self::textKey($row['value']);
            $sizeMap[$key] = (int) $row['id'];
        }

        $normalized = [];

        foreach ($matrix as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sizeName = trim((string) ($row['size_name'] ?? ''));
            $colorName = trim((string) ($row['color_name'] ?? ''));
            $colorHex = strtolower(trim((string) ($row['color_hex'] ?? '')));
            $stock = max(0, (int) ($row['stock'] ?? 0));
            $sizeId = $sizeMap[self::textKey($sizeName)] ?? 0;
            $logicalKey = self::logicalColorKey($colorName);

            if ($sizeId <= 0 || $logicalKey === '') {
                continue;
            }

            if (!preg_match('/^#[0-9a-f]{6}$/', $colorHex)) {
                $colorHex = null;
            }

            $groupKey = $sizeId . '|' . $logicalKey;

            if (!isset($normalized[$groupKey])) {
                $normalized[$groupKey] = [
                    'size_value_id' => $sizeId,
                    'color_key' => self::colorKey($colorName, $colorHex ?: ''),
                    'color_name' => $colorName,
                    'color_hex' => $colorHex,
                    'stock' => 0
                ];
            }

            $normalized[$groupKey]['stock'] += $stock;
        }

        $db->prepare("
            DELETE FROM product_variant_stock
            WHERE product_id = :product_id
        ")->execute(['product_id' => $productId]);

        if (empty($normalized) || empty($sizeMap)) {
            return;
        }

        $insert = $db->prepare("
            INSERT INTO product_variant_stock
            (
                product_id,
                size_value_id,
                color_key,
                color_name,
                color_hex,
                stock
            )
            VALUES
            (
                :product_id,
                :size_value_id,
                :color_key,
                :color_name,
                :color_hex,
                :stock
            )
        ");
        $total = 0;
        $sizeTotals = [];

        foreach ($normalized as $row) {
            $insert->execute([
                'product_id' => $productId,
                'size_value_id' => $row['size_value_id'],
                'color_key' => $row['color_key'],
                'color_name' => $row['color_name'],
                'color_hex' => $row['color_hex'],
                'stock' => $row['stock']
            ]);

            $total += $row['stock'];
            $sizeId = (int) $row['size_value_id'];
            $sizeTotals[$sizeId] = ($sizeTotals[$sizeId] ?? 0) + $row['stock'];
        }

        $updateSize = $db->prepare("
            UPDATE product_attributes
            SET stock = :stock
            WHERE product_id = :product_id
              AND attribute_value_id = :size_value_id
        ");

        foreach ($sizeTotals as $sizeId => $sizeStock) {
            $updateSize->execute([
                'stock' => $sizeStock,
                'product_id' => $productId,
                'size_value_id' => $sizeId
            ]);
        }

        $db->prepare("
            UPDATE products
            SET stock = :stock,
                stock_mode = 'by_size'
            WHERE id = :product_id
        ")->execute([
            'stock' => $total,
            'product_id' => $productId
        ]);
    }


    public static function colorKey($name, $hex)
    {
        return self::textKey($name) . '|' . strtolower(trim((string) $hex));
    }


    private static function logicalKeyForRequestedColor(array $rows, $colorKey)
    {
        $colorKey = trim((string) $colorKey);

        foreach ($rows as $row) {
            if ((string) ($row['color_key'] ?? '') === $colorKey) {
                return self::logicalColorKey($row['color_name'] ?? '');
            }
        }

        $pipe = strpos($colorKey, '|');
        $fallback = $pipe === false
            ? $colorKey
            : substr($colorKey, 0, $pipe);
        $fallback = self::textKey($fallback);

        foreach ($rows as $row) {
            if (self::logicalColorKey($row['color_name'] ?? '') === $fallback) {
                return $fallback;
            }
        }

        return '';
    }


    private static function logicalColorKey($name)
    {
        return self::textKey($name);
    }


    private static function textKey($value)
    {
        $value = trim((string) $value);

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
