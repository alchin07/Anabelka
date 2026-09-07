<?php

class AdminUser
{
    private static $historyReady = false;


    public static function ensureHistoryTable()
    {
        if (self::$historyReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS user_rank_history
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                old_rank_id INT UNSIGNED NULL,
                new_rank_id INT UNSIGNED NOT NULL,
                changed_by_user_id INT UNSIGNED NULL,
                note VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_user_rank_history_user (user_id, created_at),
                KEY idx_user_rank_history_created (created_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$historyReady = true;
    }


    public static function ranks($activeOnly = true)
    {
        $db = Database::connect();
        $sql = "
            SELECT id, name, slug, level, is_active
            FROM user_ranks
        ";

        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }

        $sql .= " ORDER BY level ASC, id ASC";

        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function normalizeFilters(array $input)
    {
        return [
            'q' => trim((string) ($input['q'] ?? '')),
            'rank_id' => max(0, (int) ($input['rank_id'] ?? 0)),
            'status' => in_array(($input['status'] ?? ''), ['active', 'inactive'], true)
                ? (string) $input['status']
                : ''
        ];
    }


    public static function page(array $filters, $page = 1, $perPage = 100)
    {
        $db = Database::connect();
        [$where, $params] = self::buildWhere($filters);
        $offset = max(0, ((int) $page - 1) * (int) $perPage);
        $limit = max(1, (int) $perPage);

        $sql = "
            SELECT
                u.id,
                u.rank_id,
                u.name,
                u.email,
                u.is_active,
                ur.name AS rank_name,
                ur.slug AS rank_slug,
                ur.level AS rank_level
            FROM users u
            INNER JOIN user_ranks ur ON ur.id = u.rank_id
            {$where}
            ORDER BY u.id DESC
            LIMIT {$limit} OFFSET {$offset}
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function count(array $filters)
    {
        $db = Database::connect();
        [$where, $params] = self::buildWhere($filters);

        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM users u
            INNER JOIN user_ranks ur ON ur.id = u.rank_id
            {$where}
        ");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }


    public static function summary()
    {
        $db = Database::connect();

        $row = $db->query("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN u.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN u.is_active = 0 THEN 1 ELSE 0 END) AS inactive_count
            FROM users u
        ")->fetch(PDO::FETCH_ASSOC);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active_count'] ?? 0),
            'inactive' => (int) ($row['inactive_count'] ?? 0)
        ];
    }


    public static function changeRank($userId, $newRankId, $changedByUserId = null, $note = '')
    {
        self::ensureHistoryTable();

        $userId = (int) $userId;
        $newRankId = (int) $newRankId;
        $changedByUserId = $changedByUserId !== null
            ? (int) $changedByUserId
            : null;
        $note = trim((string) $note);

        if ($userId <= 0 || $newRankId <= 0) {
            throw new InvalidArgumentException('Некоректний користувач або ранг.');
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $userStmt = $db->prepare("
                SELECT id, rank_id, name, email
                FROM users
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $userStmt->execute(['id' => $userId]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new RuntimeException('Користувача не знайдено.');
            }

            $rankStmt = $db->prepare("
                SELECT id, name, slug, level
                FROM user_ranks
                WHERE id = :id
                  AND is_active = 1
                LIMIT 1
            ");
            $rankStmt->execute(['id' => $newRankId]);
            $newRank = $rankStmt->fetch(PDO::FETCH_ASSOC);

            if (!$newRank) {
                throw new RuntimeException('Обраний ранг недоступний.');
            }

            $oldRankId = (int) ($user['rank_id'] ?? 0);

            if ($oldRankId === $newRankId) {
                $db->commit();

                return [
                    'changed' => false,
                    'user' => $user,
                    'rank' => $newRank
                ];
            }

            $update = $db->prepare("
                UPDATE users
                SET rank_id = :rank_id
                WHERE id = :id
            ");
            $update->execute([
                'rank_id' => $newRankId,
                'id' => $userId
            ]);

            $history = $db->prepare("
                INSERT INTO user_rank_history
                (
                    user_id,
                    old_rank_id,
                    new_rank_id,
                    changed_by_user_id,
                    note
                )
                VALUES
                (
                    :user_id,
                    :old_rank_id,
                    :new_rank_id,
                    :changed_by_user_id,
                    :note
                )
            ");
            $history->execute([
                'user_id' => $userId,
                'old_rank_id' => $oldRankId > 0 ? $oldRankId : null,
                'new_rank_id' => $newRankId,
                'changed_by_user_id' => $changedByUserId > 0 ? $changedByUserId : null,
                'note' => $note !== '' ? $note : null
            ]);

            $db->commit();

            return [
                'changed' => true,
                'user' => $user,
                'rank' => $newRank
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function recentRankHistory($limit = 100)
    {
        self::ensureHistoryTable();

        $db = Database::connect();
        $limit = max(1, min(500, (int) $limit));

        $sql = "
            SELECT
                h.id,
                h.user_id,
                h.created_at,
                h.note,
                u.name AS user_name,
                u.email AS user_email,
                old_rank.name AS old_rank_name,
                old_rank.slug AS old_rank_slug,
                new_rank.name AS new_rank_name,
                new_rank.slug AS new_rank_slug,
                actor.name AS changed_by_name
            FROM user_rank_history h
            INNER JOIN users u ON u.id = h.user_id
            LEFT JOIN user_ranks old_rank ON old_rank.id = h.old_rank_id
            INNER JOIN user_ranks new_rank ON new_rank.id = h.new_rank_id
            LEFT JOIN users actor ON actor.id = h.changed_by_user_id
            ORDER BY h.id DESC
            LIMIT {$limit}
        ";

        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }


    private static function buildWhere(array $filters)
    {
        $conditions = [];
        $params = [];
        $query = trim((string) ($filters['q'] ?? ''));
        $rankId = max(0, (int) ($filters['rank_id'] ?? 0));
        $status = (string) ($filters['status'] ?? '');

        if ($query !== '') {
            $conditions[] = '(u.name LIKE :q_name OR u.email LIKE :q_email)';
            $params['q_name'] = '%' . $query . '%';
            $params['q_email'] = '%' . $query . '%';
        }

        if ($rankId > 0) {
            $conditions[] = 'u.rank_id = :rank_id';
            $params['rank_id'] = $rankId;
        }

        if ($status === 'active') {
            $conditions[] = 'u.is_active = 1';
        } elseif ($status === 'inactive') {
            $conditions[] = 'u.is_active = 0';
        }

        return [
            empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions),
            $params
        ];
    }
}
