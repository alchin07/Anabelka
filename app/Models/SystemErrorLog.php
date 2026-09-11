<?php

class SystemErrorLog
{
    public static function recent(array $filters = [], $limit = 100)
    {
        $limit = max(1, min(300, (int) $limit));
        $items = [];

        foreach (self::logFiles() as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            if (!is_array($lines)) {
                continue;
            }

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                $item = self::decodeLine($lines[$i], $file);

                if (!$item || !self::matches($item, $filters)) {
                    continue;
                }

                $items[] = $item;

                if (count($items) >= $limit) {
                    return $items;
                }
            }
        }

        return $items;
    }


    public static function findByReference($reference)
    {
        $reference = trim((string) $reference);

        if ($reference === '' || !preg_match('/^ERR-[A-Z0-9-]{8,80}$/i', $reference)) {
            return null;
        }

        foreach (self::logFiles() as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            if (!is_array($lines)) {
                continue;
            }

            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (strpos($lines[$i], $reference) === false) {
                    continue;
                }

                $item = self::decodeLine($lines[$i], $file);

                if ($item && ($item['reference'] ?? '') === $reference) {
                    return $item;
                }
            }
        }

        return null;
    }


    public static function summary(array $items)
    {
        $summary = [
            'total' => count($items),
            'critical' => 0,
            'error' => 0,
            'warning' => 0,
            'info' => 0
        ];

        foreach ($items as $item) {
            $level = strtolower((string) ($item['level'] ?? ''));

            if (array_key_exists($level, $summary)) {
                $summary[$level]++;
            }
        }

        return $summary;
    }


    public static function availableDates()
    {
        $dates = [];

        foreach (self::logFiles() as $file) {
            $name = basename((string) $file);

            if (preg_match('/^app-(\d{4}-\d{2}-\d{2})\.log$/', $name, $match)) {
                $dates[] = $match[1];
            }
        }

        return array_values(array_unique($dates));
    }


    private static function logFiles()
    {
        $files = glob(self::directory() . '/app-*.log');

        if (!is_array($files)) {
            return [];
        }

        rsort($files, SORT_STRING);

        return $files;
    }


    private static function decodeLine($line, $file)
    {
        $decoded = json_decode((string) $line, true);

        if (!is_array($decoded)) {
            return null;
        }

        $request = is_array($decoded['request'] ?? null)
            ? $decoded['request']
            : [];
        $context = is_array($decoded['context'] ?? null)
            ? $decoded['context']
            : [];

        return [
            'time' => (string) ($decoded['time'] ?? ''),
            'reference' => (string) ($decoded['reference'] ?? ''),
            'level' => strtolower((string) ($decoded['level'] ?? 'error')),
            'kind' => (string) ($decoded['kind'] ?? ''),
            'request' => $request,
            'context' => $context,
            'message' => (string) ($context['message'] ?? ''),
            'class' => (string) ($context['class'] ?? ''),
            'file' => (string) ($context['file'] ?? ''),
            'line' => (int) ($context['line'] ?? 0),
            'trace' => (string) ($context['trace'] ?? ''),
            'log_file' => basename((string) $file)
        ];
    }


    private static function matches(array $item, array $filters)
    {
        $level = strtolower(trim((string) ($filters['level'] ?? '')));
        $date = trim((string) ($filters['date'] ?? ''));
        $query = trim((string) ($filters['q'] ?? ''));

        if ($level !== '' && $level !== 'all' && ($item['level'] ?? '') !== $level) {
            return false;
        }

        if ($date !== '') {
            $time = (string) ($item['time'] ?? '');

            if (substr($time, 0, 10) !== $date) {
                return false;
            }
        }

        if ($query !== '') {
            $request = is_array($item['request'] ?? null) ? $item['request'] : [];
            $haystack = implode(' ', [
                (string) ($item['reference'] ?? ''),
                (string) ($item['level'] ?? ''),
                (string) ($item['kind'] ?? ''),
                (string) ($item['message'] ?? ''),
                (string) ($item['class'] ?? ''),
                (string) ($item['file'] ?? ''),
                (string) ($request['uri'] ?? ''),
                (string) ($request['method'] ?? '')
            ]);

            if (function_exists('mb_stripos')) {
                if (mb_stripos($haystack, $query, 0, 'UTF-8') === false) {
                    return false;
                }
            } elseif (stripos($haystack, $query) === false) {
                return false;
            }
        }

        return true;
    }


    private static function directory()
    {
        return dirname(__DIR__, 2) . '/storage/logs';
    }
}
