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


    public static function groupItems(array $items, $limit = 120)
    {
        $limit = max(1, min(300, (int) $limit));
        $groups = [];
        $order = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = self::groupKey($item);

            if (!isset($groups[$key])) {
                $group = $item;
                $group['group_key'] = $key;
                $group['repeat_count'] = 0;
                $group['first_time'] = (string) ($item['time'] ?? '');
                $group['last_time'] = (string) ($item['time'] ?? '');
                $group['occurrences'] = [];
                $groups[$key] = $group;
                $order[] = $key;
            }

            $groups[$key]['repeat_count']++;
            $time = (string) ($item['time'] ?? '');

            if ($time !== '') {
                $groups[$key]['first_time'] = $time;
            }

            if (count($groups[$key]['occurrences']) < 10) {
                $groups[$key]['occurrences'][] = [
                    'reference' => (string) ($item['reference'] ?? ''),
                    'time' => $time,
                    'level' => (string) ($item['level'] ?? ''),
                    'log_file' => (string) ($item['log_file'] ?? '')
                ];
            }
        }

        $result = [];

        foreach ($order as $key) {
            $result[] = $groups[$key];

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }


    public static function groupForReference($reference)
    {
        $selected = self::findByReference($reference);

        if (!is_array($selected)) {
            return null;
        }

        $key = self::groupKey($selected);
        $items = self::recent([], 300);
        $matching = [];
        $selectedIncluded = false;

        foreach ($items as $item) {
            if (self::groupKey($item) !== $key) {
                continue;
            }

            if (($item['reference'] ?? '') === ($selected['reference'] ?? '')) {
                $selectedIncluded = true;
            }

            $matching[] = $item;
        }

        if (!$selectedIncluded) {
            $matching[] = $selected;
        }

        if (empty($matching)) {
            return $selected;
        }

        usort($matching, static function ($a, $b) {
            $aTime = strtotime((string) ($a['time'] ?? '')) ?: 0;
            $bTime = strtotime((string) ($b['time'] ?? '')) ?: 0;
            return $bTime <=> $aTime;
        });

        return self::groupItems($matching, 1)[0] ?? $selected;
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
            'info' => 0,
            'events' => 0
        ];

        foreach ($items as $item) {
            $level = strtolower((string) ($item['level'] ?? ''));

            if (array_key_exists($level, $summary)) {
                $summary[$level]++;
            }

            $summary['events'] += max(1, (int) ($item['repeat_count'] ?? 1));
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


    private static function groupKey(array $item)
    {
        $request = is_array($item['request'] ?? null)
            ? $item['request']
            : [];
        $uri = (string) ($request['uri'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            $path = $uri;
        }

        $signature = [
            strtolower((string) ($item['level'] ?? 'error')),
            (string) ($item['kind'] ?? ''),
            (string) ($item['class'] ?? ''),
            (string) ($item['message'] ?? ''),
            (string) ($item['file'] ?? ''),
            (int) ($item['line'] ?? 0),
            strtoupper((string) ($request['method'] ?? '')),
            $path
        ];

        $encoded = json_encode(
            $signature,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return 'SEG-' . substr(hash('sha256', (string) $encoded), 0, 24);
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
