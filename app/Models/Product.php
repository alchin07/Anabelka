<?php

class Product
{
    /**
     * Получить активные товары
     * выбранной категории.
     */
    public static function byCategory($categoryId)
    {
        $db = Database::connect();

        $sql = "
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
                main_image,
                (
                    SELECT av.value
                    FROM product_attributes AS pa
                    JOIN attribute_values AS av
                        ON av.id = pa.attribute_value_id
                    JOIN attributes AS a
                        ON a.id = av.attribute_id
                    WHERE pa.product_id = products.id
                      AND a.slug = 'color'
                    ORDER BY av.id ASC
                    LIMIT 1
                ) AS color,
                (
                    SELECT av.value
                    FROM product_attributes AS pa
                    JOIN attribute_values AS av
                        ON av.id = pa.attribute_value_id
                    JOIN attributes AS a
                        ON a.id = av.attribute_id
                    WHERE pa.product_id = products.id
                      AND a.slug = 'material'
                    ORDER BY av.id ASC
                    LIMIT 1
                ) AS material
            FROM products
            WHERE category_id = :category_id
              AND is_active = 1
            ORDER BY id DESC
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            'category_id' => $categoryId
        ]);

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }


    /**
     * Найти товар по slug.
     */
    public static function findBySlug($slug)
    {
        $db = Database::connect();

        $sql = "
            SELECT *
            FROM products
            WHERE slug = :slug
              AND is_active = 1
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            'slug' => $slug
        ]);

        return $stmt->fetch(
            PDO::FETCH_ASSOC
        );
    }


    /**
     * Найти товар по ID.
     */
    public static function findById($id)
    {
        $db = Database::connect();

        $sql = "
            SELECT *
            FROM products
            WHERE id = :id
              AND is_active = 1
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            'id' => (int) $id
        ]);

        return $stmt->fetch(
            PDO::FETCH_ASSOC
        );
    }


    /**
     * Фотографії товару: головна першою, далі у заданому порядку.
     */
    public static function images($productId)
    {
        return ProductImage::forProduct((int) $productId);
    }


    /**
     * Получить атрибуты товара.
     *
     * Для размеров также возвращается
     * остаток конкретного размера.
     */
    public static function attributes($productId)
    {
        $db = Database::connect();

        $sql = "
            SELECT
                a.name AS attribute_name,
                a.slug AS attribute_slug,
                av.id AS value_id,
                av.value,
                av.color_hex,
                pa.stock
            FROM product_attributes pa

            JOIN attribute_values av
                ON av.id = pa.attribute_value_id

            JOIN attributes a
                ON a.id = av.attribute_id

            WHERE pa.product_id = :product_id

            ORDER BY a.id, av.id
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            'product_id' => $productId
        ]);

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }


    /**
     * Получить конкретное значение атрибута.
     *
     * Например:
     * размер 75B.
     */
    public static function getAttributeValueById($valueId)
    {
        $db = Database::connect();

        $sql = "
            SELECT
                av.id,
                av.attribute_id,
                av.value,
                av.color_hex,
                a.name AS attribute_name,
                a.slug AS attribute_slug
            FROM attribute_values av

            JOIN attributes a
                ON a.id = av.attribute_id

            WHERE av.id = :id

            LIMIT 1
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            'id' => (int) $valueId
        ]);

        return $stmt->fetch(
            PDO::FETCH_ASSOC
        );
    }


    /**
     * Получить slug текущего
     * ценового ранга пользователя.
     */
    public static function getCurrentRankSlug()
    {
        if (!empty($_SESSION['user_rank_slug'])) {

            return
                $_SESSION['user_rank_slug'];
        }

        return 'guest';
    }


    /**
     * Получить все ценовые уровни,
     * которые может видеть
     * текущий пользователь.
     *
     * Гость:
     * только обычную цену.
     *
     * Персональный клиент:
     * обычную + персональную.
     *
     * VIP:
     * обычную + персональную + VIP.
     */
    public static function getPricesByRanks($productId)
    {
        $db = Database::connect();

        $currentRankSlug =
            self::getCurrentRankSlug();


        /*
         * Определяем уровень
         * текущего пользователя.
         */
        $rankSql = "
            SELECT level
            FROM user_ranks
            WHERE slug = :slug
              AND is_active = 1
            LIMIT 1
        ";

        $rankStmt =
            $db->prepare($rankSql);

        $rankStmt->execute([
            'slug' => $currentRankSlug
        ]);

        $currentRank =
            $rankStmt->fetch(
                PDO::FETCH_ASSOC
            );


        $currentLevel =
            $currentRank
                ? (int) $currentRank['level']
                : 0;


        /*
         * Получаем все уровни цен
         * до текущего ранга включительно.
         */
        $sql = "
            SELECT
                ur.id AS rank_id,
                ur.name AS rank_name,
                ur.slug AS rank_slug,
                ur.level,
                pp.price
            FROM user_ranks ur

            JOIN product_prices pp
                ON pp.rank_id = ur.id

            WHERE pp.product_id = :product_id
              AND ur.is_active = 1
              AND ur.level <= :current_level

            ORDER BY ur.level ASC
        ";

        $stmt =
            $db->prepare($sql);

        $stmt->execute([
            'product_id' => $productId,
            'current_level' => $currentLevel
        ]);

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }


    /**
     * Получить максимальную
     * активную скидку товара.
     */
    public static function getActiveDiscountPercent(
        $productId
    ) {
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                MAX(discount_percent)
                    AS discount_percent

            FROM product_badges

            WHERE product_id = :product_id
              AND is_active = 1
              AND discount_percent IS NOT NULL
              AND discount_percent > 0
        ");

        $stmt->execute([
            'product_id' => $productId
        ]);

        $result =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return (float) (
            $result['discount_percent']
            ?? 0
        );
    }


    /**
     * Получить актуальную цену товара
     * для текущего пользователя.
     *
     * Сначала:
     * цена по рангу.
     *
     * Затем:
     * активная скидка товара.
     */
    public static function getCurrentPrice($product)
    {
        $db = Database::connect();

        $productId =
            (int) $product['id'];

        $currentRankSlug =
            self::getCurrentRankSlug();


        /*
         * Получаем цену
         * текущего ранга.
         */
        $sql = "
            SELECT pp.price

            FROM product_prices pp

            INNER JOIN user_ranks ur
                ON ur.id = pp.rank_id

            WHERE pp.product_id = :product_id
              AND ur.slug = :rank_slug
              AND ur.is_active = 1

            LIMIT 1
        ";

        $stmt =
            $db->prepare($sql);

        $stmt->execute([
            'product_id' => $productId,
            'rank_slug' => $currentRankSlug
        ]);

        $priceRow =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        /*
         * Если отдельная цена ранга
         * не найдена — используем
         * обычную цену товара.
         */
        if ($priceRow) {

            $price =
                (float) $priceRow['price'];

        } else {

            $price =
                (float) $product['price'];
        }


        /*
         * Применяем активную скидку.
         */
        $discount =
            self::getActiveDiscountPercent(
                $productId
            );


        if ($discount > 0) {

            $price =
                $price
                * (
                    1
                    - $discount / 100
                );
        }


        return round(
            $price,
            2
        );
    }


    /**
     * Получить остаток
     * конкретного размера товара.
     */
    public static function getSizeStock(
        $productId,
        $sizeId
    ) {
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT stock

            FROM product_attributes

            WHERE product_id = :product_id
              AND attribute_value_id = :size_id

            LIMIT 1
        ");

        $stmt->execute([
            'product_id' => $productId,
            'size_id' => $sizeId
        ]);

        $result =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$result) {
            return 0;
        }


        return (int) $result['stock'];
    }


    /**
     * Получить допустимый остаток
     * с учётом режима товара.
     *
     * total:
     * общий products.stock.
     *
     * by_size:
     * остаток конкретного размера.
     */
    public static function getStockLimit(
        $product,
        $sizeId
    ) {
        $stockMode =
            $product['stock_mode']
            ?? 'total';


        if ($stockMode === 'by_size') {

            return self::getSizeStock(
                $product['id'],
                $sizeId
            );
        }


        return (int) (
            $product['stock']
            ?? 0
        );
    }


    /**
     * Проверить,
     * есть ли товар в наличии.
     *
     * total:
     * проверяем общий остаток.
     *
     * by_size:
     * достаточно хотя бы одного
     * размера с остатком > 0.
     */
    public static function isAvailable($product)
    {
        $productId =
            (int) ($product['id'] ?? 0);


        if ($productId <= 0) {
            return false;
        }


        $stockMode =
            $product['stock_mode']
            ?? 'total';


        /*
         * Общий остаток.
         */
        if ($stockMode === 'total') {

            return (int) (
                $product['stock']
                ?? 0
            ) > 0;
        }


        /*
         * Остаток по размерам.
         */
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT COUNT(*)
                AS available_count

            FROM product_attributes

            WHERE product_id = :product_id
              AND stock > 0
        ");

        $stmt->execute([
            'product_id' => $productId
        ]);

        $result =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        return (int) (
            $result['available_count']
            ?? 0
        ) > 0;
    }


    /**
     * Получить активные
     * информационные бейджи товара.
     *
     * Например:
     * Новый товар,
     * Скидка 10%.
     */
    public static function getBadges($productId)
    {
        $db = Database::connect();

        $sql = "
            SELECT
                id,
                product_id,
                type,
                label,
                discount_percent,
                is_active,
                sort_order

            FROM product_badges

            WHERE product_id = :product_id
              AND is_active = 1

            ORDER BY
                sort_order ASC,
                id ASC
        ";

        $stmt =
            $db->prepare($sql);

        $stmt->execute([
            'product_id' => $productId
        ]);

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }
}
