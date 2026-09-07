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

        if ($query !== '') {
            $results = CatalogSearch::run($query, $languageCode);
            $products = $results['products'] ?? [];
            $categories = $results['categories'] ?? [];

            SearchQueryLog::record(
                $query,
                $_SESSION['user_id'] ?? 0,
                $languageCode,
                count($products),
                count($categories)
            );
        }

        $this->view('search/index', [
            'pageTitle' => Translator::t('search.title', 'Пошук'),
            'currentLanguage' => $currentLanguage,
            'query' => $query,
            'products' => $products,
            'categories' => $categories
        ]);
    }
}
