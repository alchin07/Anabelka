<?php

class CategoryTranslator
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
            CREATE TABLE IF NOT EXISTS category_translations
            (
                category_id INT UNSIGNED NOT NULL,
                language_code VARCHAR(10) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                status VARCHAR(20) NOT NULL DEFAULT 'approved',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (
                    category_id,
                    language_code
                ),
                KEY idx_category_translations_language (
                    language_code
                )
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function getForCategory($categoryId)
    {
        self::ensureTable();

        $categoryId = (int) $categoryId;

        if ($categoryId <= 0) {
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
            FROM category_translations
            WHERE category_id = :category_id
        ");

        $stmt->execute([
            'category_id' => $categoryId
        ]);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['language_code']] = $row;
        }

        return $result;
    }


    public static function saveForCategory(
        $categoryId,
        $languageCode,
        $name,
        $description,
        $source = 'manual',
        $status = 'approved'
    ) {
        self::ensureTable();

        $categoryId = (int) $categoryId;
        $languageCode = strtolower(trim((string) $languageCode));
        $name = trim((string) $name);
        $description = trim((string) $description);

        if ($categoryId <= 0 || $languageCode === '') {
            throw new InvalidArgumentException(
                'Некорректные данные перевода категории.'
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
                DELETE FROM category_translations
                WHERE category_id = :category_id
                  AND language_code = :language_code
            ");

            return $stmt->execute([
                'category_id' => $categoryId,
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
            INSERT INTO category_translations
            (
                category_id,
                language_code,
                name,
                description,
                source,
                status
            )
            VALUES
            (
                :category_id,
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
            'category_id' => $categoryId,
            'language_code' => $languageCode,
            'name' => $name,
            'description' => $description !== '' ? $description : null,
            'source' => $source,
            'status' => $status
        ]);
    }


    public static function markOutdated($categoryId)
    {
        self::ensureTable();

        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE category_translations
            SET status = 'outdated'
            WHERE category_id = :category_id
              AND TRIM(name) <> ''
        ");

        return $stmt->execute([
            'category_id' => (int) $categoryId
        ]);
    }


    public static function localize(array $category, $languageCode)
    {
        self::ensureTable();

        $categoryId = (int) ($category['id'] ?? 0);
        $languageCode = strtolower(trim((string) $languageCode));

        if (
            $categoryId <= 0
            || $languageCode === ''
            || $languageCode === Language::SOURCE_CODE
        ) {
            return $category;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                name,
                description
            FROM category_translations
            WHERE category_id = :category_id
              AND language_code = :language_code
              AND status IN ('approved', 'outdated')
            LIMIT 1
        ");

        $stmt->execute([
            'category_id' => $categoryId,
            'language_code' => $languageCode
        ]);

        $translation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$translation) {
            return $category;
        }

        $category['name'] = $translation['name'];
        $category['description'] = $translation['description'];

        return $category;
    }


    public static function localizeList(array $categories, $languageCode)
    {
        foreach ($categories as $index => $category) {
            $categories[$index] = self::localize(
                $category,
                $languageCode
            );
        }

        return $categories;
    }
}
