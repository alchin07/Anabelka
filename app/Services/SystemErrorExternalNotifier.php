<?php

class SystemErrorExternalNotifier
{
    private static $config;


    public static function status(array $settings = null)
    {
        $settings = $settings ?? SystemErrorExternalNotificationSettings::get();
        $config = self::config();
        $smtp = is_array($config['smtp'] ?? null) ? $config['smtp'] : [];
        $telegram = is_array($config['telegram'] ?? null) ? $config['telegram'] : [];

        $emailConfigured = trim((string) ($smtp['host'] ?? '')) !== ''
            && (int) ($smtp['port'] ?? 0) > 0
            && filter_var((string) ($smtp['from_email'] ?? ''), FILTER_VALIDATE_EMAIL)
            && filter_var((string) ($settings['email_to'] ?? ''), FILTER_VALIDATE_EMAIL);

        $username = trim((string) ($smtp['username'] ?? ''));
        if ($username !== '' && trim((string) ($smtp['password'] ?? '')) === '') {
            $emailConfigured = false;
        }

        $telegramConfigured = trim((string) ($telegram['bot_token'] ?? '')) !== ''
            && preg_match('/^-?\d{5,30}$/', (string) ($settings['telegram_chat_id'] ?? '')) === 1;

        return [
            'email' => [
                'configured' => (bool) $emailConfigured,
                'selected' => in_array('email', $settings['channels'] ?? [], true),
                'recipient' => trim((string) ($settings['email_to'] ?? ''))
            ],
            'telegram' => [
                'configured' => (bool) $telegramConfigured,
                'selected' => in_array('telegram', $settings['channels'] ?? [], true),
                'chat_id' => trim((string) ($settings['telegram_chat_id'] ?? ''))
            ]
        ];
    }


    public static function sendTest(array $settings = null)
    {
        $settings = $settings ?? SystemErrorExternalNotificationSettings::get();
        $channels = is_array($settings['channels'] ?? null)
            ? $settings['channels']
            : [];

        if (empty($channels)) {
            throw new InvalidArgumentException(
                'Спочатку оберіть і збережіть хоча б один канал для тесту.'
            );
        }

        $status = self::status($settings);
        $now = date('Y-m-d H:i:s');
        $subject = 'Анабелька — тестове системне сповіщення';
        $message = implode("\n", [
            'Це тестове зовнішнє сповіщення від системи «Анабелька».',
            '',
            'Час: ' . $now,
            'Подія: перевірка каналу системних помилок.',
            'Реальної помилки не створено.',
            '',
            'Якщо ви отримали це повідомлення — канал налаштовано правильно.'
        ]);

        $results = [];

        foreach ($channels as $channel) {
            $channel = (string) $channel;

            if (!isset($status[$channel])) {
                continue;
            }

            if (empty($status[$channel]['configured'])) {
                $results[$channel] = [
                    'ok' => false,
                    'message' => self::channelLabel($channel)
                        . ': канал ще не налаштовано.'
                ];
                continue;
            }

            try {
                if ($channel === 'email') {
                    self::sendEmail(
                        (string) ($settings['email_to'] ?? ''),
                        $subject,
                        $message
                    );
                } elseif ($channel === 'telegram') {
                    self::sendTelegram(
                        (string) ($settings['telegram_chat_id'] ?? ''),
                        $subject . "\n\n" . $message
                    );
                }

                $results[$channel] = [
                    'ok' => true,
                    'message' => self::channelLabel($channel)
                        . ': тестове повідомлення відправлено.'
                ];
            } catch (Throwable $e) {
                $results[$channel] = [
                    'ok' => false,
                    'message' => self::channelLabel($channel)
                        . ': ' . self::safeErrorMessage($e->getMessage())
                ];
            }
        }

        return $results;
    }


    public static function sendAutomatic(array $group, array $settings = null)
    {
        $settings = $settings ?? SystemErrorExternalNotificationSettings::get();

        if (!SystemErrorExternalNotificationSettings::shouldNotify($group, $settings)) {
            return [];
        }

        $channels = is_array($settings['channels'] ?? null)
            ? $settings['channels']
            : [];
        $status = self::status($settings);
        $results = [];

        foreach ($channels as $channel) {
            $channel = (string) $channel;

            if (!isset($status[$channel]) || empty($status[$channel]['configured'])) {
                continue;
            }

            if (!self::cooldownPassed($group, $channel, $settings)) {
                $results[$channel] = [
                    'ok' => true,
                    'sent' => false,
                    'reason' => 'cooldown'
                ];
                continue;
            }

            try {
                [$subject, $message] = self::automaticMessage($group);

                if ($channel === 'email') {
                    self::sendEmail(
                        (string) ($settings['email_to'] ?? ''),
                        $subject,
                        $message
                    );
                } elseif ($channel === 'telegram') {
                    self::sendTelegram(
                        (string) ($settings['telegram_chat_id'] ?? ''),
                        $subject . "\n\n" . $message
                    );
                }

                self::markAutomaticSent($group, $channel);

                $results[$channel] = [
                    'ok' => true,
                    'sent' => true
                ];
            } catch (Throwable $e) {
                $results[$channel] = [
                    'ok' => false,
                    'sent' => false,
                    'message' => self::safeErrorMessage($e->getMessage())
                ];
            }
        }

        return $results;
    }


