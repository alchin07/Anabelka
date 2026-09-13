<?php

class FavoriteController extends Controller
{
    public function index()
    {
        PublicInterfaceTranslator::seed();
        FavoriteInterfaceTranslator::seed();

        $currentLanguage = Translator::currentLanguage();
        $languageCode = $currentLanguage['code']
            ?? Language::SOURCE_CODE;
        $products = Favorite::currentProducts($languageCode);

        $this->view('favorites/index', [
            'pageTitle' => Translator::t('favorite.title', 'Обране'),
            'currentLanguage' => $currentLanguage,
            'products' => $products
        ]);
    }


    public function state()
    {
        FavoriteInterfaceTranslator::seed();

        $this->json([
            'success' => true,
            'count' => Favorite::countCurrent(),
            'items' => Favorite::currentStateItems()
        ]);
    }


    public function toggle()
    {
        FavoriteInterfaceTranslator::seed();

        if (
            ($_SERVER['HTTP_X_ANABELKA_REQUEST'] ?? '')
            !== 'favorites'
        ) {
            $this->json([
                'success' => false,
                'message' => 'Некоректний запит.'
            ], 400);
        }

        $productId = (int) ($_POST['product_id'] ?? 0);
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $product = $productId > 0
            ? Product::findById($productId)
            : Product::findBySlug($slug);

        if (!$product) {
            $this->json([
                'success' => false,
                'message' => 'Товар не знайдено.'
            ], 404);
        }

        $productId = (int) ($product['id'] ?? 0);
        $category = Category::findById(
            (int) ($product['category_id'] ?? 0)
        );

        if (!$category) {
            $this->json([
                'success' => false,
                'message' => 'Товар недоступний.'
            ], 404);
        }

        if (
            !empty($category['effective_adult'])
            && !AdultAccess::isConfirmed()
        ) {
            $this->json([
                'success' => false,
                'message' => 'Потрібне підтвердження віку.',
                'gate_url' => AdultAccess::gateUrl(
                    $category,
                    '/Anabelka/product/'
                        . rawurlencode((string) ($product['slug'] ?? ''))
                )
            ], 403);
        }

        try {
            $result = Favorite::toggleCurrent($productId);
            $active = !empty($result['active']);

            $this->json([
                'success' => true,
                'product_id' => $productId,
                'slug' => (string) ($product['slug'] ?? ''),
                'active' => $active,
                'count' => (int) ($result['count'] ?? 0),
                'label' => Translator::t(
                    $active ? 'favorite.remove' : 'favorite.add',
                    $active ? 'Видалити з обраного' : 'Додати до обраного'
                )
            ]);
        } catch (Throwable $e) {
            $this->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }


    private function json(array $payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
