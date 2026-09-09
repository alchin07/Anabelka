<?php

class AdultAccess
{
    private const SESSION_KEY = 'adult_age_confirmed_at';


    public static function isConfirmed()
    {
        return self::canShowAdultContent()
            || !empty($_SESSION[self::SESSION_KEY]);
    }


    public static function canShowAdultContent()
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        try {
            $user = CustomerAccount::current();

            return is_array($user)
                && !empty($user['is_adult'])
                && !empty($user['show_adult']);
        } catch (Throwable $e) {
            return false;
        }
    }


    public static function isKnownUnderage()
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        try {
            $profile = CustomerProfile::getForUser((int) $_SESSION['user_id']);
            $birthDate = trim((string) ($profile['birth_date'] ?? ''));

            return $birthDate !== ''
                && !CustomerProfile::isAdultBirthDate($birthDate);
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

        $_SESSION[self::SESSION_KEY] = time();
    }


    public static function clearConfirmation()
    {
        unset($_SESSION[self::SESSION_KEY]);
    }


    public static function gateUrl($categorySlug, $returnUrl = '')
    {
        $categorySlug = trim((string) $categorySlug);
        $url = '/Anabelka/18-plus/' . rawurlencode($categorySlug);
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
}
