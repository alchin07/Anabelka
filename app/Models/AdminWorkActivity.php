<?php

class AdminWorkActivity
{
    private static $schemaReady = false;

    private const MAX_CREDIT_GAP_SECONDS = 90;
    private const IDLE_SESSION_SECONDS = 300;

    private const SOURCES = [
        'web_admin',
        'web_public',
        'android',
        'ios'
    ];


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_work_activity_sessions
            (
                admin_user_id INT UNSIGNED NOT NULL,
                client_session_id VARCHAR(80) NOT NULL,
                source VARCHAR(20) NOT NULL,
                active_state TINYINT(1) NOT NULL DEFAULT 0,
                run_no INT UNSIGNED NOT NULL DEFAULT 0,
                last_heartbeat_at DATETIME NULL,
                last_active_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (admin_user_id, client_session_id),
                KEY idx_admin_work_activity_session_seen
                    (admin_user_id, last_heartbeat_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_work_activity_segments
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                admin_user_id INT UNSIGNED NOT NULL,
                work_date DATE NOT NULL,
                source VARCHAR(20) NOT NULL,
                client_session_id VARCHAR(80) NOT NULL,
                run_no INT UNSIGNED NOT NULL DEFAULT 0,
                started_at DATETIME NOT NULL,
                ended_at DATETIME NOT NULL,
                active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_admin_work_segment_date
                    (admin_user_id, work_date, started_at),
                KEY idx_admin_work_segment_overlap
                    (admin_user_id, started_at, ended_at),
                KEY idx_admin_work_segment_source
                    (admin_user_id, source, work_date)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_work_time_source_daily
            (
                admin_user_id INT UNSIGNED NOT NULL,
                work_date DATE NOT NULL,
                source VARCHAR(20) NOT NULL,
                active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
                first_activity_at DATETIME NULL,
                last_activity_at DATETIME NULL,
                PRIMARY KEY (admin_user_id, work_date, source),
                KEY idx_admin_work_source_daily_range
                    (admin_user_id, work_date)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::migrateLegacyDaily($db);

        self::$schemaReady = true;
    }


    public static function supportedSources()
    {
        return self::SOURCES;
    }


    public static function heartbeat(
        $adminUserId,
        $source,
        $clientSessionId,
        $active
    ) {
        self::ensureSchema();

        $adminUserId = (int) $adminUserId;
        $source = self::normalizeSource($source);
        $clientSessionId = self::normalizeSessionId(
            $clientSessionId
        );
        $active = (bool) $active;

        if ($adminUserId <= 0) {
            throw new InvalidArgumentException(
                'Некоректний адміністратор.'
            );
        }

        if ($clientSessionId === '') {
            throw new InvalidArgumentException(
                'Некоректна робоча сесія.'
            );
        }

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $adminLock = $db->prepare("
                SELECT id, is_active
                FROM admin_users
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
            ");
            $adminLock->execute([
                'id' => $adminUserId
            ]);
            $admin = $adminLock->fetch(PDO::FETCH_ASSOC);

            if (!$admin || empty($admin['is_active'])) {
                throw new RuntimeException(
                    'Активного адміністратора не знайдено.'
                );
            }

            $now = (string) $db->query(
                "SELECT DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')"
            )->fetchColumn();
            $nowTs = strtotime($now);

            if (!$nowTs) {
                throw new RuntimeException(
                    'Не вдалося визначити час сервера.'
                );
            }

            $insertSession = $db->prepare("
                INSERT IGNORE INTO admin_work_activity_sessions
                (
                    admin_user_id,
                    client_session_id,
                    source,
                    active_state,
                    run_no,
                    last_heartbeat_at,
                    last_active_at
                )
                VALUES
                (
                    :admin_user_id,
                    :client_session_id,
                    :source,
                    :active_state,
                    :run_no,
                    :last_heartbeat_at,
                    :last_active_at
                )
            ");
            $insertSession->execute([
                'admin_user_id' => $adminUserId,
                'client_session_id' => $clientSessionId,
                'source' => $source,
                'active_state' => $active ? 1 : 0,
                'run_no' => $active ? 1 : 0,
                'last_heartbeat_at' => $now,
                'last_active_at' => $active ? $now : null
            ]);

            if ($insertSession->rowCount() > 0) {
                $db->commit();

                return [
                    'credited_seconds' => 0,
                    'source' => $source,
                    'session_id' => $clientSessionId,
                    'run_no' => $active ? 1 : 0,
                    'server_authoritative' => true
                ];
            }

            $sessionStmt = $db->prepare("
                SELECT
                    source,
                    active_state,
                    run_no,
                    last_heartbeat_at,
                    last_active_at
                FROM admin_work_activity_sessions
                WHERE admin_user_id = :admin_user_id
                  AND client_session_id = :client_session_id
                LIMIT 1
                FOR UPDATE
            ");
            $sessionStmt->execute([
                'admin_user_id' => $adminUserId,
                'client_session_id' => $clientSessionId
            ]);
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $lastHeartbeat = trim((string) (
                $session['last_heartbeat_at'] ?? ''
            ));
            $lastTs = $lastHeartbeat !== ''
                ? strtotime($lastHeartbeat)
                : false;
            $elapsed = $lastTs
                ? max(0, $nowTs - $lastTs)
                : 0;
            $wasActive = !empty($session['active_state']);
            $runNo = max(
                0,
                (int) ($session['run_no'] ?? 0)
            );

            if (
                $active
                && (
                    !$wasActive
                    || $elapsed > self::IDLE_SESSION_SECONDS
                )
            ) {
                $runNo++;
            }

            $creditedSeconds = 0;

            if (
                $wasActive
                && $lastTs
                && $elapsed > 0
                && $elapsed <= self::MAX_CREDIT_GAP_SECONDS
            ) {
                $creditedSeconds = self::claimUniqueInterval(
                    $db,
                    $adminUserId,
                    $source,
                    $clientSessionId,
                    max(1, $runNo),
                    $lastTs,
                    $nowTs
                );
            }

            $updateSession = $db->prepare("
                UPDATE admin_work_activity_sessions
                SET
                    source = :source,
                    active_state = :active_state,
                    run_no = :run_no,
                    last_heartbeat_at = :last_heartbeat_at,
                    last_active_at = CASE
                        WHEN :active_state_copy = 1
                        THEN :last_active_at
                        ELSE last_active_at
                    END
                WHERE admin_user_id = :admin_user_id
                  AND client_session_id = :client_session_id
            ");
            $updateSession->execute([
                'source' => $source,
                'active_state' => $active ? 1 : 0,
                'run_no' => $runNo,
                'last_heartbeat_at' => $now,
                'active_state_copy' => $active ? 1 : 0,
                'last_active_at' => $now,
                'admin_user_id' => $adminUserId,
                'client_session_id' => $clientSessionId
            ]);

            $db->commit();

            return [
                'credited_seconds' => $creditedSeconds,
                'source' => $source,
                'session_id' => $clientSessionId,
                'run_no' => $runNo,
                'server_authoritative' => true
            ];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }
    }


    public static function rangeSummary(
        $adminUserId,
        $dateFrom,
        $dateTo
    ) {
        self::ensureSchema();

        $adminUserId = (int) $adminUserId;
        $dateFrom = self::normalizeDate($dateFrom);
        $dateTo = self::normalizeDate($dateTo);

        if (
            $adminUserId <= 0
            || $dateFrom === ''
            || $dateTo === ''
        ) {
            return self::emptyRange(
                $dateFrom,
                $dateTo
            );
        }

        if (strcmp($dateFrom, $dateTo) > 0) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $db = Database::connect();
        $daily = [];

        $sourceStmt = $db->prepare("
            SELECT
                work_date,
                source,
                active_seconds,
                first_activity_at,
                last_activity_at
            FROM admin_work_time_source_daily
            WHERE admin_user_id = :admin_user_id
              AND work_date BETWEEN :date_from AND :date_to
            ORDER BY work_date DESC, source ASC
        ");
        $sourceStmt->execute([
            'admin_user_id' => $adminUserId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);

        foreach ($sourceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = (string) ($row['work_date'] ?? '');

            if ($date === '') {
                continue;
            }

            if (!isset($daily[$date])) {
                $daily[$date] = self::emptyDailyRow($date);
            }

            $source = self::normalizeSource(
                $row['source'] ?? ''
            );
            $seconds = max(
                0,
                (int) ($row['active_seconds'] ?? 0)
            );

            $daily[$date]['sources'][$source] += $seconds;
            $daily[$date]['total_seconds'] += $seconds;

            $first = trim((string) (
                $row['first_activity_at'] ?? ''
            ));
            $last = trim((string) (
                $row['last_activity_at'] ?? ''
            ));

            if (
                $first !== ''
                && (
                    $daily[$date]['first_activity_at'] === ''
                    || strcmp(
                        $first,
                        $daily[$date]['first_activity_at']
                    ) < 0
                )
            ) {
                $daily[$date]['first_activity_at'] = $first;
            }

            if (
                $last !== ''
                && (
                    $daily[$date]['last_activity_at'] === ''
                    || strcmp(
                        $last,
                        $daily[$date]['last_activity_at']
                    ) > 0
                )
            ) {
                $daily[$date]['last_activity_at'] = $last;
            }
        }

        $legacySessions = $db->prepare("
            SELECT
                work_date,
                session_count
            FROM admin_work_time_daily
            WHERE admin_user_id = :admin_user_id
              AND work_date BETWEEN :date_from AND :date_to
        ");
        $legacySessions->execute([
            'admin_user_id' => $adminUserId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);

        foreach ($legacySessions->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = (string) ($row['work_date'] ?? '');

            if ($date === '') {
                continue;
            }

            if (!isset($daily[$date])) {
                $daily[$date] = self::emptyDailyRow($date);
            }

            $daily[$date]['session_count'] += max(
                0,
                (int) ($row['session_count'] ?? 0)
            );
        }

        $newSessions = $db->prepare("
            SELECT
                work_date,
                COUNT(
                    DISTINCT CONCAT(
                        client_session_id,
                        '#',
                        run_no
                    )
                ) AS session_count
            FROM admin_work_activity_segments
            WHERE admin_user_id = :admin_user_id
              AND work_date BETWEEN :date_from AND :date_to
            GROUP BY work_date
        ");
        $newSessions->execute([
            'admin_user_id' => $adminUserId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);

        foreach ($newSessions->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $date = (string) ($row['work_date'] ?? '');

            if ($date === '') {
                continue;
            }

            if (!isset($daily[$date])) {
                $daily[$date] = self::emptyDailyRow($date);
            }

            $daily[$date]['session_count'] += max(
                0,
                (int) ($row['session_count'] ?? 0)
            );
        }

        krsort($daily);

        $summary = self::emptyRange(
            $dateFrom,
            $dateTo
        );

        foreach ($daily as &$day) {
            self::finalizeDailyRow($day);

            if ($day['total_seconds'] > 0) {
                $summary['active_days']++;
            }

            $summary['session_count'] += $day['session_count'];
            $summary['total_seconds'] += $day['total_seconds'];

            foreach (self::SOURCES as $source) {
                $summary['sources'][$source] +=
                    $day['sources'][$source];
            }

            $first = (string) $day['first_activity_at'];
            $last = (string) $day['last_activity_at'];

            if (
                $first !== ''
                && (
                    $summary['first_activity_at'] === ''
                    || strcmp(
                        $first,
                        $summary['first_activity_at']
                    ) < 0
                )
            ) {
                $summary['first_activity_at'] = $first;
            }

            if (
                $last !== ''
                && (
                    $summary['last_activity_at'] === ''
                    || strcmp(
                        $last,
                        $summary['last_activity_at']
                    ) > 0
                )
            ) {
                $summary['last_activity_at'] = $last;
            }
        }
        unset($day);

        self::applyCompatibilityFields($summary);
        $summary['daily'] = array_values($daily);

        return $summary;
    }


    public static function forgetSessions($adminUserId)
    {
        self::ensureSchema();

        $stmt = Database::connect()->prepare("
            DELETE FROM admin_work_activity_sessions
            WHERE admin_user_id = :admin_user_id
        ");
        $stmt->execute([
            'admin_user_id' => (int) $adminUserId
        ]);
    }


    private static function claimUniqueInterval(
        PDO $db,
        $adminUserId,
        $source,
        $clientSessionId,
        $runNo,
        $startTs,
        $endTs
    ) {
        $credited = 0;

        foreach (
            self::splitByDay((int) $startTs, (int) $endTs)
            as $slice
        ) {
            [$workDate, $sliceStart, $sliceEnd] = $slice;

            if ($sliceEnd <= $sliceStart) {
                continue;
            }

            $overlap = $db->prepare("
                SELECT started_at, ended_at
                FROM admin_work_activity_segments
                WHERE admin_user_id = :admin_user_id
                  AND work_date = :work_date
                  AND started_at < :slice_end
                  AND ended_at > :slice_start
                ORDER BY started_at ASC, ended_at ASC
            ");
            $overlap->execute([
                'admin_user_id' => (int) $adminUserId,
                'work_date' => $workDate,
                'slice_end' => date(
                    'Y-m-d H:i:s',
                    $sliceEnd
                ),
                'slice_start' => date(
                    'Y-m-d H:i:s',
                    $sliceStart
                )
            ]);

            $cursor = $sliceStart;
            $uncovered = [];

            foreach ($overlap->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existingStart = strtotime(
                    (string) ($row['started_at'] ?? '')
                );
                $existingEnd = strtotime(
                    (string) ($row['ended_at'] ?? '')
                );

                if (!$existingStart || !$existingEnd) {
                    continue;
                }

                if ($existingEnd <= $cursor) {
                    continue;
                }

                if ($existingStart > $cursor) {
                    $uncovered[] = [
                        $cursor,
                        min($existingStart, $sliceEnd)
                    ];
                }

                $cursor = max(
                    $cursor,
                    $existingEnd
                );

                if ($cursor >= $sliceEnd) {
                    break;
                }
            }

            if ($cursor < $sliceEnd) {
                $uncovered[] = [
                    $cursor,
                    $sliceEnd
                ];
            }

            foreach ($uncovered as $piece) {
                [$pieceStart, $pieceEnd] = $piece;
                $seconds = max(
                    0,
                    $pieceEnd - $pieceStart
                );

                if ($seconds <= 0) {
                    continue;
                }

                $startText = date(
                    'Y-m-d H:i:s',
                    $pieceStart
                );
                $endText = date(
                    'Y-m-d H:i:s',
                    $pieceEnd
                );

                $insert = $db->prepare("
                    INSERT INTO admin_work_activity_segments
                    (
                        admin_user_id,
                        work_date,
                        source,
                        client_session_id,
                        run_no,
                        started_at,
                        ended_at,
                        active_seconds
                    )
                    VALUES
                    (
                        :admin_user_id,
                        :work_date,
                        :source,
                        :client_session_id,
                        :run_no,
                        :started_at,
                        :ended_at,
                        :active_seconds
                    )
                ");
                $insert->execute([
                    'admin_user_id' => (int) $adminUserId,
                    'work_date' => $workDate,
                    'source' => $source,
                    'client_session_id' => $clientSessionId,
                    'run_no' => (int) $runNo,
                    'started_at' => $startText,
                    'ended_at' => $endText,
                    'active_seconds' => $seconds
                ]);

                self::addSourceDaily(
                    $db,
                    $adminUserId,
                    $workDate,
                    $source,
                    $seconds,
                    $startText,
                    $endText
                );

                $credited += $seconds;
            }
        }

        return $credited;
    }


    private static function addSourceDaily(
        PDO $db,
        $adminUserId,
        $workDate,
        $source,
        $seconds,
        $firstActivityAt,
        $lastActivityAt
    ) {
        $stmt = $db->prepare("
            INSERT INTO admin_work_time_source_daily
            (
                admin_user_id,
                work_date,
                source,
                active_seconds,
                first_activity_at,
                last_activity_at
            )
            VALUES
            (
                :admin_user_id,
                :work_date,
                :source,
                :active_seconds,
                :first_activity_at,
                :last_activity_at
            )
            ON DUPLICATE KEY UPDATE
                active_seconds =
                    active_seconds + VALUES(active_seconds),
                first_activity_at = CASE
                    WHEN first_activity_at IS NULL
                      OR VALUES(first_activity_at) < first_activity_at
                    THEN VALUES(first_activity_at)
                    ELSE first_activity_at
                END,
                last_activity_at = CASE
                    WHEN last_activity_at IS NULL
                      OR VALUES(last_activity_at) > last_activity_at
                    THEN VALUES(last_activity_at)
                    ELSE last_activity_at
                END
        ");
        $stmt->execute([
            'admin_user_id' => (int) $adminUserId,
            'work_date' => (string) $workDate,
            'source' => (string) $source,
            'active_seconds' => max(0, (int) $seconds),
            'first_activity_at' => (string) $firstActivityAt,
            'last_activity_at' => (string) $lastActivityAt
        ]);
    }


    private static function migrateLegacyDaily(PDO $db)
    {
        $legacyTable = $db->query(
            "SHOW TABLES LIKE 'admin_work_time_daily'"
        )->fetchColumn();

        if (!$legacyTable) {
            return;
        }

        $db->exec("
            INSERT INTO admin_work_time_source_daily
            (
                admin_user_id,
                work_date,
                source,
                active_seconds,
                first_activity_at,
                last_activity_at
            )
            SELECT
                admin_user_id,
                work_date,
                'web_admin',
                admin_seconds,
                first_activity_at,
                last_activity_at
            FROM admin_work_time_daily
            WHERE admin_seconds > 0
            ON DUPLICATE KEY UPDATE
                active_seconds = GREATEST(
                    active_seconds,
                    VALUES(active_seconds)
                ),
                first_activity_at = CASE
                    WHEN first_activity_at IS NULL
                    THEN VALUES(first_activity_at)
                    WHEN VALUES(first_activity_at) IS NULL
                    THEN first_activity_at
                    ELSE LEAST(
                        first_activity_at,
                        VALUES(first_activity_at)
                    )
                END,
                last_activity_at = CASE
                    WHEN last_activity_at IS NULL
                    THEN VALUES(last_activity_at)
                    WHEN VALUES(last_activity_at) IS NULL
                    THEN last_activity_at
                    ELSE GREATEST(
                        last_activity_at,
                        VALUES(last_activity_at)
                    )
                END
        ");

        $db->exec("
            INSERT INTO admin_work_time_source_daily
            (
                admin_user_id,
                work_date,
                source,
                active_seconds,
                first_activity_at,
                last_activity_at
            )
            SELECT
                admin_user_id,
                work_date,
                'web_public',
                public_seconds,
                first_activity_at,
                last_activity_at
            FROM admin_work_time_daily
            WHERE public_seconds > 0
            ON DUPLICATE KEY UPDATE
                active_seconds = GREATEST(
                    active_seconds,
                    VALUES(active_seconds)
                ),
                first_activity_at = CASE
                    WHEN first_activity_at IS NULL
                    THEN VALUES(first_activity_at)
                    WHEN VALUES(first_activity_at) IS NULL
                    THEN first_activity_at
                    ELSE LEAST(
                        first_activity_at,
                        VALUES(first_activity_at)
                    )
                END,
                last_activity_at = CASE
                    WHEN last_activity_at IS NULL
                    THEN VALUES(last_activity_at)
                    WHEN VALUES(last_activity_at) IS NULL
                    THEN last_activity_at
                    ELSE GREATEST(
                        last_activity_at,
                        VALUES(last_activity_at)
                    )
                END
        ");
    }


    private static function normalizeSource($source)
    {
        $source = strtolower(trim((string) $source));

        $aliases = [
            'admin' => 'web_admin',
            'public' => 'web_public'
        ];

        if (isset($aliases[$source])) {
            $source = $aliases[$source];
        }

        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException(
                'Некоректне джерело робочої активності.'
            );
        }

        return $source;
    }


    private static function normalizeSessionId($value)
    {
        $value = trim((string) $value);

        if (
            strlen($value) < 8
            || strlen($value) > 80
            || preg_match(
                '/^[A-Za-z0-9._:-]+$/',
                $value
            ) !== 1
        ) {
            return '';
        }

        return $value;
    }


    private static function splitByDay($startTs, $endTs)
    {
        $result = [];
        $cursor = (int) $startTs;
        $endTs = (int) $endTs;

        while ($cursor < $endTs) {
            $date = date('Y-m-d', $cursor);
            $nextMidnight = strtotime(
                $date . ' 00:00:00 +1 day'
            );

            if (!$nextMidnight || $nextMidnight <= $cursor) {
                $nextMidnight = $endTs;
            }

            $sliceEnd = min(
                $endTs,
                $nextMidnight
            );

            $result[] = [
                $date,
                $cursor,
                $sliceEnd
            ];

            $cursor = $sliceEnd;
        }

        return $result;
    }


    private static function emptyRange($dateFrom, $dateTo)
    {
        return [
            'date_from' => (string) $dateFrom,
            'date_to' => (string) $dateTo,
            'sources' => array_fill_keys(
                self::SOURCES,
                0
            ),
            'web_admin_seconds' => 0,
            'web_public_seconds' => 0,
            'admin_seconds' => 0,
            'public_seconds' => 0,
            'android_seconds' => 0,
            'ios_seconds' => 0,
            'total_seconds' => 0,
            'session_count' => 0,
            'active_days' => 0,
            'first_activity_at' => '',
            'last_activity_at' => '',
            'daily' => []
        ];
    }


    private static function emptyDailyRow($date)
    {
        return [
            'work_date' => (string) $date,
            'sources' => array_fill_keys(
                self::SOURCES,
                0
            ),
            'web_admin_seconds' => 0,
            'web_public_seconds' => 0,
            'admin_seconds' => 0,
            'public_seconds' => 0,
            'android_seconds' => 0,
            'ios_seconds' => 0,
            'total_seconds' => 0,
            'session_count' => 0,
            'first_activity_at' => '',
            'last_activity_at' => ''
        ];
    }


    private static function finalizeDailyRow(array &$row)
    {
        self::applyCompatibilityFields($row);
    }


    private static function applyCompatibilityFields(array &$row)
    {
        $row['web_admin_seconds'] = max(
            0,
            (int) ($row['sources']['web_admin'] ?? 0)
        );
        $row['web_public_seconds'] = max(
            0,
            (int) ($row['sources']['web_public'] ?? 0)
        );
        $row['admin_seconds'] =
            $row['web_admin_seconds'];
        $row['public_seconds'] =
            $row['web_public_seconds'];
        $row['android_seconds'] = max(
            0,
            (int) ($row['sources']['android'] ?? 0)
        );
        $row['ios_seconds'] = max(
            0,
            (int) ($row['sources']['ios'] ?? 0)
        );
        $row['total_seconds'] =
            $row['web_admin_seconds']
            + $row['web_public_seconds']
            + $row['android_seconds']
            + $row['ios_seconds'];
    }


    private static function normalizeDate($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $date = DateTime::createFromFormat(
            '!Y-m-d',
            $value
        );

        if (
            !$date
            || $date->format('Y-m-d') !== $value
        ) {
            return '';
        }

        return $value;
    }
}
