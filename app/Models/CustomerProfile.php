<?php

class CustomerProfile
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        $db = Database::connect();

        $db->exec("
            CREATE TABLE IF NOT EXISTS customer_profiles
            (
                user_id INT UNSIGNED NOT NULL,
                phone VARCHAR(40) NULL,
                birth_date DATE NULL,
                show_adult TINYINT(1) NOT NULL DEFAULT 0,
                adult_confirmed_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::ensureAdultConfirmedAtColumn($db);
        self::$schemaReady = true;
    }


    public static function getForUser($userId)
    {
        self::ensureSchema();
        $userId = (int) $userId;

        if ($userId <= 0) {
            return self::emptyProfile();
        }

        $stmt = Database::connect()->prepare("
            SELECT phone, birth_date, show_adult, adult_confirmed_at
            FROM customer_profiles
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: self::emptyProfile();
    }


    public static function updatePhone($userId, $phone)
    {
        self::ensureSchema();
        $userId = (int) $userId;

        if ($userId <= 0) {
            throw new InvalidArgumentException('Некоректний користувач.');
        }

        $phone = self::normalizePhone($phone);
        $stmt = Database::connect()->prepare("
            INSERT INTO customer_profiles
                (user_id, phone)
            VALUES
                (:user_id, :phone)
            ON DUPLICATE KEY UPDATE
                phone = VALUES(phone)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'phone' => $phone !== '' ? $phone : null
        ]);

        return $phone;
    }


    public static function updateAdultPreferences(
        $userId,
        $birthDate,
        $adultConfirmed,
        $showAdult,
        $hasAutomaticAccess = false
    ) {
        self::ensureSchema();
        $userId = (int) $userId;

        if ($userId <= 0) {
            throw new InvalidArgumentException('Некоректний користувач.');
        }

        $birthDate = self::normalizeBirthDate($birthDate);
        $adultConfirmed = !empty($adultConfirmed);
        $showAdult = !empty($showAdult);
        $hasAutomaticAccess = !empty($hasAutomaticAccess);
        $knownUnderage = self::isKnownUnderageBirthDate($birthDate);

        if ($knownUnderage && ($adultConfirmed || $showAdult || $hasAutomaticAccess)) {
            throw new InvalidArgumentException(
                'Розділ 18+ недоступний неповнолітнім користувачам.'
            );
        }

        if ($showAdult && !$adultConfirmed && !$hasAutomaticAccess) {
            throw new InvalidArgumentException(
                'Спочатку підтвердьте, що вам уже виповнилося 18 років.'
            );
        }

        $existing = self::getForUser($userId);
        $confirmedAt = null;

        if ($adultConfirmed) {
            $confirmedAt = !empty($existing['adult_confirmed_at'])
                ? (string) $existing['adult_confirmed_at']
                : date('Y-m-d H:i:s');
        }

        if (!$adultConfirmed && !$hasAutomaticAccess) {
            $showAdult = false;
        }

        $stmt = Database::connect()->prepare("
            INSERT INTO customer_profiles
                (user_id, birth_date, show_adult, adult_confirmed_at)
            VALUES
                (:user_id, :birth_date, :show_adult, :adult_confirmed_at)
            ON DUPLICATE KEY UPDATE
                birth_date = VALUES(birth_date),
                show_adult = VALUES(show_adult),
                adult_confirmed_at = VALUES(adult_confirmed_at)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'birth_date' => $birthDate,
            'show_adult' => $showAdult ? 1 : 0,
            'adult_confirmed_at' => $confirmedAt
        ]);

        return [
            'birth_date' => $birthDate,
            'show_adult' => $showAdult ? 1 : 0,
            'adult_confirmed_at' => $confirmedAt,
            'adult_confirmed' => $confirmedAt !== null,
            'is_adult' => $birthDate !== null
                && self::isAdultBirthDate($birthDate),
            'is_known_underage' => $knownUnderage
        ];
    }


    public static function confirmAdultForUser($userId)
    {
        self::ensureSchema();
        $userId = (int) $userId;

        if ($userId <= 0) {
            throw new InvalidArgumentException('Некоректний користувач.');
        }

        $profile = self::getForUser($userId);

        if (self::isKnownUnderageBirthDate($profile['birth_date'] ?? null)) {
            throw new RuntimeException(
                'Розділ 18+ недоступний неповнолітнім користувачам.'
            );
        }

        if (!empty($profile['adult_confirmed_at'])) {
            return (string) $profile['adult_confirmed_at'];
        }

        $confirmedAt = date('Y-m-d H:i:s');
        $stmt = Database::connect()->prepare("
            INSERT INTO customer_profiles
                (user_id, adult_confirmed_at)
            VALUES
                (:user_id, :adult_confirmed_at)
            ON DUPLICATE KEY UPDATE
                adult_confirmed_at = VALUES(adult_confirmed_at)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'adult_confirmed_at' => $confirmedAt
        ]);

        return $confirmedAt;
    }


    public static function isAdultConfirmedForUser($userId)
    {
        $profile = self::getForUser((int) $userId);

        return !empty($profile['adult_confirmed_at'])
            && !self::isKnownUnderageBirthDate($profile['birth_date'] ?? null);
    }


    public static function canShowAdultForUser($userId, $hasAutomaticAccess = false)
    {
        $profile = self::getForUser((int) $userId);
        $knownUnderage = self::isKnownUnderageBirthDate(
            $profile['birth_date'] ?? null
        );

        if ($knownUnderage || empty($profile['show_adult'])) {
            return false;
        }

        return !empty($profile['adult_confirmed_at'])
            || !empty($hasAutomaticAccess);
    }


    public static function isKnownUnderageBirthDate($birthDate)
    {
        $birthDate = self::normalizeBirthDate($birthDate);

        return $birthDate !== null
            && !self::isAdultBirthDate($birthDate);
    }


    public static function isAdultBirthDate($birthDate)
    {
        $birthDate = self::normalizeBirthDate($birthDate);

        if ($birthDate === null) {
            return false;
        }

        $birth = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);

        if (!$birth) {
            return false;
        }

        $adultBoundary = (new DateTimeImmutable('today'))->modify('-18 years');

        return $birth <= $adultBoundary;
    }


    public static function normalizeBirthDate($birthDate)
    {
        $birthDate = trim((string) $birthDate);

        if ($birthDate === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthDate);
        $errors = DateTimeImmutable::getLastErrors();

        if (
            !$date
            || ($errors !== false && (
                !empty($errors['warning_count'])
                || !empty($errors['error_count'])
            ))
            || $date->format('Y-m-d') !== $birthDate
        ) {
            throw new InvalidArgumentException('Вкажіть коректну дату народження.');
        }

        $today = new DateTimeImmutable('today');

        if ($date > $today) {
            throw new InvalidArgumentException('Дата народження не може бути в майбутньому.');
        }

        if ($date < $today->modify('-120 years')) {
            throw new InvalidArgumentException('Вкажіть коректну дату народження.');
        }

        return $date->format('Y-m-d');
    }


    public static function normalizePhone($phone)
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return '';
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($phone, 'UTF-8')
            : strlen($phone);

        if ($length > 40) {
            throw new InvalidArgumentException('Номер телефону занадто довгий.');
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (strlen((string) $digits) < 5) {
            throw new InvalidArgumentException('Вкажіть коректний номер телефону.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/u', $phone)) {
            throw new InvalidArgumentException('Вкажіть коректний номер телефону.');
        }

        return $phone;
    }


    private static function emptyProfile()
    {
        return [
            'phone' => '',
            'birth_date' => null,
            'show_adult' => 0,
            'adult_confirmed_at' => null
        ];
    }


    private static function ensureAdultConfirmedAtColumn(PDO $db)
    {
        $column = $db->query("
            SHOW COLUMNS FROM customer_profiles LIKE 'adult_confirmed_at'
        ")->fetch(PDO::FETCH_ASSOC);

        if (!$column) {
            $db->exec("
                ALTER TABLE customer_profiles
                ADD COLUMN adult_confirmed_at DATETIME NULL
                AFTER show_adult
            ");
        }

        // Старе поле show_adult раніше одночасно означало згоду 18+.
        // Для сумісності один раз перетворюємо наявне значення на підтвердження.
        $db->exec("
            UPDATE customer_profiles
            SET adult_confirmed_at = COALESCE(adult_confirmed_at, updated_at, created_at)
            WHERE show_adult = 1
              AND adult_confirmed_at IS NULL
        ");
    }
}
