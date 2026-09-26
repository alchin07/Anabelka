<?php

class ProductController extends Controller
{
    public function show($slug)
    {
        $product = Product::findBySlug($slug);

        if (!$product) {
            http_response_code(404);
            die('Товар не найден');
        }

        $productCategory = Category::findById(
            (int) ($product['category_id'] ?? 0)
        );

        if (!$productCategory) {
            http_response_code(404);
            die('Категорію товару не знайдено');
        }

        if (
            !empty($productCategory['effective_adult'])
            && !AdultAccess::isConfirmed()
        ) {
            $returnUrl = $_SERVER['REQUEST_URI']
                ?? '/Anabelka/product/' . rawurlencode((string) $slug);

            header(
                'Location: '
                . AdultAccess::gateUrl(
                    $productCategory,
                    $returnUrl
                )
            );
            exit;
        }

        if (($_GET['view'] ?? '') === 'category') {
            $category = $productCategory;

            if (!$category || empty($category['slug'])) {
                http_response_code(404);
                die('Категория товара не найдена');
            }

            $url = Category::catalogUrl($category)
                . '?highlight_product='
                . rawurlencode((string) $product['slug']);

            header('Location: ' . $url);
            exit;
        }

        $attributes = Product::attributes($product['id']);
        $images = Product::images($product['id']);
        $productId = (int) $product['id'];
        $stockMode = $product['stock_mode'] ?? 'total';
        $cartProductQuantity = 0;
        $cartSizeQuantities = [];

        if (!empty($_SESSION['user_id'])) {
            $userId = (int) $_SESSION['user_id'];
            $cartProductQuantity = Cart::getProductQuantity(
                $userId,
                $productId
            );

            foreach ($attributes as $attribute) {
                if (($attribute['attribute_slug'] ?? '') !== 'size') {
                    continue;
                }

                $sizeId = (int) ($attribute['value_id'] ?? 0);

                if ($sizeId > 0) {
                    $cartSizeQuantities[$sizeId] = Cart::getSizeQuantity(
                        $userId,
                        $productId,
                        $sizeId
                    );
                }
            }
        } else {
            foreach ($_SESSION['cart'] ?? [] as $cartItem) {
                if ((int) ($cartItem['product_id'] ?? 0) !== $productId) {
                    continue;
                }

                $quantity = (int) ($cartItem['quantity'] ?? 0);
                $sizeId = (int) ($cartItem['size_id'] ?? 0);
                $cartProductQuantity += $quantity;

                if ($sizeId > 0) {
                    $cartSizeQuantities[$sizeId] =
                        ($cartSizeQuantities[$sizeId] ?? 0) + $quantity;
                }
            }
        }

        $productStockOnHand = max(
            0,
            (int) ($product['stock'] ?? 0)
        );

        if ($stockMode === 'by_size') {
            $availableTotal = 0;

            foreach ($attributes as &$attribute) {
                if (($attribute['attribute_slug'] ?? '') !== 'size') {
                    continue;
                }

                $sizeId = (int) ($attribute['value_id'] ?? 0);
                $stockOnHand = max(
                    0,
                    (int) ($attribute['stock'] ?? 0)
                );
                $inCart = max(
                    0,
                    (int) ($cartSizeQuantities[$sizeId] ?? 0)
                );
                $available = max(0, $stockOnHand - $inCart);

                $attribute['stock_on_hand'] = $stockOnHand;
                $attribute['cart_quantity'] = $inCart;
                $attribute['available_stock'] = $available;
                $attribute['stock'] = $available;
                $availableTotal += $available;
            }
            unset($attribute);
        } else {
            $availableTotal = max(
                0,
                $productStockOnHand - $cartProductQuantity
            );

            foreach ($attributes as &$attribute) {
                if (($attribute['attribute_slug'] ?? '') !== 'size') {
                    continue;
                }

                $sizeId = (int) ($attribute['value_id'] ?? 0);

                $attribute['stock_on_hand'] = $productStockOnHand;
                $attribute['cart_quantity'] = max(
                    0,
                    (int) ($cartSizeQuantities[$sizeId] ?? 0)
                );
                $attribute['available_stock'] = $availableTotal;
                $attribute['stock'] = $availableTotal;
            }
            unset($attribute);
        }

        $product['stock_on_hand'] = $productStockOnHand;
        $product['cart_quantity'] = max(0, $cartProductQuantity);
        $product['available_stock'] = $availableTotal;
        $product['stock'] = $availableTotal;

        $prices = Product::getPricesByRanks($productId);
        $currentRankSlug = Product::getCurrentRankSlug();
        $vipPriceWatermarks = VipPriceProtection::forVisiblePrices(
            $productId,
            $prices,
            'product'
        );
        $badges = Product::getBadges($productId);
        $currentLanguage = Translator::currentLanguage();
        $product = ProductTranslator::localize(
            $product,
            $currentLanguage['code'] ?? Language::SOURCE_CODE
        );

        $reviews = [];
        $canReview = false;
        $reviewCsrfToken = '';
        $reviewFlash = is_array($_SESSION['product_review_flash'] ?? null)
            ? $_SESSION['product_review_flash']
            : null;
        unset($_SESSION['product_review_flash']);

        try {
            $reviews = ProductReview::approvedForProduct($productId);
            $reviewUserId = CustomerAccount::currentId();

            if ($reviewUserId > 0) {
                $canReview = !ProductReview::hasReview(
                    $productId,
                    $reviewUserId
                );
                $reviewCsrfToken = CustomerAccount::csrfToken();
            }
        } catch (Throwable $e) {
            error_log('Product reviews: ' . $e->getMessage());
            $reviews = [];
            $canReview = false;
            $reviewCsrfToken = '';
        }

        $this->view('product/show', [
            'product' => $product,
            'attributes' => $attributes,
            'images' => $images,
            'prices' => $prices,
            'currentRankSlug' => $currentRankSlug,
            'vipPriceWatermarks' => $vipPriceWatermarks,
            'badges' => $badges,
            'reviews' => $reviews,
            'canReview' => $canReview,
            'reviewCsrfToken' => $reviewCsrfToken,
            'reviewFlash' => $reviewFlash
        ]);
    }


