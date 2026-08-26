<?php

class CartController extends Controller
{
    /**
     * Страница корзины.
     */
    public function index()
    {
        /*
         * Авторизованный пользователь:
         * читаем корзину из MySQL.
         *
         * Гость:
         * читаем корзину из сессии.
         */
        if (!empty($_SESSION['user_id'])) {

            $dbItems = Cart::getItemsByUserId(
                $_SESSION['user_id']
            );

            $cart = [];

            foreach ($dbItems as $item) {

                $cartKey =
                    $item['product_id']
                    . '_'
                    . $item['size_id'];

                $cart[$cartKey] = [
                    'product_id' => $item['product_id'],
                    'size_id' => $item['size_id'],
                    'quantity' => $item['quantity']
                ];
            }

        } else {

            $cart =
                $_SESSION['cart'] ?? [];
        }


        $items = [];
        $total = 0;


        foreach ($cart as $cartKey => $cartItem) {

            $product =
                Product::findById(
                    $cartItem['product_id']
                );

            $size =
                Product::getAttributeValueById(
                    $cartItem['size_id']
                );


            if (!$product) {
                continue;
            }


            $quantity =
                (int) $cartItem['quantity'];

            $price =
                Product::getCurrentPrice(
                    $product
                );

            $sum =
                $price * $quantity;

            $total += $sum;


            $items[] = [
                'cart_key' => $cartKey,
                'product' => $product,
                'size_id' => $cartItem['size_id'],
                'quantity' => $quantity,
                'sum' => $sum,
                'size' => $size
            ];
        }


        $this->view(
            'cart/index',
            [
                'items' => $items,
                'total' => $total
            ]
        );
    }


    /**
     * Добавление товара в корзину.
     */
    public function add()
    {
      
        $productId =
            (int) ($_POST['product_id'] ?? 0);

        $sizes =
            $_POST['sizes'] ?? [];


        if ($productId <= 0) {

            $this->jsonError(
                'Товар не выбран'
            );
        }


        if (
            !is_array($sizes) ||
            empty($sizes)
        ) {

            $this->jsonError(
                'Выберите хотя бы один размер'
            );
        }


        /*
         * Получаем товар.
         */
        $product =
            Product::findById(
                $productId
            );

        if (!$product) {

            $this->jsonError(
                'Товар не найден'
            );
        }


        /*
         * Остаток товара.
         */
        $stock =
            (int) $product['stock'];


        /*
         * Очищаем список размеров.
         * Убираем нули и возможные дубли.
         */
        $cleanSizes = [];

        foreach ($sizes as $sizeId) {

            $sizeId =
                (int) $sizeId;

            if ($sizeId > 0) {

                $cleanSizes[$sizeId] =
                    $sizeId;
            }
        }

      /*
 * =========================================
 * КОНТРОЛЬ ОСТАТКОВ
 * =========================================
 */

$stockMode =
    $product['stock_mode']
    ?? 'total';


/*
 * РЕЖИМ 1:
 * Общий остаток товара.
 *
 * Все размеры используют один общий stock.
 */
if ($stockMode === 'total') {

    $stockLimit =
        (int) ($product['stock'] ?? 0);


    /*
     * Сколько всего этого товара
     * уже находится в корзине.
     */
    $currentQuantity = 0;


    if (!empty($_SESSION['user_id'])) {

        $currentQuantity =
            Cart::getProductQuantity(
                $_SESSION['user_id'],
                $productId
            );

    } else {

        foreach (
            $_SESSION['cart'] ?? []
            as $cartItem
        ) {

            if (
                (int) (
                    $cartItem['product_id']
                    ?? 0
                ) === $productId
            ) {

                $currentQuantity +=
                    (int) (
                        $cartItem['quantity']
                        ?? 0
                    );
            }
        }
    }


    /*
     * Каждый выбранный размер =
     * одна добавляемая штука.
     */
    $addingQuantity =
        count($sizes);


    /*
     * Проверяем сразу ВСЮ операцию.
     *
     * Например:
     * в корзине 9,
     * выбрано 2 размера,
     * 9 + 2 = 11 → запрещаем.
     */
    if (
        $currentQuantity
        + $addingQuantity
        > $stockLimit
    ) {

        $available =
            max(
                0,
                $stockLimit
                - $currentQuantity
            );


        $this->jsonError(
            'Недостаточно товара на складе. '
            . 'Доступно: '
            . $available
            . ' шт.'
        );
    }


/*
 * РЕЖИМ 2:
 * Остаток отдельно по размерам.
 */
} else {

    foreach ($sizes as $sizeId) {

        $sizeId =
            (int) $sizeId;


        $stockLimit =
            Product::getStockLimit(
                $product,
                $sizeId
            );


        /*
         * Сколько конкретного размера
         * уже находится в корзине.
         */
        $currentQuantity = 0;


        if (!empty($_SESSION['user_id'])) {

            $currentQuantity =
                Cart::getSizeQuantity(
                    $_SESSION['user_id'],
                    $productId,
                    $sizeId
                );

        } else {

            $cartKey =
                $productId
                . '_'
                . $sizeId;

            $currentQuantity =
                (int) (
                    $_SESSION['cart']
                        [$cartKey]
                        ['quantity']
                    ?? 0
                );
        }


        if (
            $currentQuantity + 1
            > $stockLimit
        ) {

            $size =
                Product::getAttributeValueById(
                    $sizeId
                );

            $sizeName =
                $size['value'] ?? '—';


            $this->jsonError(
                'Размер '
                . $sizeName
                . ' закончился.'
            );
        }
    }



    if (
        $currentQuantity + 1
        > $stockLimit
    ) {

        $size =
            Product::getAttributeValueById(
                $sizeId
            );

        $sizeName =
            $size['value'] ?? '—';


        $this->jsonError(
            'Размер '
            . $sizeName
            . ' закончился.'
        );
    }
}


        /*
         * Добавляем выбранные размеры.
         */
        foreach ($sizes as $sizeId) {

            $sizeId =
                (int) $sizeId;


            /*
             * Авторизованный пользователь:
             * корзина хранится в MySQL.
             */
            if (!empty($_SESSION['user_id'])) {

                $added =
    Cart::addItem(
        $_SESSION['user_id'],
        $productId,
        $sizeId,
        1
    );


if (!$added) {

    $size =
        Product::getAttributeValueById(
            $sizeId
        );

    $sizeName =
        $size['value'] ?? '—';


    $this->jsonError(
        'Размер '
        . $sizeName
        . ' закончился.'
    );
}


continue;
            }


            /*
             * Гость:
             * корзина хранится в сессии.
             */
            if (!isset($_SESSION['cart'])) {

                $_SESSION['cart'] = [];
            }


            $cartKey =
                $productId
                . '_'
                . $sizeId;


            if (
                isset(
                    $_SESSION['cart'][$cartKey]
                )
            ) {

                $_SESSION['cart']
                    [$cartKey]
                    ['quantity']++;

            } else {

                $_SESSION['cart'][$cartKey] = [
                    'product_id' => $productId,
                    'size_id' => $sizeId,
                    'quantity' => 1
                ];
            }
        }


        /*
         * AJAX-запрос со страницы товара.
         */
        if ($this->isAjax()) {

            header(
                'Content-Type: application/json; charset=utf-8'
            );


            $cartCount =
                $this->getCartCount();


            echo json_encode([
                'success' => true,
                'cart_count' => $cartCount
            ]);

            exit;
        }


        /*
         * Запасной вариант без JavaScript.
         */
        header(
            'Location: /Anabelka/cart'
        );

        exit;
    }


