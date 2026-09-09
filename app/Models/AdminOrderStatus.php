<?php

class AdminOrderStatus
{
    private const ALLOWED_TRANSITIONS = [
        'new' => ['processing', 'cancelled'],
        'processing' => ['completed', 'cancelled'],
        'completed' => ['processing'],
        'cancelled' => ['processing']
    ];


    public static function update($type, $orderId, $status)
    {
        $type = strtolower(trim((string) $type));
        $orderId = (int) $orderId;
        $status = strtolower(trim((string) $status));

        if (!in_array($type, ['regular', 'quick'], true)) {
            throw new InvalidArgumentException('Некоректний тип замовлення.');
        }

        if ($orderId <= 0) {
            throw new InvalidArgumentException('Некоректний номер замовлення.');
        }

        $table = $type === 'regular' ? 'orders' : 'quick_orders';

        Order::ensureSchema();

        if ($type === 'quick') {
            QuickOrder::ensureTables();
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT id, status
            FROM {$table}
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new RuntimeException('Замовлення не знайдено.');
        }

        $currentStatus = strtolower(trim((string) ($order['status'] ?? 'new')));

        if ($currentStatus === $status) {
            return;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($status, $allowed, true)) {
            throw new RuntimeException('Такий перехід стану замовлення не дозволено.');
        }

        /*
         * Залишки змінюються лише при скасуванні замовлення або
         * при повторному відкритті раніше скасованого замовлення.
         * Звичайні робочі переходи (new -> processing,
         * processing -> completed) НЕ повинні повторно резервувати товар.
         */
        if ($currentStatus !== 'cancelled' && $status !== 'cancelled') {
            $update = $db->prepare("
                UPDATE {$table}
                SET status = :status
                WHERE id = :id
                  AND status = :current_status
            ");
            $update->execute([
                'status' => $status,
                'id' => $orderId,
                'current_status' => $currentStatus
            ]);

            if ($update->rowCount() <= 0) {
                throw new RuntimeException(
                    'Стан замовлення вже змінився. Оновіть сторінку та повторіть дію.'
                );
            }

            return;
        }

        AdminOrder::updateStatus($type, $orderId, $status);
    }
}
