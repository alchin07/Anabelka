<?php

class AdminOrder
{
    private const TYPES = [
        'all',
        'regular',
        'quick'
    ];

    private const STATUSES = [
        'new',
        'processing',
        'completed',
        'cancelled'
    ];


    public static function normalizeFilters(array $input)
    {
        $type = strtolower(trim((string) ($input['type'] ?? 'all')));
        $status = strtolower(trim((string) ($input['status'] ?? 'all')));
        $query = trim((string) ($input['q'] ?? ''));

        if (!in_array($type, self::TYPES, true)) {
            $type = 'all';
        }

        if ($status !== 'all' && !in_array($status, self::STATUSES, true)) {
            $status = 'all';
        }

        $query = function_exists('mb_substr')
            ? mb_substr($query, 0, 120, 'UTF-8')
            : substr($query, 0, 120);

        return [
            'type' => $type,
            'status' => $status,
            'q' => $query
        ];
    }


    public static function statusOptions()
    {
        return [
            'new' => 'Нове',
            'processing' => 'В обробці',
            'completed' => 'Завершене',
            'cancelled' => 'Скасоване'
        ];
    }


    public static function getAll(array $filters = [])
    {
        $filters = self::normalizeFilters($filters);
        Order::ensureSchema();

        if ($filters['type'] !== 'regular') {
            QuickOrder::ensureTables();
        }

        $db = Database::connect();
        $orders = [];

        if ($filters['type'] !== 'quick') {
            $orders = array_merge(
                $orders,
                self::fetchRegularOrders($db, $filters)
            );
        }

        if ($filters['type'] !== 'regular') {
            $orders = array_merge(
                $orders,
                self::fetchQuickOrders($db, $filters)
            );
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

                $typeCompare = strcmp(
                    (string) ($left['order_type'] ?? ''),
                    (string) ($right['order_type'] ?? '')
                );

                if ($typeCompare !== 0) {
                    return $typeCompare;
                }

                return (int) ($right['id'] ?? 0)
                    <=> (int) ($left['id'] ?? 0);
            }
        );

        $orders = array_slice($orders, 0, 200);
        self::attachItems($db, $orders);

