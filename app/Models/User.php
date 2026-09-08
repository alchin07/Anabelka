<?php

class User
{
    public static function findByEmail($email)
    {
        $user = self::findByEmailAnyStatus($email);

        if (!$user || empty($user['is_active'])) {
            return false;
        }

        return $user;
    }


    public static function findByEmailAnyStatus($email)
    {
        $db = Database::connect();
        $email = strtolower(trim((string) $email));

        $sql = "
            SELECT
                u.*,
                ur.slug AS rank_slug,
                ur.name AS rank_name
            FROM users u
            INNER JOIN user_ranks ur ON ur.id = u.rank_id
            WHERE u.email = :email
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(['email' => $email]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    public static function findById($userId)
    {
        $stmt = Database::connect()->prepare("
            SELECT
                u.*,
                ur.slug AS rank_slug,
                ur.name AS rank_name
            FROM users u
            INNER JOIN user_ranks ur ON ur.id = u.rank_id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute(['id' => (int) $userId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }


    public static function create($name, $email, $password)
    {
        $db = Database::connect();
        $name = trim((string) $name);
        $email = strtolower(trim((string) $email));
        $passwordHash = password_hash(
            (string) $password,
            PASSWORD_DEFAULT
        );
        $rankId = UserRank::defaultRegistrationRankId();

        $sql = "
            INSERT INTO users
                (rank_id, name, email, password)
            VALUES
                (:rank_id, :name, :email, :password)
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


    public static function rehashPassword($userId, $plainPassword)
    {
        $stmt = Database::connect()->prepare("
            UPDATE users
            SET password = :password
            WHERE id = :id
        ");

        return $stmt->execute([
            'password' => password_hash(
                (string) $plainPassword,
                PASSWORD_DEFAULT
            ),
            'id' => (int) $userId
        ]);
    }
}
