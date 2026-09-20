<?php

class SearchController extends Controller
{
    public function index()
    {
        PublicInterfaceTranslator::seed();
        SearchInterfaceTranslator::seed();

        $currentLanguage = Translator::currentLanguage();
        $languageCode = $currentLanguage['code']
            ?? Language::SOURCE_CODE;

        $query = CatalogSearch::normalizeQuery($_GET['q'] ?? '');
        $products = [];
        $categories = [];
        $searchPagination = [
            'page' => 1,
            'per_page' => 24,
            'total_products' => 0,
            'total_categories' => 0,
            'total' => 0,
            'total_pages' => 1,
            'has_previous' => false,
            'has_next' => false
        ];

        if ($query !== '') {
            $results = CatalogSearch::page(
                $query,
                $languageCode,
                $_GET['page'] ?? 1,
                24
            );
            $products = $results['products'] ?? [];
            $categories = $results['categories'] ?? [];
            $searchPagination = $results;

            SearchQueryLog::record(
                $query,
                $_SESSION['user_id'] ?? 0,
                $languageCode,
                (int) ($results['total_products'] ?? 0),
                (int) ($results['total_categories'] ?? 0)
            );
        }

        $this->view('search/index', [
            'pageTitle' => Translator::t('search.title', 'Пошук'),
            'currentLanguage' => $currentLanguage,
            'query' => $query,
            'products' => $products,
            'categories' => $categories,
            'searchPagination' => $searchPagination
        ]);
    }


    /**
     * Живі підказки в шапці сайту.
     *
     * Важливо: цей endpoint НЕ записує введені фрагменти до журналу.
     * У статистику потрапляє тільки остаточний перехід на /search.
     */
    public function suggest()
    {
        PublicInterfaceTranslator::seed();
        SearchInterfaceTranslator::seed();

        $currentLanguage = Translator::currentLanguage();
        $languageCode = $currentLanguage['code']
            ?? Language::SOURCE_CODE;
        $query = CatalogSearch::normalizeQuery($_GET['q'] ?? '');
        $length = function_exists('mb_strlen')
            ? mb_strlen($query, 'UTF-8')
            : strlen($query);

        if ($length < 2) {
            $this->json([
                'success' => true,
                'query' => $query,
                'products' => [],
                'categories' => []
            ]);
            return;
        }

        $results = CatalogSearch::run($query, $languageCode);
        $products = array_slice(
            is_array($results['products'] ?? null)
                ? $results['products']
                : [],
            0,
            5
        );
        $categories = array_slice(
            is_array($results['categories'] ?? null)
                ? $results['categories']
                : [],
            0,
            4
        );

        $productItems = [];

        foreach ($products as $product) {
            $productItems[] = [
                'id' => (int) ($product['id'] ?? 0),
                'name' => (string) ($product['name'] ?? ''),
                'sku' => (string) ($product['sku'] ?? ''),
                'url' => '/Anabelka/product/'
                    . rawurlencode((string) ($product['slug'] ?? '')),
                'image' => $this->assetUrl($product['main_image'] ?? ''),
                'price' => number_format(
                    (float) Product::getCurrentPrice($product),
                    2,
                    ',',
                    ' '
                ) . ' €'
            ];
        }

        $categoryItems = [];

        foreach ($categories as $category) {
            $categoryItems[] = [
                'id' => (int) ($category['id'] ?? 0),
                'name' => (string) ($category['name'] ?? ''),
                'url' => Category::catalogUrl($category)
            ];
        }

        $this->json([
            'success' => true,
            'query' => $query,
            'products' => $productItems,
            'categories' => $categoryItems
        ]);
    }


    private function assetUrl($path)
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (strpos($path, '/Anabelka/') === 0) {
            return $path;
        }

        return '/Anabelka/' . ltrim($path, '/');
    }


    private function json(array $payload)
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