    private static function automaticMessage(array $group)
    {
        $level = strtoupper((string) ($group['level'] ?? 'ERROR'));
        $reference = trim((string) ($group['reference'] ?? ''));
        $repeatCount = max(1, (int) ($group['repeat_count'] ?? 1));
        $time = (string) ($group['last_time'] ?? $group['time'] ?? date('c'));
        $request = is_array($group['request'] ?? null) ? $group['request'] : [];
        $method = strtoupper((string) ($request['method'] ?? ''));
        $uri = (string) ($request['uri'] ?? '');
        $message = trim((string) ($group['message'] ?? ''));
        $file = trim((string) ($group['file'] ?? ''));
        $line = (int) ($group['line'] ?? 0);

        $subject = '[' . $level . '] Анабелька — системна помилка'
            . ($reference !== '' ? ' ' . $reference : '');

        $body = [
            'Добрий день.',
            '',
            'Система моніторингу «Анабелька» зафіксувала системну помилку, яка потребує уваги.',
            '',
            'Рівень: ' . $level,
            'Код помилки: ' . ($reference !== '' ? $reference : 'не вказано'),
            'Кількість повторів: ' . $repeatCount,
            'Останнє спрацювання: ' . $time
        ];

        if ($method !== '' || $uri !== '') {
            $body[] = 'Запит: ' . trim($method . ' ' . $uri);
        }

        if ($message !== '') {
            $body[] = 'Повідомлення: ' . self::shortText($message, 700);
        }

        if ($file !== '') {
            $body[] = 'Файл: ' . $file . ($line > 0 ? ':' . $line : '');
        }

        $body[] = '';
        $body[] = 'Будь ласка, відкрийте журнал «Системні помилки» в адмін-панелі та перевірте подробиці.';
        $body[] = 'Стек викликів і секретні дані в email не надсилаються.';
        $body[] = '';
        $body[] = 'З повагою,';
        $body[] = 'система моніторингу «Анабелька».';

        return [$subject, implode("\n", $body)];
    }


    private static function cooldownPassed(array $group, $channel, array $settings)
    {
        $cooldownMinutes = max(0, (int) ($settings['cooldown_minutes'] ?? 0));

        if ($cooldownMinutes === 0) {
            return true;
        }

        $lastSent = (int) AppSetting::get(
            self::deliveryStateKey($group, $channel),
            '0'
        );

        return $lastSent <= 0 || (time() - $lastSent) >= ($cooldownMinutes * 60);
    }


    private static function markAutomaticSent(array $group, $channel)
    {
        AppSetting::set(
            self::deliveryStateKey($group, $channel),
            (string) time()
        );
    }


    private static function deliveryStateKey(array $group, $channel)
    {
        $groupKey = trim((string) ($group['group_key'] ?? ''));

        if ($groupKey === '') {
            $reference = trim((string) ($group['reference'] ?? 'unknown'));
            $groupKey = 'REF-' . substr(hash('sha256', $reference), 0, 24);
        }

        $safeGroupKey = preg_replace('/[^A-Za-z0-9_-]/', '', $groupKey);
        $safeChannel = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $channel);

