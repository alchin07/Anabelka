<?php

class User
{
    /**
     * Найти пользователя по email.
     */
    public static function findByEmail($email)
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
              AND u.is_active = 1

            LIMIT 1
        ";

        $stmt = $db->prepare($sql);

        $stmt->execute([
            'email' => $email
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
