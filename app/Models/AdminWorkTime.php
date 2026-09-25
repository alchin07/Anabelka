<?php

class AdminWorkTime
{
    private static $schemaReady = false;

    private const MAX_HEARTBEAT_SECONDS = 60;
    private const IDLE_SESSION_SECONDS = 300;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_work_time_daily
            (
                admin_user_id INT UNSIGNED NOT NULL,
                work_date DATE NOT NULL,
                admin_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                public_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                session_count INT UNSIGNED NOT NULL DEFAULT 0,
                first_activity_at DATETIME NULL,
                last_activity_at DATETIME NULL,
                admin_name VARCHAR(120) NOT NULL DEFAULT '',
                admin_email VARCHAR(190) NOT NULL DEFAULT '',
                PRIMARY KEY (admin_user_id, work_date),
                KEY idx_admin_work_time_date (work_date)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_work_time_presence
            (
                admin_user_id INT UNSIGNED NOT NULL,
                last_heartbeat_at DATETIME NULL,
                last_surface VARCHAR(20) NOT NULL DEFAULT 'admin',
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (admin_user_id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function heartbeat(
        $adminUserId,
        $surface,
        $activeSeconds
    ) {
        self::ensureSchema();

        $adminUserId = (int) $adminUserId;
        $surface = strtolower(trim((string) $surface));
        $activeSeconds = max(
            0,
            min(
                self::MAX_HEARTBEAT_SECONDS,
                (int) $activeSeconds
            )
        );

        if ($adminUserId <= 0) {
            throw new InvalidArgumentException(
                'Некоректний адміністратор.'
            );
        }

        if (!in_array($surface, ['admin', 'public'], true)) {
            throw new InvalidArgumentException(
                'Некоректний тип робочої поверхні.'
            );
        }

        if ($activeSeconds <= 0) {
            return [
                'credited_seconds' => 0,
                'surface' => $surface
            ];
        }

        $db = Database::connect();
        $admin = self::adminIdentity($db, $adminUserId);

        if (!$admin || empty($admin['is_active'])) {
            throw new RuntimeException(
                'Активного адміністратора не знайдено.'
            );
        }

        $db->beginTransaction();

        try {
            $seed = $db->prepare("
                INSERT IGNORE INTO admin_work_time_presence
                    (admin_user_id, last_heartbeat_at, last_surface)
                VALUES
                    (:admin_user_id, NULL, :last_surface)
            ");
            $seed->execute([
                'admin_user_id' => $adminUserId,
                'last_surface' => $surface
            ]);

            $presenceStmt = $db->prepare("
                SELECT
                    last_heartbeat_at,
                    last_surface
                FROM admin_work_time_presence
                WHERE admin_user_id = :admin_user_id
                LIMIT 1
                FOR UPDATE
            ");
            $presenceStmt->execute([
                'admin_user_id' => $adminUserId
            ]);
            $presence = $presenceStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $now = (string) $db->query(
                "SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')"
            )->fetchColumn();
            $workDate = substr($now, 0, 10);
            $lastHeartbeat = trim((string) (
                $presence['last_heartbeat_at'] ?? ''
            ));

            $elapsed = null;
            if ($lastHeartbeat !== '') {
                $elapsed = max(
                    0,
                    strtotime($now) - strtotime($lastHeartbeat)
                );
            }

            $creditedSeconds = $activeSeconds;

            if ($elapsed !== null) {
                $creditedSeconds = min(
                    $activeSeconds,
                    max(
                        0,
                        min(
                            self::MAX_HEARTBEAT_SECONDS,
                            $elapsed + 2
                        )
                    )
                );
            }

            $newSession = 0;

            if (
                $lastHeartbeat === ''
                || substr($lastHeartbeat, 0, 10) !== $workDate
                || (
                    $elapsed !== null
                    && $elapsed > self::IDLE_SESSION_SECONDS
                )
            ) {
                $newSession = 1;
            }

            if ($creditedSeconds > 0) {
                $adminSeconds = $surface === 'admin'
                    ? $creditedSeconds
                    : 0;
                $publicSeconds = $surface === 'public'
                    ? $creditedSeconds
                    : 0;

                $daily = $db->prepare("
                    INSERT INTO admin_work_time_daily
                    (
                        admin_user_id,
                        work_date,
                        admin_seconds,
                        public_seconds,
                        session_count,
                        first_activity_at,
                        last_activity_at,
                        admin_name,
                        admin_email
                    )
                    VALUES
                    (
                        :admin_user_id,
                        :work_date,
                        :admin_seconds,
                        :public_seconds,
                        :session_count,
                        :first_activity_at,
                        :last_activity_at,
                        :admin_name,
                        :admin_email
                    )
                    ON DUPLICATE KEY UPDATE
                        admin_seconds =
                            admin_seconds + VALUES(admin_seconds),
                        public_seconds =
                            public_seconds + VALUES(public_seconds),
                        session_count =
                            session_count + VALUES(session_count),
                        first_activity_at =
                            COALESCE(
                                first_activity_at,
                                VALUES(first_activity_at)
                            ),
                        last_activity_at = VALUES(last_activity_at),
                        admin_name = VALUES(admin_name),
                        admin_email = VALUES(admin_email)
                ");
                $daily->execute([
                    'admin_user_id' => $adminUserId,
                    'work_date' => $workDate,
                    'admin_seconds' => $adminSeconds,
                    'public_seconds' => $publicSeconds,
                    'session_count' => $newSession,
                    'first_activity_at' => $now,
                    'last_activity_at' => $now,
                    'admin_name' => (string) ($admin['name'] ?? ''),
                    'admin_email' => (string) ($admin['email'] ?? '')
                ]);
            }

            $presenceUpdate = $db->prepare("
                UPDATE admin_work_time_presence
                SET
                    last_heartbeat_at = :last_heartbeat_at,
                    last_surface = :last_surface
                WHERE admin_user_id = :admin_user_id
            ");
            $presenceUpdate->execute([
                'last_heartbeat_at' => $now,
                'last_surface' => $surface,
                'admin_user_id' => $adminUserId
            ]);

            $db->commit();

            return [
                'credited_seconds' => $creditedSeconds,
                'surface' => $surface,
                'new_session' => $newSession,
                'work_date' => $workDate
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function canViewReport($admin = null)
    {
        if (!class_exists('AdminAccess')) {
            return false;
        }

        $admin = is_array($admin)
            ? $admin
            : AdminAccess::current();

        if (!$admin) {
            return false;
        }

        return in_array(
            (string) ($admin['role_slug'] ?? ''),
            ['owner', 'store_owner'],
            true
        );
    }


    public static function periodRange($period)
    {
        $period = strtolower(trim((string) $period));
        $today = date('Y-m-d');

        if ($period === 'week') {
            return [
                'period' => 'week',
                'date_from' => date(
                    'Y-m-d',
                    strtotime($today . ' -6 days')
                ),
                'date_to' => $today
            ];
        }

        if ($period === 'month') {
            return [
                'period' => 'month',
                'date_from' => date('Y-m-01'),
                'date_to' => $today
            ];
        }

        return [
            'period' => 'today',
            'date_from' => $today,
            'date_to' => $today
        ];
    }


    public static function report($period = 'today')
    {
        self::ensureSchema();
        $range = self::periodRange($period);
        $db = Database::connect();

        $stmt = $db->prepare("
            SELECT
                au.id AS admin_user_id,
                au.name AS admin_name,
                au.email AS admin_email,
                ar.name AS role_name,
                ar.slug AS role_slug,
                COALESCE(SUM(d.admin_seconds), 0) AS admin_seconds,
                COALESCE(SUM(d.public_seconds), 0) AS public_seconds,
                COALESCE(SUM(d.session_count), 0) AS session_count,
                MIN(d.first_activity_at) AS first_activity_at,
                MAX(d.last_activity_at) AS last_activity_at,
                COUNT(DISTINCT d.work_date) AS active_days
            FROM admin_users au
            INNER JOIN admin_roles ar
                ON ar.id = au.role_id
            LEFT JOIN admin_work_time_daily d
                ON d.admin_user_id = au.id
               AND d.work_date BETWEEN :date_from AND :date_to
            WHERE au.is_active = 1
            GROUP BY
                au.id,
                au.name,
                au.email,
                ar.name,
                ar.slug
            ORDER BY
                CASE ar.slug
                    WHEN 'owner' THEN 0
                    WHEN 'store_owner' THEN 1
                    ELSE 2
                END,
                au.name ASC,
                au.id ASC
        ");
        $stmt->execute([
            'date_from' => $range['date_from'],
            'date_to' => $range['date_to']
        ]);
        $administrators = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($administrators as &$administrator) {
            $administrator['admin_seconds'] = max(
                0,
                (int) ($administrator['admin_seconds'] ?? 0)
            );
            $administrator['public_seconds'] = max(
                0,
                (int) ($administrator['public_seconds'] ?? 0)
            );
            $administrator['total_seconds'] =
                $administrator['admin_seconds']
                + $administrator['public_seconds'];
            $administrator['session_count'] = max(
                0,
                (int) ($administrator['session_count'] ?? 0)
            );
            $administrator['active_days'] = max(
                0,
                (int) ($administrator['active_days'] ?? 0)
            );
        }
        unset($administrator);

        $dailyStmt = $db->prepare("
            SELECT
                admin_user_id,
                work_date,
                admin_seconds,
                public_seconds,
                session_count,
                first_activity_at,
                last_activity_at
            FROM admin_work_time_daily
            WHERE work_date BETWEEN :date_from AND :date_to
            ORDER BY work_date DESC, admin_user_id ASC
        ");
        $dailyStmt->execute([
            'date_from' => $range['date_from'],
            'date_to' => $range['date_to']
        ]);

        $dailyByAdmin = [];
        foreach ($dailyStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $adminId = (int) ($row['admin_user_id'] ?? 0);
            if ($adminId <= 0) {
                continue;
            }

            $row['admin_seconds'] = max(
                0,
                (int) ($row['admin_seconds'] ?? 0)
            );
            $row['public_seconds'] = max(
                0,
                (int) ($row['public_seconds'] ?? 0)
            );
            $row['total_seconds'] =
                $row['admin_seconds']
                + $row['public_seconds'];
            $row['session_count'] = max(
                0,
                (int) ($row['session_count'] ?? 0)
            );
            $dailyByAdmin[$adminId][] = $row;
        }

        return [
            'range' => $range,
            'administrators' => $administrators,
            'daily_by_admin' => $dailyByAdmin
        ];
    }


    public static function forgetPresence($adminUserId)
    {
        self::ensureSchema();

        $stmt = Database::connect()->prepare("
            DELETE FROM admin_work_time_presence
            WHERE admin_user_id = :admin_user_id
        ");
        $stmt->execute([
            'admin_user_id' => (int) $adminUserId
        ]);
    }


    private static function adminIdentity(PDO $db, $adminUserId)
    {
        $stmt = $db->prepare("
            SELECT
                au.id,
                au.name,
                au.email,
                au.is_active,
                ar.slug AS role_slug,
                ar.name AS role_name
            FROM admin_users au
            INNER JOIN admin_roles ar
                ON ar.id = au.role_id
            WHERE au.id = :id
            LIMIT 1
        ");
        $stmt->execute([
            'id' => (int) $adminUserId
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
