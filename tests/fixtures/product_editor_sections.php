<?php
// Render the production view with deterministic data and no database connection.
class AdminProduct
{
    public static function normalizeFilters($filters)
    {
        return array_merge(['status' => 'all', 'category_id' => 0, 'q' => ''], $filters);
    }
}

class TranslationWorkflow
{
    public static function statusOptions()
    {
        return ['draft' => 'Чернетка', 'approved' => 'Перевірено'];
    }
}

class Category
{
    public static function buildAdminForest($categories)
    {
        return [$categories];
    }

    public static function findById($id)
    {
        return ['id' => $id, 'effective_adult' => false];
    }
}

class Language
{
    const SOURCE_CODE = 'uk';
}

$_SERVER['REQUEST_URI'] = '/Anabelka/admin/products';
$productsError = '';
$csrfToken = 'fixture-csrf';
$categories = [['id' => 7, 'name' => 'Білизна', 'effective_active' => 1]];
$priceRanks = [['id' => 2, 'name' => 'Учасник', 'slug' => 'member']];
$languages = [
    ['code' => 'uk', 'name' => 'Українська', 'short_name' => 'UA'],
    ['code' => 'en', 'name' => 'Англійська', 'short_name' => 'EN']
];
$products = [
    [
        'id' => 1, 'category_id' => 7, 'category_name' => 'Білизна',
        'name' => 'Мереживний комплект', 'sku' => 'SET-1', 'slug' => 'lace-set',
        'description' => 'Комплект з мереживом', 'is_active' => 1,
        'price' => '49.50', 'old_price' => '60.00', 'rank_prices' => ['2' => '45.00'],
        'stock_mode' => 'by_size', 'stock' => 3, 'size_stock' => 3,
        'sizes' => [['id' => 11, 'name' => 'M', 'stock' => 3]],
        'material' => 'Поліамід', 'brand' => 'Anabelka', 'country' => 'Україна',
        'colors' => [['name' => 'Білий', 'hex' => '#ffffff']],
        'images' => [['id' => 17, 'path' => '/Anabelka/uploads/products/original.svg', 'is_main' => 1]],
        'translations' => ['en' => ['name' => 'Lace set', 'description' => '', 'status' => 'draft']]
    ],
    [
        'id' => 2, 'category_id' => 7, 'category_name' => 'Білизна',
        'name' => 'Другий товар', 'sku' => 'SET-2', 'slug' => 'second-set',
        'description' => '', 'is_active' => 1, 'price' => '25.00',
        'stock_mode' => 'total', 'stock' => 2, 'sizes' => [], 'images' => [], 'colors' => []
    ]
];

ob_start();
require dirname(__DIR__, 2) . '/views/admin/products/index.php';
echo json_encode(ob_get_clean(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