    /**
     * Увеличить количество позиции на 1.
     */
    public function increase()
{
    $cartKey =
        $_POST['cart_key'] ?? '';


    if ($cartKey === '') {

        http_response_code(400);

        echo 'ERROR';

        exit;
    }


    [$productId, $sizeId] =
        $this->parseCartKey(
            $cartKey
        );


    if (
        $productId <= 0 ||
        $sizeId <= 0
    ) {

        http_response_code(400);

        echo 'ERROR';

        exit;
    }


    /*
     * Получаем товар.
     */
    $product =
        Product::findById(
            $productId
        );


    if (!$product) {

        http_response_code(404);

        echo 'Товар не найден';

        exit;
    }


    /*
     * Получаем лимит остатка
     * с учётом stock_mode.
     */
    $stockLimit =
        Product::getStockLimit(
            $product,
            $sizeId
        );


    /*
     * Сколько товара уже находится
     * в корзине.
     */
    $currentQuantity = 0;


    if (!empty($_SESSION['user_id'])) {

        $currentQuantity =
            Cart::getCurrentQuantityForMode(
                $_SESSION['user_id'],
                $product,
                $sizeId
            );

    } else {

        /*
         * Гость.
         */
        if (
            ($product['stock_mode'] ?? 'total')
            === 'by_size'
        ) {

            $currentQuantity =
                (int) (
                    $_SESSION['cart']
                        [$cartKey]
                        ['quantity']
                    ?? 0
                );

        } else {

            /*
             * Режим total:
             * считаем все размеры
             * этого товара.
             */
            foreach (
                $_SESSION['cart'] ?? []
                as $cartItem
            ) {

                if (
                    (int) (
                        $cartItem['product_id']
                        ?? 0
                    ) === $productId
                ) {

                    $currentQuantity +=
                        (int) (
                            $cartItem['quantity']
                            ?? 0
                        );
                }
            }
        }
    }


    /*
     * Достигнут предел остатка.
     */
    if (
        $currentQuantity >= $stockLimit
    ) {

        http_response_code(409);

        if (
            ($product['stock_mode'] ?? 'total')
            === 'by_size'
        ) {

            $size =
    Product::getAttributeValueById(
        $sizeId
    );

$sizeName =
    $size['value'] ?? '—';

echo
    'Размер '
    . $sizeName
    . ' закончился';

        } else {

            echo 'Товар закончился';
        }

        exit;
    }


    /*
     * Авторизованный пользователь.
     */
    if (!empty($_SESSION['user_id'])) {

        $increased =
            Cart::increaseItem(
                $_SESSION['user_id'],
                $productId,
                $sizeId
            );


        if (!$increased) {

            http_response_code(409);

            echo 'Размер закончился';

            exit;
        }

    } else {

        /*
         * Гость.
         */
        if (
            isset(
                $_SESSION['cart'][$cartKey]
            )
        ) {

            $_SESSION['cart']
                [$cartKey]
                ['quantity']++;
        }
    }


    http_response_code(200);

    echo 'OK';

    exit;
}


