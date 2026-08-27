<?php

class ProductController extends Controller
{
    public function show($slug)
    {
        // Ищем товар по slug
        $product = Product::findBySlug($slug);

if (!$product) {
    http_response_code(404);
    die('Товар не найден');
}

// Получаем характеристики товара
$attributes = Product::attributes($product['id']);
$prices = Product::getPricesByRanks(
    $product['id']
);

$currentRankSlug =
    Product::getCurrentRankSlug();

$badges =
    Product::getBadges(
        $product['id']
    );

$this->view('product/show', [
    'product' => $product,
    'attributes' => $attributes,
    'prices' => $prices,
    'currentRankSlug' => $currentRankSlug,
    'badges' => $badges
]);
    }
}