        return 'system_error.external.sent.' . $safeGroupKey . '.' . $safeChannel;
    }


    private static function sendEmail($to, $subject, $body)
    {
        $config = self::config();
        $smtp = is_array($config['smtp'] ?? null) ? $config['smtp'] : [];

        $host = trim((string) ($smtp['host'] ?? ''));
        $port = (int) ($smtp['port'] ?? 587);
        $encryption = strtolower(trim((string) ($smtp['encryption'] ?? 'tls')));
        $username = trim((string) ($smtp['username'] ?? ''));
        $password = (string) ($smtp['password'] ?? '');
        $fromEmail = trim((string) ($smtp['from_email'] ?? ''));
        $fromName = trim((string) ($smtp['from_name'] ?? 'Анабелька'));
        $timeout = max(5, min(60, (int) ($smtp['timeout'] ?? 15)));

        if ($host === '' || $port <= 0 || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP не налаштовано.');
        }

        if ($username !== '' && $password === '') {
            throw new RuntimeException('Для SMTP не задано пароль.');
        }

        $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
                'SNI_enabled' => true,
                'peer_name' => $host
            ]
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($socket)) {
            throw new RuntimeException('Не вдалося підключитися до SMTP-сервера.');
        }

        stream_set_timeout($socket, $timeout);

        try {
            self::expectSmtp($socket, [220]);
            self::smtpCommand($socket, 'EHLO anabelka.local', [250]);

            if ($encryption === 'tls') {
                self::smtpCommand($socket, 'STARTTLS', [220]);

                $cryptoOk = @stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

                if ($cryptoOk !== true) {
                    throw new RuntimeException('Не вдалося увімкнути TLS для SMTP.');
                }

                self::smtpCommand($socket, 'EHLO anabelka.local', [250]);
            }

            if ($username !== '') {
                self::smtpCommand($socket, 'AUTH LOGIN', [334]);
                self::smtpCommand($socket, base64_encode($username), [334]);
                self::smtpCommand($socket, base64_encode($password), [235]);
            }

            self::smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            self::smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            self::smtpCommand($socket, 'DATA', [354]);

            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
            $normalizedBody = str_replace(["\r\n", "\r"], "\n", (string) $body);
            $lines = explode("\n", $normalizedBody);
            foreach ($lines as &$line) {
                if (isset($line[0]) && $line[0] === '.') {
                    $line = '.' . $line;
                }
            }
            unset($line);

            $payload = implode("\r\n", [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
                'To: <' . $to . '>',
                'Subject: ' . $encodedSubject,
                'MIME-Version: 1.0',
                'Content-Type: text/plain; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
                '',
                implode("\r\n", $lines),
                '.'
            ]) . "\r\n";

            if (@fwrite($socket, $payload) === false) {
                throw new RuntimeException('Не вдалося передати лист SMTP-серверу.');
            }

            self::expectSmtp($socket, [250]);
            self::smtpCommand($socket, 'QUIT', [221, 250]);
        } finally {
            fclose($socket);
        }
    }


    private static function sendTelegram($chatId, $text)
    {
        $config = self::config();
        $telegram = is_array($config['telegram'] ?? null) ? $config['telegram'] : [];
        $token = trim((string) ($telegram['bot_token'] ?? ''));
        $apiBase = rtrim((string) ($telegram['api_base'] ?? 'https://api.telegram.org'), '/');
        $timeout = max(5, min(60, (int) ($telegram['timeout'] ?? 15)));

        if ($token === '') {
            throw new RuntimeException('Telegram-бот не налаштований.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('У PHP недоступний cURL для Telegram.');
        }

        $url = $apiBase . '/bot' . $token . '/sendMessage';
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => 'true'
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new RuntimeException('Не вдалося підключитися до Telegram API.');
        }

        $decoded = json_decode((string) $response, true);

        if ($httpCode < 200 || $httpCode >= 300 || empty($decoded['ok'])) {
            throw new RuntimeException('Telegram API відхилив тестове повідомлення.');
        }
    }


    private static function smtpCommand($socket, $command, array $expectedCodes)
    {
        if (@fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('Не вдалося передати команду SMTP-серверу.');
        }

        self::expectSmtp($socket, $expectedCodes);
    }


    private static function expectSmtp($socket, array $expectedCodes)
    {
        $response = '';
        $code = 0;

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            if (preg_match('/^(\d{3})([ -])/', $line, $matches)) {
                $code = (int) $matches[1];
                if ($matches[2] === ' ') {
                    break;
                }
            }
        }

        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException(
                'SMTP-сервер повернув код ' . ($code > 0 ? $code : 'невідомий') . '.'
            );
        }
    }


    private static function config()
    {
        if (is_array(self::$config)) {
            return self::$config;
        }

        $path = dirname(__DIR__, 2) . '/config/error-notifications.php';
        $config = is_file($path) ? require $path : [];

        self::$config = is_array($config) ? $config : [];
        return self::$config;
    }


    private static function shortText($value, $limit)
    {
        $value = trim((string) $value);
        $limit = max(1, (int) $limit);

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value, 'UTF-8') > $limit
                ? mb_substr($value, 0, $limit, 'UTF-8') . '…'
                : $value;
        }

        return strlen($value) > $limit
            ? substr($value, 0, $limit) . '…'
            : $value;
    }


    private static function safeErrorMessage($message)
    {
        $message = trim((string) $message);

        if ($message === '') {
            return 'не вдалося відправити тестове повідомлення.';
        }

        return self::shortText($message, 300);
    }


    private static function channelLabel($channel)
    {
        return $channel === 'telegram' ? 'Telegram' : 'Email';
    }
}
