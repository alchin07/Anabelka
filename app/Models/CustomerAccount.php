<?php

class CustomerAccount
{
    private const CSRF_KEY = 'customer_account_csrf';


    public static function current()
    {
        $userId = self::currentId();

        if ($userId <= 0) {
            return null;
        }

        $stmt = Database::connect()->prepare("
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
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['is_active'])) {
            self::clearSession();
            return null;
        }

        $profile = CustomerProfile::getForUser($userId);
        $birthDate = $profile['birth_date'] ?? null;
        $knownUnderage = CustomerProfile::isKnownUnderageBirthDate($birthDate);

        $user['phone'] = (string) ($profile['phone'] ?? '');
        $user['birth_date'] = $birthDate;
        $user['show_adult'] = (int) ($profile['show_adult'] ?? 0);
        $user['adult_confirmed_at'] = $profile['adult_confirmed_at'] ?? null;
        $user['is_adult'] = $birthDate !== null
            && CustomerProfile::isAdultBirthDate($birthDate);
        $user['is_known_underage'] = $knownUnderage ? 1 : 0;
        $user['adult_confirmed'] = !$knownUnderage
            && !empty($profile['adult_confirmed_at'])
            ? 1
            : 0;
        $user['automatic_adult_access'] = !$knownUnderage
            && AdultAccess::hasAutomaticRankAccessForUser($user)
            ? 1
            : 0;
        $user['adult_section_access'] = !empty($user['adult_confirmed'])
            || !empty($user['automatic_adult_access'])
            ? 1
            : 0;

        $_SESSION['user_name'] = (string) ($user['name'] ?? '');
        $_SESSION['user_rank_slug'] = (string) ($user['rank_slug'] ?? '');

        return $user;
    }


    public static function currentId()
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }


    public static function csrfToken()
    {
        if (empty($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(24));
        }

        return (string) $_SESSION[self::CSRF_KEY];
    }


    public static function verifyCsrf($token)
    {
        $stored = (string) ($_SESSION[self::CSRF_KEY] ?? '');
        $token = (string) $token;

        return $stored !== ''
            && $token !== ''
            && hash_equals($stored, $token);
    }


    public static function updateIdentity($name, $email, $phone, $currentPassword)
    {
        $user = self::currentWithPassword();
        $name = self::normalizeName($name);
        $email = self::normalizeEmail($email);
        $phone = CustomerProfile::normalizePhone($phone);
        $oldEmail = strtolower(trim((string) ($user['email'] ?? '')));

        if (!password_verify((string) $currentPassword, (string) $user['password'])) {
            throw new RuntimeException('Поточний пароль введено неправильно.');
        }

        $db = Database::connect();

        // DDL у MySQL виконує implicit COMMIT, тому залежні таблиці
        // потрібно підготувати до початку транзакції оновлення даних.
        CustomerProfile::ensureSchema();
        CustomerEmailVerification::ensureSchema();

        $duplicate = $db->prepare("
            SELECT id
            FROM users
            WHERE email = :email
              AND id <> :id
            LIMIT 1
        ");
        $duplicate->execute([
            'email' => $email,
            'id' => (int) $user['id']
        ]);

        if ($duplicate->fetchColumn()) {
            throw new RuntimeException('Користувач із таким email уже існує.');
        }

        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                UPDATE users
                SET name = :name,
                    email = :email
                WHERE id = :id
            ");
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'id' => (int) $user['id']
            ]);

            CustomerProfile::updatePhone((int) $user['id'], $phone);

            if ($oldEmail !== $email) {
                CustomerEmailVerification::invalidateForEmailChange(
                    (int) $user['id'],
                    $oldEmail,
                    $email
                );
            }

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        $_SESSION['user_name'] = $name;

        return self::current();
    }


    public static function updateAdultPreferences(
        $birthDate,
        $adultConfirmed,
        $showAdult,
        $currentPassword
    ) {
        $user = self::currentWithPassword();

        if (!password_verify((string) $currentPassword, (string) $user['password'])) {
            throw new RuntimeException('Поточний пароль введено неправильно.');
        }

        CustomerProfile::ensureSchema();
        $current = self::current();
        $hasAutomaticAccess = is_array($current)
            && AdultAccess::hasAutomaticRankAccessForUser($current);

        return CustomerProfile::updateAdultPreferences(
            (int) $user['id'],
            $birthDate,
            $adultConfirmed,
            $showAdult,
            $hasAutomaticAccess
        );
    }


    public static function changePassword($currentPassword, $newPassword, $confirmation)
    {
        $user = self::currentWithPassword();

        if (!password_verify((string) $currentPassword, (string) $user['password'])) {
            throw new RuntimeException('Поточний пароль введено неправильно.');
        }

        $newPassword = (string) $newPassword;
        $confirmation = (string) $confirmation;
        PasswordPolicy::validate($newPassword);

        if (!hash_equals($newPassword, $confirmation)) {
            throw new RuntimeException('Новий пароль і підтвердження не збігаються.');
        }

        if (password_verify($newPassword, (string) $user['password'])) {
            throw new RuntimeException('Новий пароль має відрізнятися від поточного.');
        }

        $stmt = Database::connect()->prepare("
            UPDATE users
            SET password = :password
            WHERE id = :id
        ");
        $stmt->execute([
            'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => (int) $user['id']
        ]);

        session_regenerate_id(true);
    }


    public static function startSession(array $user)
    {
        $userId = (int) ($user['id'] ?? 0);

        if ($userId <= 0 || empty($user['is_active'])) {
            throw new RuntimeException('Не вдалося відкрити сесію користувача.');
        }

        session_regenerate_id(true);
        AdultAccess::clearConfirmation();
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = (string) ($user['name'] ?? '');
        $_SESSION['user_rank_slug'] = (string) ($user['rank_slug'] ?? '');
    }


    public static function clearSession()
    {
        unset(
            $_SESSION['user_id'],
            $_SESSION['user_name'],
            $_SESSION['user_rank_slug']
        );

        AdultAccess::clearConfirmation();
    }


    public static function validateRegistrationPassword($password)
    {
        PasswordPolicy::validate($password);
    }


    private static function currentWithPassword()
    {
        $userId = self::currentId();

        if ($userId <= 0) {
            throw new RuntimeException('Сесію користувача не знайдено.');
        }

        $stmt = Database::connect()->prepare("
            SELECT id, name, email, password, is_active
            FROM users
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || empty($user['is_active'])) {
            self::clearSession();
            throw new RuntimeException('Акаунт користувача недоступний.');
        }

        return $user;
    }


    private static function normalizeName($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException('Вкажіть ім’я.');
        }

        $length = function_exists('mb_strlen')
            ? mb_strlen($name, 'UTF-8')
            : strlen($name);

        if ($length > 120) {
            throw new InvalidArgumentException('Ім’я занадто довге.');
        }

        return $name;
    }


    private static function normalizeEmail($email)
    {
        $email = strtolower(trim((string) $email));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Вкажіть коректний email.');
        }

        return $email;
    }
}