    public function variants($slug)
    {
        $product = Product::findBySlug($slug);

        if (!$product) {
            $this->json([
                'success' => false,
                'message' => 'Товар не найден'
            ], 404);
        }

        $productCategory = Category::findById(
            (int) ($product['category_id'] ?? 0)
        );

        if (!$productCategory) {
            $this->json([
                'success' => false,
                'message' => 'Категорію товару не знайдено'
            ], 404);
        }

        if (
            !empty($productCategory['effective_adult'])
            && !AdultAccess::isConfirmed()
        ) {
            $this->json([
                'success' => false,
                'message' => 'Потрібне підтвердження віку.',
                'gate_url' => AdultAccess::gateUrl(
                    $productCategory,
                    '/Anabelka/product/' . rawurlencode((string) $slug)
                )
            ], 403);
        }

        $productId = (int) $product['id'];
        $variantsByProduct = ProductColor::variantsForProducts([
            $productId
        ]);
        $imageColors = $variantsByProduct[$productId] ?? [];
        $rows = ProductVariantStock::forProduct($productId);
        $usesVariantStock = !empty($rows);
        $normalizeColorName = static function ($name) {
            $name = trim((string) $name);

            return function_exists('mb_strtolower')
                ? mb_strtolower($name, 'UTF-8')
                : strtolower($name);
        };
        $matrixColorsByName = [];

        foreach ($rows as $row) {
            $normalizedName = $normalizeColorName(
                $row['color_name'] ?? ''
            );

            if ($normalizedName !== '' && !isset($matrixColorsByName[$normalizedName])) {
                $matrixColorsByName[$normalizedName] = $row;
            }
        }

        $colors = [];
        $seenColors = [];

        foreach ($imageColors as $variant) {
            $name = trim((string) ($variant['name'] ?? ''));
            $hex = strtolower(trim((string) ($variant['hex'] ?? '')));
            $normalizedName = $normalizeColorName($name);

            if ($normalizedName === '' || isset($seenColors[$normalizedName])) {
                continue;
            }

            $matrixColor = $matrixColorsByName[$normalizedName] ?? null;
            $key = is_array($matrixColor)
                ? (string) ($matrixColor['color_key'] ?? '')
                : ProductVariantStock::colorKey($name, $hex);
            $matrixHex = is_array($matrixColor)
                ? strtolower(trim((string) ($matrixColor['color_hex'] ?? '')))
                : '';

            $seenColors[$normalizedName] = true;
            $colors[] = [
                'key' => $key,
                'name' => $name,
                'hex' => $matrixHex !== '' ? $matrixHex : $hex,
                'image' => (string) ($variant['path'] ?? ''),
                'image_id' => (int) ($variant['image_id'] ?? 0)
            ];
        }

        $availableRows = [];

        foreach ($rows as $row) {
            $sizeId = (int) ($row['size_value_id'] ?? 0);
            $colorKey = (string) ($row['color_key'] ?? '');
            $stock = max(0, (int) ($row['stock'] ?? 0));
            $inCart = 0;

            if (!empty($_SESSION['user_id'])) {
                $inCart = Cart::getVariantQuantity(
                    (int) $_SESSION['user_id'],
                    $productId,
                    $sizeId,
                    $colorKey
                );
            } else {
                foreach ($_SESSION['cart'] ?? [] as $item) {
                    if (
                        (int) ($item['product_id'] ?? 0) === $productId
                        && (int) ($item['size_id'] ?? 0) === $sizeId
                        && (string) ($item['color_key'] ?? '') === $colorKey
                    ) {
                        $inCart += (int) ($item['quantity'] ?? 0);
                    }
                }
            }

            $available = max(0, $stock - $inCart);

            $availableRows[] = [
                'size_id' => $sizeId,
                'size_name' => (string) ($row['size_name'] ?? ''),
                'color_key' => $colorKey,
                'color_name' => (string) ($row['color_name'] ?? ''),
                'color_hex' => (string) ($row['color_hex'] ?? ''),
                'stock_on_hand' => $stock,
                'in_cart' => max(0, $inCart),
                'available' => $available,
                'stock' => $available
            ];
        }

        $this->json([
            'success' => true,
            'uses_variant_stock' => $usesVariantStock,
            'colors' => $colors,
            'stock' => $availableRows
        ]);
    }


    private function json(array $payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
