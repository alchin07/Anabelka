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

        ProductTranslator::getForProduct(0);
        CategoryTranslator::getForCategory(0);

        $products = self::searchProducts($query, $languageCode);
        $categories = self::searchCategories($query, $languageCode);

        /*
         * На окремих збірках MySQL/MariaDB у KSWEB Unicode-пошук через
         * LOWER()/LOCATE() може повертати 0 для кирилиці. Якщо SQL нічого
         * не знайшов, виконуємо резервний Unicode-пошук у PHP.
         */
        if (empty($products)) {
            $products = self::searchProductsFallback($query, $languageCode);
        }

        if (empty($categories)) {
            $categories = self::searchCategoriesFallback($query, $languageCode);
        }

        if (!AdultAccess::canShowAdultContent()) {
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
        }

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
               AND pt.language_code = :product_language_code
               AND pt.status IN ('approved', 'outdated')
            LEFT JOIN categories c
                ON c.id = p.category_id
            LEFT JOIN category_translations ct
                ON ct.category_id = c.id
               AND ct.language_code = :category_language_code
               AND ct.status IN ('approved', 'outdated')
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
                        COALESCE(pt.description, ''),
                        COALESCE(c.name, ''),
                        COALESCE(c.description, ''),
                        COALESCE(ct.name, ''),
                        COALESCE(ct.description, '')
                    ))
                  ) > 0
            ORDER BY
                CASE
                    WHEN LOWER(COALESCE(p.sku, '')) = LOWER(:exact_sku) THEN 0
                    WHEN LOWER(COALESCE(p.name, '')) = LOWER(:exact_name) THEN 1
                    WHEN LOWER(COALESCE(pt.name, '')) = LOWER(:exact_translation_name) THEN 2
                    ELSE 3
                END,
                p.id DESC
            LIMIT 100
        ");

        $stmt->execute([
            'product_language_code' => $languageCode,
            'category_language_code' => $languageCode,
            'query' => $query,
            'exact_sku' => $query,
            'exact_name' => $query,
            'exact_translation_name' => $query
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
                    WHEN LOWER(COALESCE(c.name, '')) = LOWER(:exact_name) THEN 0
                    WHEN LOWER(COALESCE(ct.name, '')) = LOWER(:exact_translation_name) THEN 1
                    ELSE 2
                END,
                c.sort_order ASC,
                c.name ASC
            LIMIT 50
        ");

        $stmt->execute([
            'language_code' => $languageCode,
            'query' => $query,
            'exact_name' => $query,
            'exact_translation_name' => $query
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    private static function searchProductsFallback($query, $languageCode)
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
                p.main_image,
                pt.name AS translated_name,
                pt.description AS translated_description,
                c.name AS category_name,
                c.description AS category_description,
                ct.name AS translated_category_name,
                ct.description AS translated_category_description
            FROM products p
            LEFT JOIN product_translations pt
                ON pt.product_id = p.id
               AND pt.language_code = :product_language_code
               AND pt.status IN ('approved', 'outdated')
            LEFT JOIN categories c
                ON c.id = p.category_id
            LEFT JOIN category_translations ct
                ON ct.category_id = c.id
               AND ct.language_code = :category_language_code
               AND ct.status IN ('approved', 'outdated')
            WHERE p.is_active = 1
            ORDER BY p.id DESC
        ");

        $stmt->execute([
            'product_language_code' => $languageCode,
            'category_language_code' => $languageCode
        ]);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $haystack = implode(' ', [
                $row['name'] ?? '',
                $row['description'] ?? '',
                $row['sku'] ?? '',
                $row['brand'] ?? '',
                $row['translated_name'] ?? '',
                $row['translated_description'] ?? '',
                $row['category_name'] ?? '',
                $row['category_description'] ?? '',
                $row['translated_category_name'] ?? '',
                $row['translated_category_description'] ?? ''
            ]);

            if (!self::containsUnicode($haystack, $query)) {
                continue;
            }

            unset(
                $row['translated_name'],
                $row['translated_description'],
                $row['category_name'],
                $row['category_description'],
                $row['translated_category_name'],
                $row['translated_category_description']
            );

            $result[] = $row;

            if (count($result) >= 100) {
                break;
            }
        }

        return $result;
    }


    private static function searchCategoriesFallback($query, $languageCode)
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
                c.image,
                ct.name AS translated_name,
                ct.description AS translated_description
            FROM categories c
            LEFT JOIN category_translations ct
                ON ct.category_id = c.id
               AND ct.language_code = :language_code
               AND ct.status IN ('approved', 'outdated')
            WHERE c.is_active = 1
            ORDER BY c.sort_order ASC, c.name ASC
        ");

        $stmt->execute([
            'language_code' => $languageCode
        ]);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $haystack = implode(' ', [
                $row['name'] ?? '',
                $row['description'] ?? '',
                $row['translated_name'] ?? '',
                $row['translated_description'] ?? ''
            ]);

            if (!self::containsUnicode($haystack, $query)) {
                continue;
            }

            unset($row['translated_name'], $row['translated_description']);
            $result[] = $row;

            if (count($result) >= 50) {
                break;
            }
        }

        return $result;
    }


    private static function containsUnicode($haystack, $needle)
    {
        $haystack = (string) $haystack;
        $needle = (string) $needle;

        if ($needle === '') {
            return true;
        }

        if (function_exists('mb_stripos')) {
            return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
        }

        return stripos($haystack, $needle) !== false
            || strpos($haystack, $needle) !== false;
    }
}
