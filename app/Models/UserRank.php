<?php

class UserRank
{
    public const DEFAULT_REGISTRATION_SETTING = 'default_registered_user_rank_id';


    public static function allWithUsage()
    {
        $db = Database::connect();

        $sql = "
            SELECT
                ur.id,
                ur.name,
                ur.slug,
                ur.level,
                ur.is_active,
                COUNT(DISTINCT u.id) AS user_count,
                COUNT(DISTINCT pp.product_id) AS priced_product_count
            FROM user_ranks ur
            LEFT JOIN users u
                ON u.rank_id = ur.id
            LEFT JOIN product_prices pp
                ON pp.rank_id = ur.id
            GROUP BY
                ur.id,
                ur.name,
                ur.slug,
                ur.level,
                ur.is_active
            ORDER BY ur.level ASC, ur.id ASC
        ";

        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function find($rankId)
    {
        $db = Database::connect();
        $stmt = $db->prepare("
            SELECT id, name, slug, level, is_active
            FROM user_ranks
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([
            'id' => (int) $rankId
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    public static function create($name)
    {
        $name = self::normalizeName($name);
        $db = Database::connect();
        $slug = self::uniqueSlug($name);

        $level = (int) $db->query("
            SELECT COALESCE(MAX(level), 0) + 1
            FROM user_ranks
            WHERE slug <> 'guest'
        ")->fetchColumn();

        if ($level < 1) {
            $level = 1;
        }

        $stmt = $db->prepare("
            INSERT INTO user_ranks
            (name, slug, level, is_active)
            VALUES
            (:name, :slug, :level, 1)
        ");
        $stmt->execute([
            'name' => $name,
            'slug' => $slug,
            'level' => $level
        ]);

        self::normalizeOrder();

        return (int) $db->lastInsertId();
    }


    public static function update($rankId, $name)
    {
        $rankId = (int) $rankId;
        $rank = self::find($rankId);

        if (!$rank) {
            throw new RuntimeException('Ранг не знайдено.');
        }

        $name = self::normalizeName($name);
        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE user_ranks
            SET name = :name
            WHERE id = :id
        ");

        return $stmt->execute([
            'name' => $name,
            'id' => $rankId
        ]);
    }


    public static function move($rankId, $direction)
    {
        $rankId = (int) $rankId;
        $direction = (string) $direction;

        if (!in_array($direction, ['up', 'down'], true)) {
            throw new InvalidArgumentException('Некоректний напрямок переміщення.');
        }

        $rank = self::find($rankId);

        if (!$rank) {
            throw new RuntimeException('Ранг не знайдено.');
        }

        if (($rank['slug'] ?? '') === 'guest') {
            throw new RuntimeException('Системний ранг guest не можна переміщувати.');
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $rows = $db->query("
                SELECT id, slug, level
                FROM user_ranks
                WHERE slug <> 'guest'
                ORDER BY level ASC, id ASC
                FOR UPDATE
            ")->fetchAll(PDO::FETCH_ASSOC);

            $currentIndex = null;

            foreach ($rows as $index => $row) {
                if ((int) ($row['id'] ?? 0) === $rankId) {
                    $currentIndex = $index;
                    break;
                }
            }

            if ($currentIndex === null) {
                throw new RuntimeException('Ранг не знайдено в порядку сортування.');
            }

            $targetIndex = $direction === 'up'
                ? $currentIndex - 1
                : $currentIndex + 1;

            if ($targetIndex < 0 || $targetIndex >= count($rows)) {
                $db->commit();
                return false;
            }

            $temp = $rows[$currentIndex];
            $rows[$currentIndex] = $rows[$targetIndex];
            $rows[$targetIndex] = $temp;

            $stmt = $db->prepare("
                UPDATE user_ranks
                SET level = :level
                WHERE id = :id
            ");

            foreach ($rows as $index => $row) {
                $stmt->execute([
                    'level' => $index + 1,
                    'id' => (int) $row['id']
                ]);
            }

            $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function toggle($rankId)
    {
        $rankId = (int) $rankId;
        $rank = self::find($rankId);

        if (!$rank) {
            throw new RuntimeException('Ранг не знайдено.');
        }

        $isActive = !empty($rank['is_active']);

        if ($isActive) {
            if (($rank['slug'] ?? '') === 'guest') {
                throw new RuntimeException(
                    'Системний ранг guest не можна вимкнути.'
                );
            }

            if ($rankId === self::defaultRegistrationRankId()) {
                throw new RuntimeException(
                    'Спочатку призначте інший ранг для нових користувачів.'
                );
            }

            $db = Database::connect();
            $stmt = $db->prepare("
                SELECT COUNT(*)
                FROM users
                WHERE rank_id = :rank_id
            ");
            $stmt->execute(['rank_id' => $rankId]);

            if ((int) $stmt->fetchColumn() > 0) {
                throw new RuntimeException(
                    'Ранг використовується користувачами. Спочатку змініть їм ранг.'
                );
            }
        }

        $db = Database::connect();
        $stmt = $db->prepare("
            UPDATE user_ranks
            SET is_active = :is_active
            WHERE id = :id
        ");
        $stmt->execute([
            'is_active' => $isActive ? 0 : 1,
            'id' => $rankId
        ]);

        return !$isActive;
    }


    public static function setDefaultRegistrationRank($rankId)
    {
        $rankId = (int) $rankId;
        $rank = self::find($rankId);

        if (!$rank || empty($rank['is_active'])) {
            throw new RuntimeException(
                'Для реєстрації можна вибрати лише активний ранг.'
            );
        }

        if (
            ($rank['slug'] ?? '') === 'guest'
            || (int) ($rank['level'] ?? 0) <= 0
        ) {
            throw new RuntimeException(
                'Гостьовий ранг не можна призначити новому акаунту.'
            );
        }

        AppSetting::set(
            self::DEFAULT_REGISTRATION_SETTING,
            (string) $rankId
        );

        return $rank;
    }


    public static function defaultRegistrationRankId()
    {
        $configuredId = (int) AppSetting::get(
            self::DEFAULT_REGISTRATION_SETTING,
            '0'
        );

        if ($configuredId > 0) {
            $rank = self::find($configuredId);

            if (
                $rank
                && !empty($rank['is_active'])
                && ($rank['slug'] ?? '') !== 'guest'
                && (int) ($rank['level'] ?? 0) > 0
            ) {
                return $configuredId;
            }
        }

        $db = Database::connect();
        $stmt = $db->query("
            SELECT id
            FROM user_ranks
            WHERE is_active = 1
              AND slug <> 'guest'
              AND level > 0
            ORDER BY
                CASE
                    WHEN slug IN ('registered', 'registered-user', 'member', 'customer')
                        THEN 0
                    ELSE 1
                END,
                level ASC,
                id ASC
            LIMIT 1
        ");

        $fallbackId = (int) $stmt->fetchColumn();

        if ($fallbackId <= 0) {
            throw new RuntimeException(
                'Немає активного рангу для нових користувачів.'
            );
        }

        return $fallbackId;
    }


    public static function defaultRegistrationRank()
    {
        return self::find(self::defaultRegistrationRankId());
    }


    public static function summary()
    {
        $db = Database::connect();
        $row = $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
            FROM user_ranks
        ")->fetch(PDO::FETCH_ASSOC);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active_count'] ?? 0),
            'inactive' => (int) ($row['inactive_count'] ?? 0)
        ];
    }


    private static function normalizeOrder()
    {
        $db = Database::connect();
        $rows = $db->query("
            SELECT id
            FROM user_ranks
            WHERE slug <> 'guest'
            ORDER BY level ASC, id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("
            UPDATE user_ranks
            SET level = :level
            WHERE id = :id
        ");

        foreach ($rows as $index => $row) {
            $stmt->execute([
                'level' => $index + 1,
                'id' => (int) $row['id']
            ]);
        }
    }


    private static function normalizeName($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException('Вкажіть назву рангу.');
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($name, 'UTF-8')
            : strlen($name);

        if ($length > 100) {
            throw new InvalidArgumentException(
                'Назва рангу занадто довга.'
            );
        }

        return $name;
    }


    private static function uniqueSlug($name)
    {
        $base = self::makeSlug($name);
        $db = Database::connect();
        $slug = $base;
        $suffix = 2;
        $stmt = $db->prepare("
            SELECT 1
            FROM user_ranks
            WHERE slug = :slug
            LIMIT 1
        ");

        while (true) {
            $stmt->execute(['slug' => $slug]);

            if (!$stmt->fetchColumn()) {
                return $slug;
            }

            $slug = $base . '-' . $suffix;
            $suffix++;
        }
    }


    private static function makeSlug($value)
    {
        $original = trim((string) $value);
        $value = $original;
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','ґ'=>'g','д'=>'d','е'=>'e','ё'=>'e','є'=>'ie','ж'=>'zh','з'=>'z','и'=>'i','і'=>'i','ї'=>'i','й'=>'i','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'iu','я'=>'ia'
        ];

        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
        $value = trim((string) $value, '-');

        if ($value === '') {
            $value = 'rank-' . substr(sha1($original), 0, 8);
        }

        return substr($value, 0, 80);
    }
}
