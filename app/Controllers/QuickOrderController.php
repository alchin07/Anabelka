<?php

class QuickOrderController extends Controller
{
    public function form()
    {
        [$items, $total] = $this->getCartItems();

        if (empty($items)) {
            header('Location: /Anabelka/cart');
            exit;
        }

        $this->view('order/quick', [
            'items' => $items,
            'total' => $total
        ]);
    }


    public function store()
    {
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $comment = trim($_POST['comment'] ?? '');

        if ($customerName === '' || $customerPhone === '') {
            die(
                Translator::t(
                    'quick.error_required',
                    'Заповніть ім’я та номер телефону.'
                )
            );
        }

        [$items, $total] = $this->getCartItems();

        if (empty($items)) {
            die(
                Translator::t(
                    'quick.error_empty',
                    'Кошик порожній.'
                )
            );
        }

        $userId = !empty($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null;

        try {
            $result = QuickOrder::create(
                $userId,
                $customerName,
                $customerPhone,
                $comment,
                $items,
                $total
            );
        } catch (RuntimeException $e) {
            $message = trim((string) $e->getMessage());
            $isInventoryError =
                strpos($message, 'Недостатньо товару') === 0
                || strpos($message, 'Не вдалося визначити розмір') === 0
                || strpos($message, 'Не вдалося знайти товар для обліку залишку') === 0;

            if (!$isInventoryError) {
                throw $e;
            }

            http_response_code(409);

            $this->view(
                'order/error',
                [
                    'errorTitle' => Translator::t(
                        'public.order.stock_changed_title',
                        'Залишок товару змінився'
                    ),
                    'errorMessage' => $message,
                    'backUrl' => '/Anabelka/cart',
                    'backLabel' => Translator::t(
                        'public.order.back_cart',
                        'Повернутися до кошика'
                    )
                ]
            );
            return;
        }

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
        $token = trim($_GET['token'] ?? '');

        if ($token === '') {
            $this->view('order/error');
            return;
        }

        $order = QuickOrder::findByToken($token);

        if (!$order) {
            $this->view('order/error');
            return;
        }

        $this->view('order/quick-success', [
            'order' => $order
        ]);
    }



    /**
     * Quick order from a product page, without including or modifying cart items.
     * URL selection is validated server-side; POST uses an opaque one-use draft.
     */
    public function productForm()
    {
        $selection = [
            'product_id' => $_GET['product_id'] ?? null,
            'sizes' => $_GET['sizes'] ?? [],
            'color_key' => $_GET['color_key'] ?? ''
        ];

        try {
            [$items, $total] = $this->selectedProductItems($selection);
        } catch (RuntimeException $e) {
            $this->productOrderError($e->getMessage(), $selection, 409);
            return;
        }

        if (!is_array($_SESSION['quick_order_product_drafts'] ?? null)) {
            $_SESSION['quick_order_product_drafts'] = [];
        }

        $drafts =& $_SESSION['quick_order_product_drafts'];

        foreach ($drafts as $key => $existing) {
            if (
                !is_array($existing)
                || time() - (int) ($existing['created_at'] ?? 0) > 1800
            ) {
                unset($drafts[$key]);
            }
        }

        while (count($drafts) >= 10) {
            array_shift($drafts);
        }

        $token = bin2hex(random_bytes(16));
        $drafts[$token] = [
            'product_id' => (int) $items[0]['product']['id'],
            'sizes' => array_map(
                static function ($item) {
                    return (int) $item['size_id'];
                },
                $items
            ),
            'color_key' => (string) ($items[0]['color_key'] ?? ''),
            'created_at' => time()
        ];

        $this->view('order/quick', [
            'items' => $items,
            'total' => $total,
            'productDraftToken' => $token
        ]);
    }


    public function productStore()
    {
        $token = $_POST['draft_token'] ?? '';
        $drafts =& $_SESSION['quick_order_product_drafts'];

        if (
            !is_string($token)
            || !preg_match('/\A[a-f0-9]{32}\z/', $token)
            || !is_array($drafts ?? null)
            || !is_array($drafts[$token] ?? null)
        ) {
            $this->productOrderError(
                'Форма швидкого замовлення застаріла. Оберіть товар ще раз.',
                [],
                409
            );
            return;
        }

        $selection = $drafts[$token];

        if (time() - (int) ($selection['created_at'] ?? 0) > 1800) {
            unset($drafts[$token]);
            $this->productOrderError(
                'Час оформлення минув. Оберіть товар ще раз.',
                $selection,
                409
            );
            return;
        }

        $customerName = trim((string) ($_POST['customer_name'] ?? ''));
        $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
        $comment = trim((string) ($_POST['comment'] ?? ''));

        if ($customerName === '' || $customerPhone === '') {
            $this->productOrderError(
                Translator::t(
                    'quick.error_required',
                    'Заповніть ім’я та номер телефону.'
                ),
                $selection,
                422
            );
            return;
        }

        try {
            // Re-read current product, size, color, stock and prices. No
            // client-submitted product details or price are trusted.
            [$items, $total] = $this->selectedProductItems($selection);
            $result = QuickOrder::create(
                !empty($_SESSION['user_id'])
                    ? (int) $_SESSION['user_id']
                    : null,
                $customerName,
                $customerPhone,
                $comment,
                $items,
                $total
            );
        } catch (RuntimeException $e) {
            $this->productOrderError($e->getMessage(), $selection, 409);
            return;
        }

        unset($drafts[$token]);
        // This flow does not clear or add anything to the customer's cart.
        header(
            'Location: /Anabelka/quick-order/success?token='
            . urlencode($result['token'])
        );
        exit;
    }


    private function selectedProductItems(array $selection)
    {
        $productId = filter_var(
            $selection['product_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $sizes = $selection['sizes'] ?? [];

        if (!$productId) {
            throw new RuntimeException('Товар не знайдено.');
        }

        if (!is_array($sizes) || count($sizes) === 0 || count($sizes) > 30) {
            throw new RuntimeException('Оберіть хоча б один розмір.');
        }

        $sizeIds = [];

        foreach ($sizes as $value) {
            $sizeId = filter_var(
                $value,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );

            if (!$sizeId) {
                throw new RuntimeException('Некоректний розмір товару.');
            }

            $sizeIds[(int) $sizeId] = (int) $sizeId;
        }

        $product = Product::findById((int) $productId);

        if (!$product) {
            throw new RuntimeException('Товар більше недоступний.');
        }

        $category = Category::findById(
            (int) ($product['category_id'] ?? 0)
        );

        if (!$category) {
            throw new RuntimeException('Категорію товару не знайдено.');
        }

        if (
            !empty($category['effective_adult'])
            && !AdultAccess::isConfirmed()
        ) {
            throw new RuntimeException('Потрібне підтвердження віку.');
        }

        $usesVariantStock = ProductVariantStock::hasMatrix((int) $productId);
        $colorKey = is_string($selection['color_key'] ?? null)
            ? trim($selection['color_key'])
            : '';
        $colorName = '';
        $colorHex = '';

        if ($usesVariantStock) {
            if ($colorKey === '' || strlen($colorKey) > 220) {
                throw new RuntimeException('Оберіть колір товару.');
            }

            $color = ProductVariantStock::colorInfo(
                (int) $productId,
                $colorKey
            );

            if (!$color) {
                throw new RuntimeException('Вибраний колір недоступний.');
            }

            $colorName = (string) $color['color_name'];
            $colorHex = (string) $color['color_hex'];
        } else {
            $colorKey = '';
        }

        if (
            !$usesVariantStock
            && ($product['stock_mode'] ?? 'total') !== 'by_size'
            && count($sizeIds) > max(0, (int) ($product['stock'] ?? 0))
        ) {
            throw new RuntimeException('Недостатньо товару на складі.');
        }

        $items = [];
        $total = 0;
        $unitPrice = Product::getCurrentPrice($product);

        foreach ($sizeIds as $sizeId) {
            if (!Product::hasSize((int) $productId, $sizeId)) {
                throw new RuntimeException(
                    'Вибраний розмір не належить цьому товару.'
                );
            }

            $stock = $usesVariantStock
                ? ProductVariantStock::stockFor(
                    (int) $productId,
                    $sizeId,
                    $colorKey
                )
                : Product::getStockLimit($product, $sizeId);

            if ($stock < 1) {
                throw new RuntimeException(
                    'Недостатньо товару потрібного розміру на складі.'
                );
            }

            $items[] = [
                'product' => $product,
                'size_id' => $sizeId,
                'size' => Product::getAttributeValueById($sizeId),
                'color_key' => $colorKey,
                'color_name' => $colorName,
                'color_hex' => $colorHex,
                'quantity' => 1
            ];
            $total += $unitPrice;
        }

        return [$items, $total];
    }


    private function productOrderError($message, array $selection, $status)
    {
        http_response_code((int) $status);

        $productId = filter_var(
            $selection['product_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $product = $productId ? Product::findById($productId) : null;
        $backUrl = $product
            ? '/Anabelka/product/' . rawurlencode((string) $product['slug'])
            : '/Anabelka/catalog';

        $this->view('order/error', [
            'errorTitle' => Translator::t(
                'quick.title',
                'Швидке замовлення'
            ),
            'errorMessage' => (string) $message,
            'backUrl' => $backUrl,
            'backLabel' => $product
                ? 'Повернутися до товару'
                : Translator::t(
                    'public.order.back_catalog',
                    'Повернутися до каталогу'
                )
        ]);
    }


    private function getCartItems()
    {
        $userId = !empty($_SESSION['user_id'])
            ? (int) $_SESSION['user_id']
            : null;
        $items = [];

        if ($userId) {
            $items = Cart::getDetailedItemsByUserId($userId);
        } else {
            foreach ($_SESSION['cart'] ?? [] as $cartItem) {
                $productId = (int) ($cartItem['product_id'] ?? 0);
                $sizeId = (int) ($cartItem['size_id'] ?? 0);
                $quantity = (int) ($cartItem['quantity'] ?? 0);

                if ($productId <= 0 || $sizeId <= 0 || $quantity <= 0) {
                    continue;
                }

                $product = Product::findById($productId);

                if (!$product) {
                    continue;
                }

                $size = Product::getAttributeValueById($sizeId);
                $items[] = [
                    'product' => $product,
                    'size_id' => $sizeId,
                    'size' => $size,
                    'color_key' => (string) ($cartItem['color_key'] ?? ''),
                    'color_name' => (string) ($cartItem['color_name'] ?? ''),
                    'color_hex' => (string) ($cartItem['color_hex'] ?? ''),
                    'quantity' => $quantity
                ];
            }
        }

        $total = 0;

        foreach ($items as $item) {
            $total += Product::getCurrentPrice($item['product'])
                * (int) $item['quantity'];
        }

        return [$items, $total];
    }
}
