<?php

class Language
{
    private static $schemaReady = false;

    public const SOURCE_CODE = 'uk';


    private static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS languages
            (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(10) NOT NULL,
                locale VARCHAR(20) NOT NULL,
                name VARCHAR(100) NOT NULL,
                short_name VARCHAR(10) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                is_source TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY unique_language_code (code),
                UNIQUE KEY unique_language_locale (locale)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $languageCount = (int) $db
            ->query("SELECT COUNT(*) FROM languages")
            ->fetchColumn();

        // Стартовые языки создаём только один раз —
        // при первом запуске языкового блока.
        // После этого удаление и редактирование из админки
        // должны сохраняться и не перезаписываться автоматически.
        if ($languageCount === 0) {
            self::seedBaseLanguages($db);
        }

        self::enforceSourceLanguage($db);

        self::$schemaReady = true;
    }


    private static function seedBaseLanguages(PDO $db)
    {
        $languages = [
            [
                'code' => 'uk',
                'locale' => 'uk-UA',
                'name' => 'Українська',
                'short_name' => 'UA',
                'is_default' => 1,
                'is_source' => 1,
                'sort_order' => 10
            ],
            [
                'code' => 'ru',
                'locale' => 'ru-RU',
                'name' => 'Русский',
                'short_name' => 'RU',
                'is_default' => 0,
                'is_source' => 0,
                'sort_order' => 20
            ],
            [
                'code' => 'en',
                'locale' => 'en-US',
                'name' => 'English',
                'short_name' => 'EN',
                'is_default' => 0,
                'is_source' => 0,
                'sort_order' => 30
            ]
        ];

        $stmt = $db->prepare("
            INSERT INTO languages
            (
                code,
                locale,
                name,
                short_name,
                is_active,
                is_default,
                is_source,
                sort_order
            )
            VALUES
            (
                :code,
                :locale,
                :name,
                :short_name,
                1,
                :is_default,
                :is_source,
                :sort_order
            )
        ");

        foreach ($languages as $language) {
            $stmt->execute($language);
        }
    }


    private static function enforceSourceLanguage(PDO $db)
    {
        $sourceStmt = $db->prepare("
            SELECT id
            FROM languages
            WHERE code = :code
            LIMIT 1
        ");

        $sourceStmt->execute([
            'code' => self::SOURCE_CODE
        ]);

        $sourceId = (int) $sourceStmt->fetchColumn();

        if ($sourceId <= 0) {
            // Защитный случай для ручного изменения БД.
            $insert = $db->prepare("
                INSERT INTO languages
                (
                    code,
                    locale,
                    name,
                    short_name,
                    is_active,
                    is_default,
                    is_source,
                    sort_order
                )
                VALUES
                (
                    'uk',
                    'uk-UA',
                    'Українська',
                    'UA',
                    1,
                    1,
                    1,
                    10
                )
            ");

            $insert->execute();

            $sourceId = (int) $db->lastInsertId();
        }

        $db->exec("UPDATE languages SET is_source = 0");

        $stmt = $db->prepare("
            UPDATE languages
            SET
                is_source = 1,
                is_active = 1
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $sourceId
        ]);

        $defaultCount = (int) $db
            ->query("SELECT COUNT(*) FROM languages WHERE is_default = 1")
            ->fetchColumn();

        if ($defaultCount === 0) {
            $stmt = $db->prepare("
                UPDATE languages
                SET is_default = 1
                WHERE id = :id
            ");

            $stmt->execute([
                'id' => $sourceId
            ]);
        }
    }


    public static function all()
    {
        self::ensureTable();

        $db = Database::connect();

        return $db->query("
            SELECT *
            FROM languages
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function active()
    {
        self::ensureTable();

        $db = Database::connect();

        $stmt = $db->query("
            SELECT *
            FROM languages
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function findById($id)
    {
        self::ensureTable();

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT *
            FROM languages
            WHERE id = :id
            LIMIT 1
        ");

        $stmt->execute([
            'id' => (int) $id
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    public static function findByCode($code)
    {
        self::ensureTable();

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT *
            FROM languages
            WHERE code = :code
            LIMIT 1
        ");

        $stmt->execute([
            'code' => self::normalizeCode($code)
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    public static function getDefault()
    {
        self::ensureTable();

        $db = Database::connect();

        $row = $db->query("
            SELECT *
            FROM languages
            WHERE is_default = 1
              AND is_active = 1
            ORDER BY id ASC
            LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return $row;
        }

        return self::findByCode(self::SOURCE_CODE);
    }


    public static function create(
        $name,
        $code,
        $locale,
        $shortName
    ) {
        self::ensureTable();

        $name = trim((string) $name);
        $code = self::normalizeCode($code);
        $locale = self::normalizeLocale($locale);
        $shortName = strtoupper(trim((string) $shortName));

        self::validate($name, $code, $locale, $shortName);

        if ($code === self::SOURCE_CODE) {
            throw new InvalidArgumentException(
                'Код uk уже закреплён за исходным украинским языком.'
            );
        }

        $db = Database::connect();

        $maxOrder = (int) $db
            ->query("SELECT COALESCE(MAX(sort_order), 0) FROM languages")
            ->fetchColumn();

        $stmt = $db->prepare("
            INSERT INTO languages
            (
                code,
                locale,
                name,
                short_name,
                is_active,
                is_default,
                is_source,
                sort_order
            )
            VALUES
            (
                :code,
                :locale,
                :name,
                :short_name,
                1,
                0,
                0,
                :sort_order
            )
        ");

        $stmt->execute([
            'code' => $code,
            'locale' => $locale,
            'name' => $name,
            'short_name' => $shortName,
            'sort_order' => $maxOrder + 10
        ]);

        return (int) $db->lastInsertId();
    }


    public static function update(
        $id,
        $name,
        $code,
        $locale,
        $shortName
    ) {
        self::ensureTable();

        $language = self::findById($id);

        if (!$language) {
            throw new RuntimeException('Язык не найден.');
        }

        $name = trim((string) $name);
        $code = self::normalizeCode($code);
        $locale = self::normalizeLocale($locale);
        $shortName = strtoupper(trim((string) $shortName));

        if (!empty($language['is_source'])) {
            $code = self::SOURCE_CODE;
        } elseif ($code === self::SOURCE_CODE) {
            throw new InvalidArgumentException(
                'Код uk зарезервирован для исходного украинского языка.'
            );
        }

        self::validate($name, $code, $locale, $shortName);

        $db = Database::connect();

        $stmt = $db->prepare("
            UPDATE languages
            SET
                code = :code,
                locale = :locale,
                name = :name,
                short_name = :short_name
            WHERE id = :id
        ");

        return $stmt->execute([
            'id' => (int) $id,
            'code' => $code,
            'locale' => $locale,
            'name' => $name,
            'short_name' => $shortName
        ]);
    }


    public static function setActive($id, $isActive)
    {
        self::ensureTable();

        $language = self::findById($id);

        if (!$language) {
            throw new RuntimeException('Язык не найден.');
        }

        if (!empty($language['is_source']) && !$isActive) {
            throw new RuntimeException(
                'Исходный украинский язык нельзя отключить.'
            );
        }

        if (!empty($language['is_default']) && !$isActive) {
            throw new RuntimeException(
                'Основной язык сайта нельзя отключить.'
            );
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            UPDATE languages
            SET is_active = :is_active
            WHERE id = :id
        ");

        return $stmt->execute([
            'id' => (int) $id,
            'is_active' => $isActive ? 1 : 0
        ]);
    }


    public static function setDefault($id)
    {
        self::ensureTable();

        $language = self::findById($id);

        if (!$language) {
            throw new RuntimeException('Язык не найден.');
        }

        if (empty($language['is_active'])) {
            throw new RuntimeException(
                'Сначала включите язык, затем назначьте его основным.'
            );
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $db->exec("UPDATE languages SET is_default = 0");

            $stmt = $db->prepare("
                UPDATE languages
                SET is_default = 1
                WHERE id = :id
            ");

            $stmt->execute([
                'id' => (int) $id
            ]);

            $db->commit();

            return true;

        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }


    public static function delete($id)
    {
        self::ensureTable();

        $language = self::findById($id);

        if (!$language) {
            throw new RuntimeException('Язык не найден.');
        }

        if (!empty($language['is_source'])) {
            throw new RuntimeException(
                'Исходный украинский язык нельзя удалить.'
            );
        }

        if (!empty($language['is_default'])) {
            throw new RuntimeException(
                'Сначала назначьте другой язык основным.'
            );
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            DELETE FROM languages
            WHERE id = :id
        ");

        return $stmt->execute([
            'id' => (int) $id
        ]);
    }


    private static function validate(
        $name,
        $code,
        $locale,
        $shortName
    ) {
        if ($name === '') {
            throw new InvalidArgumentException('Укажите название языка.');
        }

        if (!preg_match('/^[a-z]{2,10}$/', $code)) {
            throw new InvalidArgumentException(
                'Код языка: только латинские строчные буквы, от 2 до 10 символов.'
            );
        }

        if (!preg_match('/^[a-zA-Z]{2,10}(?:-[a-zA-Z0-9]{2,10})?$/', $locale)) {
            throw new InvalidArgumentException(
                'Локаль должна быть в формате uk-UA, ru-RU, en-US.'
            );
        }

        if ($shortName === '' || strlen($shortName) > 10) {
            throw new InvalidArgumentException(
                'Укажите короткое обозначение языка, например UA.'
            );
        }
    }


    private static function normalizeCode($code)
    {
        return strtolower(trim((string) $code));
    }


    private static function normalizeLocale($locale)
    {
        return trim((string) $locale);
    }
}
