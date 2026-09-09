<?php

class CustomerAddress
{
    private static $schemaReady = false;


    public static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS customer_addresses
            (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                label VARCHAR(80) NOT NULL,
                country VARCHAR(120) NOT NULL,
                city VARCHAR(120) NOT NULL,
                address VARCHAR(255) NOT NULL,
                postcode VARCHAR(30) NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_customer_addresses_user (user_id, is_default, id)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    public static function allForUser($userId)
    {
        self::ensureSchema();
        $stmt = Database::connect()->prepare("
            SELECT id, label, country, city, address, postcode, is_default
            FROM customer_addresses
            WHERE user_id = :user_id
            ORDER BY is_default DESC, id ASC
        ");
        $stmt->execute(['user_id' => (int) $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    public static function defaultForUser($userId)
    {
        self::ensureSchema();
        $stmt = Database::connect()->prepare("
            SELECT id, label, country, city, address, postcode, is_default
            FROM customer_addresses
            WHERE user_id = :user_id
            ORDER BY is_default DESC, id ASC
            LIMIT 1
        ");
        $stmt->execute(['user_id' => (int) $userId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }


    public static function create($userId, array $data)
    {
        self::ensureSchema();
        $userId = (int) $userId;

        if ($userId <= 0) {
            throw new InvalidArgumentException('Некоректний користувач.');
        }

        $data = self::normalize($data);
        $db = Database::connect();
        $countStmt = $db->prepare("
            SELECT COUNT(*)
            FROM customer_addresses
            WHERE user_id = :user_id
        ");
        $countStmt->execute(['user_id' => $userId]);
        $makeDefault = !empty($data['is_default'])
            || (int) $countStmt->fetchColumn() === 0;

        $db->beginTransaction();

        try {
            if ($makeDefault) {
                self::clearDefault($db, $userId);
            }

            $stmt = $db->prepare("
                INSERT INTO customer_addresses
                    (user_id, label, country, city, address, postcode, is_default)
                VALUES
                    (:user_id, :label, :country, :city, :address, :postcode, :is_default)
            ");
            $stmt->execute([
                'user_id' => $userId,
                'label' => $data['label'],
                'country' => $data['country'],
                'city' => $data['city'],
                'address' => $data['address'],
                'postcode' => $data['postcode'] !== '' ? $data['postcode'] : null,
                'is_default' => $makeDefault ? 1 : 0
            ]);
            $id = (int) $db->lastInsertId();
            $db->commit();

            return $id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    public static function update($userId, $addressId, array $data)
    {
        self::ensureSchema();
        $userId = (int) $userId;
        $addressId = (int) $addressId;
        $data = self::normalize($data);
        self::requireOwned($userId, $addressId);
        $db = Database::connect();

        $db->beginTransaction();

        try {
            if (!empty($data['is_default'])) {
                self::clearDefault($db, $userId);
            }

            $stmt = $db->prepare("
                UPDATE customer_addresses
                SET label = :label,
                    country = :country,
                    city = :city,
                    address = :address,
                    postcode = :postcode,
                    is_default = CASE
                        WHEN :make_default = 1 THEN 1
                        ELSE is_default
                    END
                WHERE id = :id
                  AND user_id = :user_id
            ");
            $stmt->execute([
                'label' => $data['label'],
                'country' => $data['country'],
                'city' => $data['city'],
                'address' => $data['address'],
                'postcode' => $data['postcode'] !== '' ? $data['postcode'] : null,
                'make_default' => !empty($data['is_default']) ? 1 : 0,
                'id' => $addressId,
                'user_id' => $userId
            ]);

            self::ensureOneDefault($db, $userId);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    public static function setDefault($userId, $addressId)
    {
        self::ensureSchema();
        $userId = (int) $userId;
        $addressId = (int) $addressId;
        self::requireOwned($userId, $addressId);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            self::clearDefault($db, $userId);
            $stmt = $db->prepare("
                UPDATE customer_addresses
                SET is_default = 1
                WHERE id = :id
                  AND user_id = :user_id
            ");
            $stmt->execute([
                'id' => $addressId,
                'user_id' => $userId
            ]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    public static function delete($userId, $addressId)
    {
        self::ensureSchema();
        $userId = (int) $userId;
        $addressId = (int) $addressId;
        self::requireOwned($userId, $addressId);
        $db = Database::connect();
        $db->beginTransaction();

        try {
            $stmt = $db->prepare("
                DELETE FROM customer_addresses
                WHERE id = :id
                  AND user_id = :user_id
            ");
            $stmt->execute([
                'id' => $addressId,
                'user_id' => $userId
            ]);
            self::ensureOneDefault($db, $userId);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    private static function normalize(array $data)
    {
        $result = [
            'label' => trim((string) ($data['label'] ?? '')),
            'country' => trim((string) ($data['country'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'address' => trim((string) ($data['address'] ?? '')),
            'postcode' => trim((string) ($data['postcode'] ?? '')),
            'is_default' => !empty($data['is_default'])
        ];

        if ($result['label'] === '') {
            $result['label'] = 'Основна адреса';
        }

        if ($result['country'] === '' || $result['city'] === '' || $result['address'] === '') {
            throw new InvalidArgumentException('Заповніть країну, місто та адресу.');
        }

        $limits = [
            'label' => 80,
            'country' => 120,
            'city' => 120,
            'address' => 255,
            'postcode' => 30
        ];

        foreach ($limits as $key => $limit) {
            $length = function_exists('mb_strlen')
                ? mb_strlen($result[$key], 'UTF-8')
                : strlen($result[$key]);

            if ($length > $limit) {
                throw new InvalidArgumentException('Одне з полів адреси занадто довге.');
            }
        }

        return $result;
    }


    private static function requireOwned($userId, $addressId)
    {
        if ($userId <= 0 || $addressId <= 0) {
            throw new InvalidArgumentException('Некоректна адреса.');
        }

        $stmt = Database::connect()->prepare("
            SELECT id
            FROM customer_addresses
            WHERE id = :id
              AND user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            'id' => $addressId,
            'user_id' => $userId
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Адресу не знайдено.');
        }
    }


    private static function clearDefault(PDO $db, $userId)
    {
        $stmt = $db->prepare("
            UPDATE customer_addresses
            SET is_default = 0
            WHERE user_id = :user_id
        ");
        $stmt->execute(['user_id' => (int) $userId]);
    }


    private static function ensureOneDefault(PDO $db, $userId)
    {
        $stmt = $db->prepare("
            SELECT id
            FROM customer_addresses
            WHERE user_id = :user_id
              AND is_default = 1
            LIMIT 1
        ");
        $stmt->execute(['user_id' => (int) $userId]);

        if ($stmt->fetchColumn()) {
            return;
        }

        $first = $db->prepare("
            SELECT id
            FROM customer_addresses
            WHERE user_id = :user_id
            ORDER BY id ASC
            LIMIT 1
        ");
        $first->execute(['user_id' => (int) $userId]);
        $firstId = (int) $first->fetchColumn();

        if ($firstId <= 0) {
            return;
        }

        $update = $db->prepare("
            UPDATE customer_addresses
            SET is_default = 1
            WHERE id = :id
        ");
        $update->execute(['id' => $firstId]);
    }
}
