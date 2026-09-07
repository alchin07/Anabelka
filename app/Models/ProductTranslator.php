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

        /*
         * У старій версії таблиці перекладів існувало поле
         * department_id. Воно більше не використовується, але
         * CREATE TABLE IF NOT EXISTS не змінює вже створену таблицю.
         * Якщо старе поле залишилося NOT NULL без DEFAULT, будь-який
         * INSERT нового перекладу завершується MySQL 1364.
         * Робимо його сумісним зі старою базою без втрати даних.
         */
        $legacyColumnStmt = $db->query(
            "SHOW COLUMNS FROM product_translations LIKE 'department_id'"
        );
        $legacyColumn = $legacyColumnStmt->fetch(PDO::FETCH_ASSOC);

        if ($legacyColumn) {
            $isNullable = strtoupper((string) ($legacyColumn['Null'] ?? 'NO')) === 'YES';
            $hasDefault = $legacyColumn['Default'] !== null;

            if (!$isNullable && !$hasDefault) {
                $type = trim((string) ($legacyColumn['Type'] ?? 'INT UNSIGNED'));

                if (!preg_match('/^[a-z]+(?:\([0-9,]+\))?(?: unsigned)?$/i', $type)) {
                    $type = 'INT UNSIGNED';
                }

                $db->exec(
                    "ALTER TABLE product_translations "
                    . "MODIFY department_id {$type} NULL DEFAULT NULL"
                );
            }
        }

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
        $source = 'manual',
        $status = 'approved'
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

        $source = TranslationWorkflow::normalizeSource($source);
        $status = TranslationWorkflow::normalizeStatus($status, true);

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
                :status
            )
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                description = VALUES(description),
                source = VALUES(source),
                status = VALUES(status)
        ");

        return $stmt->execute([
            'product_id' => $productId,
            'language_code' => $languageCode,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'source' => $source,
            'status' => $status
        ]);
    }


    public static function markOutdated($productId)
    {
        self::ensureTable();

        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE product_translations
            SET status = 'outdated'
            WHERE product_id = :product_id
              AND TRIM(name) <> ''
        ");

        return $stmt->execute([
            'product_id' => (int) $productId
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
              AND status IN ('approved', 'outdated')
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
