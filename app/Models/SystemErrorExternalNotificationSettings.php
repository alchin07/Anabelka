<?php

class SystemErrorExternalNotificationSettings
{
    private const PREFIX = 'system_error.external.';

    private const ALLOWED_LEVELS = [
        'critical',
        'error',
        'warning'
    ];

    private const ALLOWED_CHANNELS = [
        'email',
        'telegram'
    ];


    public static function get()
    {
        $levels = self::csv(
            AppSetting::get(self::PREFIX . 'levels', 'critical,error')
        );
        $channels = self::csv(
            AppSetting::get(self::PREFIX . 'channels', '')
        );

        $levels = array_values(array_intersect(
            self::ALLOWED_LEVELS,
            $levels
        ));
        $channels = array_values(array_intersect(
            self::ALLOWED_CHANNELS,
            $channels
        ));

        return [
            'enabled' => self::boolValue(
                AppSetting::get(self::PREFIX . 'enabled', '0')
            ),
            'levels' => $levels,
            'channels' => $channels,
            'repeat_threshold' => self::intRange(
                AppSetting::get(self::PREFIX . 'repeat_threshold', '3'),
                1,
                50,
                3
            ),
            'cooldown_minutes' => self::intRange(
                AppSetting::get(self::PREFIX . 'cooldown_minutes', '30'),
                0,
                1440,
                30
            ),
            'critical_immediate' => self::boolValue(
                AppSetting::get(self::PREFIX . 'critical_immediate', '1')
            ),
            'email_to' => trim((string) AppSetting::get(
                self::PREFIX . 'email_to',
                ''
            )),
            'telegram_chat_id' => trim((string) AppSetting::get(
                self::PREFIX . 'telegram_chat_id',
                ''
            ))
        ];
    }


    public static function save(array $input)
    {
        $enabled = !empty($input['enabled']);
        $criticalImmediate = !empty($input['critical_immediate']);

        $levels = is_array($input['levels'] ?? null)
            ? $input['levels']
            : [];
        $levels = array_values(array_intersect(
            self::ALLOWED_LEVELS,
            array_map('strval', $levels)
        ));

        $channels = is_array($input['channels'] ?? null)
            ? $input['channels']
            : [];
        $channels = array_values(array_intersect(
            self::ALLOWED_CHANNELS,
            array_map('strval', $channels)
        ));

        $repeatThreshold = self::intRange(
            $input['repeat_threshold'] ?? 3,
            1,
            50,
            3
        );
        $cooldownMinutes = self::intRange(
            $input['cooldown_minutes'] ?? 30,
            0,
            1440,
            30
        );

        $emailTo = trim((string) ($input['email_to'] ?? ''));
        $telegramChatId = trim((string) ($input['telegram_chat_id'] ?? ''));

        if ($enabled && empty($levels)) {
            throw new InvalidArgumentException(
                'Оберіть хоча б один рівень помилок для зовнішніх сповіщень.'
            );
        }

        if ($enabled && empty($channels)) {
            throw new InvalidArgumentException(
                'Оберіть хоча б один канал зовнішніх сповіщень.'
            );
        }

        if (in_array('email', $channels, true)) {
            if ($emailTo === '' || !filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(
                    'Вкажіть коректну email-адресу для сповіщень.'
                );
            }
        }

        if (in_array('telegram', $channels, true)) {
            if ($telegramChatId === '' || !preg_match('/^-?\d{5,30}$/', $telegramChatId)) {
                throw new InvalidArgumentException(
                    'Вкажіть коректний Telegram Chat ID.'
                );
            }
        }

        AppSetting::set(self::PREFIX . 'enabled', $enabled ? '1' : '0');
        AppSetting::set(self::PREFIX . 'levels', implode(',', $levels));
        AppSetting::set(self::PREFIX . 'channels', implode(',', $channels));
        AppSetting::set(
            self::PREFIX . 'repeat_threshold',
            (string) $repeatThreshold
        );
        AppSetting::set(
            self::PREFIX . 'cooldown_minutes',
            (string) $cooldownMinutes
        );
        AppSetting::set(
            self::PREFIX . 'critical_immediate',
            $criticalImmediate ? '1' : '0'
        );
        AppSetting::set(self::PREFIX . 'email_to', $emailTo);
        AppSetting::set(self::PREFIX . 'telegram_chat_id', $telegramChatId);

        return self::get();
    }


    public static function shouldNotify(array $group, array $settings = null)
    {
        $settings = $settings ?? self::get();

        if (empty($settings['enabled'])) {
            return false;
        }

        $level = strtolower((string) ($group['level'] ?? ''));

        if (!in_array($level, $settings['levels'] ?? [], true)) {
            return false;
        }

        if ($level === 'critical' && !empty($settings['critical_immediate'])) {
            return true;
        }

        $repeatCount = max(1, (int) ($group['repeat_count'] ?? 1));

        return $repeatCount >= max(
            1,
            (int) ($settings['repeat_threshold'] ?? 1)
        );
    }


    private static function csv($value)
    {
        $parts = array_map('trim', explode(',', (string) $value));
        return array_values(array_filter($parts, static function ($value) {
            return $value !== '';
        }));
    }


    private static function boolValue($value)
    {
        return in_array(
            strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }


    private static function intRange($value, $min, $max, $default)
    {
        if (!is_numeric($value)) {
            return (int) $default;
        }

        $value = (int) $value;

        if ($value < $min || $value > $max) {
            return (int) $default;
        }

        return $value;
    }
}
