<?php

class Cart
{
    public static function getOrCreateByUserId($userId)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return null;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT *
            FROM carts
            WHERE user_id = ?
            LIMIT 1
        ");

        $stmt->execute([$userId]);

        $cart = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cart) {
            return $cart;
        }

        $stmt = $db->prepare("
            INSERT INTO carts (user_id)
            VALUES (?)
        ");

        $stmt->execute([$userId]);

        return [
            'id' => (int) $db->lastInsertId(),
            'user_id' => $userId
        ];
    }


    public static function mergeSessionCart($userId, $sessionCart)
    {
        if (empty($sessionCart) || !is_array($sessionCart)) {
            return;
        }

        foreach ($sessionCart as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $sizeId = (int) ($item['size_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($productId <= 0 || $sizeId <= 0 || $quantity <= 0) {
                continue;
            }

            self::addItem(
                $userId,
                $productId,
                $sizeId,
                $quantity
            );
        }
    }


    public static function getItemsByUserId($userId)
    {
        $cart = self::getOrCreateByUserId($userId);

        if (!$cart) {
            return [];
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                id,
                cart_id,
                product_id,
                size_id,
                quantity
            FROM cart_items
            WHERE cart_id = :cart_id
            ORDER BY id ASC
        ");

        $stmt->execute([
            'cart_id' => $cart['id']
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /**
     * Добавить товар в корзину пользователя.
     *
     * total:
     * проверяем общий остаток товара по всем размерам.
     *
     * by_size:
     * проверяем остаток конкретного размера.
     */
    public static function addItem(
        $userId,
        $productId,
        $sizeId,
        $quantity = 1
    ) {
        $userId = (int) $userId;
        $productId = (int) $productId;
        $sizeId = (int) $sizeId;
        $quantity = (int) $quantity;

        if (
            $userId <= 0
            || $productId <= 0
            || $sizeId <= 0
            || $quantity <= 0
        ) {
            return false;
        }

        $product = Product::findById($productId);

        if (!$product) {
            return false;
        }

        /*
         * Размер должен действительно принадлежать товару.
         * Это проверяем независимо от режима остатков.
         */
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT 1
            FROM product_attributes
            WHERE product_id = :product_id
              AND attribute_value_id = :size_id
            LIMIT 1
        ");

        $stmt->execute([
            'product_id' => $productId,
            'size_id' => $sizeId
        ]);

        if (!$stmt->fetchColumn()) {
            return false;
        }

        $cart = self::getOrCreateByUserId($userId);

        if (!$cart) {
            return false;
        }

        $stockLimit = Product::getStockLimit(
            $product,
            $sizeId
        );

        $currentQuantity = self::getCurrentQuantityForMode(
            $userId,
            $product,
            $sizeId
        );

        if ($currentQuantity + $quantity > $stockLimit) {
            return false;
        }

        $stmt = $db->prepare("
            SELECT
                id,
                quantity
            FROM cart_items
            WHERE cart_id = :cart_id
              AND product_id = :product_id
              AND size_id = :size_id
            LIMIT 1
        ");

        $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => $productId,
            'size_id' => $sizeId
        ]);

        $existingItem = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingItem) {
            $newQuantity =
                (int) $existingItem['quantity']
                + $quantity;

            $stmt = $db->prepare("
                UPDATE cart_items
                SET quantity = :quantity
                WHERE id = :id
            ");

            return $stmt->execute([
                'quantity' => $newQuantity,
                'id' => $existingItem['id']
            ]);
        }

        $stmt = $db->prepare("
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
        ");

        return $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => $productId,
            'size_id' => $sizeId,
            'quantity' => $quantity
        ]);
    }


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


    public static function decreaseItem(
        $userId,
        $productId,
        $sizeId
    ) {
        $cart = self::getOrCreateByUserId($userId);

        if (!$cart) {
            return false;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                id,
                quantity
            FROM cart_items
            WHERE cart_id = :cart_id
              AND product_id = :product_id
              AND size_id = :size_id
            LIMIT 1
        ");

        $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => (int) $productId,
            'size_id' => (int) $sizeId
        ]);

        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            return false;
        }

        if ((int) $item['quantity'] <= 1) {
            $stmt = $db->prepare("
                DELETE FROM cart_items
                WHERE id = :id
            ");

            return $stmt->execute([
                'id' => $item['id']
            ]);
        }

        $stmt = $db->prepare("
            UPDATE cart_items
            SET quantity = quantity - 1
            WHERE id = :id
        ");

        return $stmt->execute([
            'id' => $item['id']
        ]);
    }


    public static function removeItem(
        $userId,
        $productId,
        $sizeId
    ) {
        $cart = self::getOrCreateByUserId($userId);

        if (!$cart) {
            return false;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            DELETE FROM cart_items
            WHERE cart_id = :cart_id
              AND product_id = :product_id
              AND size_id = :size_id
        ");

        return $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => (int) $productId,
            'size_id' => (int) $sizeId
        ]);
    }


    public static function getProductQuantity(
        $userId,
        $productId
    ) {
        $cart = self::getOrCreateByUserId($userId);

        if (!$cart) {
            return 0;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                COALESCE(SUM(quantity), 0) AS total_quantity
            FROM cart_items
            WHERE cart_id = :cart_id
              AND product_id = :product_id
        ");

        $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => (int) $productId
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($result['total_quantity'] ?? 0);
    }


    public static function getSizeQuantity(
        $userId,
        $productId,
        $sizeId
    ) {
        $cart = self::getOrCreateByUserId($userId);

        if (!$cart) {
            return 0;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT quantity
            FROM cart_items
            WHERE cart_id = :cart_id
              AND product_id = :product_id
              AND size_id = :size_id
            LIMIT 1
        ");

        $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => (int) $productId,
            'size_id' => (int) $sizeId
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result
            ? (int) $result['quantity']
            : 0;
    }


    public static function getCurrentQuantityForMode(
        $userId,
        $product,
        $sizeId
    ) {
        $productId = (int) ($product['id'] ?? 0);
        $stockMode = $product['stock_mode'] ?? 'total';

        if ($productId <= 0) {
            return 0;
        }

        if ($stockMode === 'by_size') {
            return self::getSizeQuantity(
                $userId,
                $productId,
                $sizeId
            );
        }

        return self::getProductQuantity(
            $userId,
            $productId
        );
    }


    public static function getDetailedItemsByUserId($userId)
    {
        $dbItems = self::getItemsByUserId($userId);
        $items = [];

        foreach ($dbItems as $item) {
            $product = Product::findById(
                $item['product_id']
            );

            if (!$product) {
                continue;
            }

            $size = Product::getAttributeValueById(
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


    public static function clearByUserId($userId)
    {
        $cart = self::getOrCreateByUserId($userId);

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
