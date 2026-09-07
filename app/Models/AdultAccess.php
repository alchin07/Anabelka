<?php

class AdultAccess
{
    private const SESSION_KEY = 'adult_age_confirmed_at';


    public static function isConfirmed()
    {
        return !empty($_SESSION[self::SESSION_KEY]);
    }


    public static function confirm()
    {
        $_SESSION[self::SESSION_KEY] = time();
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
