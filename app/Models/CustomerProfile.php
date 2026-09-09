<?php

class CustomerProfile
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS customer_profiles
            (
                user_id INT UNSIGNED NOT NULL,
                phone VARCHAR(40) NULL,
                birth_date DATE NULL,
                show_adult TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function getForUser($userId)
    {
        self::ensureSchema();
        $userId = (int) $userId;

        if ($userId <= 0) {
            return [
                'phone' => '',
                'birth_date' => null,
                'show_adult' => 0
            ];
        }

        $stmt = Database::connect()->prepare("
            SELECT phone, birth_date, show_adult
            FROM customer_profiles
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: [
            'phone' => '',
            'birth_date' => null,
            'show_adult' => 0
        ];
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


    public static function updateAdultPreferences($userId, $birthDate, $showAdult)
    {
        self::ensureSchema();
        $userId = (int) $userId;

        if ($userId <= 0) {
            throw new InvalidArgumentException('Некоректний користувач.');
        }

        $birthDate = self::normalizeBirthDate($birthDate);
        $showAdult = !empty($showAdult);

        if ($showAdult && $birthDate === null) {
            throw new InvalidArgumentException(
                'Щоб увімкнути товари 18+, вкажіть дату народження.'
            );
        }

        if ($showAdult && !self::isAdultBirthDate($birthDate)) {
            throw new InvalidArgumentException(
                'Товари 18+ доступні лише повнолітнім користувачам.'
            );
        }

        $stmt = Database::connect()->prepare("
            INSERT INTO customer_profiles
                (user_id, birth_date, show_adult)
            VALUES
                (:user_id, :birth_date, :show_adult)
            ON DUPLICATE KEY UPDATE
                birth_date = VALUES(birth_date),
                show_adult = VALUES(show_adult)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'birth_date' => $birthDate,
            'show_adult' => $showAdult ? 1 : 0
        ]);

        return [
            'birth_date' => $birthDate,
            'show_adult' => $showAdult ? 1 : 0,
            'is_adult' => self::isAdultBirthDate($birthDate)
        ];
    }


    public static function canShowAdultForUser($userId)
    {
        $profile = self::getForUser((int) $userId);

        return !empty($profile['show_adult'])
            && self::isAdultBirthDate($profile['birth_date'] ?? null);
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
}
