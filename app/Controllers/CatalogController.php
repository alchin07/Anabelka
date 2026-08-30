<?php

class CatalogController extends Controller
{
    public function index()
    {
        $categories = Category::all();

        $currentLanguage =
            Translator::currentLanguage();

        $categories =
            CategoryTranslator::localizeList(
                $categories,
                $currentLanguage['code'] ?? Language::SOURCE_CODE
            );

        $this->view('catalog/index', [
            'categories' => $categories
        ]);
    }


    public function category($slug)
    {
        $category = Category::findBySlug($slug);

        if (!$category) {
            http_response_code(404);
            die('Категория не найдена');
        }

        $children = Category::children($category['id']);
        $products = Product::byCategory($category['id']);

        $currentLanguage =
            Translator::currentLanguage();

        $languageCode =
            $currentLanguage['code'] ?? Language::SOURCE_CODE;

        $category =
            CategoryTranslator::localize(
                $category,
                $languageCode
            );

        $children =
            CategoryTranslator::localizeList(
                $children,
                $languageCode
            );

        $this->view('catalog/category', [
            'category' => $category,
            'children' => $children,
            'products' => $products
        ]);
    }
}
