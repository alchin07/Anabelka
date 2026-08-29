<?php

class QuickOrderController extends Controller
{
    public function form()
    {
        [$items, $total] =
            $this->getCartItems();

        if (empty($items)) {
            header('Location: /Anabelka/cart');
            exit;
        }

        $this->view(
            'order/quick',
            [
                'items' => $items,
                'total' => $total
            ]
        );
    }


    public function store()
    {
        $customerName =
            trim($_POST['customer_name'] ?? '');

        $customerPhone =
            trim($_POST['customer_phone'] ?? '');

        $comment =
            trim($_POST['comment'] ?? '');

        if (
            $customerName === ''
            || $customerPhone === ''
        ) {
            die('Заполните имя и номер телефона.');
        }

        [$items, $total] =
            $this->getCartItems();

        if (empty($items)) {
            die('Корзина пуста.');
        }

        $userId =
            !empty($_SESSION['user_id'])
                ? (int) $_SESSION['user_id']
                : null;

        $result =
            QuickOrder::create(
                $userId,
                $customerName,
                $customerPhone,
                $comment,
                $items,
                $total
            );

        if ($userId) {
            Cart::clearByUserId($userId);
        } else {
            $_SESSION['cart'] = [];
        }

        header(
            'Location: /Anabelka/quick-order/success?token='
            . urlencode($result['token'])
        );

        exit;
    }


    public function success()
    {
        $token =
            trim($_GET['token'] ?? '');

        if ($token === '') {
            $this->view('order/error');
            return;
        }

        $order =
            QuickOrder::findByToken($token);

        if (!$order) {
            $this->view('order/error');
            return;
        }

        $this->view(
            'order/quick-success',
            [
                'order' => $order
            ]
        );
    }


    /**
     * Получаем актуальные позиции корзины
     * и пересчитываем сумму на сервере.
     */
    private function getCartItems()
    {
        $userId =
            !empty($_SESSION['user_id'])
                ? (int) $_SESSION['user_id']
                : null;

        $items = [];

        if ($userId) {
            $items =
                Cart::getDetailedItemsByUserId(
                    $userId
                );

        } else {
            foreach (
                $_SESSION['cart'] ?? []
                as $cartItem
            ) {
                $productId =
                    (int) ($cartItem['product_id'] ?? 0);

                $sizeId =
                    (int) ($cartItem['size_id'] ?? 0);

                $quantity =
                    (int) ($cartItem['quantity'] ?? 0);

                if (
                    $productId <= 0
                    || $sizeId <= 0
                    || $quantity <= 0
                ) {
                    continue;
                }

                $product =
                    Product::findById($productId);

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

        $total = 0;

        foreach ($items as $item) {
            $total +=
                Product::getCurrentPrice(
                    $item['product']
                )
                * (int) $item['quantity'];
        }

        return [$items, $total];
    }
}
