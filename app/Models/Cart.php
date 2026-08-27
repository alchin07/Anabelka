<?php

class Cart
{
    /**
     * Получить корзину пользователя.
     *
     * Если корзины ещё нет —
     * автоматически создаём её.
     */
    public static function getOrCreateByUserId($userId)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return null;
        }

        $db = Database::connect();


        // Ищем существующую корзину.
        $stmt = $db->prepare(
            "
            SELECT *
            FROM carts
            WHERE user_id = ?
            LIMIT 1
            "
        );

        $stmt->execute([
            $userId
        ]);

        $cart =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        // Корзина уже существует.
        if ($cart) {
            return $cart;
        }


        // Корзины ещё нет — создаём.
        $stmt = $db->prepare(
            "
            INSERT INTO carts
                (user_id)
            VALUES
                (?)
            "
        );

        $stmt->execute([
            $userId
        ]);


        return [
            'id' =>
                (int) $db->lastInsertId(),

            'user_id' =>
                $userId
        ];
    }


    /**
     * Перенести гостевую корзину
     * из сессии в корзину пользователя.
     */
    public static function mergeSessionCart(
        $userId,
        $sessionCart
    ) {
        if (
            empty($sessionCart) ||
            !is_array($sessionCart)
        ) {
            return;
        }


        foreach ($sessionCart as $item) {

            $productId =
                (int) (
                    $item['product_id']
                    ?? 0
                );

            $sizeId =
                (int) (
                    $item['size_id']
                    ?? 0
                );

            $quantity =
                (int) (
                    $item['quantity']
                    ?? 0
                );


            if (
                $productId <= 0 ||
                $sizeId <= 0 ||
                $quantity <= 0
            ) {
                continue;
            }


            /*
             * Добавляем через общий метод.
             *
             * Он сам проверит остаток
             * конкретного размера.
             */
            self::addItem(
                $userId,
                $productId,
                $sizeId,
                $quantity
            );
        }
    }


    /**
     * Получить все позиции
     * корзины пользователя.
     */
    public static function getItemsByUserId(
        $userId
    ) {
        $cart =
            self::getOrCreateByUserId(
                $userId
            );

        if (!$cart) {
            return [];
        }


        $db = Database::connect();

        $stmt = $db->prepare(
            "
            SELECT
                id,
                cart_id,
                product_id,
                size_id,
                quantity

            FROM cart_items

            WHERE cart_id = :cart_id

            ORDER BY id ASC
            "
        );

        $stmt->execute([
            'cart_id' =>
                $cart['id']
        ]);


        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }


    /**
     * Добавить товар в корзину пользователя.
     *
     * Не позволяет превысить остаток
     * конкретного размера.
     */
    public static function addItem(
        $userId,
        $productId,
        $sizeId,
        $quantity = 1
    ) {
        $userId =
            (int) $userId;

        $productId =
            (int) $productId;

        $sizeId =
            (int) $sizeId;

        $quantity =
            (int) $quantity;


        if (
            $userId <= 0 ||
            $productId <= 0 ||
            $sizeId <= 0 ||
            $quantity <= 0
        ) {
            return false;
        }


        $cart =
            self::getOrCreateByUserId(
                $userId
            );

        if (!$cart) {
            return false;
        }


        $db = Database::connect();


        /*
         * Получаем остаток конкретного размера.
         */
        $stmt = $db->prepare(
            "
            SELECT stock

            FROM product_attributes

            WHERE product_id = :product_id
              AND attribute_value_id = :size_id

            LIMIT 1
            "
        );

        $stmt->execute([
            'product_id' =>
                $productId,

            'size_id' =>
                $sizeId
        ]);

        $stockRow =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$stockRow) {
            return false;
        }


        $stock =
            (int) $stockRow['stock'];


        /*
         * Проверяем, есть ли уже
         * такая позиция в корзине.
         */
        $stmt = $db->prepare(
            "
            SELECT
                id,
                quantity

            FROM cart_items

            WHERE cart_id = :cart_id
              AND product_id = :product_id
              AND size_id = :size_id

            LIMIT 1
            "
        );

        $stmt->execute([
            'cart_id' =>
                $cart['id'],

            'product_id' =>
                $productId,

            'size_id' =>
                $sizeId
        ]);

        $existingItem =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        /*
         * Позиция уже существует.
         */
        if ($existingItem) {

            $currentQuantity =
                (int) $existingItem['quantity'];

            $newQuantity =
                $currentQuantity
                + $quantity;


            if ($newQuantity > $stock) {
                return false;
            }


            $stmt = $db->prepare(
                "
                UPDATE cart_items

                SET quantity = :quantity

                WHERE id = :id
                "
            );

            return $stmt->execute([
                'quantity' =>
                    $newQuantity,

                'id' =>
                    $existingItem['id']
            ]);
        }


        /*
         * Позиции ещё нет.
         */
        if ($quantity > $stock) {
            return false;
        }


        $stmt = $db->prepare(
            "
            INSERT INTO cart_items
                (
                    cart_id,
                    product_id,
                    size_id,
                    quantity
                )
            VALUES
                (
                    :cart_id,
                    :product_id,
                    :size_id,
                    :quantity
                )
            "
        );


        return $stmt->execute([
            'cart_id' =>
                $cart['id'],

            'product_id' =>
                $productId,

            'size_id' =>
                $sizeId,

            'quantity' =>
                $quantity
        ]);
    }


    /**
     * Увеличить количество товара на 1.
     *
     * Не позволяет превысить остаток
     * конкретного размера.
     */
    public static function increaseItem(
        $userId,
        $productId,
        $sizeId
    ) {
        return self::addItem(
            $userId,
            $productId,
            $sizeId,
            1
        );
    }


    /**
     * Уменьшить количество товара на 1.
     *
     * Если осталась одна штука —
     * полностью удаляем позицию.
     */
    public static function decreaseItem(
        $userId,
        $productId,
        $sizeId
    ) {
        $cart =
            self::getOrCreateByUserId(
                $userId
            );

        if (!$cart) {
            return false;
        }


        $db = Database::connect();


        $stmt = $db->prepare(
            "
            SELECT
                id,
                quantity

            FROM cart_items

            WHERE cart_id = :cart_id
              AND product_id = :product_id
              AND size_id = :size_id

            LIMIT 1
            "
        );

        $stmt->execute([
            'cart_id' =>
                $cart['id'],

            'product_id' =>
                $productId,

            'size_id' =>
                $sizeId
        ]);

        $item =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$item) {
            return false;
        }


        $quantity =
            (int) $item['quantity'];


        /*
         * Если одна штука —
         * удаляем позицию.
         */
        if ($quantity <= 1) {

            $stmt = $db->prepare(
                "
                DELETE FROM cart_items

                WHERE id = :id
                "
            );

            return $stmt->execute([
                'id' =>
                    $item['id']
            ]);
        }


        /*
         * Иначе уменьшаем на 1.
         */
        $stmt = $db->prepare(
            "
            UPDATE cart_items

            SET quantity =
                quantity - 1

            WHERE id = :id
            "
        );


        return $stmt->execute([
            'id' =>
                $item['id']
        ]);
    }


    /**
     * Полностью удалить позицию
     * из корзины пользователя.
     */
    public static function removeItem(
        $userId,
        $productId,
        $sizeId
    ) {
        $cart =
            self::getOrCreateByUserId(
                $userId
            );

        if (!$cart) {
            return false;
        }


        $db = Database::connect();

        $stmt = $db->prepare(
            "
            DELETE FROM cart_items

            WHERE cart_id = :cart_id
              AND product_id = :product_id
              AND size_id = :size_id
            "
        );


        return $stmt->execute([
            'cart_id' =>
                $cart['id'],

            'product_id' =>
                $productId,

            'size_id' =>
                $sizeId
        ]);
    }


    /**
     * Сколько всего штук конкретного товара
     * находится в корзине пользователя.
     *
     * Суммируем все размеры.
     */
    public static function getProductQuantity(
        $userId,
        $productId
    ) {
        $cart =
            self::getOrCreateByUserId(
                $userId
            );

        if (!$cart) {
            return 0;
        }


        $db = Database::connect();

        $stmt = $db->prepare(
            "
            SELECT
                COALESCE(
                    SUM(quantity),
                    0
                ) AS total_quantity

            FROM cart_items

            WHERE cart_id = :cart_id
              AND product_id = :product_id
            "
        );

        $stmt->execute([
            'cart_id' =>
                $cart['id'],

            'product_id' =>
                $productId
        ]);

        $result =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        return (int) (
            $result['total_quantity']
            ?? 0
        );
    }


    /**
     * Сколько штук конкретного размера
     * товара находится в корзине пользователя.
     */
    public static function getSizeQuantity(
        $userId,
        $productId,
        $sizeId
    ) {
        $cart =
            self::getOrCreateByUserId(
                $userId
            );

        if (!$cart) {
            return 0;
        }


        $db = Database::connect();

        $stmt = $db->prepare(
            "
            SELECT quantity

            FROM cart_items

            WHERE cart_id = :cart_id
              AND product_id = :product_id
              AND size_id = :size_id

            LIMIT 1
            "
        );

        $stmt->execute([
            'cart_id' =>
                $cart['id'],

            'product_id' =>
                $productId,

            'size_id' =>
                $sizeId
        ]);

        $result =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$result) {
            return 0;
        }


        return (int) $result['quantity'];
    }

  /**
 * Получить текущее количество товара в корзине
 * с учётом режима складского остатка.
 *
 * by_size:
 * считаем только конкретный размер.
 *
 * total:
 * считаем весь товар по всем размерам.
 */
