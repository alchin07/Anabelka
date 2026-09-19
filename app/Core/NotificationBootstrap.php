<?php

class NotificationBootstrap
{
    private static $registered = false;


    public static function register()
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;
        ob_start([self::class, 'inject']);
    }


    public static function inject($html)
    {
        $html = (string) $html;

        if (
            $html === ''
            || stripos($html, '<html') === false
            || stripos($html, '<head') === false
            || stripos($html, '<body') === false
        ) {
            return $html;
        }

        $assets = '';

        if (strpos($html, '/Anabelka/css/anabelka-notify.css?v=2') === false) {
            $assets .= "\n<link rel=\"stylesheet\" href=\"/Anabelka/css/anabelka-notify.css?v=2\">";
        }

        if (strpos($html, '/Anabelka/js/anabelka-notify.js?v=2') === false) {
            $assets .= "\n<script src=\"/Anabelka/js/anabelka-notify.js?v=2\"></script>";
        }

        if ($assets !== '') {
            $html = preg_replace(
                '/<\/head>/i',
                $assets . "\n</head>",
                $html,
                1
            );
        }

        if (strpos($html, 'id="site-message"') === false
            && strpos($html, "id='site-message'") === false) {
            $host = "\n<div id=\"site-message\" class=\"site-message anabelka-notify\" role=\"status\" aria-live=\"polite\" aria-atomic=\"true\"></div>";
            $html = preg_replace(
                '/(<body\b[^>]*>)/i',
                '$1' . $host,
                $html,
                1
            );
        }

        return $html;
    }
}
