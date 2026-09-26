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

        $db->exec("
            CREATE TABLE IF NOT EXISTS admin_work_compensation
            (
                admin_user_id INT UNSIGNED NOT NULL,
                hourly_rate_minor BIGINT UNSIGNED NOT NULL DEFAULT 0,
                currency CHAR(3) NOT NULL DEFAULT 'UAH',
                payout_type VARCHAR(20) NOT NULL DEFAULT 'monthly',
                one_time_from DATE NULL,
                one_time_to DATE NULL,
                updated_by_admin_id INT UNSIGNED NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (admin_user_id),
                KEY idx_admin_work_compensation_updated_by
                    (updated_by_admin_id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        if (class_exists('AdminWorkActivity')) {
            AdminWorkActivity::ensureSchema();
        }

        self::$schemaReady = true;
    }


    public static function heartbeat(
        $adminUserId,
        $source,
        $clientSessionId,
        $active
    ) {
        self::ensureSchema();

        if (!class_exists('AdminWorkActivity')) {
            throw new RuntimeException(
                'Модуль робочої активності недоступний.'
            );
        }

        return AdminWorkActivity::heartbeat(
            (int) $adminUserId,
            $source,
            $clientSessionId,
            (bool) $active
        );
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

        $stmt = $db->query("
            SELECT
                au.id AS admin_user_id,
                au.name AS admin_name,
                au.email AS admin_email,
                ar.name AS role_name,
                ar.slug AS role_slug
            FROM admin_users au
            INNER JOIN admin_roles ar
                ON ar.id = au.role_id
            WHERE au.is_active = 1
            ORDER BY
                CASE ar.slug
                    WHEN 'owner' THEN 0
                    WHEN 'store_owner' THEN 1
                    ELSE 2
                END,
                au.name ASC,
                au.id ASC
        ");
        $administrators = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $compensationMap = self::compensationMap($db);
        $dailyByAdmin = [];

        foreach ($administrators as &$administrator) {
            $adminId = (int) (
                $administrator['admin_user_id'] ?? 0
            );
            $activity = class_exists('AdminWorkActivity')
                ? AdminWorkActivity::rangeSummary(
                    $adminId,
                    $range['date_from'],
                    $range['date_to']
                )
                : [
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

            $administrator = array_merge(
                $administrator,
                [
                    'admin_seconds' => max(
                        0,
                        (int) ($activity['admin_seconds'] ?? 0)
                    ),
                    'public_seconds' => max(
                        0,
                        (int) ($activity['public_seconds'] ?? 0)
                    ),
                    'android_seconds' => max(
                        0,
                        (int) ($activity['android_seconds'] ?? 0)
                    ),
                    'ios_seconds' => max(
                        0,
                        (int) ($activity['ios_seconds'] ?? 0)
                    ),
                    'total_seconds' => max(
                        0,
                        (int) ($activity['total_seconds'] ?? 0)
                    ),
                    'session_count' => max(
                        0,
                        (int) ($activity['session_count'] ?? 0)
                    ),
                    'active_days' => max(
                        0,
                        (int) ($activity['active_days'] ?? 0)
                    ),
                    'first_activity_at' => (string) (
                        $activity['first_activity_at'] ?? ''
                    ),
                    'last_activity_at' => (string) (
                        $activity['last_activity_at'] ?? ''
                    )
                ]
            );

            $dailyByAdmin[$adminId] = is_array(
                $activity['daily'] ?? null
            ) ? $activity['daily'] : [];

            $agreement = self::normalizeCompensationRow(
                $compensationMap[$adminId] ?? []
            );
            $administrator['compensation'] = $agreement;
            $administrator['earnings'] = self::calculateCompensation(
                $db,
                $adminId,
                $agreement
            );
        }
        unset($administrator);

        return [
            'range' => $range,
            'administrators' => $administrators,
            'daily_by_admin' => $dailyByAdmin
        ];
    }


    public static function currentAdminContract()
    {
        self::ensureSchema();

        if (!class_exists('AdminAccess')) {
            return [
                'has_contract' => false
            ];
        }

        $adminUserId = AdminAccess::currentId();

        if ($adminUserId <= 0) {
            return [
                'has_contract' => false
            ];
        }

        $db = Database::connect();
        $admin = self::adminIdentity($db, $adminUserId);

        if (!$admin || empty($admin['is_active'])) {
            return [
                'has_contract' => false
            ];
        }

        $stmt = $db->prepare("
            SELECT
                admin_user_id,
                hourly_rate_minor,
                currency,
                payout_type,
                one_time_from,
                one_time_to,
                created_at,
                updated_at
            FROM admin_work_compensation
            WHERE admin_user_id = :admin_user_id
            LIMIT 1
        ");
        $stmt->execute([
            'admin_user_id' => $adminUserId
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'has_contract' => false,
                'admin_user_id' => $adminUserId
            ];
        }

        $agreement = self::normalizeCompensationRow($row);
        $range = self::compensationRange($agreement);
        $activity = [
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
        $workError = '';

        if (
            class_exists('AdminWorkActivity')
            && ($range['date_from'] ?? '') !== ''
            && ($range['date_to'] ?? '') !== ''
        ) {
            try {
                $activity = AdminWorkActivity::rangeSummary(
                    $adminUserId,
                    $range['date_from'],
                    $range['date_to']
                );
            } catch (Throwable $e) {
                error_log(
                    'Admin work contract activity: '
                    . $e->getMessage()
                );
                $workError =
                    'Статистику робочого часу тимчасово не вдалося завантажити.';
            }
        }

        $seconds = max(
            0,
            (int) ($activity['total_seconds'] ?? 0)
        );
        $rateMinor = max(
            0,
            (int) ($agreement['hourly_rate_minor'] ?? 0)
        );
        $amountMinor = $seconds > 0 && $rateMinor > 0
            ? intdiv(($seconds * $rateMinor) + 1800, 3600)
            : 0;
        $earnings = [
            'date_from' => (string) ($range['date_from'] ?? ''),
            'date_to' => (string) ($range['date_to'] ?? ''),
            'seconds' => $seconds,
            'amount_minor' => $amountMinor,
            'currency' => (string) ($agreement['currency'] ?? 'UAH'),
            'payout_type' => (string) (
                $agreement['payout_type'] ?? 'monthly'
            ),
            'has_rate' => $rateMinor > 0
        ];

        return [
            'has_contract' => true,
            'admin_user_id' => $adminUserId,
            'agreement' => $agreement,
            'earnings' => $earnings,
            'work_error' => $workError,
            'work' => [
                'admin_seconds' => max(
                    0,
                    (int) ($activity['admin_seconds'] ?? 0)
                ),
                'public_seconds' => max(
                    0,
                    (int) ($activity['public_seconds'] ?? 0)
                ),
                'android_seconds' => max(
                    0,
                    (int) ($activity['android_seconds'] ?? 0)
                ),
                'ios_seconds' => max(
                    0,
                    (int) ($activity['ios_seconds'] ?? 0)
                ),
                'total_seconds' => $seconds,
                'session_count' => max(
                    0,
                    (int) ($activity['session_count'] ?? 0)
                ),
                'active_days' => max(
                    0,
                    (int) ($activity['active_days'] ?? 0)
                ),
                'first_activity_at' => (string) (
                    $activity['first_activity_at'] ?? ''
                ),
                'last_activity_at' => (string) (
                    $activity['last_activity_at'] ?? ''
                )
            ],
            'daily' => is_array($activity['daily'] ?? null)
                ? $activity['daily']
                : []
        ];
    }


    public static function saveCompensation(
        $adminUserId,
        $hourlyRate,
        $currency,
        $payoutType,
        $oneTimeFrom,
        $oneTimeTo,
        $updatedByAdminId
    ) {
        self::ensureSchema();

        if (!self::canViewReport()) {
            throw new RuntimeException(
                'Змінювати умови оплати може лише Розробник або Власник.'
            );
        }

        $adminUserId = (int) $adminUserId;
        $updatedByAdminId = (int) $updatedByAdminId;

        if ($adminUserId <= 0 || $updatedByAdminId <= 0) {
            throw new InvalidArgumentException(
                'Некоректний адміністратор.'
            );
        }

        $db = Database::connect();
        $admin = self::adminIdentity($db, $adminUserId);

        if (!$admin || empty($admin['is_active'])) {
            throw new RuntimeException(
                'Активного адміністратора не знайдено.'
            );
        }

        $hourlyRateMinor = self::moneyToMinor($hourlyRate);

        if ($hourlyRateMinor > 100000000) {
            throw new InvalidArgumentException(
                'Погодинна ставка занадто велика.'
            );
        }

        $currency = strtoupper(trim((string) $currency));
        if (!in_array($currency, ['UAH', 'EUR', 'USD', 'PLN'], true)) {
            throw new InvalidArgumentException(
                'Непідтримувана валюта.'
            );
        }

        $payoutType = strtolower(trim((string) $payoutType));
        if (!in_array(
            $payoutType,
            ['one_time', 'weekly', 'monthly'],
            true
        )) {
            throw new InvalidArgumentException(
                'Некоректний тип виплати.'
            );
        }

        $oneTimeFrom = self::normalizeDate($oneTimeFrom);
        $oneTimeTo = self::normalizeDate($oneTimeTo);

        if ($payoutType === 'one_time') {
            if ($oneTimeFrom === '' || $oneTimeTo === '') {
                throw new InvalidArgumentException(
                    'Для разової виплати вкажіть початок і кінець періоду.'
                );
            }

            if (strcmp($oneTimeFrom, $oneTimeTo) > 0) {
                [$oneTimeFrom, $oneTimeTo] = [
                    $oneTimeTo,
                    $oneTimeFrom
                ];
            }
        } else {
            $oneTimeFrom = '';
            $oneTimeTo = '';
        }

        $stmt = $db->prepare("
            INSERT INTO admin_work_compensation
            (
                admin_user_id,
                hourly_rate_minor,
                currency,
                payout_type,
                one_time_from,
                one_time_to,
                updated_by_admin_id
            )
            VALUES
            (
                :admin_user_id,
                :hourly_rate_minor,
                :currency,
                :payout_type,
                :one_time_from,
                :one_time_to,
                :updated_by_admin_id
            )
            ON DUPLICATE KEY UPDATE
                hourly_rate_minor = VALUES(hourly_rate_minor),
                currency = VALUES(currency),
                payout_type = VALUES(payout_type),
                one_time_from = VALUES(one_time_from),
                one_time_to = VALUES(one_time_to),
                updated_by_admin_id = VALUES(updated_by_admin_id),
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([
            'admin_user_id' => $adminUserId,
            'hourly_rate_minor' => $hourlyRateMinor,
            'currency' => $currency,
            'payout_type' => $payoutType,
            'one_time_from' => $oneTimeFrom !== '' ? $oneTimeFrom : null,
            'one_time_to' => $oneTimeTo !== '' ? $oneTimeTo : null,
            'updated_by_admin_id' => $updatedByAdminId
        ]);

        return self::normalizeCompensationRow([
            'admin_user_id' => $adminUserId,
            'hourly_rate_minor' => $hourlyRateMinor,
            'currency' => $currency,
            'payout_type' => $payoutType,
            'one_time_from' => $oneTimeFrom,
            'one_time_to' => $oneTimeTo
        ]);
    }


    private static function compensationMap(PDO $db)
    {
        $rows = $db->query("
            SELECT
                admin_user_id,
                hourly_rate_minor,
                currency,
                payout_type,
                one_time_from,
                one_time_to,
                updated_at
            FROM admin_work_compensation
        ")->fetchAll(PDO::FETCH_ASSOC);

        $map = [];

        foreach ($rows as $row) {
            $adminId = (int) ($row['admin_user_id'] ?? 0);
            if ($adminId > 0) {
                $map[$adminId] = $row;
            }
        }

        return $map;
    }


    private static function normalizeCompensationRow(array $row)
    {
        return [
            'hourly_rate_minor' => max(
                0,
                (int) ($row['hourly_rate_minor'] ?? 0)
            ),
            'currency' => in_array(
                strtoupper((string) ($row['currency'] ?? 'UAH')),
                ['UAH', 'EUR', 'USD', 'PLN'],
                true
            ) ? strtoupper((string) ($row['currency'] ?? 'UAH')) : 'UAH',
            'payout_type' => in_array(
                (string) ($row['payout_type'] ?? 'monthly'),
                ['one_time', 'weekly', 'monthly'],
                true
            ) ? (string) $row['payout_type'] : 'monthly',
            'one_time_from' => trim(
                (string) ($row['one_time_from'] ?? '')
            ),
            'one_time_to' => trim(
                (string) ($row['one_time_to'] ?? '')
            ),
            'created_at' => trim(
                (string) ($row['created_at'] ?? '')
            ),
            'updated_at' => trim(
                (string) ($row['updated_at'] ?? '')
            )
        ];
    }


    private static function calculateCompensation(
        PDO $db,
        $adminUserId,
        array $agreement
    ) {
        $range = self::compensationRange($agreement);
        $seconds = 0;

        if (
            $range['date_from'] !== ''
            && $range['date_to'] !== ''
            && strcmp($range['date_from'], $range['date_to']) <= 0
            && class_exists('AdminWorkActivity')
        ) {
            $activity = AdminWorkActivity::rangeSummary(
                (int) $adminUserId,
                $range['date_from'],
                $range['date_to']
            );
            $seconds = max(
                0,
                (int) ($activity['total_seconds'] ?? 0)
            );
        }

        $rateMinor = max(
            0,
            (int) ($agreement['hourly_rate_minor'] ?? 0)
        );
        $amountMinor = $seconds > 0 && $rateMinor > 0
            ? intdiv(($seconds * $rateMinor) + 1800, 3600)
            : 0;

        return [
            'date_from' => $range['date_from'],
            'date_to' => $range['date_to'],
            'seconds' => $seconds,
            'amount_minor' => $amountMinor,
            'currency' => (string) ($agreement['currency'] ?? 'UAH'),
            'payout_type' => (string) (
                $agreement['payout_type'] ?? 'monthly'
            ),
            'has_rate' => $rateMinor > 0
        ];
    }


    private static function compensationRange(array $agreement)
    {
        $today = date('Y-m-d');
        $type = (string) ($agreement['payout_type'] ?? 'monthly');

        if ($type === 'one_time') {
            $from = self::normalizeDate(
                $agreement['one_time_from'] ?? ''
            );
            $to = self::normalizeDate(
                $agreement['one_time_to'] ?? ''
            );

            if ($from === '' || $to === '') {
                return [
                    'date_from' => '',
                    'date_to' => ''
                ];
            }

            if (strcmp($from, $to) > 0) {
                [$from, $to] = [$to, $from];
            }

            if (strcmp($to, $today) > 0) {
                $to = $today;
            }

            return [
                'date_from' => $from,
                'date_to' => $to
            ];
        }

        if ($type === 'weekly') {
            return [
                'date_from' => date(
                    'Y-m-d',
                    strtotime('monday this week')
                ),
                'date_to' => $today
            ];
        }

        return [
            'date_from' => date('Y-m-01'),
            'date_to' => $today
        ];
    }


    private static function moneyToMinor($value)
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        $value = trim(str_replace(',', '.', (string) $value));

        if (
            $value === ''
            || preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1
        ) {
            throw new InvalidArgumentException(
                'Вкажіть коректну погодинну ставку.'
            );
        }

        [$whole, $fraction] = array_pad(
            explode('.', $value, 2),
            2,
            ''
        );
        $fraction = str_pad($fraction, 2, '0');
        $minor = ((int) $whole * 100)
            + (int) substr($fraction, 0, 2);

        return max(0, $minor);
    }


    private static function normalizeDate($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $date = DateTime::createFromFormat('!Y-m-d', $value);

        if (!$date || $date->format('Y-m-d') !== $value) {
            return '';
        }

        return $value;
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

        if (class_exists('AdminWorkActivity')) {
            AdminWorkActivity::forgetSessions(
                (int) $adminUserId
            );
        }
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
