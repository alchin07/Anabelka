<?php

class Order
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();
        $itemColumns = $db->query("SHOW COLUMNS FROM order_items")
            ->fetchAll(PDO::FETCH_ASSOC);
        $itemNames = array_map(
            function ($column) {
                return strtolower((string) ($column['Field'] ?? ''));
            },
            $itemColumns
        );

        if (!in_array('color_key', $itemNames, true)) {
            $db->exec("
                ALTER TABLE order_items
                ADD COLUMN color_key VARCHAR(220) NOT NULL DEFAULT '' AFTER size_name
            ");
        }

        if (!in_array('color_name', $itemNames, true)) {
            $db->exec("
                ALTER TABLE order_items
                ADD COLUMN color_name VARCHAR(100) NOT NULL DEFAULT '' AFTER color_key
            ");
        }

        if (!in_array('color_hex', $itemNames, true)) {
            $db->exec("
                ALTER TABLE order_items
                ADD COLUMN color_hex VARCHAR(7) NULL DEFAULT NULL AFTER color_name
            ");
        }

        $orderColumns = $db->query("SHOW COLUMNS FROM orders")
            ->fetchAll(PDO::FETCH_ASSOC);
        $orderNames = array_map(
            function ($column) {
                return strtolower((string) ($column['Field'] ?? ''));
            },
            $orderColumns
        );

        if (!in_array('inventory_reserved', $orderNames, true)) {
            $db->exec("
                ALTER TABLE orders
                ADD COLUMN inventory_reserved TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_status
            ");
        }

        self::$schemaReady = true;
    }


    public static function create(
        $userId,
        $customerName,
        $customerEmail,
        $customerPhone,
        $deliveryMethod,
        $deliveryService,
        $deliveryServiceOption,
        $deliveryCountry,
        $deliveryCity,
        $deliveryAddress,
        $deliveryPostcode,
        $comment,
        $items,
        $total
    ) {
        self::ensureSchema();
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $orderToken = bin2hex(random_bytes(32));
            $stmt = $db->prepare("
                INSERT INTO orders
                    (
                        user_id,
                        customer_name,
                        customer_email,
                        customer_phone,
                        delivery_method,
                        delivery_service,
                        delivery_service_option,
                        delivery_country,
                        delivery_city,
                        delivery_address,
                        delivery_postcode,
                        status,
                        payment_status,
                        inventory_reserved,
                        subtotal,
                        total,
                        currency,
                        comment,
                        order_token
                    )
                VALUES
                    (
                        :user_id,
                        :customer_name,
                        :customer_email,
                        :customer_phone,
                        :delivery_method,
                        :delivery_service,
                        :delivery_service_option,
                        :delivery_country,
                        :delivery_city,
                        :delivery_address,
                        :delivery_postcode,
                        'new',
                        'pending',
                        0,
                        :subtotal,
                        :total,
                        'EUR',
                        :comment,
                        :order_token
                    )
            ");

            $stmt->execute([
                'user_id' => $userId ?: null,
                'customer_name' => $customerName,
                'customer_email' => $customerEmail,
                'customer_phone' => $customerPhone ?: null,
                'delivery_method' => $deliveryMethod ?: null,
                'delivery_service' => $deliveryService ?: null,
                'delivery_service_option' => $deliveryServiceOption ?: null,
                'delivery_country' => $deliveryCountry ?: null,
                'delivery_city' => $deliveryCity ?: null,
                'delivery_address' => $deliveryAddress ?: null,
                'delivery_postcode' => $deliveryPostcode ?: null,
                'subtotal' => $total,
                'total' => $total,
                'comment' => $comment ?: null,
                'order_token' => $orderToken
            ]);

            $orderId = (int) $db->lastInsertId();
            $itemStmt = $db->prepare("
                INSERT INTO order_items
                    (
                        order_id,
                        product_id,
                        product_name,
                        sku,
                        size_id,
                        size_name,
                        color_key,
                        color_name,
                        color_hex,
                        quantity,
                        unit_price,
                        line_total
                    )
                VALUES
                    (
                        :order_id,
                        :product_id,
                        :product_name,
                        :sku,
                        :size_id,
                        :size_name,
                        :color_key,
                        :color_name,
                        :color_hex,
                        :quantity,
                        :unit_price,
                        :line_total
                    )
            ");

            $sessionCartItems = !$userId
                ? array_values($_SESSION['cart'] ?? [])
                : [];
            $itemPosition = 0;
            $inventoryItems = [];

            foreach ($items as $item) {
                $product = $item['product'];
                $quantity = (int) $item['quantity'];
                $unitPrice = Product::getCurrentPrice($product);
                $lineTotal = $unitPrice * $quantity;
                $colorKey = trim((string) ($item['color_key'] ?? ''));
                $colorName = trim((string) ($item['color_name'] ?? ''));
                $colorHex = strtolower(trim((string) ($item['color_hex'] ?? '')));

                if (
                    !$userId
                    && $colorKey === ''
                    && isset($sessionCartItems[$itemPosition])
                ) {
                    $sessionItem = $sessionCartItems[$itemPosition];

                    if (
                        (int) ($sessionItem['product_id'] ?? 0)
                            === (int) ($product['id'] ?? 0)
                        && (int) ($sessionItem['size_id'] ?? 0)
                            === (int) ($item['size_id'] ?? 0)
                    ) {
                        $colorKey = trim((string) ($sessionItem['color_key'] ?? ''));
                        $colorName = trim((string) ($sessionItem['color_name'] ?? ''));
                        $colorHex = strtolower(trim((string) ($sessionItem['color_hex'] ?? '')));
                    }
                }

                $itemPosition++;

                if (!preg_match('/^#[0-9a-f]{6}$/', $colorHex)) {
                    $colorHex = null;
                }

                $itemStmt->execute([
                    'order_id' => $orderId,
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'sku' => $product['sku'] ?? null,
                    'size_id' => $item['size_id'],
                    'size_name' => $item['size']['value'] ?? null,
                    'color_key' => $colorKey,
                    'color_name' => $colorName,
                    'color_hex' => $colorHex,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal
                ]);

                $inventoryItems[] = [
                    'product' => $product,
                    'product_id' => (int) ($product['id'] ?? 0),
                    'size_id' => (int) ($item['size_id'] ?? 0),
                    'color_key' => $colorKey,
                    'color_name' => $colorName,
                    'color_hex' => $colorHex,
                    'quantity' => $quantity
                ];
            }

            Inventory::reserveItems($inventoryItems);

            $db->prepare("
                UPDATE orders
                SET inventory_reserved = 1
                WHERE id = :id
            ")->execute(['id' => $orderId]);

            $db->commit();

            return [
                'id' => $orderId,
                'token' => $orderToken
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    public static function findById($orderId)
    {
        self::ensureSchema();
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT *
            FROM orders
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => (int) $orderId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    public static function findByToken($token)
    {
        self::ensureSchema();
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT *
            FROM orders
            WHERE order_token = :token
            LIMIT 1
        ");
        $stmt->execute(['token' => $token]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
