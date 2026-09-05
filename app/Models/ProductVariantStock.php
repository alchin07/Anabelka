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
            ORDER BY stock.product_id, values_list.id, stock.color_name
        ");
        $stmt->execute($productIds);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int) $row['product_id'];
            $result[$productId][] = [
                'size_value_id' => (int) $row['size_value_id'],
                'size_name' => (string) $row['size_name'],
                'color_key' => (string) $row['color_key'],
                'color_name' => (string) $row['color_name'],
                'color_hex' => (string) ($row['color_hex'] ?? ''),
                'stock' => (int) $row['stock']
            ];
        }

        return $result;
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

        $db->prepare("
            DELETE FROM product_variant_stock
            WHERE product_id = :product_id
        ")->execute(['product_id' => $productId]);

        if (empty($matrix) || empty($sizeMap)) {
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

        foreach ($matrix as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sizeName = trim((string) ($row['size_name'] ?? ''));
            $colorName = trim((string) ($row['color_name'] ?? ''));
            $colorHex = strtolower(trim((string) ($row['color_hex'] ?? '')));
            $stock = max(0, (int) ($row['stock'] ?? 0));
            $sizeId = $sizeMap[self::textKey($sizeName)] ?? 0;

            if ($sizeId <= 0 || $colorName === '') {
                continue;
            }

            if (!preg_match('/^#[0-9a-f]{6}$/', $colorHex)) {
                $colorHex = null;
            }

            $colorKey = self::colorKey($colorName, $colorHex ?: '');
            $insert->execute([
                'product_id' => $productId,
                'size_value_id' => $sizeId,
                'color_key' => $colorKey,
                'color_name' => $colorName,
                'color_hex' => $colorHex,
                'stock' => $stock
            ]);

            $total += $stock;
            $sizeTotals[$sizeId] = ($sizeTotals[$sizeId] ?? 0) + $stock;
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


    private static function textKey($value)
    {
        $value = trim((string) $value);

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}
