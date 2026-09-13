<?php

class CatalogController extends Controller
{
    public function index()
    {
        $currentLanguage = Translator::currentLanguage();
        $categories = CategoryTranslator::localizeList(
            Category::all(),
            $currentLanguage['code'] ?? Language::SOURCE_CODE
        );

        $this->view('catalog/index', [
            'categories' => $categories
        ]);
    }


    public function category($departmentSlug, $categorySlug)
    {
        $category = Category::findByDepartmentAndSlug(
            $departmentSlug,
            $categorySlug
        );

        $this->showCategory($category);
    }


    public function legacyCategory($slug)
    {
        $category = Category::findUniqueActiveBySlug($slug);

        if (!$category) {
            $this->notFound();
        }

        $url = Category::catalogUrl($category);
        $query = http_build_query(is_array($_GET ?? null) ? $_GET : []);

        if ($query !== '') {
            $url .= '?' . $query;
        }

        // The mapping may change after a category moves departments or a
        // second visible department starts using the same slug. Do not let
        // clients cache a potentially stale permanent redirect.
        header('Location: ' . $url, true, 302);
        exit;
    }


    private function showCategory($category)
    {
        if (!$category) {
            $this->notFound();
        }

        if (
            !empty($category['effective_adult'])
            && !AdultAccess::isConfirmed()
        ) {
            $returnUrl = $_SERVER['REQUEST_URI']
                ?? Category::catalogUrl($category);

            header(
                'Location: '
                . AdultAccess::gateUrl($category, $returnUrl)
            );
            exit;
        }

        $children = Category::children((int) $category['id']);
        $products = Product::byCategory((int) $category['id']);
        $currentLanguage = Translator::currentLanguage();
        $languageCode = $currentLanguage['code']
            ?? Language::SOURCE_CODE;
        $category = CategoryTranslator::localize(
            $category,
            $languageCode
        );
        $children = CategoryTranslator::localizeList(
            $children,
            $languageCode
        );
        $products = ProductTranslator::localizeList(
            $products,
            $languageCode
        );

        $this->view('catalog/category', [
            'category' => $category,
            'children' => $children,
            'products' => $products
        ]);
    }


    private function notFound()
    {
        http_response_code(404);
        die('Категорію не знайдено');
    }
}
