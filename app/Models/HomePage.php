<?php

class HomePage
{
    /**
     * Активные корневые направления/категории для главной страницы.
     *
     * Пока данные берутся из существующего дерева категорий. В будущем
     * этот метод станет источником для конструктора главной страницы,
     * не меняя контракт контроллера и будущего API.
     */
    public static function directions()
    {
        return Category::all();
    }


    /**
     * Последние активные товары для блока «Новинки».
     */
    public static function latestProducts($limit = 8)
    {
        $limit = max(1, min(24, (int) $limit));
        $db = Database::connect();

        $stmt = $db->query("
            SELECT
                id,
                category_id,
                name,
                slug,
                sku,
                description,
                price,
                member_price,
                old_price,
                stock,
                stock_mode,
                show_stock_quantity,
                brand,
                country,
                main_image
            FROM products
            WHERE is_active = 1
            ORDER BY id DESC
            LIMIT {$limit}
        ");

        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($products)) {
            return [];
        }

        $productIds = array_map(
            function ($product) {
                return (int) ($product['id'] ?? 0);
            },
            $products
        );

        $colorVariants = ProductImage::colorVariantsForProducts(
            $productIds
        );

        foreach ($products as &$product) {
            $productId = (int) ($product['id'] ?? 0);
            $product['color_variants'] = $colorVariants[$productId] ?? [];
        }
        unset($product);

        return $products;
    }
}
