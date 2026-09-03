<?php

class Language
{
    private static $schemaReady = false;

    public const SOURCE_CODE = 'uk';


    /**
     * Локальний довідник мов.
     *
     * Адміністратор обирає лише мову,
     * а code / locale / short_name призначаються автоматично.
     */
    public static function catalog()
    {
        return [
            'uk' => ['name' => 'Українська', 'locale' => 'uk-UA', 'short_name' => 'UA'],
            'ru' => ['name' => 'Русский', 'locale' => 'ru-RU', 'short_name' => 'RU'],
            'en' => ['name' => 'English', 'locale' => 'en-US', 'short_name' => 'EN'],
            'de' => ['name' => 'Deutsch', 'locale' => 'de-DE', 'short_name' => 'DE'],
            'pl' => ['name' => 'Polski', 'locale' => 'pl-PL', 'short_name' => 'PL'],
            'fr' => ['name' => 'Français', 'locale' => 'fr-FR', 'short_name' => 'FR'],
            'es' => ['name' => 'Español', 'locale' => 'es-ES', 'short_name' => 'ES'],
            'it' => ['name' => 'Italiano', 'locale' => 'it-IT', 'short_name' => 'IT'],
            'pt' => ['name' => 'Português', 'locale' => 'pt-PT', 'short_name' => 'PT'],
            'nl' => ['name' => 'Nederlands', 'locale' => 'nl-NL', 'short_name' => 'NL'],
            'cs' => ['name' => 'Čeština', 'locale' => 'cs-CZ', 'short_name' => 'CS'],
            'sk' => ['name' => 'Slovenčina', 'locale' => 'sk-SK', 'short_name' => 'SK'],
            'ro' => ['name' => 'Română', 'locale' => 'ro-RO', 'short_name' => 'RO'],
            'hu' => ['name' => 'Magyar', 'locale' => 'hu-HU', 'short_name' => 'HU'],
            'bg' => ['name' => 'Български', 'locale' => 'bg-BG', 'short_name' => 'BG'],
            'el' => ['name' => 'Ελληνικά', 'locale' => 'el-GR', 'short_name' => 'EL'],
            'tr' => ['name' => 'Türkçe', 'locale' => 'tr-TR', 'short_name' => 'TR'],
            'sv' => ['name' => 'Svenska', 'locale' => 'sv-SE', 'short_name' => 'SV'],
            'da' => ['name' => 'Dansk', 'locale' => 'da-DK', 'short_name' => 'DA'],
            'no' => ['name' => 'Norsk', 'locale' => 'nb-NO', 'short_name' => 'NO'],
            'fi' => ['name' => 'Suomi', 'locale' => 'fi-FI', 'short_name' => 'FI'],
            'et' => ['name' => 'Eesti', 'locale' => 'et-EE', 'short_name' => 'ET'],
            'lv' => ['name' => 'Latviešu', 'locale' => 'lv-LV', 'short_name' => 'LV'],
            'lt' => ['name' => 'Lietuvių', 'locale' => 'lt-LT', 'short_name' => 'LT'],
            'hr' => ['name' => 'Hrvatski', 'locale' => 'hr-HR', 'short_name' => 'HR'],
            'sl' => ['name' => 'Slovenščina', 'locale' => 'sl-SI', 'short_name' => 'SL'],
            'sr' => ['name' => 'Српски', 'locale' => 'sr-RS', 'short_name' => 'SR'],
            'ar' => ['name' => 'العربية', 'locale' => 'ar-SA', 'short_name' => 'AR'],
            'he' => ['name' => 'עברית', 'locale' => 'he-IL', 'short_name' => 'HE'],
            'zh' => ['name' => '中文', 'locale' => 'zh-CN', 'short_name' => 'ZH'],
            'ja' => ['name' => '日本語', 'locale' => 'ja-JP', 'short_name' => 'JA'],
            'ko' => ['name' => '한국어', 'locale' => 'ko-KR', 'short_name' => 'KO']
        ];
    }


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

