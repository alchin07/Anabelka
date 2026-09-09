<?php

class CustomerRankRequest
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();
        $db->exec("
            CREATE TABLE IF NOT EXISTS customer_rank_requests
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                requested_from_rank_id INT UNSIGNED NOT NULL,
                approved_rank_id INT UNSIGNED NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                admin_user_id BIGINT UNSIGNED NULL,
                admin_note VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                reviewed_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_rank_request_user (user_id, id),
                KEY idx_rank_request_status (status, id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            DELETE rr
            FROM customer_rank_requests rr
            LEFT JOIN users u ON u.id = rr.user_id
            WHERE u.id IS NULL
        ");

        self::$schemaReady = true;
    }


    public static function stateForUser($userId)
    {
        self::ensureSchema();
        CustomerProfile::ensureSchema();
        CustomerAddress::ensureSchema();

        $userId = (int) $userId;

        if ($userId <= 0) {
            return self::emptyState();
        }

        $db = Database::connect();
        $userStmt = $db->prepare("
            SELECT
                u.id,
                u.rank_id,
                u.is_active,
                ur.name AS rank_name,
                ur.level AS rank_level,
                COALESCE(cp.phone, '') AS phone,
                (
                    SELECT COUNT(*)
                    FROM customer_addresses ca
                    WHERE ca.user_id = u.id
                ) AS address_count
            FROM users u
            INNER JOIN user_ranks ur ON ur.id = u.rank_id
            LEFT JOIN customer_profiles cp ON cp.user_id = u.id
            WHERE u.id = :user_id
            LIMIT 1
        ");
        $userStmt->execute(['user_id' => $userId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['is_active'])) {
            return self::emptyState();
        }

        $latest = self::latestForUser($userId);
        $hasPhone = trim((string) ($user['phone'] ?? '')) !== '';
        $hasAddress = (int) ($user['address_count'] ?? 0) > 0;
        $hasHigherRank = !empty(self::higherRanks((int) ($user['rank_level'] ?? 0)));
        $hasPending = is_array($latest) && ($latest['status'] ?? '') === 'pending';

        return [
            'latest' => $latest,
            'has_phone' => $hasPhone,
            'has_address' => $hasAddress,
            'has_higher_rank' => $hasHigherRank,
            'has_pending' => $hasPending,
            'can_request' => $hasPhone && $hasAddress && $hasHigherRank && !$hasPending
        ];
    }


    public static function createForUser($userId)
    {
        self::ensureSchema();
        CustomerProfile::ensureSchema();
        CustomerAddress::ensureSchema();

        $userId = (int) $userId;

        if ($userId <= 0) {
            throw new InvalidArgumentException('Некоректний користувач.');
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                SELECT
                    u.id,
                    u.rank_id,
                    u.is_active,
                    ur.level AS rank_level,
                    COALESCE(cp.phone, '') AS phone,
                    (
                        SELECT COUNT(*)
                        FROM customer_addresses ca
                        WHERE ca.user_id = u.id
                    ) AS address_count
                FROM users u
                INNER JOIN user_ranks ur ON ur.id = u.rank_id
                LEFT JOIN customer_profiles cp ON cp.user_id = u.id
                WHERE u.id = :user_id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute(['user_id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || empty($user['is_active'])) {
                throw new RuntimeException('Акаунт користувача недоступний.');
            }

            if (trim((string) ($user['phone'] ?? '')) === '') {
                throw new RuntimeException('Для запиту на підвищення рангу спочатку вкажіть номер телефону.');
            }

            if ((int) ($user['address_count'] ?? 0) <= 0) {
                throw new RuntimeException('Для запиту на підвищення рангу спочатку додайте адресу доставки.');
            }

            if (empty(self::higherRanks((int) ($user['rank_level'] ?? 0)))) {
                throw new RuntimeException('Ваш ранг уже є найвищим доступним рангом.');
            }

            $pendingStmt = $db->prepare("
                SELECT id
                FROM customer_rank_requests
                WHERE user_id = :user_id
                  AND status = 'pending'
                ORDER BY id DESC
                LIMIT 1
                FOR UPDATE
            ");
            $pendingStmt->execute(['user_id' => $userId]);

            if ($pendingStmt->fetchColumn()) {
                throw new RuntimeException('Запит на підвищення рангу вже очікує на розгляд.');
            }

            $insert = $db->prepare("
                INSERT INTO customer_rank_requests
                (user_id, requested_from_rank_id, status)
                VALUES
                (:user_id, :rank_id, 'pending')
            ");
            $insert->execute([
                'user_id' => $userId,
                'rank_id' => (int) $user['rank_id']
            ]);

            $requestId = (int) $db->lastInsertId();
            $db->commit();

            return $requestId;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    public static function pendingForAdmin()
    {
        self::ensureSchema();
        CustomerProfile::ensureSchema();
        CustomerAddress::ensureSchema();

        $rows = Database::connect()->query("
            SELECT
                rr.id,
                rr.user_id,
                rr.created_at,
                u.name AS user_name,
                u.email AS user_email,
                u.rank_id,
                ur.name AS rank_name,
                ur.level AS rank_level,
                COALESCE(cp.phone, '') AS phone,
                ca.label AS address_label,
                ca.country,
                ca.city,
                ca.address,
                ca.postcode
            FROM customer_rank_requests rr
            INNER JOIN users u ON u.id = rr.user_id
            INNER JOIN user_ranks ur ON ur.id = u.rank_id
            LEFT JOIN customer_profiles cp ON cp.user_id = u.id
            LEFT JOIN customer_addresses ca
                ON ca.user_id = u.id
               AND ca.is_default = 1
            WHERE rr.status = 'pending'
              AND u.is_active = 1
            ORDER BY rr.id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['higher_ranks'] = self::higherRanks((int) ($row['rank_level'] ?? 0));
        }
        unset($row);

        return $rows;
    }


    public static function approve($requestId, $rankId, $adminUserId, $note = '')
    {
        self::ensureSchema();
        AdminUser::ensureHistoryTable();

        $requestId = (int) $requestId;
        $rankId = (int) $rankId;
        $adminUserId = (int) $adminUserId;
        $note = trim((string) $note);

        if ($requestId <= 0 || $rankId <= 0 || $adminUserId <= 0) {
            throw new InvalidArgumentException('Некоректні дані запиту.');
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $requestStmt = $db->prepare("
                SELECT rr.id, rr.user_id, rr.status, u.rank_id, ur.level AS rank_level
                FROM customer_rank_requests rr
                INNER JOIN users u ON u.id = rr.user_id
                INNER JOIN user_ranks ur ON ur.id = u.rank_id
                WHERE rr.id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $requestStmt->execute(['id' => $requestId]);
            $request = $requestStmt->fetch(PDO::FETCH_ASSOC);

            if (!$request || ($request['status'] ?? '') !== 'pending') {
                throw new RuntimeException('Запит уже оброблено або не знайдено.');
            }

            $rankStmt = $db->prepare("
                SELECT id, name, slug, level
                FROM user_ranks
                WHERE id = :id
                  AND is_active = 1
                  AND slug <> 'guest'
                LIMIT 1
            ");
            $rankStmt->execute(['id' => $rankId]);
            $rank = $rankStmt->fetch(PDO::FETCH_ASSOC);

            if (!$rank || (int) ($rank['level'] ?? 0) <= (int) ($request['rank_level'] ?? 0)) {
                throw new RuntimeException('Для підвищення можна вибрати лише ранг вище поточного.');
            }

            $oldRankId = (int) $request['rank_id'];
            $userId = (int) $request['user_id'];

            $updateUser = $db->prepare("
                UPDATE users
                SET rank_id = :rank_id
                WHERE id = :user_id
            ");
            $updateUser->execute([
                'rank_id' => $rankId,
                'user_id' => $userId
            ]);

            $history = $db->prepare("
                INSERT INTO user_rank_history
                (user_id, old_rank_id, new_rank_id, changed_by_user_id, note)
                VALUES
                (:user_id, :old_rank_id, :new_rank_id, NULL, :note)
            ");
            $history->execute([
                'user_id' => $userId,
                'old_rank_id' => $oldRankId,
                'new_rank_id' => $rankId,
                'note' => $note !== '' ? $note : 'Підвищення за запитом користувача #' . $requestId
            ]);

            $updateRequest = $db->prepare("
                UPDATE customer_rank_requests
                SET status = 'approved',
                    approved_rank_id = :approved_rank_id,
                    admin_user_id = :admin_user_id,
                    admin_note = :admin_note,
                    reviewed_at = NOW()
                WHERE id = :id
            ");
            $updateRequest->execute([
                'approved_rank_id' => $rankId,
                'admin_user_id' => $adminUserId,
                'admin_note' => $note !== '' ? $note : null,
                'id' => $requestId
            ]);

            $db->commit();

            return [
                'request_id' => $requestId,
                'user_id' => $userId,
                'old_rank_id' => $oldRankId,
                'new_rank_id' => $rankId,
                'new_rank_name' => (string) ($rank['name'] ?? '')
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    public static function reject($requestId, $adminUserId, $note = '')
    {
        self::ensureSchema();
        $requestId = (int) $requestId;
        $adminUserId = (int) $adminUserId;
        $note = trim((string) $note);

        if ($requestId <= 0 || $adminUserId <= 0) {
            throw new InvalidArgumentException('Некоректні дані запиту.');
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $select = $db->prepare("
                SELECT id, user_id, status
                FROM customer_rank_requests
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $select->execute(['id' => $requestId]);
            $request = $select->fetch(PDO::FETCH_ASSOC);

            if (!$request || ($request['status'] ?? '') !== 'pending') {
                throw new RuntimeException('Запит уже оброблено або не знайдено.');
            }

            $stmt = $db->prepare("
                UPDATE customer_rank_requests
                SET status = 'rejected',
                    admin_user_id = :admin_user_id,
                    admin_note = :admin_note,
                    reviewed_at = NOW()
                WHERE id = :id
                  AND status = 'pending'
            ");
            $stmt->execute([
                'admin_user_id' => $adminUserId,
                'admin_note' => $note !== '' ? $note : null,
                'id' => $requestId
            ]);

            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('Запит уже оброблено або не знайдено.');
            }

            $db->commit();

            return [
                'request_id' => $requestId,
                'user_id' => (int) ($request['user_id'] ?? 0),
                'note' => $note
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    private static function latestForUser($userId)
    {
        $stmt = Database::connect()->prepare("
            SELECT
                rr.id,
                rr.status,
                rr.created_at,
                rr.reviewed_at,
                rr.admin_note,
                from_rank.name AS from_rank_name,
                approved_rank.name AS approved_rank_name
            FROM customer_rank_requests rr
            LEFT JOIN user_ranks from_rank ON from_rank.id = rr.requested_from_rank_id
            LEFT JOIN user_ranks approved_rank ON approved_rank.id = rr.approved_rank_id
            WHERE rr.user_id = :user_id
            ORDER BY rr.id DESC
            LIMIT 1
        ");
        $stmt->execute(['user_id' => (int) $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }


    private static function higherRanks($currentLevel)
    {
        $stmt = Database::connect()->prepare("
            SELECT id, name, slug, level
            FROM user_ranks
            WHERE is_active = 1
              AND slug <> 'guest'
              AND level > :level
            ORDER BY level ASC, id ASC
        ");
        $stmt->execute(['level' => (int) $currentLevel]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    private static function emptyState()
    {
        return [
            'latest' => null,
            'has_phone' => false,
            'has_address' => false,
            'has_higher_rank' => false,
            'has_pending' => false,
            'can_request' => false
        ];
    }
}
