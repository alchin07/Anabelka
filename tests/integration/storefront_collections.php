<?php

$required = [
    'ANABELKA_TEST_DSN',
    'ANABELKA_TEST_USER',
    'ANABELKA_TEST_PASSWORD'
];

if (getenv('ANABELKA_ALLOW_TEST_DB') !== '1') {
    fwrite(
        STDERR,
        "PENDING: set ANABELKA_ALLOW_TEST_DB=1 for isolated DB integration.\n"
    );
    exit(2);
}

foreach ($required as $key) {
    if (getenv($key) === false) {
        fwrite(STDERR, "PENDING: missing {$key}.\n");
        exit(2);
    }
}

$pdo = new PDO(
    getenv('ANABELKA_TEST_DSN'),
    getenv('ANABELKA_TEST_USER'),
    getenv('ANABELKA_TEST_PASSWORD'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]
);

$databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

if ($databaseName !== 'anabelka_mobile_navigation_test') {
    fwrite(
        STDERR,
        "REFUSED: integration DB must be anabelka_mobile_navigation_test.\n"
    );
    exit(3);
}

class Database
{
    public static $connection;

    public static function connect()
    {
        return self::$connection;
    }
}

class Category
{
    public static $visibleIds = [1, 2];
    public static $adultIds = [2];

    public static function visibleCategoryIds()
    {
        return self::$visibleIds;
    }

    public static function adultCategoryIds()
    {
        return self::$adultIds;
    }
}

class Product
{
    public static $rankSlug = 'guest';

    public static function getCurrentRankSlug()
    {
        return self::$rankSlug;
    }
}

class ProductTranslator
{
    public static function localizeList(array $products, $languageCode)
    {
        return $products;
    }
}

class ProductImage
{
    public static function colorVariantsForProducts(array $productIds)
    {
        $result = [];

        foreach ($productIds as $productId) {
            $result[(int) $productId] = [];
        }

        return $result;
    }
}

Database::$connection = $pdo;

require __DIR__ . '/../../app/Models/StorefrontProductCollection.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true)
        );
    }
}

function assertTrueValue($value, $message)
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

