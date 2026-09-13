<?php

final class AnabelkaFlash
{
    private const SESSION_KEY = 'anabelka_flash';
    private const TYPES = ['success', 'info', 'warning', 'error'];

    public static function push($type, $message, $options = [])
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $item = self::normalizeItem([
            'type' => $type,
            'message' => $message,
            'options' => $options
        ]);

        if ($item === null) {
            return false;
        }

        $items = self::itemsFromPayload(
            $_SESSION[self::SESSION_KEY] ?? null
        );
        $signature = self::signature($item);

        foreach ($items as $storedItem) {
            if (self::signature($storedItem) === $signature) {
                return false;
            }
        }

        $items[] = $item;
        $_SESSION[self::SESSION_KEY] = [
            'version' => 1,
            'items' => $items
        ];

        return true;
    }

    public static function success($message, $options = [])
    {
        return self::push('success', $message, $options);
    }

    public static function info($message, $options = [])
    {
        return self::push('info', $message, $options);
    }

    public static function warning($message, $options = [])
    {
        return self::push('warning', $message, $options);
    }

    public static function error($message, $options = [])
    {
        return self::push('error', $message, $options);
    }

    public static function consume()
    {
        if (
            session_status() !== PHP_SESSION_ACTIVE
            || !array_key_exists(self::SESSION_KEY, $_SESSION)
        ) {
            return [];
        }

        $payload = $_SESSION[self::SESSION_KEY];
        unset($_SESSION[self::SESSION_KEY]);

        return self::itemsFromPayload($payload);
    }

    private static function itemsFromPayload($payload)
    {
        if (
            !is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || !is_array($payload['items'] ?? null)
        ) {
            return [];
        }

        $items = [];
        $signatures = [];

        foreach ($payload['items'] as $candidate) {
            $item = self::normalizeItem($candidate);

            if ($item === null) {
                continue;
            }

            $signature = self::signature($item);

            if (isset($signatures[$signature])) {
                continue;
            }

            $signatures[$signature] = true;
            $items[] = $item;
        }

        return $items;
    }

    private static function normalizeItem($item)
    {
        if (!is_array($item)) {
            return null;
        }

        $type = strtolower(trim((string) ($item['type'] ?? '')));
        $messageValue = $item['message'] ?? '';

        if (
            !in_array($type, self::TYPES, true)
            || (!is_scalar($messageValue) && $messageValue !== null)
        ) {
            return null;
        }

        $message = trim((string) $messageValue);

        if ($message === '') {
            return null;
        }

        return [
            'type' => $type,
            'message' => $message,
            'options' => self::normalizeOptions($item['options'] ?? [])
        ];
    }

    private static function normalizeOptions($options)
    {
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];

        if (($options['persistent'] ?? false) === true) {
            $normalized['persistent'] = true;
        }

        if (
            isset($options['duration'])
            && is_numeric($options['duration'])
            && (float) $options['duration'] > 0
        ) {
            $normalized['duration'] = (int) round(
                (float) $options['duration']
            );
        }

        return $normalized;
    }

    private static function signature($item)
    {
        return $item['type'] . "\0" . $item['message'];
    }
}
