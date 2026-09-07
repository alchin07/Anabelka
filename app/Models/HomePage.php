<?php

class HomePage
{
    private static $adultCategoryIds = null;


    /**
     * Активные корневые направления/категории для главной страницы.
     *
     * Пока данные берутся из существующего дерева категорий. В будущем
     * этот метод станет источником для конструктора главной страницы,
     * не меняя контракт контроллера и будущего API.
     */
    public static function directions()
    {
        $directions = Category::all();

        foreach ($directions as &$direction) {
            $direction['is_adult'] = self::isAdultCategoryId(
                (int) ($direction['id'] ?? 0)
            );
        }
        unset($direction);

        return $directions;
    }


    /**
     * Полное дерево активных категорий для навигации на широком экране.
     * Уровни не получают дополнительных визуальных отступов — это решает UI.
     */
    public static function navigationTree()
    {
        $db = Database::connect();
        $rows = $db->query("
            SELECT
                id,
                department_id,
                parent_id,
                name,
                slug,
                description,
                image,
                sort_order
            FROM categories
            WHERE is_active = 1
            ORDER BY
                COALESCE(parent_id, 0) ASC,
                sort_order ASC,
                name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($rows)) {
            return [];
        }

        $childrenByParent = [];

        foreach ($rows as $row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['parent_id'] = isset($row['parent_id'])
                ? (int) $row['parent_id']
                : 0;
            $row['is_adult'] = self::isAdultCategoryId($row['id']);
            $row['children'] = [];
            $childrenByParent[$row['parent_id']][] = $row;
        }

        $build = function ($parentId) use (&$build, &$childrenByParent) {
            $nodes = $childrenByParent[(int) $parentId] ?? [];

            foreach ($nodes as &$node) {
                $node['children'] = $build((int) $node['id']);
            }
            unset($node);

            return $nodes;
        };

        $roots = $build(0);

        usort($roots, function ($a, $b) {
            $aAdult = !empty($a['is_adult']) ? 1 : 0;
            $bAdult = !empty($b['is_adult']) ? 1 : 0;

            if ($aAdult !== $bAdult) {
                return $aAdult <=> $bAdult;
            }

            return 0;
        });

        return $roots;
    }


    /**
     * Последние активные товары для блока «Новинки».
     *
     * В обычную главную страницу намеренно не попадают товары из ветки 18+.
     */
    public static function latestProducts($limit = 8)
    {
        $limit = max(1, min(24, (int) $limit));
        $db = Database::connect();
        $adultIds = self::adultCategoryIds();
        $adultFilter = '';

        if (!empty($adultIds)) {
            $adultFilter = ' AND category_id NOT IN ('
                . implode(',', array_map('intval', $adultIds))
                . ')';
        }

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
            {$adultFilter}
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


    public static function isAdultCategoryId($categoryId)
    {
        $categoryId = (int) $categoryId;

        if ($categoryId <= 0) {
            return false;
        }

        return in_array(
            $categoryId,
            self::adultCategoryIds(),
            true
        );
    }


    private static function adultCategoryIds()
    {
        if (is_array(self::$adultCategoryIds)) {
            return self::$adultCategoryIds;
        }

        $db = Database::connect();
        $rows = $db->query("
            SELECT id, parent_id, name, slug
            FROM categories
            WHERE is_active = 1
            ORDER BY id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $adultIds = [];
        $childrenByParent = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $parentId = isset($row['parent_id'])
                ? (int) $row['parent_id']
                : 0;

            if ($id <= 0) {
                continue;
            }

            if ($parentId > 0) {
                $childrenByParent[$parentId][] = $id;
            }

            if ($parentId === 0 && self::looksAdult($row)) {
                $adultIds[] = $id;
            }
        }

        $queue = $adultIds;

        while (!empty($queue)) {
            $parentId = array_shift($queue);

            foreach ($childrenByParent[$parentId] ?? [] as $childId) {
                if (in_array($childId, $adultIds, true)) {
                    continue;
                }

                $adultIds[] = $childId;
                $queue[] = $childId;
            }
        }

        self::$adultCategoryIds = array_values(array_unique($adultIds));

        return self::$adultCategoryIds;
    }


    private static function looksAdult(array $category)
    {
        $slug = trim((string) ($category['slug'] ?? ''));
        $name = trim((string) ($category['name'] ?? ''));
        $text = trim($slug . ' ' . $name);

        $text = function_exists('mb_strtolower')
            ? mb_strtolower($text, 'UTF-8')
            : strtolower($text);
        $slugLower = strtolower($slug);

        $markers = [
            '18+',
            '18-plus',
            'adult',
            'intim',
            'intimate',
            'інтим',
            'интим',
            'ерот',
            'erot'
        ];

        foreach ($markers as $marker) {
            if (strpos($text, $marker) !== false) {
                return true;
            }
        }

        return (bool) preg_match(
            '/(^|[-_])(sex|seks)([-_]|$)/',
            $slugLower
        );
    }
}
