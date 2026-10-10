<?php

class VipPriceProtection
{
    private static $schemaReady = false;
    private static $currentAccessResolved = false;
    private static $currentAccess = null;
    private static $requestMarks = [];


    public static function forVisiblePrices(
        $productId,
        array $prices,
        $surface = 'product'
    ) {
        $productId = (int) $productId;
        $access = self::currentAccess();

        if ($productId <= 0 || !$access) {
            return [];
        }

        $marks = [];

        foreach ($prices as $priceItem) {
            if (
                !is_array($priceItem)
                || !self::isVipPrice($priceItem)
            ) {
                continue;
            }

            $priceLevel = (int) ($priceItem['level'] ?? 0);

            if (
                $priceLevel <= 0
                || (int) $access['rank_level'] < $priceLevel
            ) {
                continue;
            }

            $rankId = (int) ($priceItem['rank_id'] ?? 0);

            if ($rankId <= 0) {
                continue;
            }

            $marks[$rankId] = self::mark(
                $access,
                $productId,
                $rankId,
                (float) ($priceItem['price'] ?? 0),
                $surface
            );
        }

        return $marks;
    }


    public static function isVipPrice(array $priceItem)
    {
        $slug = strtolower(trim((string) ($priceItem['rank_slug'] ?? '')));

        if ($slug === 'vip' || strpos($slug, 'vip-') === 0) {
            return true;
        }

        $name = trim((string) ($priceItem['rank_name'] ?? ''));
        $name = function_exists('mb_strtolower')
            ? mb_strtolower($name, 'UTF-8')
            : strtolower($name);

        return $name === 'vip';
    }


    private static function currentAccess()
    {
        if (self::$currentAccessResolved) {
            return self::$currentAccess;
        }

        self::$currentAccessResolved = true;
        $userId = (int) ($_SESSION['user_id'] ?? 0);

        if ($userId <= 0) {
            self::$currentAccess = null;
            return null;
        }

        try {
            $stmt = Database::connect()->prepare("
                SELECT
                    u.id,
                    u.name,
                    ur.id AS rank_id,
                    ur.slug AS rank_slug,
                    ur.level AS rank_level
                FROM users AS u
                INNER JOIN user_ranks AS ur
                    ON ur.id = u.rank_id
                WHERE u.id = :user_id
                  AND u.is_active = 1
                  AND ur.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([
                'user_id' => $userId
            ]);

            $access = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$access) {
                self::$currentAccess = null;
                return null;
            }

            self::$currentAccess = $access;

            return self::$currentAccess;
        } catch (Throwable $e) {
            error_log(
                'VIP price access check error: ' . $e->getMessage()
            );
            self::$currentAccess = null;

            return null;
        }
    }


    private static function mark(
        array $access,
        $productId,
        $rankId,
        $price,
        $surface
    ) {
        $userId = (int) ($access['id'] ?? 0);
        $surface = self::normalizeSurface($surface);
        $cacheKey = implode(':', [
            $userId,
            (int) $productId,
            (int) $rankId,
            $surface
        ]);

        if (isset(self::$requestMarks[$cacheKey])) {
            return self::$requestMarks[$cacheKey];
        }

        $viewCode = strtoupper(bin2hex(random_bytes(3)));
        $viewedAt = date('Y-m-d H:i:s');
        $displayTime = date('d.m.Y H:i');
        $shortUserId = 'U' . strtoupper(
            base_convert((string) max(0, $userId), 10, 36)
        );
        $accountName = trim((string) ($access['name'] ?? ''));

        if ($accountName === '') {
            $accountName = $shortUserId;
        }

        $mark = [
            'label' => $accountName
                . ' · '
                . $shortUserId
                . ' · '
                . $displayTime
                . ' · '
                . $viewCode,
            'view_code' => $viewCode,
            'viewed_at' => $viewedAt
        ];

        self::$requestMarks[$cacheKey] = $mark;
        self::record(
            $userId,
            (int) $productId,
            (int) $rankId,
            (float) $price,
            $surface,
            $viewCode,
            $viewedAt
        );

        return $mark;
    }


    private static function record(
        $userId,
        $productId,
        $rankId,
        $price,
        $surface,
        $viewCode,
        $viewedAt
    ) {
        try {
            self::ensureSchema();

            $sessionId = session_id();
            $sessionHash = $sessionId !== ''
                ? hash('sha256', $sessionId)
                : null;

            $stmt = Database::connect()->prepare("
                INSERT INTO vip_price_view_log
                (
                    user_id,
                    product_id,
                    rank_id,
                    price_amount,
                    surface,
                    view_code,
                    session_hash,
                    viewed_at
                )
                VALUES
                (
                    :user_id,
                    :product_id,
                    :rank_id,
                    :price_amount,
                    :surface,
                    :view_code,
                    :session_hash,
                    :viewed_at
                )
            ");
            $stmt->execute([
                'user_id' => (int) $userId,
                'product_id' => (int) $productId,
                'rank_id' => (int) $rankId,
                'price_amount' => round((float) $price, 2),
                'surface' => (string) $surface,
                'view_code' => (string) $viewCode,
                'session_hash' => $sessionHash,
                'viewed_at' => (string) $viewedAt
            ]);
        } catch (Throwable $e) {
            error_log(
                'VIP price view log error: ' . $e->getMessage()
            );
        }
    }


    private static function ensureSchema()
    {
        if (self::$schemaReady) {
            return;
        }

        Database::connect()->exec("
            CREATE TABLE IF NOT EXISTS vip_price_view_log
            (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id INT UNSIGNED NOT NULL,
                product_id INT UNSIGNED NOT NULL,
                rank_id INT UNSIGNED NOT NULL,
                price_amount DECIMAL(12,2) NOT NULL,
                surface VARCHAR(40) NOT NULL,
                view_code CHAR(6) NOT NULL,
                session_hash CHAR(64) NULL,
                viewed_at DATETIME NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_vip_price_user_date (user_id, viewed_at),
                KEY idx_vip_price_product_date (product_id, viewed_at),
                KEY idx_vip_price_code (view_code)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        ");

        self::$schemaReady = true;
    }


    private static function normalizeSurface($surface)
    {
        $surface = strtolower(trim((string) $surface));
        $surface = preg_replace('/[^a-z0-9_-]+/', '-', $surface);
        $surface = trim((string) $surface, '-');

        return $surface !== ''
            ? substr($surface, 0, 40)
            : 'product';
    }
}
