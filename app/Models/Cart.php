<?php

class Cart
{
    private static $colorSchemaReady = false;


    public static function ensureColorSupport()
    {
        if (self::$colorSchemaReady) {
            return;
        }

        $db = Database::connect();
        $columns = $db->query("SHOW COLUMNS FROM cart_items")
            ->fetchAll(PDO::FETCH_ASSOC);
        $columnNames = array_map(
            function ($column) {
                return strtolower((string) ($column['Field'] ?? ''));
            },
            $columns
        );

        if (!in_array('color_key', $columnNames, true)) {
            $db->exec("
                ALTER TABLE cart_items
                ADD COLUMN color_key VARCHAR(220) NOT NULL DEFAULT '' AFTER size_id
            ");
        }

        if (!in_array('color_name', $columnNames, true)) {
            $db->exec("
                ALTER TABLE cart_items
                ADD COLUMN color_name VARCHAR(100) NOT NULL DEFAULT '' AFTER color_key
            ");
        }

        if (!in_array('color_hex', $columnNames, true)) {
            $db->exec("
                ALTER TABLE cart_items
                ADD COLUMN color_hex VARCHAR(7) NULL DEFAULT NULL AFTER color_name
            ");
        }

        $indexes = $db->query("SHOW INDEX FROM cart_items")
            ->fetchAll(PDO::FETCH_ASSOC);
        $uniqueIndexes = [];

        foreach ($indexes as $index) {
            if ((int) ($index['Non_unique'] ?? 1) !== 0) {
                continue;
            }

            $name = (string) ($index['Key_name'] ?? '');

            if ($name === '' || strtoupper($name) === 'PRIMARY') {
                continue;
            }

            $sequence = max(1, (int) ($index['Seq_in_index'] ?? 1));
            $uniqueIndexes[$name][$sequence] = strtolower(
                (string) ($index['Column_name'] ?? '')
            );
        }

        $hasColorUnique = false;

        foreach ($uniqueIndexes as $name => $columnsByPosition) {
            ksort($columnsByPosition);
            $names = array_values($columnsByPosition);

            if ($names === ['cart_id', 'product_id', 'size_id']) {
                $safeName = str_replace('`', '``', $name);
                $db->exec("ALTER TABLE cart_items DROP INDEX `{$safeName}`");
                continue;
            }

            if (
                $names === [
                    'cart_id',
                    'product_id',
                    'size_id',
                    'color_key'
                ]
            ) {
                $hasColorUnique = true;
            }
        }

        if (!$hasColorUnique) {
            $db->exec("
                ALTER TABLE cart_items
                ADD UNIQUE KEY unique_cart_product_size_color
                    (cart_id, product_id, size_id, color_key)
            ");
        }

        self::$colorSchemaReady = true;
    }


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
                $quantity,
                $item['color_key'] ?? '',
                $item['color_name'] ?? '',
                $item['color_hex'] ?? ''
            );
        }
    }


    public static function getItemsByUserId($userId)
    {
        self::ensureColorSupport();
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
                color_key,
                color_name,
                color_hex,
                quantity
            FROM cart_items
            WHERE cart_id = :cart_id
            ORDER BY id ASC
        ");
        $stmt->execute(['cart_id' => $cart['id']]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function addItem(
        $userId,
        $productId,
        $sizeId,
        $quantity = 1,
        $colorKey = '',
        $colorName = '',
        $colorHex = ''
    ) {
        self::ensureColorSupport();
        $userId = (int) $userId;
        $productId = (int) $productId;
        $sizeId = (int) $sizeId;
        $quantity = (int) $quantity;
        $colorKey = trim((string) $colorKey);
        $colorName = trim((string) $colorName);
        $colorHex = strtolower(trim((string) $colorHex));

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

        $usesVariantStock = ProductVariantStock::hasMatrix($productId);

        if ($usesVariantStock) {
            if ($colorKey === '') {
                return false;
            }

            $colorInfo = ProductVariantStock::colorInfo(
                $productId,
                $colorKey
            );

            if (!$colorInfo) {
                return false;
            }

            $colorName = (string) $colorInfo['color_name'];
            $colorHex = (string) $colorInfo['color_hex'];
            $stockLimit = ProductVariantStock::stockFor(
                $productId,
                $sizeId,
                $colorKey
            );
            $currentQuantity = self::getVariantQuantity(
                $userId,
                $productId,
                $sizeId,
                $colorKey
            );
        } else {
            $stockLimit = Product::getStockLimit($product, $sizeId);
            $currentQuantity = self::getCurrentQuantityForMode(
                $userId,
                $product,
                $sizeId
            );
        }

        if ($currentQuantity + $quantity > $stockLimit) {
            return false;
        }

        $cart = self::getOrCreateByUserId($userId);

        if (!$cart) {
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
              AND color_key = :color_key
            LIMIT 1
        ");
        $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => $productId,
            'size_id' => $sizeId,
            'color_key' => $colorKey
        ]);
        $existingItem = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingItem) {
            $stmt = $db->prepare("
                UPDATE cart_items
                SET
                    quantity = :quantity,
                    color_name = :color_name,
                    color_hex = :color_hex
                WHERE id = :id
            ");

            return $stmt->execute([
                'quantity' => (int) $existingItem['quantity'] + $quantity,
                'color_name' => $colorName,
                'color_hex' => self::nullableHex($colorHex),
                'id' => $existingItem['id']
            ]);
        }

        $stmt = $db->prepare("
            INSERT INTO cart_items
            (
                cart_id,
                product_id,
                size_id,
                color_key,
                color_name,
                color_hex,
                quantity
            )
            VALUES
            (
                :cart_id,
                :product_id,
                :size_id,
                :color_key,
                :color_name,
                :color_hex,
                :quantity
            )
        ");

        return $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => $productId,
            'size_id' => $sizeId,
            'color_key' => $colorKey,
            'color_name' => $colorName,
            'color_hex' => self::nullableHex($colorHex),
            'quantity' => $quantity
        ]);
    }


    public static function increaseItem(
        $userId,
        $productId,
        $sizeId,
        $colorKey = ''
    ) {
        self::ensureColorSupport();
        $colorInfo = $colorKey !== ''
            ? ProductVariantStock::colorInfo($productId, $colorKey)
            : null;

        return self::addItem(
            $userId,
            $productId,
            $sizeId,
            1,
            $colorKey,
            $colorInfo['color_name'] ?? '',
            $colorInfo['color_hex'] ?? ''
        );
    }


    public static function decreaseItem(
        $userId,
        $productId,
        $sizeId,
        $colorKey = ''
    ) {
        self::ensureColorSupport();
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
              AND color_key = :color_key
            LIMIT 1
        ");
        $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => (int) $productId,
            'size_id' => (int) $sizeId,
            'color_key' => trim((string) $colorKey)
        ]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            return false;
        }

