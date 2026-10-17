<?php

require_once __DIR__ . '/AnabelkaFlash.php';

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

        $tags = self::htmlTags($html);
        $hasHtml = false;
        $hasHead = false;
        $headEnd = null;
        $bodyStart = null;
        $hasStylesheet = false;
        $hasScript = false;
        $hasHost = false;
        $hasFlash = false;

        foreach ($tags as $tag) {
            if ($tag['closing']) {
                if ($tag['name'] === 'head' && $headEnd === null) {
                    $headEnd = $tag['offset'];
                }
                continue;
            }

            $attributes = $tag['attributes'];
            $hasHtml = $hasHtml || $tag['name'] === 'html';
            $hasHead = $hasHead || $tag['name'] === 'head';
            if ($tag['name'] === 'body' && $bodyStart === null) {
                $bodyStart = $tag['offset'] + $tag['length'];
            }
            $hasHost = $hasHost || ($attributes['id'] ?? '') === 'site-message';
            $hasFlash = $hasFlash || ($attributes['id'] ?? '') === 'anabelka-flash';
            if ($tag['name'] === 'script'
                && self::isExecutableScript($attributes)
                && self::isAsset($attributes['src'] ?? '', 'js/anabelka-notify.js')) {
                $hasScript = true;
            }
            if ($tag['name'] === 'link'
                && !array_key_exists('disabled', $attributes)
                && preg_match('/(?:^|\s)stylesheet(?:\s|$)/i', $attributes['rel'] ?? '')
                && self::isAsset($attributes['href'] ?? '', 'css/anabelka-notify.css')) {
                $hasStylesheet = true;
            }
        }

        if (!$hasHtml || !$hasHead || $headEnd === null || $bodyStart === null) {
            return $html;
        }

        $assets = '';

        if (!$hasStylesheet) {
            $assets .= "\n<link rel=\"stylesheet\" href=\"/Anabelka/css/anabelka-notify.css?v=3\">";
        }

        if (!$hasScript) {
            $assets .= "\n<script src=\"/Anabelka/js/anabelka-notify.js?v=3\"></script>";
        }

        $bodyContent = '';
        if (!$hasHost) {
            $bodyContent .= "\n<div id=\"site-message\" class=\"site-message anabelka-notify\" role=\"status\" aria-live=\"polite\" aria-atomic=\"true\"></div>";
        }

        if (!$hasFlash) {
            $messages = AnabelkaFlash::consume();
            foreach ($messages as &$message) {
                $message['options'] = (object) $message['options'];
            }
            unset($message);
            if ($messages) {
                $json = json_encode(
                    $messages,
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
                );
                $bodyContent .= "\n<script type=\"application/json\" id=\"anabelka-flash\">" . $json . '</script>';
            }
        }

        $insertions = [
            ['offset' => $headEnd, 'content' => $assets === '' ? '' : $assets . "\n"],
            ['offset' => $bodyStart, 'content' => $bodyContent],
        ];
        usort($insertions, function ($left, $right) {
            return $right['offset'] <=> $left['offset'];
        });
        foreach ($insertions as $insertion) {
            $html = substr_replace(
                $html,
                $insertion['content'],
                $insertion['offset'],
                0
            );
        }

        return $html;
    }

    private static function isAsset($url, $asset)
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return false;
        }
        $path = preg_replace('~^(?:\./)+~', '', ltrim($path, '/'));
        return $path === 'Anabelka/' . $asset || $path === $asset;
    }

    private static function isExecutableScript($attributes)
    {
        $type = strtolower($attributes['type'] ?? '');
        if ($type === 'module') {
            return true;
        }
        if (array_key_exists('nomodule', $attributes)) {
            return false;
        }
        if ($type === '') {
            return true;
        }

        // HTML accepts JavaScript MIME essence strings, not MIME parameters.
        // https://mimesniff.spec.whatwg.org/#javascript-mime-type
        return in_array(trim($type, "\t\n\f\r "), [
            'application/ecmascript', 'application/javascript',
            'application/x-ecmascript', 'application/x-javascript',
            'text/ecmascript', 'text/javascript', 'text/javascript1.0',
            'text/javascript1.1', 'text/javascript1.2', 'text/javascript1.3',
            'text/javascript1.4', 'text/javascript1.5', 'text/jscript',
            'text/livescript', 'text/x-ecmascript', 'text/x-javascript',
        ], true);
    }

    private static function htmlTags($html)
    {
        $tags = [];
        $offset = 0;
        $templateDepth = 0;
        $pattern = '~<!--[\s\S]*?-->|<(/?)([a-z][a-z0-9:-]*)\b((?:[^<>"\']|"[^"]*"|\'[^\']*\')*)>~i';
        while (preg_match($pattern, $html, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $offset = $match[0][1] + strlen($match[0][0]);
            if (!isset($match[2]) || $match[2][0] === '') {
                continue;
            }

            $name = strtolower($match[2][0]);
            $closing = $match[1][0] === '/';
            if ($name === 'template') {
                $templateDepth = $closing ? max(0, $templateDepth - 1) : $templateDepth + 1;
                continue;
            }

            if ($templateDepth === 0 && $name !== 'noscript') {
                $attributes = [];
                preg_match_all(
                    '~(?:^|\s)([a-z_:][-a-z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~i',
                    $match[3][0],
                    $attributeMatches,
                    PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
                );
                foreach ($attributeMatches as $attribute) {
                    $key = strtolower($attribute[1]);
                    if (!isset($attributes[$key])) {
                        $attributes[$key] = html_entity_decode($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    }
                }

                $tags[] = [
                    'name' => $name,
                    'closing' => $closing,
                    'attributes' => $attributes,
                    'offset' => $match[0][1],
                    'length' => strlen($match[0][0]),
                ];
            }

            // Browsers treat these elements as raw text; apparent tags inside
            // their content are not resource or message-host elements. Noscript
            // is also raw text when JavaScript is enabled, as required here.
            if (!$closing && in_array($name, ['script', 'style', 'textarea', 'title', 'noscript', 'iframe', 'noembed', 'xmp'], true)) {
                if (!preg_match('~</' . $name . '\s*>~i', $html, $end, PREG_OFFSET_CAPTURE, $offset)) {
                    break;
                }
                $offset = $end[0][1] + strlen($end[0][0]);
            }
        }

        return $tags;
    }
}
