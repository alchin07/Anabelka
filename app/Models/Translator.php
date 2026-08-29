<?php

class Translator
{
    private static $schemaReady = false;
    private static $currentLanguage = null;
    private static $cache = [];


    private static function ensureTable()
    {
        if (self::$schemaReady) {
            return;
        }

        // Гарантируем, что таблица языков уже создана.
        Language::all();

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS interface_translations
            (
                translation_key VARCHAR(190) NOT NULL,
                language_code VARCHAR(10) NOT NULL,
                value TEXT NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                status VARCHAR(20) NOT NULL DEFAULT 'approved',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (translation_key, language_code),
                KEY idx_interface_translations_language (language_code)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::seedBaseTranslations($db);

        self::$schemaReady = true;
    }


    private static function seedBaseTranslations(PDO $db)
    {
        /*
         * Это стартовый набор интерфейса.
         * INSERT IGNORE принципиален: будущие ручные или ИИ-переводы
         * из админ-панели не должны перезаписываться кодом.
         */
        $translations = [
            'uk' => [
                'header.cart' => 'Кошик',
                'header.login' => 'Увійти',
                'header.logout' => 'Вийти',
                'header.register' => 'Реєстрація',
                'language.switcher_label' => 'Мова сайту'
            ],
            'ru' => [
                'header.cart' => 'Корзина',
                'header.login' => 'Войти',
                'header.logout' => 'Выйти',
                'header.register' => 'Регистрация',
                'language.switcher_label' => 'Язык сайта'
            ],
            'en' => [
                'header.cart' => 'Cart',
                'header.login' => 'Sign in',
                'header.logout' => 'Sign out',
                'header.register' => 'Register',
                'language.switcher_label' => 'Site language'
            ]
        ];

        $stmt = $db->prepare("
            INSERT IGNORE INTO interface_translations
            (
                translation_key,
                language_code,
                value,
                source,
                status
            )
            VALUES
            (
                :translation_key,
                :language_code,
                :value,
                'manual',
                'approved'
            )
        ");

        foreach ($translations as $languageCode => $items) {
            foreach ($items as $key => $value) {
                $stmt->execute([
                    'translation_key' => $key,
                    'language_code' => $languageCode,
                    'value' => $value
                ]);
            }
        }
    }


    public static function currentLanguage()
    {
        self::ensureTable();

        if (self::$currentLanguage !== null) {
            return self::$currentLanguage;
        }

        $sessionCode =
            strtolower(
                trim(
                    (string) ($_SESSION['language_code'] ?? '')
                )
            );

        if ($sessionCode !== '') {
            $language = Language::findByCode($sessionCode);

            if ($language && !empty($language['is_active'])) {
                self::$currentLanguage = $language;
                return $language;
            }
        }

        $language = Language::getDefault();

        if (!$language) {
            $language = Language::findByCode(Language::SOURCE_CODE);
        }

        if ($language) {
            $_SESSION['language_code'] = $language['code'];
        }

        self::$currentLanguage = $language ?: [
            'code' => Language::SOURCE_CODE,
            'locale' => 'uk-UA',
            'name' => 'Українська',
            'short_name' => 'UA'
        ];

        return self::$currentLanguage;
    }


    public static function setCurrentLanguage($code)
    {
        self::ensureTable();

        $code = strtolower(trim((string) $code));
        $language = Language::findByCode($code);

        if (!$language || empty($language['is_active'])) {
            throw new RuntimeException(
                'Выбранный язык недоступен.'
            );
        }

        $_SESSION['language_code'] = $language['code'];
        self::$currentLanguage = $language;
        self::$cache = [];

        return $language;
    }


    public static function activeLanguages()
    {
        self::ensureTable();
        return Language::active();
    }


    public static function t($key, $fallback = '')
    {
        self::ensureTable();

        $key = trim((string) $key);

        if ($key === '') {
            return (string) $fallback;
        }

        $language = self::currentLanguage();
        $code = (string) ($language['code'] ?? Language::SOURCE_CODE);

        $value = self::findValue($key, $code);

        if ($value !== null) {
            return $value;
        }

        // Любой отсутствующий перевод возвращается к исходному украинскому.
        if ($code !== Language::SOURCE_CODE) {
            $sourceValue = self::findValue(
                $key,
                Language::SOURCE_CODE
            );

            if ($sourceValue !== null) {
                return $sourceValue;
            }
        }

        return $fallback !== ''
            ? (string) $fallback
            : $key;
    }


    public static function deleteForLanguage($code)
    {
        self::ensureTable();

        $code = strtolower(trim((string) $code));

        if ($code === '' || $code === Language::SOURCE_CODE) {
            return false;
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            DELETE FROM interface_translations
            WHERE language_code = :language_code
        ");

        $result = $stmt->execute([
            'language_code' => $code
        ]);

        self::$cache = [];

        return $result;
    }


    private static function findValue($key, $code)
    {
        $cacheKey = $code . ':' . $key;

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT value
            FROM interface_translations
            WHERE translation_key = :translation_key
              AND language_code = :language_code
              AND status = 'approved'
            LIMIT 1
        ");

        $stmt->execute([
            'translation_key' => $key,
            'language_code' => $code
        ]);

        $value = $stmt->fetchColumn();

        self::$cache[$cacheKey] =
            $value === false
                ? null
                : (string) $value;

        return self::$cache[$cacheKey];
    }
}