        return $orders;
    }


    public static function summary($type = 'all')
    {
        $type = strtolower(trim((string) $type));

        if (!in_array($type, self::TYPES, true)) {
            $type = 'all';
        }

        Order::ensureSchema();

        if ($type !== 'regular') {
            QuickOrder::ensureTables();
        }

        $summary = [
            'total' => 0,
            'new' => 0,
            'processing' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'regular_total' => 0,
            'regular_new' => 0,
            'quick_total' => 0,
            'quick_new' => 0
        ];

        $db = Database::connect();

        if ($type !== 'quick') {
            self::addTableSummary(
                $db,
                'orders',
                'regular',
                $summary
            );
        }

        if ($type !== 'regular') {
            self::addTableSummary(
                $db,
                'quick_orders',
                'quick',
                $summary
            );
        }

        return $summary;
    }


    public static function updateStatus($type, $orderId, $status)
    {
        $type = strtolower(trim((string) $type));
        $orderId = (int) $orderId;
        $status = strtolower(trim((string) $status));

        if (!in_array($type, ['regular', 'quick'], true)) {
            throw new InvalidArgumentException(
                'Некоректний тип замовлення.'
            );
        }

        if ($orderId <= 0) {
            throw new InvalidArgumentException(
                'Некоректний номер замовлення.'
            );
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(
                'Некоректний стан замовлення.'
            );
        }

        Order::ensureSchema();

        if ($type === 'quick') {
            QuickOrder::ensureTables();
        }

        $table = $type === 'regular'
            ? 'orders'
            : 'quick_orders';
        $itemTable = $type === 'regular'
            ? 'order_items'
            : 'quick_order_items';
        $parentColumn = $type === 'regular'
            ? 'order_id'
            : 'quick_order_id';

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $exists = $db->prepare("
                SELECT
                    id,
                    status,
                    inventory_reserved
                FROM {$table}
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $exists->execute(['id' => $orderId]);
            $order = $exists->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                throw new RuntimeException(
                    'Замовлення не знайдено.'
                );
            }

            $currentStatus = strtolower(
                trim((string) ($order['status'] ?? 'new'))
            );
            $inventoryReserved = !empty($order['inventory_reserved']);

            if ($status !== $currentStatus) {
                $items = self::loadInventoryItems(
                    $db,
                    $itemTable,
                    $parentColumn,
                    $orderId
                );

                if (
                    $status === 'cancelled'
                    && $currentStatus !== 'cancelled'
                    && $inventoryReserved
                ) {
                    Inventory::releaseItems($items);
                    $inventoryReserved = false;
                } elseif (
                    $status !== 'cancelled'
                    && !$inventoryReserved
                ) {
                    Inventory::reserveItems($items);
                    $inventoryReserved = true;
                }
            }

            $stmt = $db->prepare("
                UPDATE {$table}
                SET status = :status,
                    inventory_reserved = :inventory_reserved
                WHERE id = :id
            ");
            $stmt->execute([
                'status' => $status,
                'inventory_reserved' => $inventoryReserved ? 1 : 0,
                'id' => $orderId
            ]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    private static function fetchRegularOrders(PDO $db, array $filters)
    {
        [$where, $params] = self::buildWhere(
            $filters,
            true,
            'o'
        );

        $stmt = $db->prepare("
            SELECT
                o.id,
                'regular' AS order_type,
                o.user_id,
                o.customer_name,
                o.customer_email,
                o.customer_phone,
                o.delivery_method,
                o.delivery_service,
                o.delivery_service_option,
                COALESCE(NULLIF(dm.name, ''), o.delivery_method)
                    AS delivery_method_name,
                COALESCE(NULLIF(ds.name, ''), o.delivery_service)
                    AS delivery_service_name,
                COALESCE(NULLIF(dso.name, ''), o.delivery_service_option)
                    AS delivery_option_name,
                o.delivery_country,
                o.delivery_city,
                o.delivery_address,
                o.delivery_postcode,
                o.comment,
                o.subtotal,
                o.total,
                o.currency,
                o.status,
                o.payment_status,
                o.inventory_reserved,
                o.created_at
            FROM orders AS o
            LEFT JOIN delivery_methods AS dm
                ON dm.slug = o.delivery_method
            LEFT JOIN delivery_services AS ds
                ON ds.delivery_method_id = dm.id
               AND ds.slug = o.delivery_service
            LEFT JOIN delivery_service_options AS dso
                ON dso.delivery_service_id = ds.id
               AND dso.slug = o.delivery_service_option
            {$where}
            ORDER BY o.created_at DESC, o.id DESC
            LIMIT 200
        ");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    private static function fetchQuickOrders(PDO $db, array $filters)
    {
        [$where, $params] = self::buildWhere(
            $filters,
            false,
            'q'
        );

        $stmt = $db->prepare("
            SELECT
                q.id,
                'quick' AS order_type,
                q.user_id,
                q.customer_name,
                NULL AS customer_email,
                q.customer_phone,
                NULL AS delivery_method,
                NULL AS delivery_service,
                NULL AS delivery_service_option,
                NULL AS delivery_method_name,
                NULL AS delivery_service_name,
                NULL AS delivery_option_name,
                NULL AS delivery_country,
                NULL AS delivery_city,
                NULL AS delivery_address,
                NULL AS delivery_postcode,
                q.comment,
                q.subtotal,
                q.total,
                q.currency,
                q.status,
                NULL AS payment_status,
                q.inventory_reserved,
                q.created_at
            FROM quick_orders AS q
            {$where}
            ORDER BY q.created_at DESC, q.id DESC
            LIMIT 200
        ");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    private static function buildWhere(
        array $filters,
        $hasEmail,
        $alias
    ) {
        $conditions = [];
        $params = [];

        if ($filters['status'] !== 'all') {
            $conditions[] = "{$alias}.status = :filter_status";
            $params['filter_status'] = $filters['status'];
        }

        if ($filters['q'] !== '') {
            $like = '%' . $filters['q'] . '%';
            $searchConditions = [
                "CAST({$alias}.id AS CHAR) LIKE :query_id",
                "{$alias}.customer_name LIKE :query_name",
                "COALESCE({$alias}.customer_phone, '') LIKE :query_phone"
            ];

            $params['query_id'] = $like;
            $params['query_name'] = $like;
            $params['query_phone'] = $like;

            if ($hasEmail) {
                $searchConditions[] =
                    "COALESCE({$alias}.customer_email, '') LIKE :query_email";
                $params['query_email'] = $like;
            }

            $conditions[] = '(' . implode(' OR ', $searchConditions) . ')';
        }

        return [
            empty($conditions)
                ? ''
                : 'WHERE ' . implode(' AND ', $conditions),
            $params
        ];
    }


    private static function attachItems(PDO $db, array &$orders)
    {
        $ids = [
            'regular' => [],
            'quick' => []
        ];

        foreach ($orders as &$order) {
            $type = (string) ($order['order_type'] ?? '');
            $order['items'] = [];

            if (isset($ids[$type])) {
                $ids[$type][] = (int) ($order['id'] ?? 0);
            }
        }
        unset($order);

        $items = [
            'regular' => self::loadItems(
                $db,
                'order_items',
                'order_id',
                $ids['regular']
            ),
            'quick' => self::loadItems(
                $db,
                'quick_order_items',
                'quick_order_id',
                $ids['quick']
            )
        ];

        foreach ($orders as &$order) {
            $type = (string) ($order['order_type'] ?? '');
            $orderId = (int) ($order['id'] ?? 0);
            $order['items'] = $items[$type][$orderId] ?? [];
        }
        unset($order);
    }


    private static function loadItems(
        PDO $db,
        $table,
        $parentColumn,
        array $ids
    ) {
        $ids = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            return [];
        }

        $placeholders = implode(
            ', ',
            array_fill(0, count($ids), '?')
        );

        $stmt = $db->prepare("
            SELECT
                {$parentColumn} AS parent_id,
                product_id,
                product_name,
                sku,
                size_id,
                CASE
                    WHEN COALESCE(color_name, '') <> ''
                    THEN CONCAT(
                        COALESCE(size_name, '—'),
                        ' · Колір: ',
                        color_name
                    )
                    ELSE size_name
                END AS size_name,
                color_key,
                color_name,
                color_hex,
                quantity,
                unit_price,
                line_total
            FROM {$table}
            WHERE {$parentColumn} IN ({$placeholders})
            ORDER BY {$parentColumn} ASC, id ASC
        ");
        $stmt->execute($ids);

        $grouped = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $parentId = (int) ($item['parent_id'] ?? 0);
            $grouped[$parentId][] = $item;
        }

        return $grouped;
    }


    private static function loadInventoryItems(
        PDO $db,
        $table,
        $parentColumn,
        $orderId
    ) {
        $stmt = $db->prepare("
            SELECT
                product_id,
                size_id,
                color_key,
                color_name,
                color_hex,
                quantity
            FROM {$table}
            WHERE {$parentColumn} = :order_id
            ORDER BY id ASC
        ");
        $stmt->execute(['order_id' => (int) $orderId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    private static function addTableSummary(
        PDO $db,
        $table,
        $type,
        array &$summary
    ) {
        $rows = $db->query("
            SELECT status, COUNT(*) AS amount
            FROM {$table}
            GROUP BY status
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $status = strtolower(trim((string) ($row['status'] ?? '')));
            $amount = (int) ($row['amount'] ?? 0);

            $summary['total'] += $amount;
            $summary[$type . '_total'] += $amount;

            if (in_array($status, self::STATUSES, true)) {
                $summary[$status] += $amount;
            }

            if ($status === 'new') {
                $summary[$type . '_new'] += $amount;
            }
        }
    }
}