$pdo->exec("
    CREATE TEMPORARY TABLE products (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        category_id INT UNSIGNED NOT NULL,
        name VARCHAR(255) NOT NULL,
        slug VARCHAR(190) NOT NULL,
        sku VARCHAR(100) NULL,
        description TEXT NULL,
        price DECIMAL(12,2) NOT NULL,
        member_price DECIMAL(12,2) NULL,
        old_price DECIMAL(12,2) NULL,
        stock INT NOT NULL DEFAULT 0,
        stock_mode VARCHAR(20) NOT NULL DEFAULT 'total',
        show_stock_quantity TINYINT(1) NOT NULL DEFAULT 0,
        brand VARCHAR(190) NULL,
        country VARCHAR(190) NULL,
        main_image VARCHAR(500) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB
");
$pdo->exec("
    CREATE TEMPORARY TABLE user_ranks (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        slug VARCHAR(80) NOT NULL UNIQUE,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB
");
$pdo->exec("
    CREATE TEMPORARY TABLE product_prices (
        product_id INT UNSIGNED NOT NULL,
        rank_id INT UNSIGNED NOT NULL,
        price DECIMAL(12,2) NOT NULL,
        PRIMARY KEY (product_id, rank_id)
    ) ENGINE=InnoDB
");
$pdo->exec("
    CREATE TEMPORARY TABLE product_badges (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        discount_percent DECIMAL(7,2) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB
");

$pdo->exec("
    INSERT INTO user_ranks (id, slug, is_active)
    VALUES (1, 'guest', 1), (2, 'member', 1)
");

$insertProduct = $pdo->prepare("
    INSERT INTO products (
        id, category_id, name, slug, sku, description,
        price, member_price, old_price, stock, stock_mode,
        show_stock_quantity, brand, country, main_image, is_active
    ) VALUES (
        :id, :category_id, :name, :slug, :sku, '',
        :price, :member_price, :old_price, 10, 'total',
        0, 'Anabelka', 'UA', NULL, :is_active
    )
");

for ($id = 100; $id < 125; $id++) {
    $insertProduct->execute([
        'id' => $id,
        'category_id' => 1,
        'name' => 'New ' . $id,
        'slug' => 'new-' . $id,
        'sku' => 'NEW-' . $id,
        'price' => 50,
        'member_price' => 50,
        'old_price' => null,
        'is_active' => 1
    ]);
}

$fixtureProducts = [
    [1, 1, 80, 100, 1],
    [2, 1, 100, null, 1],
    [3, 1, 100, null, 1],
    [4, 1, 100, 100, 1],
    [5, 1, 100, null, 1],
    [6, 1, 100, 90, 1],
    [7, 2, 50, 100, 1],
    [8, 3, 50, 100, 1],
    [9, 1, 50, 100, 0]
];

foreach ($fixtureProducts as $row) {
    [$id, $categoryId, $price, $oldPrice, $isActive] = $row;
    $insertProduct->execute([
        'id' => $id,
        'category_id' => $categoryId,
        'name' => 'Fixture ' . $id,
        'slug' => 'fixture-' . $id,
        'sku' => 'FIX-' . $id,
        'price' => $price,
        'member_price' => $price,
        'old_price' => $oldPrice,
        'is_active' => $isActive
    ]);
}

$pdo->exec("
    INSERT INTO product_badges
        (product_id, discount_percent, is_active)
    VALUES
        (2, 10, 1),
        (3, 25, 0),
        (5, 5, 1),
        (5, 15, 1)
");
$pdo->exec("
    INSERT INTO product_prices (product_id, rank_id, price)
    VALUES (6, 2, 80)
");

$newPage1 = StorefrontProductCollection::page('new', 1, 'uk');
$newPage2 = StorefrontProductCollection::page('new', 2, 'uk');

assertSameValue(31, $newPage1['total'], 'new collection total');
assertSameValue(24, count($newPage1['items']), 'new page 1 size');
assertSameValue(7, count($newPage2['items']), 'new page 2 size');
assertTrueValue(
    (int) $newPage1['items'][0]['id'] > (int) $newPage1['items'][1]['id'],
    'new collection must be id DESC'
);

$allNewIds = array_merge(
    array_column($newPage1['items'], 'id'),
    array_column($newPage2['items'], 'id')
);
assertSameValue(
    count($allNewIds),
    count(array_unique(array_map('intval', $allNewIds))),
    'new pagination must not duplicate IDs'
);
assertTrueValue(!in_array(7, array_map('intval', $allNewIds), true), 'adult product excluded');
assertTrueValue(!in_array(8, array_map('intval', $allNewIds), true), 'invisible category excluded');
assertTrueValue(!in_array(9, array_map('intval', $allNewIds), true), 'inactive product excluded');

Product::$rankSlug = 'guest';
$guestDiscounts = StorefrontProductCollection::page('discounts', 1, 'uk');
$guestById = [];

foreach ($guestDiscounts['items'] as $item) {
    $guestById[(int) $item['id']] = $item;
}

assertTrueValue(isset($guestById[1]), 'old price reduction qualifies');
assertTrueValue(isset($guestById[2]), 'active badge qualifies');
assertTrueValue(isset($guestById[5]), 'multiple badges qualify');
assertTrueValue(!isset($guestById[3]), 'disabled badge does not qualify');
assertTrueValue(!isset($guestById[4]), 'equal old/current does not qualify');
assertTrueValue(!isset($guestById[6]), 'guest price does not qualify rank-only reduction');
assertTrueValue(!isset($guestById[7]), 'adult discounted product excluded');
assertSameValue(15.0, $guestById[5]['active_discount_percent'], 'max active badge wins');

Product::$rankSlug = 'member';
$memberDiscounts = StorefrontProductCollection::page('discounts', 1, 'uk');
$memberIds = array_map('intval', array_column($memberDiscounts['items'], 'id'));
assertTrueValue(in_array(6, $memberIds, true), 'member price can qualify old-price reduction');

Category::$visibleIds = [1, 2];
Category::$adultIds = [2];
$adultConfirmedEquivalent = StorefrontProductCollection::page('new', 1, 'uk');
$adultConfirmedIds = array_map('intval', array_column($adultConfirmedEquivalent['items'], 'id'));
assertTrueValue(!in_array(7, $adultConfirmedIds, true), 'adult remains excluded after confirmation state');

fwrite(STDOUT, "storefront collection isolated DB integration passed\n");
