<?php

class StorefrontProductCollection
{
    private const PER_PAGE = 24;
    private const KINDS = [
        'discounts',
        'new'
    ];


    public static function page($kind, $pageInput, $languageCode)
    {
        $kind = strtolower(trim((string) $kind));

        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException(
                'Невідома колекція товарів.'
            );
        }

        $visibleIds = Category::visibleCategoryIds();
        $adultIds = Category::adultCategoryIds();
        $allowedIds = array_values(array_unique(array_filter(
            array_map(
                'intval',
                array_diff($visibleIds, $adultIds)
            ),
            static function ($id) {
                return $id > 0;
            }
        )));

        if (empty($allowedIds)) {
            $meta = self::pageMeta($pageInput, 0);
            $meta['items'] = [];
            return $meta;
        }

        $db = Database::connect();
        $rankSlug = (string) Product::getCurrentRankSlug();
        $categoryParams = [];
        $categoryPlaceholders = [];

        foreach ($allowedIds as $index => $categoryId) {
            $key = 'category_' . $index;
            $categoryPlaceholders[] = ':' . $key;
            $categoryParams[$key] = (int) $categoryId;
        }

        $baseSql = self::pricedProductSql(
            implode(', ', $categoryPlaceholders)
        );
        $predicate = $kind === 'discounts'
            ? '(
                q.active_discount_percent > 0
                OR q.old_price > q.current_price
            )'
            : '1 = 1';

        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM (
                {$baseSql}
            ) AS q
            WHERE {$predicate}
        ");
        self::bindCommonParams(
            $countStmt,
            $rankSlug,
            $categoryParams
        );
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();
        $meta = self::pageMeta($pageInput, $total);
        $offset = ($meta['page'] - 1) * self::PER_PAGE;

        $pageStmt = $db->prepare("
            SELECT q.*
            FROM (
                {$baseSql}
            ) AS q
            WHERE {$predicate}
            ORDER BY q.id DESC
            LIMIT :limit OFFSET :offset
        ");
        self::bindCommonParams(
            $pageStmt,
            $rankSlug,
            $categoryParams
        );
        $pageStmt->bindValue(
            ':limit',
            self::PER_PAGE,
            PDO::PARAM_INT
        );
        $pageStmt->bindValue(
            ':offset',
            $offset,
            PDO::PARAM_INT
        );
        $pageStmt->execute();
        $products = $pageStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$collectionItem) {
            $collectionItem['current_price'] = (float) (
                $collectionItem['current_price'] ?? 0
            );
            $collectionItem['active_discount_percent'] = (float) (
                $collectionItem['active_discount_percent'] ?? 0
            );
            $collectionItem['display_discount_percent'] =
                self::discountPercent(
                    $collectionItem['old_price'] ?? null,
                    $collectionItem['current_price'],
                    $collectionItem['active_discount_percent']
                );
        }
        unset($collectionItem);

        $products = ProductTranslator::localizeList(
            $products,
            (string) $languageCode
        );
        $productIds = array_map(
            static function ($collectionItem) {
                return (int) ($collectionItem['id'] ?? 0);
            },
            $products
        );
        $colorVariants = ProductImage::colorVariantsForProducts(
            $productIds
        );

        foreach ($products as &$collectionItem) {
            $productId = (int) ($collectionItem['id'] ?? 0);
            $collectionItem['color_variants'] =
                $colorVariants[$productId] ?? [];
        }
        unset($collectionItem);

        $meta['items'] = $products;

        return $meta;
    }


    public static function pageMeta($pageInput, $total)
    {
        $total = max(0, (int) $total);
        $totalPages = max(
            1,
            (int) ceil($total / self::PER_PAGE)
        );
        $page = self::normalizePage($pageInput);
        $page = min($page, $totalPages);

        return [
            'page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_previous' => $page > 1,
            'has_next' => $page < $totalPages
        ];
    }


    public static function discountPercent(
        $oldPrice,
        $currentPrice,
        $activePercent
    ) {
        $activePercent = self::finiteNumber($activePercent);

        if (
            $activePercent !== null
            && $activePercent > 0
            && $activePercent <= 100
        ) {
            return round($activePercent, 2);
        }

        $oldPrice = self::finiteNumber($oldPrice);
        $currentPrice = self::finiteNumber($currentPrice);

        if (
            $oldPrice === null
            || $currentPrice === null
            || $oldPrice <= 0
            || $currentPrice < 0
            || $oldPrice <= $currentPrice
        ) {
            return null;
        }

        return round(
            (($oldPrice - $currentPrice) / $oldPrice) * 100,
            2
        );
    }


    private static function pricedProductSql($categoryPlaceholderList)
    {
        return "
            SELECT
                r.id,
                r.category_id,
                r.name,
                r.slug,
                r.sku,
                r.description,
                r.price,
                r.member_price,
                r.old_price,
                r.stock,
                r.stock_mode,
                r.show_stock_quantity,
                r.brand,
                r.country,
                r.main_image,
                r.active_discount_percent,
                ROUND(
                    r.rank_price
                    * (
                        1
                        - r.active_discount_percent / 100
                    ),
                    2
                ) AS current_price
            FROM (
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
                    COALESCE(
                        (
                            SELECT pp.price
                            FROM product_prices AS pp
                            INNER JOIN user_ranks AS ur
                                ON ur.id = pp.rank_id
                            WHERE pp.product_id = p.id
                              AND ur.slug = :rank_slug
                              AND ur.is_active = 1
                            LIMIT 1
                        ),
                        p.price
                    ) AS rank_price,
                    COALESCE(
                        (
                            SELECT MAX(pb.discount_percent)
                            FROM product_badges AS pb
                            WHERE pb.product_id = p.id
                              AND pb.is_active = 1
                              AND pb.discount_percent IS NOT NULL
                              AND pb.discount_percent > 0
                        ),
                        0
                    ) AS active_discount_percent
                FROM products AS p
                WHERE p.is_active = 1
                  AND p.category_id IN ({$categoryPlaceholderList})
            ) AS r
        ";
    }


    private static function bindCommonParams(
        PDOStatement $stmt,
        $rankSlug,
        array $categoryParams
    ) {
        $stmt->bindValue(
            ':rank_slug',
            (string) $rankSlug,
            PDO::PARAM_STR
        );

        foreach ($categoryParams as $key => $categoryId) {
            $stmt->bindValue(
                ':' . $key,
                (int) $categoryId,
                PDO::PARAM_INT
            );
        }
    }


    private static function normalizePage($pageInput)
    {
        if (is_int($pageInput)) {
            return $pageInput > 0 ? $pageInput : 1;
        }

        if (!is_string($pageInput)) {
            return 1;
        }

        $pageInput = trim($pageInput);

        if (!preg_match('/^[1-9][0-9]*$/', $pageInput)) {
            return 1;
        }

        $validated = filter_var(
            $pageInput,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => PHP_INT_MAX
                ]
            ]
        );

        return $validated === false ? 1 : (int) $validated;
    }


    private static function finiteNumber($value)
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }
}
