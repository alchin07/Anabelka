<?php

/**
 * Configure the shared PHP session before session_start().
 * The admin-only inactivity and absolute timeouts live in AdminAccess.
 */
final class SessionSecurity
{
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        // PHP garbage collection is not an authentication timeout.
        // Allow an admin session to remain stored for the full idle window.
        $gcLifetime = (int) ini_get('session.gc_maxlifetime');
        if ($gcLifetime < 7200) {
            ini_set('session.gc_maxlifetime', '7200');
        }

        $cookie = session_get_cookie_params();
        $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
        $isHttps = in_array($https, ['on', '1', 'true'], true);

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string) ($cookie['path'] ?? '/'),
            'domain' => (string) ($cookie['domain'] ?? ''),
            'secure' => $isHttps || !empty($cookie['secure']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        session_start();
    }
}
