<?php

class QuickOrder
{
    private static $schemaReady = false;


    /**
     * В проекте пока нет общей системы миграций,
     * поэтому таблицы быстрого заказа создаются
     * безопасно при первом обращении.
     */
    public static function ensureTables()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS quick_orders
            (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT NULL,
                customer_name VARCHAR(150) NOT NULL,
                customer_phone VARCHAR(60) NOT NULL,
                comment TEXT NULL,
                subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
                status VARCHAR(30) NOT NULL DEFAULT 'new',
                order_token CHAR(64) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_quick_order_token (order_token),
                KEY idx_quick_orders_status (status),
                KEY idx_quick_orders_created_at (created_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS quick_order_items
            (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                quick_order_id INT UNSIGNED NOT NULL,
                product_id INT NULL,
                product_name VARCHAR(255) NOT NULL,
                sku VARCHAR(100) NULL,
                size_id INT NULL,
                size_name VARCHAR(100) NULL,
                quantity INT UNSIGNED NOT NULL DEFAULT 1,
                unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (id),
                KEY idx_quick_order_items_order (quick_order_id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function create(
        $userId,
        $customerName,
        $customerPhone,
        $comment,
        $items,
        $total
    ) {
        self::ensureTables();

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $token = bin2hex(random_bytes(32));

            $stmt = $db->prepare("
                INSERT INTO quick_orders
                (
                    user_id,
                    customer_name,
                    customer_phone,
                    comment,
                    subtotal,
                    total,
                    currency,
                    status,
                    order_token
                )
                VALUES
                (
                    :user_id,
                    :customer_name,
                    :customer_phone,
                    :comment,
                    :subtotal,
                    :total,
                    'EUR',
                    'new',
                    :order_token
                )
            ");

            $stmt->execute([
                'user_id' => $userId ?: null,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'comment' => $comment !== '' ? $comment : null,
                'subtotal' => $total,
                'total' => $total,
                'order_token' => $token
            ]);

            $orderId = (int) $db->lastInsertId();

            $itemStmt = $db->prepare("
                INSERT INTO quick_order_items
                (
                    quick_order_id,
                    product_id,
                    product_name,
                    sku,
                    size_id,
                    size_name,
                    quantity,
                    unit_price,
                    line_total
                )
                VALUES
                (
                    :quick_order_id,
                    :product_id,
                    :product_name,
                    :sku,
                    :size_id,
                    :size_name,
                    :quantity,
                    :unit_price,
                    :line_total
                )
            ");

            foreach ($items as $item) {
                $product = $item['product'];
                $quantity = (int) $item['quantity'];
                $unitPrice = Product::getCurrentPrice($product);
                $lineTotal = $unitPrice * $quantity;

                $itemStmt->execute([
                    'quick_order_id' => $orderId,
                    'product_id' => (int) ($product['id'] ?? 0),
                    'product_name' => (string) ($product['name'] ?? ''),
                    'sku' => !empty($product['sku']) ? $product['sku'] : null,
                    'size_id' => !empty($item['size_id']) ? (int) $item['size_id'] : null,
                    'size_name' => $item['size']['value'] ?? null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal
                ]);
            }

            $db->commit();

            return [
                'id' => $orderId,
                'token' => $token
            ];

        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }


    public static function findByToken($token)
    {
        self::ensureTables();

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT *
            FROM quick_orders
            WHERE order_token = :token
            LIMIT 1
        ");

        $stmt->execute([
            'token' => trim((string) $token)
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    public static function getAllWithItems()
    {
        self::ensureTables();

        $db = Database::connect();

        $orders = $db->query("
            SELECT *
            FROM quick_orders
            ORDER BY id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($orders)) {
            return [];
        }

        $itemStmt = $db->prepare("
            SELECT *
            FROM quick_order_items
            WHERE quick_order_id = :quick_order_id
            ORDER BY id ASC
        ");

        foreach ($orders as &$order) {
            $itemStmt->execute([
                'quick_order_id' => (int) $order['id']
            ]);

            $order['items'] =
                $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($order);

        return $orders;
    }
}
