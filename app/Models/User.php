<?php

class User
{
    /**
     * Найти активного пользователя по email.
     */
    public static function findByEmail($email)
    {
        $user = self::findByEmailAnyStatus($email);

        if (!$user || empty($user['is_active'])) {
            return false;
        }

        return $user;
    }


    /**
     * Найти пользователя по email независимо от активности.
     * Нужен для корректного различения отсутствующего и
     * деактивированного аккаунта при входе/регистрации.
     */
    public static function findByEmailAnyStatus($email)
    {
        $db = Database::connect();

        $sql = "
            SELECT
                u.*,
                ur.slug AS rank_slug,
                ur.name AS rank_name
            FROM users u

            INNER JOIN user_ranks ur
                ON ur.id = u.rank_id

            WHERE u.email = :email

            LIMIT 1
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            'email' => trim((string) $email)
        ]);

        return $stmt->fetch();
    }


    /**
     * Создать нового пользователя.
     *
     * Ранг новой регистрации задаётся
     * в админ-панели, а не жёстким ID.
     */
    public static function create($name, $email, $password)
    {
        $db = Database::connect();

        $passwordHash =
            password_hash(
                $password,
                PASSWORD_DEFAULT
            );

        $rankId = UserRank::defaultRegistrationRankId();

        $sql = "
            INSERT INTO users
                (
                    rank_id,
                    name,
                    email,
                    password
                )
            VALUES
                (
                    :rank_id,
                    :name,
                    :email,
                    :password
                )
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            'rank_id' => $rankId,
            'name' => $name,
            'email' => $email,
            'password' => $passwordHash
        ]);

        return (int) $db->lastInsertId();
    }
}
