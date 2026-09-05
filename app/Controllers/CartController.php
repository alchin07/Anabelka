<?php

class CartController extends Controller
{
    public function index()
    {
        if (!empty($_SESSION['user_id'])) {
            $cart = [];

            foreach (Cart::getItemsByUserId($_SESSION['user_id']) as $item) {
                $cartKey = $this->buildCartKey(
                    $item['product_id'],
                    $item['size_id'],
                    $item['color_key'] ?? ''
                );
                $cart[$cartKey] = [
                    'product_id' => (int) $item['product_id'],
                    'size_id' => (int) $item['size_id'],
                    'color_key' => (string) ($item['color_key'] ?? ''),
                    'color_name' => (string) ($item['color_name'] ?? ''),
                    'color_hex' => (string) ($item['color_hex'] ?? ''),
                    'quantity' => (int) $item['quantity']
                ];
            }
        } else {
            $cart = $_SESSION['cart'] ?? [];
        }

        $items = [];
        $total = 0;

        foreach ($cart as $cartKey => $cartItem) {
            $product = Product::findById($cartItem['product_id'] ?? 0);

            if (!$product) {
                continue;
            }

            $size = Product::getAttributeValueById(
                (int) ($cartItem['size_id'] ?? 0)
            );
            $quantity = max(0, (int) ($cartItem['quantity'] ?? 0));

            if ($quantity <= 0) {
                continue;
            }

            $price = Product::getCurrentPrice($product);
            $sum = $price * $quantity;
            $total += $sum;
            $items[] = [
                'cart_key' => $cartKey,
                'product' => $product,
                'size_id' => (int) ($cartItem['size_id'] ?? 0),
                'size' => $size,
                'color_key' => (string) ($cartItem['color_key'] ?? ''),
                'color_name' => (string) ($cartItem['color_name'] ?? ''),
                'color_hex' => (string) ($cartItem['color_hex'] ?? ''),
                'quantity' => $quantity,
                'sum' => $sum
            ];
        }

        $this->view('cart/index', [
            'items' => $items,
            'total' => $total
        ]);
    }


    public function add()
    {
        $productId = (int) ($_POST['product_id'] ?? 0);
        $sizes = $_POST['sizes'] ?? [];
        $colorKey = trim((string) ($_POST['color_key'] ?? ''));
        $colorName = trim((string) ($_POST['color_name'] ?? ''));
        $colorHex = strtolower(trim((string) ($_POST['color_hex'] ?? '')));

        if ($productId <= 0) {
            $this->jsonError('Товар не выбран');
        }

        if (!is_array($sizes) || empty($sizes)) {
            $this->jsonError('Выберите хотя бы один размер');
        }

        $product = Product::findById($productId);

        if (!$product) {
            $this->jsonError('Товар не найден');
        }

        $cleanSizes = [];

        foreach ($sizes as $sizeId) {
            $sizeId = (int) $sizeId;

            if ($sizeId > 0) {
                $cleanSizes[$sizeId] = $sizeId;
            }
        }

        $cleanSizes = array_values($cleanSizes);

        if (empty($cleanSizes)) {
            $this->jsonError('Выберите хотя бы один размер');
        }

        $usesVariantStock = ProductVariantStock::hasMatrix($productId);
        $colorInfo = null;

        if ($usesVariantStock) {
            if ($colorKey === '') {
                $this->jsonError('Выберите цвет');
            }

            $colorInfo = ProductVariantStock::colorInfo($productId, $colorKey);

            if (!$colorInfo) {
                $this->jsonError('Выбранный цвет недоступен');
            }

            $colorName = (string) $colorInfo['color_name'];
            $colorHex = (string) $colorInfo['color_hex'];

            foreach ($cleanSizes as $sizeId) {
                $stockLimit = ProductVariantStock::stockFor(
                    $productId,
                    $sizeId,
                    $colorKey
                );
                $currentQuantity = $this->currentVariantQuantity(
                    $productId,
                    $sizeId,
                    $colorKey
                );

                if ($currentQuantity + 1 > $stockLimit) {
                    $size = Product::getAttributeValueById($sizeId);
                    $sizeName = $size['value'] ?? '—';
                    $this->jsonError(
                        'Размер ' . $sizeName . ' в цвете '
                        . $colorName . ' закончился.'
                    );
                }
            }
        } else {
            $this->validateLegacyAdd($product, $cleanSizes);
            $colorKey = '';
            $colorName = '';
            $colorHex = '';
        }

        foreach ($cleanSizes as $sizeId) {
            if (!empty($_SESSION['user_id'])) {
                $added = Cart::addItem(
                    $_SESSION['user_id'],
                    $productId,
                    $sizeId,
                    1,
                    $colorKey,
                    $colorName,
                    $colorHex
                );

                if (!$added) {
                    $this->jsonError('Недостаточно товара на складе.');
                }

                continue;
            }

            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }

            $cartKey = $this->buildCartKey(
                $productId,
                $sizeId,
                $colorKey
            );

            if (isset($_SESSION['cart'][$cartKey])) {
                $_SESSION['cart'][$cartKey]['quantity']++;
            } else {
                $_SESSION['cart'][$cartKey] = [
                    'product_id' => $productId,
                    'size_id' => $sizeId,
                    'color_key' => $colorKey,
                    'color_name' => $colorName,
                    'color_hex' => $colorHex,
                    'quantity' => 1
                ];
            }
        }

