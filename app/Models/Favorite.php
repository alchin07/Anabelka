<?php

class Favorite
{
    private const SESSION_KEY = 'favorite_product_ids';
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS user_favorites
            (
                user_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, product_id),
                KEY idx_user_favorites_product (product_id),
                KEY idx_user_favorites_created (created_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function currentIds()
    {
        if (!empty($_SESSION['user_id'])) {
            return self::idsForUser((int) $_SESSION['user_id']);
        }

        return self::sessionIds();
    }


    public static function currentStateItems()
    {
        $ids = self::currentIds();

        if (empty($ids)) {
            return [];
        }

        $db = Database::connect();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT id, slug, category_id
            FROM products
            WHERE is_active = 1
              AND id IN ({$placeholders})
        ");
        $stmt->execute($ids);
        $items = [];
        $adultConfirmed = AdultAccess::isConfirmed();

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $categoryId = (int) ($row['category_id'] ?? 0);

            if (!Category::isEffectivelyActive($categoryId)) {
                continue;
            }

            if (
                Category::isEffectivelyAdult($categoryId)
                && !$adultConfirmed
            ) {
                continue;
            }

            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'slug' => (string) ($row['slug'] ?? '')
            ];
        }

        return $items;
    }


    public static function countCurrent()
    {
        return count(self::currentStateItems());
    }


    public static function contains($productId)
    {
        $productId = (int) $productId;

        if ($productId <= 0) {
            return false;
        }

        return in_array($productId, self::currentIds(), true);
    }


    public static function toggleCurrent($productId)
    {
        $productId = (int) $productId;

        if ($productId <= 0) {
            throw new InvalidArgumentException('Некоректний товар.');
        }

        $product = Product::findById($productId);

        if (!$product) {
            throw new RuntimeException('Товар не знайдено.');
        }

        if (!empty($_SESSION['user_id'])) {
            $active = self::toggleForUser(
                (int) $_SESSION['user_id'],
                $productId
            );
        } else {
            $ids = self::sessionIds();
            $position = array_search($productId, $ids, true);

            if ($position === false) {
                array_unshift($ids, $productId);
                $active = true;
            } else {
                unset($ids[$position]);
                $ids = array_values($ids);
                $active = false;
            }

            $_SESSION[self::SESSION_KEY] = $ids;
        }

        return [
            'active' => $active,
            'count' => self::countCurrent()
        ];
    }


    public static function mergeSessionToUser($userId)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return;
        }

        $ids = self::sessionIds();

        if (empty($ids)) {
            unset($_SESSION[self::SESSION_KEY]);
            return;
        }

        self::ensureSchema();
        $db = Database::connect();
        $stmt = $db->prepare("
            INSERT IGNORE INTO user_favorites
            (user_id, product_id)
            VALUES (:user_id, :product_id)
        ");

        foreach ($ids as $productId) {
            $stmt->execute([
                'user_id' => $userId,
                'product_id' => (int) $productId
            ]);
        }

        unset($_SESSION[self::SESSION_KEY]);
    }


    public static function currentProducts($languageCode)
    {
        $ids = self::currentIds();

        if (empty($ids)) {
            return [];
        }

        $db = Database::connect();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $order = implode(',', array_map('intval', $ids));
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
                p.main_image
            FROM products p
            WHERE p.is_active = 1
              AND p.id IN ({$placeholders})
            ORDER BY FIELD(p.id, {$order})
        ");
        $stmt->execute($ids);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $adultConfirmed = AdultAccess::isConfirmed();
        $products = array_values(array_filter(
            $products,
            function ($product) use ($adultConfirmed) {
                $categoryId = (int) ($product['category_id'] ?? 0);

                if (!Category::isEffectivelyActive($categoryId)) {
                    return false;
                }

                return $adultConfirmed
                    || !Category::isEffectivelyAdult($categoryId);
            }
        ));

        $products = ProductTranslator::localizeList(
            $products,
            $languageCode
        );

        if (!empty($products)) {
            $productIds = array_map(
                function ($product) {
                    return (int) ($product['id'] ?? 0);
                },
                $products
            );
            $variants = ProductImage::colorVariantsForProducts($productIds);

            foreach ($products as &$product) {
                $productId = (int) ($product['id'] ?? 0);
                $product['color_variants'] = $variants[$productId] ?? [];
            }
            unset($product);
        }

        return $products;
    }


    private static function idsForUser($userId)
    {
        self::ensureSchema();
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT product_id
            FROM user_favorites
            WHERE user_id = :user_id
            ORDER BY created_at DESC, product_id DESC
        ");
        $stmt->execute(['user_id' => (int) $userId]);

        return array_map(
            'intval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
    }


    private static function toggleForUser($userId, $productId)
    {
        self::ensureSchema();
        $db = Database::connect();
        $check = $db->prepare("
            SELECT 1
            FROM user_favorites
            WHERE user_id = :user_id
              AND product_id = :product_id
            LIMIT 1
        ");
        $check->execute([
            'user_id' => (int) $userId,
            'product_id' => (int) $productId
        ]);

        if ($check->fetchColumn()) {
            $delete = $db->prepare("
                DELETE FROM user_favorites
                WHERE user_id = :user_id
                  AND product_id = :product_id
            ");
            $delete->execute([
                'user_id' => (int) $userId,
                'product_id' => (int) $productId
            ]);

            return false;
        }

        $insert = $db->prepare("
            INSERT IGNORE INTO user_favorites
            (user_id, product_id)
            VALUES (:user_id, :product_id)
        ");
        $insert->execute([
            'user_id' => (int) $userId,
            'product_id' => (int) $productId
        ]);

        return true;
    }


    private static function sessionIds()
    {
        $raw = is_array($_SESSION[self::SESSION_KEY] ?? null)
            ? $_SESSION[self::SESSION_KEY]
            : [];
        $ids = [];

        foreach ($raw as $value) {
            $id = (int) $value;

            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $_SESSION[self::SESSION_KEY] = $ids;

        return $ids;
    }
}