public static function getCurrentQuantityForMode(
    $userId,
    $product,
    $sizeId
) {
    $productId =
        (int) ($product['id'] ?? 0);

    $stockMode =
        $product['stock_mode']
        ?? 'total';


    if ($productId <= 0) {
        return 0;
    }


    /*
     * Остаток считается отдельно
     * для каждого размера.
     */
    if ($stockMode === 'by_size') {

        return self::getSizeQuantity(
            $userId,
            $productId,
            $sizeId
        );
    }


    /*
     * Общий остаток товара:
     * суммируем все размеры.
     */
    return self::getProductQuantity(
        $userId,
        $productId
    );
}

  /**
 * Получить готовые позиции корзины пользователя
 * для оформления заказа.
 */
public static function getDetailedItemsByUserId($userId)
{
    $dbItems =
        self::getItemsByUserId(
            $userId
        );

    $items = [];

    foreach ($dbItems as $item) {

        $product =
            Product::findById(
                $item['product_id']
            );

        if (!$product) {
            continue;
        }

        $size =
            Product::getAttributeValueById(
                $item['size_id']
            );

        $items[] = [
            'product' => $product,
            'size_id' => $item['size_id'],
            'size' => $size,
            'quantity' => (int) $item['quantity']
        ];
    }

    return $items;
}

  /**
 * Очистить корзину пользователя.
 */
public static function clearByUserId($userId)
{
    $cart =
        self::getOrCreateByUserId(
            $userId
        );

    if (!$cart) {
        return false;
    }

    $db = Database::connect();

    $stmt = $db->prepare("
        DELETE FROM cart_items
        WHERE cart_id = :cart_id
    ");

    return $stmt->execute([
        'cart_id' => $cart['id']
    ]);
}
}