<?php

class UserRankTranslator
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
            CREATE TABLE IF NOT EXISTS user_rank_translations
            (
                rank_id INT UNSIGNED NOT NULL,
                language_code VARCHAR(10) NOT NULL,
                name VARCHAR(100) NOT NULL,
                source VARCHAR(20) NOT NULL DEFAULT 'manual',
                status VARCHAR(20) NOT NULL DEFAULT 'approved',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (rank_id, language_code),
                KEY idx_user_rank_translations_language (language_code)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function getForRank($rankId)
    {
        self::ensureTable();
        $rankId = (int) $rankId;

        if ($rankId <= 0) {
            return [];
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT language_code, name, source, status
            FROM user_rank_translations
            WHERE rank_id = :rank_id
        ");
        $stmt->execute(['rank_id' => $rankId]);
        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[strtolower((string) $row['language_code'])] = $row;
        }

        return $result;
    }


    public static function saveForRank(
        $rankId,
        $languageCode,
        $name,
        $source = 'manual',
        $status = 'approved'
    ) {
        self::ensureTable();
        $rankId = (int) $rankId;
        $languageCode = strtolower(trim((string) $languageCode));
        $name = trim((string) $name);

        if ($rankId <= 0 || $languageCode === '') {
            throw new InvalidArgumentException('Некоректні дані перекладу рангу.');
        }

        if ($languageCode === Language::SOURCE_CODE) {
            return true;
        }

        $language = Language::findByCode($languageCode);

        if (!$language || empty($language['is_active'])) {
            throw new InvalidArgumentException('Мова перекладу недоступна.');
        }

        $db = Database::connect();

        if ($name === '') {
            $stmt = $db->prepare("
                DELETE FROM user_rank_translations
                WHERE rank_id = :rank_id
                  AND language_code = :language_code
            ");

            return $stmt->execute([
                'rank_id' => $rankId,
                'language_code' => $languageCode
            ]);
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($name, 'UTF-8')
            : strlen($name);

        if ($length > 100) {
            throw new InvalidArgumentException('Переклад назви рангу занадто довгий.');
        }

        $source = TranslationWorkflow::normalizeSource($source);
        $status = TranslationWorkflow::normalizeStatus($status, true);
        $stmt = $db->prepare("
            INSERT INTO user_rank_translations
            (rank_id, language_code, name, source, status)
            VALUES
            (:rank_id, :language_code, :name, :source, :status)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                source = VALUES(source),
                status = VALUES(status)
        ");

        return $stmt->execute([
            'rank_id' => $rankId,
            'language_code' => $languageCode,
            'name' => $name,
            'source' => $source,
            'status' => $status
        ]);
    }


    public static function markOutdated($rankId)
    {
        self::ensureTable();
        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE user_rank_translations
            SET status = 'outdated'
            WHERE rank_id = :rank_id
              AND TRIM(name) <> ''
        ");

        return $stmt->execute(['rank_id' => (int) $rankId]);
    }


    public static function localizeName($rankId, $fallbackName, $languageCode)
    {
        self::ensureTable();
        $languageCode = strtolower(trim((string) $languageCode));

        if (
            (int) $rankId <= 0
            || $languageCode === ''
            || $languageCode === Language::SOURCE_CODE
        ) {
            return (string) $fallbackName;
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT name
            FROM user_rank_translations
            WHERE rank_id = :rank_id
              AND language_code = :language_code
              AND status IN ('approved', 'outdated')
            LIMIT 1
        ");
        $stmt->execute([
            'rank_id' => (int) $rankId,
            'language_code' => $languageCode
        ]);
        $name = trim((string) $stmt->fetchColumn());

        return $name !== '' ? $name : (string) $fallbackName;
    }
}
