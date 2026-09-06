<?php

class Inventory
{
    public static function reserveItems(array $items)
    {
        self::changeItems($items, -1);
    }


    public static function releaseItems(array $items)
    {
        self::changeItems($items, 1);
    }


    private static function changeItems(array $items, $direction)
    {
        $direction = (int) $direction < 0 ? -1 : 1;
        $db = Database::connect();
        $variantProducts = [];
        $variantSizes = [];
        $legacySizeProducts = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = is_array($item['product'] ?? null)
                ? $item['product']
                : Product::findById((int) ($item['product_id'] ?? 0));
            $productId = (int) ($product['id'] ?? ($item['product_id'] ?? 0));
            $sizeId = (int) ($item['size_id'] ?? 0);
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            $colorKey = trim((string) ($item['color_key'] ?? ''));

            if ($productId <= 0 || $quantity <= 0 || !$product) {
                continue;
            }

            if (ProductVariantStock::hasMatrix($productId)) {
                if ($sizeId <= 0 || $colorKey === '') {
                    throw new RuntimeException(
                        'Не вдалося визначити розмір або колір товару для обліку залишку.'
                    );
                }

                if ($direction < 0) {
                    $stmt = $db->prepare("
                        UPDATE product_variant_stock
                        SET stock = stock - :quantity
                        WHERE product_id = :product_id
                          AND size_value_id = :size_id
                          AND color_key = :color_key
                          AND stock >= :quantity
                    ");
                    $stmt->execute([
                        'quantity' => $quantity,
                        'product_id' => $productId,
                        'size_id' => $sizeId,
                        'color_key' => $colorKey
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        $size = Product::getAttributeValueById($sizeId);
                        $sizeName = (string) ($size['value'] ?? '—');
                        $colorName = trim((string) ($item['color_name'] ?? ''));

                        throw new RuntimeException(
                            'Недостатньо товару: '
                            . (string) ($product['name'] ?? 'товар')
                            . ', розмір ' . $sizeName
                            . ($colorName !== '' ? ', колір ' . $colorName : '')
                            . '.'
                        );
                    }
                } else {
                    $colorName = trim((string) ($item['color_name'] ?? ''));
                    $colorHex = strtolower(trim((string) ($item['color_hex'] ?? '')));

                    if ($colorName === '') {
                        $parts = explode('|', $colorKey, 2);
                        $colorName = trim((string) ($parts[0] ?? 'Колір'));
                    }

                    if (!preg_match('/^#[0-9a-f]{6}$/', $colorHex)) {
                        $colorHex = null;
                    }

                    $stmt = $db->prepare("
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
                            :size_id,
                            :color_key,
                            :color_name,
                            :color_hex,
                            :quantity
                        )
                        ON DUPLICATE KEY UPDATE
                            stock = stock + VALUES(stock),
                            color_name = VALUES(color_name),
                            color_hex = COALESCE(VALUES(color_hex), color_hex)
                    ");
                    $stmt->execute([
                        'product_id' => $productId,
                        'size_id' => $sizeId,
                        'color_key' => $colorKey,
                        'color_name' => $colorName,
                        'color_hex' => $colorHex,
                        'quantity' => $quantity
                    ]);
                }

                $variantProducts[$productId] = true;
                $variantSizes[$productId . ':' . $sizeId] = [
                    'product_id' => $productId,
                    'size_id' => $sizeId
                ];
                continue;
            }

            if (($product['stock_mode'] ?? 'total') === 'by_size') {
                if ($sizeId <= 0) {
                    throw new RuntimeException(
                        'Не вдалося визначити розмір товару для обліку залишку.'
                    );
                }

                if ($direction < 0) {
                    $stmt = $db->prepare("
                        UPDATE product_attributes
                        SET stock = stock - :quantity
                        WHERE product_id = :product_id
                          AND attribute_value_id = :size_id
                          AND stock >= :quantity
                    ");
                    $stmt->execute([
                        'quantity' => $quantity,
                        'product_id' => $productId,
                        'size_id' => $sizeId
                    ]);

                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException(
                            'Недостатньо товару потрібного розміру на складі.'
                        );
                    }
                } else {
                    $stmt = $db->prepare("
                        UPDATE product_attributes
                        SET stock = stock + :quantity
                        WHERE product_id = :product_id
                          AND attribute_value_id = :size_id
                    ");
                    $stmt->execute([
                        'quantity' => $quantity,
                        'product_id' => $productId,
                        'size_id' => $sizeId
                    ]);
                }

                $legacySizeProducts[$productId] = true;
                continue;
            }

            if ($direction < 0) {
                $stmt = $db->prepare("
                    UPDATE products
                    SET stock = stock - :quantity
                    WHERE id = :product_id
                      AND stock >= :quantity
                ");
                $stmt->execute([
                    'quantity' => $quantity,
                    'product_id' => $productId
                ]);

                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Недостатньо товару на складі.'
                    );
                }
            } else {
                $db->prepare("
                    UPDATE products
                    SET stock = stock + :quantity
                    WHERE id = :product_id
                ")->execute([
                    'quantity' => $quantity,
                    'product_id' => $productId
                ]);
            }
        }

        foreach ($variantSizes as $pair) {
            self::recalculateVariantSize(
                (int) $pair['product_id'],
                (int) $pair['size_id']
            );
        }

        foreach (array_keys($variantProducts) as $productId) {
            self::recalculateVariantProduct((int) $productId);
        }

        foreach (array_keys($legacySizeProducts) as $productId) {
            self::recalculateLegacySizeProduct((int) $productId);
        }
    }


    private static function recalculateVariantSize($productId, $sizeId)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE product_attributes
            SET stock = (
                SELECT COALESCE(SUM(variant_stock.stock), 0)
                FROM product_variant_stock AS variant_stock
                WHERE variant_stock.product_id = :product_id_sum
                  AND variant_stock.size_value_id = :size_id_sum
            )
            WHERE product_id = :product_id
              AND attribute_value_id = :size_id
        ");
        $stmt->execute([
            'product_id_sum' => $productId,
            'size_id_sum' => $sizeId,
            'product_id' => $productId,
            'size_id' => $sizeId
        ]);
    }


    private static function recalculateVariantProduct($productId)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE products
            SET stock = (
                    SELECT COALESCE(SUM(variant_stock.stock), 0)
                    FROM product_variant_stock AS variant_stock
                    WHERE variant_stock.product_id = :product_id_sum
                ),
                stock_mode = 'by_size'
            WHERE id = :product_id
        ");
        $stmt->execute([
            'product_id_sum' => $productId,
            'product_id' => $productId
        ]);
    }


    private static function recalculateLegacySizeProduct($productId)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE products
            SET stock = (
                SELECT COALESCE(SUM(pa.stock), 0)
                FROM product_attributes AS pa
                JOIN attribute_values AS av
                    ON av.id = pa.attribute_value_id
                JOIN attributes AS a
                    ON a.id = av.attribute_id
                WHERE pa.product_id = :product_id_sum
                  AND a.slug = 'size'
            )
            WHERE id = :product_id
        ");
        $stmt->execute([
            'product_id_sum' => $productId,
            'product_id' => $productId
        ]);
    }
}
