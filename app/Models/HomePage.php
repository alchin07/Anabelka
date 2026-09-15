<?php

class HomePage
{
    public static function directions()
    {
        $directions = Category::all();

        foreach ($directions as &$direction) {
            $direction['is_adult'] = !empty(
                $direction['effective_adult']
            );
        }
        unset($direction);

        return $directions;
    }


    public static function navigationTree()
    {
        $markAdult = function (array $nodes) use (&$markAdult) {
            foreach ($nodes as &$node) {
                $node['is_adult'] = !empty($node['effective_adult']);
                $node['children'] = $markAdult(
                    is_array($node['children'] ?? null)
                        ? $node['children']
                        : []
                );
            }
            unset($node);

            return $nodes;
        };

        $roots = $markAdult(Category::navigationTree());

        usort($roots, function ($left, $right) {
            return (!empty($left['is_adult']) ? 1 : 0)
                <=> (!empty($right['is_adult']) ? 1 : 0);
        });

        return $roots;
    }


    /**
     * Latest public non-adult products. Products under an inactive department,
     * category or ancestor are excluded even if products.is_active is true.
     */
    public static function latestProducts($limit = 8)
    {
        $limit = max(1, min(24, (int) $limit));
        $visibleIds = Category::visibleCategoryIds();

        if (empty($visibleIds)) {
            return [];
        }

        $adultIds = Category::adultCategoryIds();
        $allowedIds = array_values(array_diff($visibleIds, $adultIds));

        if (empty($allowedIds)) {
            return [];
        }

        $categoryList = implode(',', array_map('intval', $allowedIds));
        $products = Database::connect()->query("
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
              AND category_id IN ({$categoryList})
            ORDER BY id DESC
            LIMIT {$limit}
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($products)) {
            return [];
        }

        $productIds = array_map(function ($product) {
            return (int) ($product['id'] ?? 0);
        }, $products);
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


    public static function isAdultCategoryId($categoryId)
    {
        return Category::isEffectivelyActive((int) $categoryId)
            && Category::isEffectivelyAdult((int) $categoryId);
    }
}
