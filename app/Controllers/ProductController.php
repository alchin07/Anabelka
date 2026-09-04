<?php

class ProductController extends Controller
{
    public function show($slug)
    {
        // Ищем товар по slug.
        $product = Product::findBySlug($slug);

        if (!$product) {
            http_response_code(404);
            die('Товар не найден');
        }


        // Получаем характеристики товара.
        $attributes = Product::attributes(
            $product['id']
        );

        $images = Product::images(
            $product['id']
        );


        /*
         * =========================================
         * ДОСТУПНЫЙ ОСТАТОК С УЧЁТОМ КОРЗИНЫ
         * =========================================
         *
         * Карточка товара должна показывать не просто
         * складской остаток, а количество, которое
         * пользователь ещё может добавить в корзину.
         *
         * Это синхронизирует страницу товара с
         * серверной проверкой CartController / Cart.
         */
        $productId =
            (int) $product['id'];

        $stockMode =
            $product['stock_mode']
            ?? 'total';

        $cartProductQuantity = 0;
        $cartSizeQuantities = [];


        if (!empty($_SESSION['user_id'])) {

            $userId =
                (int) $_SESSION['user_id'];

            $cartProductQuantity =
                Cart::getProductQuantity(
                    $userId,
                    $productId
                );

            foreach ($attributes as $attribute) {

                if (
                    ($attribute['attribute_slug'] ?? '')
                    !== 'size'
                ) {
                    continue;
                }

                $sizeId =
                    (int) ($attribute['value_id'] ?? 0);

                if ($sizeId <= 0) {
                    continue;
                }

                $cartSizeQuantities[$sizeId] =
                    Cart::getSizeQuantity(
                        $userId,
                        $productId,
                        $sizeId
                    );
            }

        } else {

            foreach (
                $_SESSION['cart'] ?? []
                as $cartItem
            ) {

                if (
                    (int) ($cartItem['product_id'] ?? 0)
                    !== $productId
                ) {
                    continue;
                }

                $quantity =
                    (int) ($cartItem['quantity'] ?? 0);

                $sizeId =
                    (int) ($cartItem['size_id'] ?? 0);

                $cartProductQuantity +=
                    $quantity;

                if ($sizeId > 0) {
                    $cartSizeQuantities[$sizeId] =
                        ($cartSizeQuantities[$sizeId] ?? 0)
                        + $quantity;
                }
            }
        }


        if ($stockMode === 'by_size') {

            $availableTotal = 0;

            foreach ($attributes as &$attribute) {

                if (
                    ($attribute['attribute_slug'] ?? '')
                    !== 'size'
                ) {
                    continue;
                }

                $sizeId =
                    (int) ($attribute['value_id'] ?? 0);

                $stock =
                    (int) ($attribute['stock'] ?? 0);

                $inCart =
                    (int) (
                        $cartSizeQuantities[$sizeId]
                        ?? 0
                    );

                $available =
                    max(
                        0,
                        $stock - $inCart
                    );

                $attribute['stock'] =
                    $available;

                $availableTotal +=
                    $available;
            }

            unset($attribute);

            /*
             * В режиме by_size общий остаток на карточке
             * равен сумме реально доступных размеров.
             */
            $product['stock'] =
                $availableTotal;

        } else {

            $availableTotal =
                max(
                    0,
                    (int) ($product['stock'] ?? 0)
                    - $cartProductQuantity
                );

            $product['stock'] =
                $availableTotal;

            /*
             * В режиме total любой размер использует
             * один общий остаток товара.
             */
            foreach ($attributes as &$attribute) {

                if (
                    ($attribute['attribute_slug'] ?? '')
                    === 'size'
                ) {
                    $attribute['stock'] =
                        $availableTotal;
                }
            }

            unset($attribute);
        }


        $prices = Product::getPricesByRanks(
            $productId
        );

        $currentRankSlug =
            Product::getCurrentRankSlug();

        $badges =
            Product::getBadges(
                $productId
            );

        $currentLanguage =
            Translator::currentLanguage();

        $product =
            ProductTranslator::localize(
                $product,
                $currentLanguage['code'] ?? Language::SOURCE_CODE
            );


        $this->view(
            'product/show',
            [
                'product' => $product,
                'attributes' => $attributes,
                'images' => $images,
                'prices' => $prices,
                'currentRankSlug' => $currentRankSlug,
                'badges' => $badges
            ]
        );
    }
}