        if ((int) $item['quantity'] <= 1) {
            return $db->prepare("
                DELETE FROM cart_items
                WHERE id = :id
            ")->execute(['id' => $item['id']]);
        }

        return $db->prepare("
            UPDATE cart_items
            SET quantity = quantity - 1
            WHERE id = :id
        ")->execute(['id' => $item['id']]);
    }


    public static function removeItem(
        $userId,
        $productId,
        $sizeId,
        $colorKey = ''
    ) {
        self::ensureColorSupport();
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
              AND color_key = :color_key
        ");

        return $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => (int) $productId,
            'size_id' => (int) $sizeId,
            'color_key' => trim((string) $colorKey)
        ]);
    }


    public static function getProductQuantity($userId, $productId)
    {
        self::ensureColorSupport();
        $cart = self::getOrCreateByUserId($userId);

        if (!$cart) {
            return 0;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(quantity), 0) AS total_quantity
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


    public static function getSizeQuantity($userId, $productId, $sizeId)
    {
        self::ensureColorSupport();
        $cart = self::getOrCreateByUserId($userId);

        if (!$cart) {
            return 0;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(quantity), 0) AS quantity
            FROM cart_items
            WHERE cart_id = :cart_id
              AND product_id = :product_id
              AND size_id = :size_id
        ");
        $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => (int) $productId,
            'size_id' => (int) $sizeId
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($result['quantity'] ?? 0);
    }


    public static function getVariantQuantity(
        $userId,
        $productId,
        $sizeId,
        $colorKey
    ) {
        self::ensureColorSupport();
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
              AND color_key = :color_key
            LIMIT 1
        ");
        $stmt->execute([
            'cart_id' => $cart['id'],
            'product_id' => (int) $productId,
            'size_id' => (int) $sizeId,
            'color_key' => trim((string) $colorKey)
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ? (int) $result['quantity'] : 0;
    }


    public static function getCurrentQuantityForMode(
        $userId,
        $product,
        $sizeId,
        $colorKey = ''
    ) {
        $productId = (int) ($product['id'] ?? 0);
        $stockMode = $product['stock_mode'] ?? 'total';

        if ($productId <= 0) {
            return 0;
        }

        if ($colorKey !== '' && ProductVariantStock::hasMatrix($productId)) {
            return self::getVariantQuantity(
                $userId,
                $productId,
                $sizeId,
                $colorKey
            );
        }

        if ($stockMode === 'by_size') {
            return self::getSizeQuantity(
                $userId,
                $productId,
                $sizeId
            );
        }

        return self::getProductQuantity($userId, $productId);
    }


    public static function getDetailedItemsByUserId($userId)
    {
        $dbItems = self::getItemsByUserId($userId);
        $items = [];

        foreach ($dbItems as $item) {
            $product = Product::findById($item['product_id']);

            if (!$product) {
                continue;
            }

            $size = Product::getAttributeValueById($item['size_id']);
            $items[] = [
                'product' => $product,
                'size_id' => $item['size_id'],
                'size' => $size,
                'color_key' => (string) ($item['color_key'] ?? ''),
                'color_name' => (string) ($item['color_name'] ?? ''),
                'color_hex' => (string) ($item['color_hex'] ?? ''),
                'quantity' => (int) $item['quantity']
            ];
        }

        return $items;
    }


    public static function clearByUserId($userId)
    {
        self::ensureColorSupport();
        $cart = self::getOrCreateByUserId($userId);

        if (!$cart) {
            return false;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            DELETE FROM cart_items
            WHERE cart_id = :cart_id
        ");

        return $stmt->execute(['cart_id' => $cart['id']]);
    }


    private static function nullableHex($value)
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^#[0-9a-f]{6}$/', $value)
            ? $value
            : null;
    }
}
