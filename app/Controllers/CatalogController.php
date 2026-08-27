<?php

class CatalogController extends Controller
{
    public function index()
    {
        // Получаем категории из базы данных
        $categories = Category::all();

        // Передаём категории в представление
        $this->view('catalog/index', [
            'categories' => $categories
        ]);
    }

    public function category($slug)
    {
        // Ищем текущую категорию по slug
        $category = Category::findBySlug($slug);

        // Если категория не найдена
        if (!$category) {
            http_response_code(404);
            die('Категория не найдена');
        }

        // Получаем дочерние категории
        $children = Category::children($category['id']);

        // Получаем товары этой категории
        $products = Product::byCategory($category['id']);

        // Передаём данные в представление
        $this->view('catalog/category', [
            'category' => $category,
            'children' => $children,
            'products' => $products
        ]);
    }
}