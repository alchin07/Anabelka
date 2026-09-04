<?php

class AdminProduct
{
    private const FILTER_STATUSES = [
        'all',
        'active',
        'out_of_stock',
        'hidden'
    ];

    private const CHARACTERISTIC_ATTRIBUTES = [
        'color' => 'Колір',
        'material' => 'Матеріал'
    ];


    public static function normalizeFilters(array $input)
    {
        $status = strtolower(trim((string) ($input['status'] ?? 'all')));
        $categoryId = (int) ($input['category_id'] ?? 0);
        $query = trim((string) ($input['q'] ?? ''));

        if (!in_array($status, self::FILTER_STATUSES, true)) {
            $status = 'all';
        }

        $query = function_exists('mb_substr')
            ? mb_substr($query, 0, 120, 'UTF-8')
            : substr($query, 0, 120);

        return [
            'status' => $status,
            'category_id' => max(0, $categoryId),
            'q' => $query
        ];
    }


    public static function all(array $filters = [])
    {
        $filters = self::normalizeFilters($filters);
        $db = Database::connect();
        $conditions = [];
        $params = [];

        if ($filters['status'] === 'active') {
            $conditions[] = 'p.is_active = 1';
        } elseif ($filters['status'] === 'hidden') {
            $conditions[] = 'p.is_active = 0';
        } elseif ($filters['status'] === 'out_of_stock') {
            $conditions[] = 'p.is_active = 1';
            $conditions[] = self::outOfStockCondition('p');
        }

        if ($filters['category_id'] > 0) {
            $conditions[] = 'p.category_id = :category_id';
            $params['category_id'] = $filters['category_id'];
        }

        if ($filters['q'] !== '') {
            $conditions[] = '('
                . 'p.name LIKE :query_name '
                . "OR COALESCE(p.sku, '') LIKE :query_sku "
                . "OR COALESCE(p.slug, '') LIKE :query_slug"
                . ')';
            $like = '%' . $filters['q'] . '%';
            $params['query_name'] = $like;
            $params['query_sku'] = $like;
            $params['query_slug'] = $like;
        }

        $where = empty($conditions)
            ? ''
            : 'WHERE ' . implode(' AND ', $conditions);

        $stmt = $db->prepare("
            SELECT
                p.id,
                p.category_id,
                p.name,
                p.slug,
                p.sku,
                p.description,
                p.price,
                p.member_price,
                p.old_price,
                p.stock,
                p.stock_mode,
                p.show_stock_quantity,
                p.brand,
                p.country,
                p.main_image,
                p.is_active,
                c.name AS category_name,
                COALESCE(
                    (
                        SELECT SUM(pa.stock)
                        FROM product_attributes AS pa
                        JOIN attribute_values AS av
                            ON av.id = pa.attribute_value_id
                        JOIN attributes AS a
                            ON a.id = av.attribute_id
                        WHERE pa.product_id = p.id
                          AND a.slug = 'size'
                    ),
                    0
                ) AS size_stock
            FROM products AS p
            LEFT JOIN categories AS c
                ON c.id = p.category_id
            {$where}
            ORDER BY
                p.is_active DESC,
                COALESCE(c.name, ''),
                p.name,
                p.id
            LIMIT 300
        ");
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::attachRelations($products);

        return $products;
    }


    public static function summary()
    {
        $db = Database::connect();

        return [
            'all' => self::count($db, 'SELECT COUNT(*) FROM products'),
            'active' => self::count(
                $db,
                'SELECT COUNT(*) FROM products WHERE is_active = 1'
            ),
            'out_of_stock' => self::count(
                $db,
                'SELECT COUNT(*) FROM products AS p '
                    . 'WHERE p.is_active = 1 AND '
                    . self::outOfStockCondition('p')
            ),
            'hidden' => self::count(
                $db,
                'SELECT COUNT(*) FROM products WHERE is_active = 0'
            )
        ];
    }


    public static function categories()
    {
        return Category::getAllForAdmin();
    }


    public static function priceRanks()
    {
        $db = Database::connect();
        $stmt = $db->query("
            SELECT
                id,
                name,
                slug,
                level,
                is_active
            FROM user_ranks
            WHERE is_active = 1
            ORDER BY level ASC, id ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function find($productId)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT *
            FROM products
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => (int) $productId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    public static function categoryExists($categoryId)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT id
            FROM categories
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => (int) $categoryId]);

        return (bool) $stmt->fetchColumn();
    }