        if ($this->isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => true,
                'cart_count' => $this->getCartCount()
            ]);
            exit;
        }

        header('Location: /Anabelka/cart');
        exit;
    }


    public function increase()
    {
        $cartKey = (string) ($_POST['cart_key'] ?? '');
        [$productId, $sizeId, $colorKey] = $this->parseCartKey($cartKey);

        if ($productId <= 0 || $sizeId <= 0) {
            $this->plainError('ERROR', 400);
        }

        $product = Product::findById($productId);

        if (!$product) {
            $this->plainError('Товар не найден', 404);
        }

        if (ProductVariantStock::hasMatrix($productId)) {
            $colorInfo = ProductVariantStock::colorInfo($productId, $colorKey);

            if (!$colorInfo) {
                $this->plainError('Цвет недоступен', 409);
            }

            $stockLimit = ProductVariantStock::stockFor(
                $productId,
                $sizeId,
                $colorKey
            );
            $currentQuantity = $this->currentVariantQuantity(
                $productId,
                $sizeId,
                $colorKey
            );

            if ($currentQuantity >= $stockLimit) {
                $this->plainError('Этот размер в выбранном цвете закончился', 409);
            }
        } else {
            $stockLimit = Product::getStockLimit($product, $sizeId);
            $currentQuantity = $this->currentLegacyQuantity(
                $product,
                $sizeId,
                $cartKey
            );

            if ($currentQuantity >= $stockLimit) {
                $this->plainError('Товар закончился', 409);
            }
        }

        if (!empty($_SESSION['user_id'])) {
            if (!Cart::increaseItem(
                $_SESSION['user_id'],
                $productId,
                $sizeId,
                $colorKey
            )) {
                $this->plainError('Товар закончился', 409);
            }
        } elseif (isset($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['quantity']++;
        }

        echo 'OK';
        exit;
    }


    public function decrease()
    {
        $cartKey = (string) ($_POST['cart_key'] ?? '');
        [$productId, $sizeId, $colorKey] = $this->parseCartKey($cartKey);

        if ($productId <= 0 || $sizeId <= 0) {
            $this->plainError('ERROR', 400);
        }

        if (!empty($_SESSION['user_id'])) {
            Cart::decreaseItem(
                $_SESSION['user_id'],
                $productId,
                $sizeId,
                $colorKey
            );
        } elseif (isset($_SESSION['cart'][$cartKey])) {
            $_SESSION['cart'][$cartKey]['quantity']--;

            if ((int) $_SESSION['cart'][$cartKey]['quantity'] <= 0) {
                unset($_SESSION['cart'][$cartKey]);
            }
        }

        echo 'OK';
        exit;
    }


    public function remove()
    {
        $cartKey = (string) ($_POST['cart_key'] ?? '');
        [$productId, $sizeId, $colorKey] = $this->parseCartKey($cartKey);

        if ($productId <= 0 || $sizeId <= 0) {
            $this->plainError('ERROR', 400);
        }

        if (!empty($_SESSION['user_id'])) {
            Cart::removeItem(
                $_SESSION['user_id'],
                $productId,
                $sizeId,
                $colorKey
            );
        } elseif (isset($_SESSION['cart'][$cartKey])) {
            unset($_SESSION['cart'][$cartKey]);
        }

        echo 'OK';
        exit;
    }


    private function validateLegacyAdd(array $product, array $sizes)
    {
        $productId = (int) $product['id'];
        $stockMode = $product['stock_mode'] ?? 'total';

        if ($stockMode === 'total') {
            $stockLimit = (int) ($product['stock'] ?? 0);
            $currentQuantity = 0;

            if (!empty($_SESSION['user_id'])) {
                $currentQuantity = Cart::getProductQuantity(
                    $_SESSION['user_id'],
                    $productId
                );
            } else {
                foreach ($_SESSION['cart'] ?? [] as $item) {
                    if ((int) ($item['product_id'] ?? 0) === $productId) {
                        $currentQuantity += (int) ($item['quantity'] ?? 0);
                    }
                }
            }

            if ($currentQuantity + count($sizes) > $stockLimit) {
                $this->jsonError('Недостаточно товара на складе.');
            }

            return;
        }

        foreach ($sizes as $sizeId) {
            $stockLimit = Product::getStockLimit($product, $sizeId);
            $currentQuantity = $this->currentLegacyQuantity(
                $product,
                $sizeId,
                $this->buildCartKey($productId, $sizeId, '')
            );

            if ($currentQuantity + 1 > $stockLimit) {
                $size = Product::getAttributeValueById($sizeId);
                $this->jsonError(
                    'Размер ' . ($size['value'] ?? '—') . ' закончился.'
                );
            }
        }
    }


    private function currentVariantQuantity($productId, $sizeId, $colorKey)
    {
        if (!empty($_SESSION['user_id'])) {
            return Cart::getVariantQuantity(
                $_SESSION['user_id'],
                $productId,
                $sizeId,
                $colorKey
            );
        }

        $cartKey = $this->buildCartKey($productId, $sizeId, $colorKey);

        return (int) (
            $_SESSION['cart'][$cartKey]['quantity'] ?? 0
        );
    }


    private function currentLegacyQuantity(array $product, $sizeId, $cartKey)
    {
        $productId = (int) $product['id'];

        if (!empty($_SESSION['user_id'])) {
            return Cart::getCurrentQuantityForMode(
                $_SESSION['user_id'],
                $product,
                $sizeId
            );
        }

        if (($product['stock_mode'] ?? 'total') === 'by_size') {
            return (int) (
                $_SESSION['cart'][$cartKey]['quantity'] ?? 0
            );
        }

        $total = 0;

        foreach ($_SESSION['cart'] ?? [] as $item) {
            if ((int) ($item['product_id'] ?? 0) === $productId) {
                $total += (int) ($item['quantity'] ?? 0);
            }
        }

        return $total;
    }


    private function buildCartKey($productId, $sizeId, $colorKey)
    {
        $key = (int) $productId . '_' . (int) $sizeId;
        $colorKey = trim((string) $colorKey);

        if ($colorKey === '') {
            return $key;
        }

        $encoded = rtrim(
            strtr(base64_encode($colorKey), '+/', '-_'),
            '='
        );

        return $key . '_' . $encoded;
    }


    private function parseCartKey($cartKey)
    {
        $parts = explode('_', (string) $cartKey, 3);
        $colorKey = '';

        if (!empty($parts[2])) {
            $encoded = strtr((string) $parts[2], '-_', '+/');
            $padding = strlen($encoded) % 4;

            if ($padding > 0) {
                $encoded .= str_repeat('=', 4 - $padding);
            }

            $decoded = base64_decode($encoded, true);

            if ($decoded !== false) {
                $colorKey = (string) $decoded;
            }
        }

        return [
            (int) ($parts[0] ?? 0),
            (int) ($parts[1] ?? 0),
            $colorKey
        ];
    }


    private function isAjax()
    {
        return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }


    private function jsonError($message)
    {
        if ($this->isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => $message
            ]);
            exit;
        }

        die($message);
    }


    private function plainError($message, $status)
    {
        http_response_code((int) $status);
        echo $message;
        exit;
    }


    private function getCartCount()
    {
        $cartCount = 0;

        if (!empty($_SESSION['user_id'])) {
            foreach (Cart::getItemsByUserId($_SESSION['user_id']) as $item) {
                $cartCount += (int) $item['quantity'];
            }
        } else {
            foreach ($_SESSION['cart'] ?? [] as $item) {
                $cartCount += (int) ($item['quantity'] ?? 0);
            }
        }

        return $cartCount;
    }
}
