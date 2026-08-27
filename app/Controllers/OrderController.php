<?php

class OrderController extends Controller
{
    /**
     * Страница оформления заказа.
     */
    public function checkout()
{
    $deliveryMethods =
        Delivery::getMethodsWithServices();

    $this->view(
        'order/checkout',
        [
            'deliveryMethods' =>
                $deliveryMethods
        ]
    );
}

    public function store()
{
    /*
     * Получаем данные покупателя.
     */
    $customerName =
        trim(
            $_POST['customer_name']
            ?? ''
        );

    $customerEmail =
        trim(
            $_POST['customer_email']
            ?? ''
        );

    $customerPhone =
        trim(
            $_POST['customer_phone']
            ?? ''
        );
    $deliveryMethod =
    trim(
        $_POST['delivery_method']
        ?? ''
    );

$deliveryService =
    trim(
        $_POST['delivery_service']
        ?? ''
    );

$deliveryServiceOption =
    trim(
        $_POST['delivery_service_option']
        ?? ''
    );  

$deliveryCountry =
    trim(
        $_POST['delivery_country']
        ?? ''
    );

$deliveryCity =
    trim(
        $_POST['delivery_city']
        ?? ''
    );

$deliveryAddress =
    trim(
        $_POST['delivery_address']
        ?? ''
    );

$deliveryPostcode =
    trim(
        $_POST['delivery_postcode']
        ?? ''
    );

    $comment =
        trim(
            $_POST['comment']
            ?? ''
        );

  /*
 * Проверяем способ доставки.
 */
$method =
    Delivery::findMethodBySlug(
        $deliveryMethod
    );


if (!$method) {

    die(
        'Некорректный способ доставки.'
    );
}


/*
 * Если у способа доставки
 * имеются службы доставки,
 * одна из них должна быть выбрана.
 */
$availableServices =
    Delivery::getServicesByMethodId(
        $method['id']
    );


if (!empty($availableServices)) {

    if ($deliveryService === '') {

        die(
            'Выберите службу доставки.'
        );
    }


    /*
     * Проверяем, что выбранная служба
     * действительно принадлежит
     * выбранному способу доставки.
     */
    $service =
        Delivery::findServiceBySlug(
            $method['id'],
            $deliveryService
        );


    if (!$service) {

        die(
            'Некорректная служба доставки.'
        );
    }

    /*
 * Проверяем варианты получения
 * выбранной службы доставки.
 */
$availableOptions =
    Delivery::getOptionsByServiceId(
        $service['id']
    );


if (!empty($availableOptions)) {

    /*
     * Если у службы есть варианты,
     * один из них обязательно
     * должен быть выбран.
     */
    if (
        $deliveryServiceOption
        === ''
    ) {

        die(
            'Выберите вариант получения.'
        );
    }


    /*
     * Проверяем, действительно ли
     * выбранный вариант принадлежит
     * выбранной службе.
     */
    $serviceOption =
        Delivery::findOptionBySlug(
            $service['id'],
            $deliveryServiceOption
        );


    if (!$serviceOption) {

        die(
            'Некорректный вариант получения.'
        );
    }

} else {

    /*
     * Если у службы нет третьего уровня,
     * ничего лишнего в заказ не сохраняем.
     */
    $deliveryServiceOption =
        '';
}  

} else {

    /*
     * Для курьера или самовывоза
     * никакая служба не сохраняется.
     */
    $deliveryService = '';
    $deliveryServiceOption = '';
}


    /*
     * Проверяем обязательные поля.
     */
    if (
        $customerName === ''
        ||
        $customerEmail === ''
    ) {

        die(
            'Заполните имя и Email.'
        );
    }


    if (
        !filter_var(
            $customerEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        die(
            'Некорректный Email.'
        );
    }


    /*
     * ID пользователя.
     *
     * Для гостя будет NULL.
     */
    $userId =
        !empty($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null;


    /*
     * Получаем товары корзины.
     */
    $items = [];


    if ($userId) {

        /*
         * Авторизованный пользователь:
         * корзина хранится в MySQL.
         */
        $items =
            Cart::getDetailedItemsByUserId(
                $userId
            );

    } else {

        /*
         * Гость:
         * корзина хранится в сессии.
         */
        foreach (
            $_SESSION['cart'] ?? []
            as $cartItem
        ) {

            $productId =
                (int) (
                    $cartItem['product_id']
                    ?? 0
                );

            $sizeId =
                (int) (
                    $cartItem['size_id']
                    ?? 0
                );

            $quantity =
                (int) (
                    $cartItem['quantity']
                    ?? 0
                );


            if (
                $productId <= 0
                ||
                $sizeId <= 0
                ||
                $quantity <= 0
            ) {

                continue;
            }


            $product =
                Product::findById(
                    $productId
                );


            if (!$product) {
                continue;
            }


            $size =
                Product::getAttributeValueById(
                    $sizeId
                );


            $items[] = [
                'product' => $product,
                'size_id' => $sizeId,
                'size' => $size,
                'quantity' => $quantity
            ];
        }
    }


    /*
     * Пустую корзину оформлять нельзя.
     */
    if (empty($items)) {

        die(
            'Корзина пуста.'
        );
    }


    /*
     * Рассчитываем итоговую стоимость
     * на сервере.
     *
     * Цене из браузера не доверяем.
     */
    $total = 0;


    foreach ($items as $item) {

        $unitPrice =
            Product::getCurrentPrice(
                $item['product']
            );

        $total +=
            $unitPrice
            * (int) $item['quantity'];
    }


    /*
     * Создаём заказ.
     */
    $result =
    Order::create(
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
    );


$orderId =
    (int) $result['id'];

$orderToken =
    $result['token'];
  
    if ($userId) {

    Cart::clearByUserId(
        $userId
    );

} else {

    $_SESSION['cart'] = [];
    }

    /*
     * Пока корзину НЕ очищаем.
     *
     * Сначала проверим, что заказ
     * и все его позиции правильно
     * записались в MySQL.
     */
    header(
    'Location: /Anabelka/order/success?token='
    . urlencode($orderToken)
);

exit;
}

  public function success()
{
    $token =
        trim(
            $_GET['token'] ?? ''
        );

    if ($token === '') {
        $this->view(
    'order/error'
);

return;  
    }

    $order =
        Order::findByToken(
            $token
        );

    if (!$order) {
        $this->view(
    'order/error'
);

return;  
    }

    $this->view(
        'order/success',
        [
            'order' => $order
        ]
    );
}
} 