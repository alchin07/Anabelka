<?php

class AnabelkaFlash
{
    private const SESSION_KEY = 'anabelka_flash_queue';
    private const TYPES = ['success', 'error', 'warning', 'info'];

    public static function success($message, $options = [])
    {
        return self::store('success', $message, $options);
    }

    public static function error($message, $options = [])
    {
        return self::store('error', $message, $options);
    }

    public static function warning($message, $options = [])
    {
        return self::store('warning', $message, $options);
    }

    public static function info($message, $options = [])
    {
        return self::store('info', $message, $options);
    }

    public static function store($type, $message, $options = [])
    {
        $entry = self::normalizeEntry([
            'type' => $type,
            'message' => $message,
            'options' => $options,
        ]);

        if ($entry === null || !self::ensureSession()) {
            return false;
        }

        $queue = self::normalizeQueue($_SESSION[self::SESSION_KEY] ?? []);
        foreach ($queue as $queued) {
            if ($queued['type'] === $entry['type'] && $queued['message'] === $entry['message']) {
                return false;
            }
        }

        $queue[] = $entry;
        $_SESSION[self::SESSION_KEY] = $queue;
        return true;
    }

    public static function consume()
    {
        $wasActive = session_status() === PHP_SESSION_ACTIVE;
        if (!self::ensureSession()) {
            return [];
        }

        $queue = self::normalizeQueue($_SESSION[self::SESSION_KEY] ?? []);
        unset($_SESSION[self::SESSION_KEY]);

        // Output buffering can finish after PHP has already saved the session.
        // Persist the removal when consumption reopened that session.
        if (!$wasActive) {
            session_write_close();
        }

        return $queue;
    }

    private static function ensureSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        if (session_status() === PHP_SESSION_DISABLED || headers_sent()) {
            return false;
        }

        return session_start();
    }

    private static function normalizeQueue($queue)
    {
        if (!is_array($queue)) {
            return [];
        }

        $normalized = [];
        $seen = [];
        foreach ($queue as $value) {
            $entry = self::normalizeEntry($value);
            if ($entry === null) {
                continue;
            }

            $key = $entry['type'] . "\0" . $entry['message'];
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $entry;
        }

        return $normalized;
    }

    private static function normalizeEntry($value)
    {
        if (!is_array($value) || !is_string($value['message'] ?? null)) {
            return null;
        }

        $message = trim($value['message']);
        if ($message === '') {
            return null;
        }

        $type = is_string($value['type'] ?? null) ? strtolower(trim($value['type'])) : 'info';
        if (!in_array($type, self::TYPES, true)) {
            $type = 'info';
        }

        $options = [];
        $duration = is_array($value['options'] ?? null) ? ($value['options']['duration'] ?? null) : null;
        if (is_numeric($duration) && is_finite((float) $duration) && (float) $duration >= 0) {
            $options['duration'] = $duration + 0;
        }

        return ['type' => $type, 'message' => $message, 'options' => $options];
    }
}
