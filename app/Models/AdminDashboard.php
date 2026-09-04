<?php

class AdminDashboard
{
    /**
     * Коротка зведена інформація для головної сторінки адмін-панелі.
     */
    public static function overview()
    {
        $db = Database::connect();

        return [
            'counts' => [
                'regular_new' => self::count(
                    $db,
                    "SELECT COUNT(*) FROM orders WHERE status = 'new'"
                ),
                'regular_total' => self::count(
                    $db,
                    'SELECT COUNT(*) FROM orders'
                ),
                'quick_new' => self::count(
                    $db,
                    "SELECT COUNT(*) FROM quick_orders WHERE status = 'new'"
                ),
                'quick_total' => self::count(
                    $db,
                    'SELECT COUNT(*) FROM quick_orders'
                ),
                'products_total' => self::count(
                    $db,
                    'SELECT COUNT(*) FROM products'
                ),
                'products_active' => self::count(
                    $db,
                    'SELECT COUNT(*) FROM products WHERE is_active = 1'
                ),
                'products_out_of_stock' => self::count(
                    $db,
                    "
                        SELECT COUNT(*)
                        FROM products AS p
                        WHERE p.is_active = 1
                          AND (
                                (
                                    p.stock_mode = 'by_size'
                                    AND COALESCE(
                                        (
                                            SELECT SUM(pa.stock)
                                            FROM product_attributes AS pa
                                            JOIN attribute_values AS av
                                                ON av.id = pa.attribute_value_id
                                            JOIN attributes AS a
                                                ON a.id = av.attribute_id
                                            WHERE pa.product_id = p.id
                                              AND a.slug = 'size'
                                        ),
                                        0
                                    ) <= 0
                                )
                                OR
                                (
                                    p.stock_mode <> 'by_size'
                                    AND p.stock <= 0
                                )
                              )
                    "
                ),
                'categories_total' => self::count(
                    $db,
                    'SELECT COUNT(*) FROM categories'
                ),
                'languages_active' => self::count(
                    $db,
                    'SELECT COUNT(*) FROM languages WHERE is_active = 1'
                ),
                'languages_total' => self::count(
                    $db,
                    'SELECT COUNT(*) FROM languages'
                ),
                'delivery_active' => self::count(
                    $db,
                    'SELECT COUNT(*) FROM delivery_methods WHERE is_active = 1'
                ),
                'delivery_total' => self::count(
                    $db,
                    'SELECT COUNT(*) FROM delivery_methods'
                )
            ],
            'recent_orders' => self::recentOrders($db, 5)
        ];
    }


    private static function count(PDO $db, $sql)
    {
        try {
            return (int) $db->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }


    private static function recentOrders(PDO $db, $limit)
    {
        $limit = max(1, min(20, (int) $limit));
        $orders = [];

        try {
            $regular = $db->query("
                SELECT
                    id,
                    'regular' AS order_type,
                    customer_name,
                    customer_phone,
                    total,
                    currency,
                    status,
                    created_at
                FROM orders
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit}
            ")->fetchAll(PDO::FETCH_ASSOC);

            $orders = array_merge($orders, $regular);
        } catch (Throwable $e) {
            // Сторінка залишається доступною навіть до імпорту таблиці.
        }

        try {
            $quick = $db->query("
                SELECT
                    id,
                    'quick' AS order_type,
                    customer_name,
                    customer_phone,
                    total,
                    currency,
                    status,
                    created_at
                FROM quick_orders
                ORDER BY created_at DESC, id DESC
                LIMIT {$limit}
            ")->fetchAll(PDO::FETCH_ASSOC);

            $orders = array_merge($orders, $quick);
        } catch (Throwable $e) {
            // Сторінка залишається доступною навіть до імпорту таблиці.
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

        return array_slice($orders, 0, $limit);
    }
}
