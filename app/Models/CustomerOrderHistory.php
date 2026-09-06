<?php

class CustomerOrderHistory
{
    public static function regularItems($orderId)
    {
        Order::ensureSchema();

        return self::loadItems(
            'order_items',
            'order_id',
            (int) $orderId
        );
    }


    public static function quickItems($orderId)
    {
        QuickOrder::ensureTables();

        return self::loadItems(
            'quick_order_items',
            'quick_order_id',
            (int) $orderId
        );
    }


    public static function forUser($userId)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return [];
        }

        Order::ensureSchema();
        QuickOrder::ensureTables();

        $db = Database::connect();
        $orders = [];

        $stmt = $db->prepare("
            SELECT
                o.*,
                'regular' AS order_type
            FROM orders AS o
            WHERE o.user_id = :user_id
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT 100
        ");
        $stmt->execute(['user_id' => $userId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
            $order['items'] = self::regularItems((int) $order['id']);
            $orders[] = $order;
        }

        $stmt = $db->prepare("
            SELECT
                q.*,
                'quick' AS order_type
            FROM quick_orders AS q
            WHERE q.user_id = :user_id
            ORDER BY q.created_at DESC, q.id DESC
            LIMIT 100
        ");
        $stmt->execute(['user_id' => $userId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
            $order['items'] = self::quickItems((int) $order['id']);
            $orders[] = $order;
        }

        usort(
            $orders,
            function ($left, $right) {
                $dateCompare = strcmp(
                    (string) ($right['created_at'] ?? ''),
                    (string) ($left['created_at'] ?? '')
                );

                if ($dateCompare !== 0) {
                    return $dateCompare;
                }

                return (int) ($right['id'] ?? 0)
                    <=> (int) ($left['id'] ?? 0);
            }
        );

        return array_slice($orders, 0, 100);
    }


    private static function loadItems($table, $parentColumn, $orderId)
    {
        $orderId = (int) $orderId;

        if ($orderId <= 0) {
            return [];
        }

        $allowed = [
            'order_items' => 'order_id',
            'quick_order_items' => 'quick_order_id'
        ];

        if (!isset($allowed[$table]) || $allowed[$table] !== $parentColumn) {
            return [];
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT
                id,
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
            FROM {$table}
            WHERE {$parentColumn} = :order_id
            ORDER BY id ASC
        ");
        $stmt->execute(['order_id' => $orderId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
