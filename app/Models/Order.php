<?php

class Order
{
    private static $colorSchemaReady = false;


    private static function ensureColorSupport()
    {
        if (self::$colorSchemaReady) {
            return;
        }

        $db = Database::connect();
        $columns = $db->query("SHOW COLUMNS FROM order_items")
            ->fetchAll(PDO::FETCH_ASSOC);
        $names = array_map(
            function ($column) {
                return strtolower((string) ($column['Field'] ?? ''));
            },
            $columns
        );

        if (!in_array('color_key', $names, true)) {
            $db->exec("
                ALTER TABLE order_items
                ADD COLUMN color_key VARCHAR(220) NOT NULL DEFAULT '' AFTER size_name
            ");
        }

        if (!in_array('color_name', $names, true)) {
            $db->exec("
                ALTER TABLE order_items
                ADD COLUMN color_name VARCHAR(100) NOT NULL DEFAULT '' AFTER color_key
            ");
        }

        if (!in_array('color_hex', $names, true)) {
            $db->exec("
                ALTER TABLE order_items
                ADD COLUMN color_hex VARCHAR(7) NULL DEFAULT NULL AFTER color_name
            ");
        }

        self::$colorSchemaReady = true;
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
        self::ensureColorSupport();
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
            }

            $db->commit();

            return [
                'id' => $orderId,
                'token' => $orderToken
            ];
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }


    public static function findById($orderId)
    {
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
