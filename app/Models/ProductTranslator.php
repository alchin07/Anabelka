<?php

class ProductTranslator
{
    private static $schemaReady = false;


    private static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        Language::all();

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS product_translations
            (
                product_id INT UNSIGNED NOT NULL,
                language_code VARCHAR(10) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                status VARCHAR(20) NOT NULL DEFAULT 'approved',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (
                    product_id,
                    language_code
                ),
                KEY idx_product_translations_language (
                    language_code
                )
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function getForProduct($productId)
    {
        self::ensureTable();

        $productId = (int) $productId;

        if ($productId <= 0) {
            return [];
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                language_code,
                name,
                description,
                source,
                status
            FROM product_translations
            WHERE product_id = :product_id
        ");

        $stmt->execute([
            'product_id' => $productId
        ]);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['language_code']] = $row;
        }

        return $result;
    }


    public static function saveForProduct(
        $productId,
        $languageCode,
        $name,
        $description,
        $source = 'manual'
    ) {
        self::ensureTable();

        $productId = (int) $productId;
        $languageCode = strtolower(trim((string) $languageCode));
        $name = trim((string) $name);
        $description = trim((string) $description);

        if ($productId <= 0 || $languageCode === '') {
            throw new InvalidArgumentException(
                'Некорректные данные перевода товара.'
            );
        }

        if ($languageCode === Language::SOURCE_CODE) {
            return true;
        }

        $language = Language::findByCode($languageCode);

        if (!$language || empty($language['is_active'])) {
            throw new InvalidArgumentException('Язык недоступен.');
        }

        $db = Database::connect();

        if ($name === '' && $description === '') {
            $stmt = $db->prepare("
                DELETE FROM product_translations
                WHERE product_id = :product_id
                  AND language_code = :language_code
            ");

            return $stmt->execute([
                'product_id' => $productId,
                'language_code' => $languageCode
            ]);
        }

        if ($name === '') {
            throw new InvalidArgumentException(
                'Если перевод заполнен, название обязательно.'
            );
        }

        $stmt = $db->prepare("
            INSERT INTO product_translations
            (
                product_id,
                language_code,
                name,
                description,
                source,
                status
            )
            VALUES
            (
                :product_id,
                :language_code,
                :name,
                :description,
                :source,
                'approved'
            )
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                source = VALUES(source),
                status = 'approved'
        ");

        return $stmt->execute([
            'product_id' => $productId,
            'language_code' => $languageCode,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'source' => trim((string) $source) ?: 'manual'
        ]);
    }


    public static function localize(array $product, $languageCode)
    {
        self::ensureTable();

        $productId = (int) ($product['id'] ?? 0);
        $languageCode = strtolower(trim((string) $languageCode));

        if (
            $productId <= 0
            || $languageCode === ''
            || $languageCode === Language::SOURCE_CODE
        ) {
            return $product;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                name,
                description
            FROM product_translations
            WHERE product_id = :product_id
              AND language_code = :language_code
              AND status = 'approved'
            LIMIT 1
        ");

        $stmt->execute([
            'product_id' => $productId,
            'language_code' => $languageCode
        ]);

        $translation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$translation) {
            return $product;
        }

        $product['name'] = $translation['name'];
        $product['description'] = $translation['description'];

        return $product;
    }


    public static function localizeList(array $products, $languageCode)
    {
        foreach ($products as $index => $product) {
            $products[$index] = self::localize(
                $product,
                $languageCode
            );
        }

        return $products;
    }
}