    /**
     * Уменьшить количество позиции на 1.
     */
    public function decrease()
    {
        $cartKey =
            $_POST['cart_key'] ?? '';


        if ($cartKey === '') {

            http_response_code(400);

            echo 'ERROR';

            exit;
        }


        [$productId, $sizeId] =
            $this->parseCartKey(
                $cartKey
            );


        if (
            $productId <= 0 ||
            $sizeId <= 0
        ) {

            http_response_code(400);

            echo 'ERROR';

            exit;
        }


        /*
         * Авторизованный пользователь.
         */
        if (!empty($_SESSION['user_id'])) {

            Cart::decreaseItem(
                $_SESSION['user_id'],
                $productId,
                $sizeId
            );

        } else {

            /*
             * Гость.
             */
            if (
                isset(
                    $_SESSION['cart'][$cartKey]
                )
            ) {

                $_SESSION['cart']
                    [$cartKey]
                    ['quantity']--;


                if (
                    $_SESSION['cart']
                        [$cartKey]
                        ['quantity']
                    <= 0
                ) {

                    unset(
                        $_SESSION['cart'][$cartKey]
                    );
                }
            }
        }


        http_response_code(200);

        echo 'OK';

        exit;
    }


    /**
     * Полностью удалить позицию.
     */
    public function remove()
    {
        $cartKey =
            $_POST['cart_key'] ?? '';


        if ($cartKey === '') {

            http_response_code(400);

            echo 'ERROR';

            exit;
        }


        [$productId, $sizeId] =
            $this->parseCartKey(
                $cartKey
            );


        if (
            $productId <= 0 ||
            $sizeId <= 0
        ) {

            http_response_code(400);

            echo 'ERROR';

            exit;
        }


        /*
         * Авторизованный пользователь.
         */
        if (!empty($_SESSION['user_id'])) {

            Cart::removeItem(
                $_SESSION['user_id'],
                $productId,
                $sizeId
            );

        } else {

            /*
             * Гость.
             */
            if (
                isset(
                    $_SESSION['cart'][$cartKey]
                )
            ) {

                unset(
                    $_SESSION['cart'][$cartKey]
                );
            }
        }


        http_response_code(200);

        echo 'OK';

        exit;
    }


    /**
     * Проверяем AJAX-запрос.
     */
    private function isAjax()
    {
        return
            isset(
                $_SERVER[
                    'HTTP_X_REQUESTED_WITH'
                ]
            )
            &&
            $_SERVER[
                'HTTP_X_REQUESTED_WITH'
            ] === 'XMLHttpRequest';
    }


    /**
     * Вернуть ошибку.
     *
     * Для AJAX — JSON.
     * Для обычного запроса — текст.
     */
    private function jsonError($message)
    {
        if ($this->isAjax()) {

            header(
                'Content-Type: application/json; charset=utf-8'
            );

            echo json_encode([
                'success' => false,
                'message' => $message
            ]);

            exit;
        }


        die($message);
    }


    /**
     * Общее количество товаров
     * для счётчика в шапке.
     */
    private function getCartCount()
    {
        $cartCount = 0;


        if (!empty($_SESSION['user_id'])) {

            $items =
                Cart::getItemsByUserId(
                    $_SESSION['user_id']
                );


            foreach ($items as $item) {

                $cartCount +=
                    (int) $item['quantity'];
            }

        } else {

            foreach (
                $_SESSION['cart'] ?? []
                as $item
            ) {

                $cartCount +=
                    (int) (
                        $item['quantity']
                        ?? 0
                    );
            }
        }


        return $cartCount;
    }


    /**
     * Разобрать ключ корзины:
     *
     * 1_5
     *
     * product_id = 1
     * size_id = 5
     */
    private function parseCartKey($cartKey)
    {
        $parts =
            explode(
                '_',
                $cartKey,
                2
            );


        return [
            (int) ($parts[0] ?? 0),
            (int) ($parts[1] ?? 0)
        ];
    }
}