        if ($languageCount === 0) {
            self::seedBaseLanguages($db);
        }

        self::enforceSourceLanguage($db);

        self::$schemaReady = true;
    }


    private static function seedBaseLanguages(PDO $db)
    {
        $catalog = self::catalog();

        $languages = [
            ['code' => 'uk', 'is_default' => 1, 'is_source' => 1, 'sort_order' => 10],
            ['code' => 'ru', 'is_default' => 0, 'is_source' => 0, 'sort_order' => 20],
            ['code' => 'en', 'is_default' => 0, 'is_source' => 0, 'sort_order' => 30]
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
            $data = $catalog[$language['code']];

            $stmt->execute([
                'code' => $language['code'],
                'locale' => $data['locale'],
                'name' => $data['name'],
                'short_name' => $data['short_name'],
                'is_default' => $language['is_default'],
                'is_source' => $language['is_source'],
                'sort_order' => $language['sort_order']
            ]);
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
            $catalog = self::catalog();
            $source = $catalog[self::SOURCE_CODE];

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
                    :code,
                    :locale,
                    :name,
                    :short_name,
                    1,
                    1,
                    1,
                    10
                )
            ");

            $insert->execute([
                'code' => self::SOURCE_CODE,
                'locale' => $source['locale'],
                'name' => $source['name'],
                'short_name' => $source['short_name']
            ]);

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

        return $db->query("
            SELECT *
            FROM languages
            WHERE is_active = 1
            ORDER BY sort_order ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function availableCatalog()
    {
        self::ensureTable();

        $existingCodes = [];

        foreach (self::all() as $language) {
            $existingCodes[$language['code']] = true;
        }

        $available = [];

        foreach (self::catalog() as $code => $data) {
            if (!isset($existingCodes[$code])) {
                $available[$code] = $data;
            }
        }

        return $available;
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


    public static function createFromCatalog($code)
    {
        self::ensureTable();

        $code = self::normalizeCode($code);
        $catalog = self::catalog();

        if (!isset($catalog[$code])) {
            throw new InvalidArgumentException(
                'Оберіть мову зі списку.'
            );
        }

        if (self::findByCode($code)) {
            throw new InvalidArgumentException(
                'Цю мову вже додано.'
            );
        }

        $data = $catalog[$code];
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
            'locale' => $data['locale'],
            'name' => $data['name'],
            'short_name' => $data['short_name'],
            'sort_order' => $maxOrder + 10
        ]);

        return (int) $db->lastInsertId();
    }


    public static function updateName($id, $name)
    {
        self::ensureTable();

        $language = self::findById($id);

        if (!$language) {
            throw new RuntimeException('Мову не знайдено.');
        }

        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException(
                'Укажіть назву мови.'
            );
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            UPDATE languages
            SET name = :name
            WHERE id = :id
        ");

        return $stmt->execute([
            'id' => (int) $id,
            'name' => $name
        ]);
    }


    public static function setActive($id, $isActive)
    {
        self::ensureTable();

        $language = self::findById($id);

        if (!$language) {
            throw new RuntimeException('Мову не знайдено.');
        }

        if (!empty($language['is_source']) && !$isActive) {
            throw new RuntimeException(
                'Вихідну українську мову не можна вимкнути.'
            );
        }

        if (!empty($language['is_default']) && !$isActive) {
            throw new RuntimeException(
                'Основну мову сайту не можна вимкнути.'
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
            throw new RuntimeException('Мову не знайдено.');
        }

        if (empty($language['is_active'])) {
            throw new RuntimeException(
                'Спочатку увімкніть мову, а потім призначте її основною.'
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
            throw new RuntimeException('Мову не знайдено.');
        }

        if (!empty($language['is_source'])) {
            throw new RuntimeException(
                'Вихідну українську мову не можна видалити.'
            );
        }

        if (!empty($language['is_default'])) {
            throw new RuntimeException(
                'Спочатку призначте іншу мову основною.'
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


    private static function normalizeCode($code)
    {
        return strtolower(trim((string) $code));
    }
}
