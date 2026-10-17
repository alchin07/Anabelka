<?php
// Render the real views using deterministic data; never bootstrap or connect a DB.
class TranslationWorkflow
{
    public static function statusOptions()
    {
        return ['draft' => 'Чернетка', 'review' => 'Перевірка', 'approved' => 'Схвалено', 'outdated' => 'Застарілий'];
    }
}

class Language
{
    const SOURCE_CODE = 'uk';
}

$requestUri = $fixtureUri ?? '/Anabelka/admin/categories';
$_SERVER['REQUEST_URI'] = $requestUri;
parse_str((string) parse_url($requestUri, PHP_URL_QUERY), $_GET);
$csrfToken = 'category-fixture-csrf';
$departments = [
    ['id' => 1, 'name' => 'Білизна', 'slug' => 'lingerie', 'is_active' => 1],
    ['id' => 2, 'name' => 'Аксесуари', 'slug' => 'accessories', 'is_active' => 1],
];
$category = static function ($id, $name, $parent = null, $department = 1) {
    return [
        'id' => $id, 'name' => $name, 'description' => 'Опис ' . $name,
        'slug' => 'category-' . $id, 'department_id' => $department,
        'department_slug' => $department === 1 ? 'lingerie' : 'accessories',
        'parent_id' => $parent, 'sort_order' => $id,
        'is_active' => 1, 'effective_active' => 1, 'is_adult' => 0,
        'effective_adult' => 0, 'child_count' => 0, 'product_count' => 0,
        'translation_count' => 1,
        'translations' => ['en' => ['name' => 'English ' . $id, 'description' => 'English description',
            'source' => 'manual', 'status' => 'draft']],
    ];
};
$categories = [$category(1, 'Корінь'), $category(2, 'Дочірня', 1),
    $category(3, 'Порожня'), $category(4, 'Інша', null, 2)];
$categories[0]['child_count'] = 1;
$root = $categories[0];
$root['children'] = [$categories[1]];
$categoryForest = [1 => [$root, $categories[2]], 2 => [$categories[3]]];
$languages = [
    ['code' => 'uk', 'name' => 'Українська', 'short_name' => 'UA'],
    ['code' => 'en', 'name' => 'Англійська', 'short_name' => 'EN'],
];

ob_start();
if (strpos($requestUri, '/Anabelka/admin/translations/missing') === 0) {
    $section = 'categories';
    $targetLanguages = [$languages[1]];
    $items = [];
    require dirname(__DIR__, 2) . '/views/admin/translations/missing.php';
} else {
    require dirname(__DIR__, 2) . '/views/admin/categories/index.php';
}
echo json_encode(ob_get_clean(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