    public static function uniqueSlug($requestedSlug, $name, $excludeId = 0)
    {
        $base = self::slugify(
            trim((string) $requestedSlug) !== ''
                ? $requestedSlug
                : $name
        );

        if ($base === '') {
            $base = 'product-' . date('Ymd-His');
        }

        $base = substr($base, 0, 170);
        $base = rtrim($base, '-');

        $candidate = $base;
        $suffix = 2;

        while (self::slugExists($candidate, $excludeId)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }


    public static function uniqueSku($requestedSku, $excludeId = 0)
    {
        $base = strtoupper(trim((string) $requestedSku));

        if ($base === '') {
            $base = 'ANB-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        $base = preg_replace('/\s+/', '-', $base);
        $base = substr((string) $base, 0, 90);
        $base = rtrim($base, '-');

        if ($base === '') {
            $base = 'ANB-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }

        $candidate = $base;
        $suffix = 2;

        while (self::skuExists($candidate, $excludeId)) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }


    public static function create(array $data)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            INSERT INTO products
            (
                category_id,
                name,
                slug,
                sku,
                description,
                price,
                member_price,
                old_price,
                stock,
                stock_mode,
                show_stock_quantity,
                brand,
                country,
                main_image,
                is_active
            )
            VALUES
            (
                :category_id,
                :name,
                :slug,
                :sku,
                :description,
                :price,
                :member_price,
                :old_price,
                :stock,
                :stock_mode,
                :show_stock_quantity,
                :brand,
                :country,
                NULL,
                :is_active
            )
        ");
        $stmt->execute(self::coreParams($data));

        return (int) $db->lastInsertId();
    }


    public static function update($productId, array $data)
    {
        $db = Database::connect();
        $params = self::coreParams($data);
        $params['id'] = (int) $productId;
        $stmt = $db->prepare("
            UPDATE products
            SET
                category_id = :category_id,
                name = :name,
                slug = :slug,
                sku = :sku,
                description = :description,
                price = :price,
                member_price = :member_price,
                old_price = :old_price,
                stock = :stock,
                stock_mode = :stock_mode,
                show_stock_quantity = :show_stock_quantity,
                brand = :brand,
                country = :country,
                is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute($params);
    }


    public static function syncPrices(
        $productId,
        $basePrice,
        array $rankPrices,
        array $ranks
    ) {
        $db = Database::connect();
        $productId = (int) $productId;
        $memberPrice = (float) $basePrice;
        $insert = $db->prepare("
            INSERT INTO product_prices
            (
                product_id,
                rank_id,
                price
            )
            VALUES
            (
                :product_id,
                :rank_id,
                :price
            )
        ");
        $delete = $db->prepare("
            DELETE FROM product_prices
            WHERE product_id = :product_id
              AND rank_id = :rank_id
        ");

        foreach ($ranks as $rank) {
            $rankId = (int) ($rank['id'] ?? 0);
            $slug = strtolower(trim((string) ($rank['slug'] ?? '')));

            if ($rankId <= 0) {
                continue;
            }

            $price = $slug === 'guest'
                ? (float) $basePrice
                : ($rankPrices[$rankId] ?? null);

            $delete->execute([
                'product_id' => $productId,
                'rank_id' => $rankId
            ]);

            if ($price === null || $price === '') {
                continue;
            }

            $price = (float) $price;
            $insert->execute([
                'product_id' => $productId,
                'rank_id' => $rankId,
                'price' => $price
            ]);

            if ($slug === 'member') {
                $memberPrice = $price;
            }
        }

        $db->prepare("
            UPDATE products
            SET
                price = :price,
                member_price = :member_price
            WHERE id = :product_id
        ")->execute([
            'price' => (float) $basePrice,
            'member_price' => $memberPrice,
            'product_id' => $productId
        ]);
    }


    public static function syncSizes(
        $productId,
        array $sizes,
        $stockMode
    ) {
        $productId = (int) $productId;
        $db = Database::connect();
        $attributeStmt = $db->prepare("
            SELECT id
            FROM attributes
            WHERE slug = 'size'
            LIMIT 1
        ");
        $attributeStmt->execute();
        $attributeId = (int) $attributeStmt->fetchColumn();

        if ($attributeId <= 0) {
            if (empty($sizes)) {
                return;
            }

            throw new RuntimeException(
                'У базі не знайдено атрибут «Розмір».'
            );
        }

        $deleteExisting = $db->prepare("
            DELETE pa
            FROM product_attributes AS pa
            JOIN attribute_values AS av
                ON av.id = pa.attribute_value_id
            WHERE pa.product_id = :product_id
              AND av.attribute_id = :attribute_id
        ");
        $deleteExisting->execute([
            'product_id' => $productId,
            'attribute_id' => $attributeId
        ]);

        $valueIds = [];
        $totalStock = 0;

        foreach ($sizes as $size) {
            $valueId = (int) ($size['id'] ?? 0);
            $value = trim((string) ($size['name'] ?? ''));
            $stock = max(0, (int) ($size['stock'] ?? 0));

            if ($value === '') {
                continue;
            }

            if ($valueId > 0) {
                $verify = $db->prepare("
                    SELECT av.id
                    FROM attribute_values AS av
                    WHERE av.id = :id
                      AND av.attribute_id = :attribute_id
                    LIMIT 1
                ");
                $verify->execute([
                    'id' => $valueId,
                    'attribute_id' => $attributeId
                ]);
                $valueId = (int) $verify->fetchColumn();
            }

            if ($valueId <= 0) {
                $find = $db->prepare("
                    SELECT id
                    FROM attribute_values
                    WHERE attribute_id = :attribute_id
                      AND LOWER(value) = LOWER(:value)
                    LIMIT 1
                ");
                $find->execute([
                    'attribute_id' => $attributeId,
                    'value' => $value
                ]);
                $valueId = (int) $find->fetchColumn();
            }

            if ($valueId <= 0) {
                $insert = $db->prepare("
                    INSERT INTO attribute_values
                    (
                        attribute_id,
                        value,
                        color_hex
                    )
                    VALUES
                    (
                        :attribute_id,
                        :value,
                        NULL
                    )
                ");
                $insert->execute([
                    'attribute_id' => $attributeId,
                    'value' => $value
                ]);
                $valueId = (int) $db->lastInsertId();
            }

            if ($valueId <= 0 || in_array($valueId, $valueIds, true)) {
                continue;
            }

            $storedStock = $stockMode === 'by_size' ? $stock : 0;
            $insertRelation = $db->prepare("
                INSERT INTO product_attributes
                (
                    product_id,
                    attribute_value_id,
                    stock
                )
                VALUES
                (
                    :product_id,
                    :attribute_value_id,
                    :stock
                )
            ");
            $insertRelation->execute([
                'product_id' => $productId,
                'attribute_value_id' => $valueId,
                'stock' => $storedStock
            ]);

            $valueIds[] = $valueId;
            $totalStock += $storedStock;
        }

        if ($stockMode === 'by_size') {
            $db->prepare("
                UPDATE products
                SET stock = :stock
                WHERE id = :product_id
            ")->execute([
                'stock' => $totalStock,
                'product_id' => $productId
            ]);
        }
    }


    public static function syncCharacteristics(
        $productId,
        array $characteristics
    ) {
        $productId = (int) $productId;

        if ($productId <= 0) {
            return;
        }

        foreach (self::CHARACTERISTIC_ATTRIBUTES as $slug => $name) {
            self::syncCharacteristic(
                $productId,
                $slug,
                $name,
                $characteristics[$slug] ?? ''
            );
        }
    }


    private static function syncCharacteristic(
        $productId,
        $slug,
        $attributeName,
        $value
    ) {
        $db = Database::connect();
        $slug = trim((string) $slug);
        $value = trim((string) $value);

        $db->prepare("
            INSERT IGNORE INTO attributes
            (
                name,
                slug
            )
            VALUES
            (
                :name,
                :slug
            )
        ")->execute([
            'name' => (string) $attributeName,
            'slug' => $slug
        ]);

        $attributeStmt = $db->prepare("
            SELECT id
            FROM attributes
            WHERE slug = :slug
            LIMIT 1
        ");
        $attributeStmt->execute(['slug' => $slug]);
        $attributeId = (int) $attributeStmt->fetchColumn();

        if ($attributeId <= 0) {
            throw new RuntimeException(
                'Не вдалося підготувати характеристику товару.'
            );
        }

        $db->prepare("
            DELETE pa
            FROM product_attributes AS pa
            JOIN attribute_values AS av
                ON av.id = pa.attribute_value_id
            WHERE pa.product_id = :product_id
              AND av.attribute_id = :attribute_id
        ")->execute([
            'product_id' => (int) $productId,
            'attribute_id' => $attributeId
        ]);

        if ($value === '') {
            return;
        }

        $valueStmt = $db->prepare("
            SELECT id
            FROM attribute_values
            WHERE attribute_id = :attribute_id
              AND LOWER(value) = LOWER(:value)
            LIMIT 1
        ");
        $valueStmt->execute([
            'attribute_id' => $attributeId,
            'value' => $value
        ]);
        $valueId = (int) $valueStmt->fetchColumn();

        if ($valueId <= 0) {
            $insertValue = $db->prepare("
                INSERT INTO attribute_values
                (
                    attribute_id,
                    value,
                    color_hex
                )
                VALUES
                (
                    :attribute_id,
                    :value,
                    NULL
                )
            ");
            $insertValue->execute([
                'attribute_id' => $attributeId,
                'value' => $value
            ]);
            $valueId = (int) $db->lastInsertId();
        }

        if ($valueId <= 0) {
            throw new RuntimeException(
                'Не вдалося зберегти характеристику товару.'
            );
        }

        $db->prepare("
            INSERT INTO product_attributes
            (
                product_id,
                attribute_value_id,
                stock
            )
            VALUES
            (
                :product_id,
                :attribute_value_id,
                0
            )
            ON DUPLICATE KEY UPDATE
                stock = 0
        ")->execute([
            'product_id' => (int) $productId,
            'attribute_value_id' => $valueId
        ]);
    }


    public static function setActive($productId, $isActive)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE products
            SET is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            'is_active' => $isActive ? 1 : 0,
            'id' => (int) $productId
        ]);

        if ($stmt->rowCount() === 0 && !self::find($productId)) {
            throw new RuntimeException('Товар не знайдено.');
        }
    }


    public static function duplicate($sourceId)
    {
        $source = self::find($sourceId);

        if (!$source) {
            throw new RuntimeException('Товар не знайдено.');
        }

        ProductTranslator::getForProduct(0);
        ProductImage::ensureTable();

        $copyName = 'Копія — ' . (string) $source['name'];
        $copyName = function_exists('mb_substr')
            ? mb_substr($copyName, 0, 255, 'UTF-8')
            : substr($copyName, 0, 255);
        $sourceSku = trim((string) ($source['sku'] ?? ''));
        $data = [
            'category_id' => (int) $source['category_id'],
            'name' => $copyName,
            'slug' => self::uniqueSlug('', $copyName),
            'sku' => self::uniqueSku(
                $sourceSku === '' ? '' : $sourceSku . '-COPY'
            ),
            'description' => $source['description'],
            'price' => (float) $source['price'],
            'member_price' => $source['member_price'] === null
                ? (float) $source['price']
                : $source['member_price'],
            'old_price' => $source['old_price'],
            'stock' => (int) $source['stock'],
            'stock_mode' => (string) $source['stock_mode'],
            'show_stock_quantity' => (int) $source['show_stock_quantity'],
            'brand' => $source['brand'],
            'country' => $source['country'],
            'is_active' => 0
        ];

        $targetId = self::create($data);
        $db = Database::connect();

        $db->prepare("
            INSERT INTO product_prices
            (
                product_id,
                rank_id,
                price
            )
            SELECT
                :target_id,
                rank_id,
                price
            FROM product_prices
            WHERE product_id = :source_id
        ")->execute([
            'target_id' => $targetId,
            'source_id' => (int) $sourceId
        ]);

        $db->prepare("
            INSERT INTO product_attributes
            (
                product_id,
                attribute_value_id,
                stock
            )
            SELECT
                :target_id,
                attribute_value_id,
                stock
            FROM product_attributes
            WHERE product_id = :source_id
        ")->execute([
            'target_id' => $targetId,
            'source_id' => (int) $sourceId
        ]);

        $db->prepare("
            INSERT INTO product_translations
            (
                product_id,
                language_code,
                name,
                description,
                source,
                status
            )
            SELECT
                :target_id,
                language_code,
                name,
                description,
                source,
                'outdated'
            FROM product_translations
            WHERE product_id = :source_id
        ")->execute([
            'target_id' => $targetId,
            'source_id' => (int) $sourceId
        ]);

        ProductImage::duplicateForProduct((int) $sourceId, $targetId);

        return $targetId;
    }


    private static function attachRelations(array &$products)
    {
        $ids = array_map(
            function ($product) {
                return (int) ($product['id'] ?? 0);
            },
            $products
        );

        if (empty($ids)) {
            return;
        }

        $imageMap = ProductImage::forProducts($ids);
        $db = Database::connect();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $priceStmt = $db->prepare("
            SELECT
                product_id,
                rank_id,
                price
            FROM product_prices
            WHERE product_id IN ({$placeholders})
        ");
        $priceStmt->execute($ids);
        $priceMap = [];

        foreach ($priceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $priceMap[(int) $row['product_id']][(int) $row['rank_id']]
                = (float) $row['price'];
        }

        $attributeStmt = $db->prepare("
            SELECT
                pa.product_id,
                av.id,
                av.value,
                pa.stock,
                a.slug AS attribute_slug
            FROM product_attributes AS pa
            JOIN attribute_values AS av
                ON av.id = pa.attribute_value_id
            JOIN attributes AS a
                ON a.id = av.attribute_id
            WHERE pa.product_id IN ({$placeholders})
            ORDER BY a.id ASC, av.id ASC
        ");
        $attributeStmt->execute($ids);
        $sizeMap = [];
        $characteristicMap = [];

        foreach ($attributeStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $productId = (int) $row['product_id'];
            $slug = strtolower(trim((string) $row['attribute_slug']));

            if ($slug === 'size') {
                $sizeMap[$productId][] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['value'],
                    'stock' => (int) $row['stock']
                ];
                continue;
            }

            if (
                isset(self::CHARACTERISTIC_ATTRIBUTES[$slug])
                && !isset($characteristicMap[$productId][$slug])
            ) {
                $characteristicMap[$productId][$slug] =
                    (string) $row['value'];
            }
        }

        foreach ($products as &$product) {
            $productId = (int) $product['id'];
            $product['images'] = $imageMap[$productId] ?? [];
            $product['rank_prices'] = $priceMap[$productId] ?? [];
            $product['sizes'] = $sizeMap[$productId] ?? [];
            $product['color'] =
                $characteristicMap[$productId]['color'] ?? '';
            $product['material'] =
                $characteristicMap[$productId]['material'] ?? '';
        }
        unset($product);
    }


    private static function coreParams(array $data)
    {
        return [
            'category_id' => (int) $data['category_id'],
            'name' => trim((string) $data['name']),
            'slug' => trim((string) $data['slug']),
            'sku' => trim((string) $data['sku']),
            'description' => self::nullable($data['description'] ?? null),
            'price' => (float) $data['price'],
            'member_price' => $data['member_price'] === null
                ? null
                : (float) $data['member_price'],
            'old_price' => $data['old_price'] === null
                ? null
                : (float) $data['old_price'],
            'stock' => max(0, (int) $data['stock']),
            'stock_mode' => $data['stock_mode'] === 'by_size'
                ? 'by_size'
                : 'total',
            'show_stock_quantity' => !empty($data['show_stock_quantity'])
                ? 1
                : 0,
            'brand' => self::nullable($data['brand'] ?? null),
            'country' => self::nullable($data['country'] ?? null),
            'is_active' => !empty($data['is_active']) ? 1 : 0
        ];
    }


    private static function nullable($value)
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }


    private static function count(PDO $db, $sql)
    {
        return (int) $db->query($sql)->fetchColumn();
    }


    private static function outOfStockCondition($alias)
    {
        return "(
            (
                {$alias}.stock_mode = 'by_size'
                AND COALESCE(
                    (
                        SELECT SUM(pa.stock)
                        FROM product_attributes AS pa
                        JOIN attribute_values AS av
                            ON av.id = pa.attribute_value_id
                        JOIN attributes AS a
                            ON a.id = av.attribute_id
                        WHERE pa.product_id = {$alias}.id
                          AND a.slug = 'size'
                    ),
                    0
                ) <= 0
            )
            OR
            (
                {$alias}.stock_mode <> 'by_size'
                AND {$alias}.stock <= 0
            )
        )";
    }


    private static function slugExists($slug, $excludeId)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT id
            FROM products
            WHERE slug = :slug
              AND id <> :exclude_id
            LIMIT 1
        ");
        $stmt->execute([
            'slug' => $slug,
            'exclude_id' => (int) $excludeId
        ]);

        return (bool) $stmt->fetchColumn();
    }


    private static function skuExists($sku, $excludeId)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT id
            FROM products
            WHERE sku = :sku
              AND id <> :exclude_id
            LIMIT 1
        ");
        $stmt->execute([
            'sku' => $sku,
            'exclude_id' => (int) $excludeId
        ]);

        return (bool) $stmt->fetchColumn();
    }


    private static function slugify($value)
    {
        $value = trim((string) $value);
        $map = [
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'H',
            'Ґ' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'E',
            'Є' => 'Ye', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'Y',
            'І' => 'I', 'Ї' => 'Yi', 'Й' => 'Y', 'К' => 'K',
            'Л' => 'L', 'М' => 'M', 'Н' => 'N', 'О' => 'O',
            'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
            'У' => 'U', 'Ф' => 'F', 'Х' => 'Kh', 'Ц' => 'Ts',
            'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Shch', 'Ъ' => '',
            'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu',
            'Я' => 'Ya',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h',
            'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
            'є' => 'ye', 'ж' => 'zh', 'з' => 'z', 'и' => 'y',
            'і' => 'i', 'ї' => 'yi', 'й' => 'y', 'к' => 'k',
            'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
            'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
            'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '',
            'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
            'я' => 'ya'
        ];
        $value = strtr($value, $map);
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);

        return trim((string) $value, '-');
    }
}
