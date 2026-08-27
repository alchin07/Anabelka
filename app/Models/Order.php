<?php

class Order
{
    /**
     * Создать заказ.
     */
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
        $db = Database::connect();

        /*
         * Начинаем транзакцию:
         * либо сохраняется весь заказ,
         * либо не сохраняется ничего.
         */
        $db->beginTransaction();

        try {

            $orderToken =
    bin2hex(
        random_bytes(32)
    );

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
                'user_id' =>
                    $userId ?: null,

                'customer_name' =>
                    $customerName,

                'customer_email' =>
                    $customerEmail,

                'customer_phone' =>
                    $customerPhone ?: null,

                'delivery_method' =>
                    $deliveryMethod ?: null,
                  
                  'delivery_service' =>
                    $deliveryService ?: null,
                  'delivery_service_option' =>
                    $deliveryServiceOption ?: null,  
                  
                  'delivery_country' =>
                    $deliveryCountry ?: null,
                  
                  'delivery_city' =>
                    $deliveryCity ?: null,
                  
                  'delivery_address' =>
                    $deliveryAddress ?: null,
                  
                  'delivery_postcode' =>
                    $deliveryPostcode ?: null,

                'subtotal' =>
                    $total,

                'total' =>
                    $total,

                'comment' =>
                    $comment ?: null,
                'order_token' =>
                    $orderToken
            ]);


            $orderId =
                (int) $db->lastInsertId();


            /*
             * Сохраняем позиции заказа.
             */
            $itemStmt = $db->prepare("
                INSERT INTO order_items
                    (
                        order_id,
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
                        :order_id,
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

                $product =
                    $item['product'];

                $quantity =
                    (int) $item['quantity'];

                $unitPrice =
                    Product::getCurrentPrice(
                        $product
                    );

                $lineTotal =
                    $unitPrice
                    * $quantity;


                $itemStmt->execute([
                    'order_id' =>
                        $orderId,

                    'product_id' =>
                        $product['id'],

                    'product_name' =>
                        $product['name'],

                    'sku' =>
                        $product['sku'] ?? null,

                    'size_id' =>
                        $item['size_id'],

                    'size_name' =>
                        $item['size']['value']
                        ?? null,

                    'quantity' =>
                        $quantity,

                    'unit_price' =>
                        $unitPrice,

                    'line_total' =>
                        $lineTotal
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

    $stmt->execute([
        'id' => (int) $orderId
    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    );
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

    $stmt->execute([
        'token' => $token
    ]);

    return $stmt->fetch(
        PDO::FETCH_ASSOC
    );
}
}