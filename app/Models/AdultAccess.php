<?php

class AdultAccess
{
    private const SESSION_KEY = 'adult_age_confirmed_at';

    private static $vipLevelResolved = false;
    private static $vipLevel = null;


    public static function isConfirmed()
    {
        if (self::isKnownUnderage()) {
            return false;
        }

        if (self::hasAutomaticRankAccess()) {
            return true;
        }

        if (!empty($_SESSION['user_id'])) {
            try {
                if (CustomerProfile::isAdultConfirmedForUser(
                    (int) $_SESSION['user_id']
                )) {
                    return true;
                }
            } catch (Throwable $e) {
                // Падіння профільної перевірки не повинно ламати публічний сайт.
            }
        }

        return !empty($_SESSION[self::SESSION_KEY]);
    }


    public static function canShowAdultContent()
    {
        if (empty($_SESSION['user_id']) || self::isKnownUnderage()) {
            return false;
        }

        try {
            $user = CustomerAccount::current();

            if (!is_array($user)) {
                return false;
            }

            return CustomerProfile::canShowAdultForUser(
                (int) ($user['id'] ?? 0),
                self::hasAutomaticRankAccessForUser($user)
            );
        } catch (Throwable $e) {
            return false;
        }
    }


    public static function hasAutomaticRankAccess()
    {
        if (empty($_SESSION['user_id']) || self::isKnownUnderage()) {
            return false;
        }

        try {
            $user = CustomerAccount::current();

            return is_array($user)
                && self::hasAutomaticRankAccessForUser($user);
        } catch (Throwable $e) {
            return false;
        }
    }


    public static function hasAutomaticRankAccessForUser(array $user)
    {
        $rankLevel = (int) ($user['rank_level'] ?? 0);
        $vipLevel = self::vipThresholdLevel();

        return $rankLevel > 0
            && $vipLevel !== null
            && $rankLevel >= $vipLevel;
    }


    public static function isKnownUnderage()
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        try {
            $profile = CustomerProfile::getForUser((int) $_SESSION['user_id']);

            return CustomerProfile::isKnownUnderageBirthDate(
                $profile['birth_date'] ?? null
            );
        } catch (Throwable $e) {
            return false;
        }
    }


    public static function confirm()
    {
        if (self::isKnownUnderage()) {
            throw new RuntimeException(
                'Розділ 18+ недоступний неповнолітнім користувачам.'
            );
        }

        if (!empty($_SESSION['user_id'])) {
            CustomerProfile::confirmAdultForUser(
                (int) $_SESSION['user_id']
            );
        }

        $_SESSION[self::SESSION_KEY] = time();
    }


    public static function clearConfirmation()
    {
        unset($_SESSION[self::SESSION_KEY]);
    }


    public static function gateUrl(array $category, $returnUrl = '')
    {
        $departmentSlug = trim(
            (string) ($category['department_slug'] ?? '')
        );
        $categorySlug = trim((string) ($category['slug'] ?? ''));
        $url = '/Anabelka/18-plus/'
            . rawurlencode($departmentSlug)
            . '/'
            . rawurlencode($categorySlug);
        $returnUrl = self::safeReturnUrl($returnUrl);

        if ($returnUrl !== '') {
            $url .= '?return=' . rawurlencode($returnUrl);
        }

        return $url;
    }


    public static function safeReturnUrl($url)
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        if (strpos($url, '/Anabelka/') !== 0) {
            return '';
        }

        if (preg_match('#[\r\n]#', $url)) {
            return '';
        }

        return $url;
    }


    private static function vipThresholdLevel()
    {
        if (self::$vipLevelResolved) {
            return self::$vipLevel;
        }

        self::$vipLevelResolved = true;

        try {
            $stmt = Database::connect()->query("
                SELECT level
                FROM user_ranks
                WHERE is_active = 1
                  AND (
                      LOWER(TRIM(slug)) = 'vip'
                      OR LOWER(TRIM(name)) = 'vip'
                  )
                ORDER BY level ASC, id ASC
                LIMIT 1
            ");
            $level = (int) $stmt->fetchColumn();
            self::$vipLevel = $level > 0 ? $level : null;
        } catch (Throwable $e) {
            self::$vipLevel = null;
        }

        return self::$vipLevel;
    }
}
