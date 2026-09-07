<?php

class CatalogSearch
{
    public static function run($query, $languageCode)
    {
        $query = self::normalizeQuery($query);
        $languageCode = strtolower(trim((string) $languageCode));

        if ($query === '') {
            return [
                'products' => [],
                'categories' => []
            ];
        }

        // Гарантуємо існування таблиць перекладів до пошукових JOIN.
        ProductTranslator::getForProduct(0);
        CategoryTranslator::getForCategory(0);

        $products = self::searchProducts($query, $languageCode);
        $categories = self::searchCategories($query, $languageCode);

        // Загальний пошук не змішується з приватною гілкою 18+.
        $products = array_values(array_filter(
            $products,
            function ($product) {
                return !HomePage::isAdultCategoryId(
                    (int) ($product['category_id'] ?? 0)
                );
            }
        ));

        $categories = array_values(array_filter(
            $categories,
            function ($category) {
                return !HomePage::isAdultCategoryId(
                    (int) ($category['id'] ?? 0)
                );
            }
        ));

        $products = ProductTranslator::localizeList(
            $products,
            $languageCode
        );
        $categories = CategoryTranslator::localizeList(
            $categories,
            $languageCode
        );

        if (!empty($products)) {
            $productIds = array_map(
                function ($product) {
                    return (int) ($product['id'] ?? 0);
                },
                $products
            );

            $variants = ProductImage::colorVariantsForProducts($productIds);

            foreach ($products as &$product) {
                $productId = (int) ($product['id'] ?? 0);
                $product['color_variants'] = $variants[$productId] ?? [];
            }
            unset($product);
        }

        return [
            'products' => $products,
            'categories' => $categories
        ];
    }


    public static function normalizeQuery($query)
    {
        $query = trim((string) $query);
        $query = preg_replace('/\s+/u', ' ', $query);

        if ($query === null) {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($query, 0, 200, 'UTF-8');
        }

        return substr($query, 0, 200);
    }


    private static function searchProducts($query, $languageCode)
    {
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                p.id,
                p.category_id,
                p.name,
                p.slug,
                p.sku,
                p.description,
                p.price,
                p.member_price,
                p.old_price,
                p.stock,
                p.stock_mode,
                p.show_stock_quantity,
                p.brand,
                p.country,
                p.main_image
            FROM products p
            LEFT JOIN product_translations pt
                ON pt.product_id = p.id
               AND pt.language_code = :language_code
               AND pt.status IN ('approved', 'outdated')
            WHERE p.is_active = 1
              AND LOCATE(
                    LOWER(:query),
                    LOWER(CONCAT_WS(
                        ' ',
                        COALESCE(p.name, ''),
                        COALESCE(p.description, ''),
                        COALESCE(p.sku, ''),
                        COALESCE(p.brand, ''),
                        COALESCE(pt.name, ''),
                        COALESCE(pt.description, '')
                    ))
                  ) > 0
            ORDER BY
                CASE
                    WHEN LOWER(COALESCE(p.sku, '')) = LOWER(:query_exact) THEN 0
                    WHEN LOWER(COALESCE(p.name, '')) = LOWER(:query_exact) THEN 1
                    WHEN LOWER(COALESCE(pt.name, '')) = LOWER(:query_exact) THEN 2
                    ELSE 3
                END,
                p.id DESC
            LIMIT 100
        ");

        $stmt->execute([
            'language_code' => $languageCode,
            'query' => $query,
            'query_exact' => $query
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    private static function searchCategories($query, $languageCode)
    {
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                c.id,
                c.department_id,
                c.parent_id,
                c.name,
                c.slug,
                c.description,
                c.image
            FROM categories c
            LEFT JOIN category_translations ct
                ON ct.category_id = c.id
               AND ct.language_code = :language_code
               AND ct.status IN ('approved', 'outdated')
            WHERE c.is_active = 1
              AND LOCATE(
                    LOWER(:query),
                    LOWER(CONCAT_WS(
                        ' ',
                        COALESCE(c.name, ''),
                        COALESCE(c.description, ''),
                        COALESCE(ct.name, ''),
                        COALESCE(ct.description, '')
                    ))
                  ) > 0
            ORDER BY
                CASE
                    WHEN LOWER(COALESCE(c.name, '')) = LOWER(:query_exact) THEN 0
                    WHEN LOWER(COALESCE(ct.name, '')) = LOWER(:query_exact) THEN 1
                    ELSE 2
                END,
                c.sort_order ASC,
                c.name ASC
            LIMIT 50
        ");

        $stmt->execute([
            'language_code' => $languageCode,
            'query' => $query,
            'query_exact' => $query
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
