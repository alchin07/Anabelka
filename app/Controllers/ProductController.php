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

        if (($_GET['view'] ?? '') === 'category') {
            $category = Category::findById(
                (int) ($product['category_id'] ?? 0)
            );

            if (!$category || empty($category['slug'])) {
                http_response_code(404);
                die('Категория товара не найдена');
            }

            $url = '/Anabelka/catalog/'
                . rawurlencode((string) $category['slug'])
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

        if ($stockMode === 'by_size') {
            $availableTotal = 0;

            foreach ($attributes as &$attribute) {
                if (($attribute['attribute_slug'] ?? '') !== 'size') {
                    continue;
                }

                $sizeId = (int) ($attribute['value_id'] ?? 0);
                $stock = (int) ($attribute['stock'] ?? 0);
                $inCart = (int) ($cartSizeQuantities[$sizeId] ?? 0);
                $available = max(0, $stock - $inCart);
                $attribute['stock'] = $available;
                $availableTotal += $available;
            }
            unset($attribute);
            $product['stock'] = $availableTotal;
        } else {
            $availableTotal = max(
                0,
                (int) ($product['stock'] ?? 0) - $cartProductQuantity
            );
            $product['stock'] = $availableTotal;

            foreach ($attributes as &$attribute) {
                if (($attribute['attribute_slug'] ?? '') === 'size') {
                    $attribute['stock'] = $availableTotal;
                }
            }
            unset($attribute);
        }

        $prices = Product::getPricesByRanks($productId);
        $currentRankSlug = Product::getCurrentRankSlug();
        $badges = Product::getBadges($productId);
        $currentLanguage = Translator::currentLanguage();
        $product = ProductTranslator::localize(
            $product,
            $currentLanguage['code'] ?? Language::SOURCE_CODE
        );

        $this->view('product/show', [
            'product' => $product,
            'attributes' => $attributes,
            'images' => $images,
            'prices' => $prices,
            'currentRankSlug' => $currentRankSlug,
            'badges' => $badges
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

        $productId = (int) $product['id'];
        $variantsByProduct = ProductImage::colorVariantsForProducts([
            $productId
        ]);
        $imageColors = $variantsByProduct[$productId] ?? [];
        $rows = ProductVariantStock::forProduct($productId);
        $usesVariantStock = !empty($rows);
        $colors = [];
        $seenColors = [];

        foreach ($imageColors as $variant) {
            $name = trim((string) ($variant['name'] ?? ''));
            $hex = strtolower(trim((string) ($variant['hex'] ?? '')));

            if ($name === '') {
                continue;
            }

            $key = ProductVariantStock::colorKey($name, $hex);

            if (isset($seenColors[$key])) {
                continue;
            }

            $seenColors[$key] = true;
            $colors[] = [
                'key' => $key,
                'name' => $name,
                'hex' => $hex,
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

            $availableRows[] = [
                'size_id' => $sizeId,
                'size_name' => (string) ($row['size_name'] ?? ''),
                'color_key' => $colorKey,
                'color_name' => (string) ($row['color_name'] ?? ''),
                'color_hex' => (string) ($row['color_hex'] ?? ''),
                'stock' => max(0, $stock - $inCart)
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
