<?php

class PasswordPolicy
{
    public const MIN_LENGTH = 10;


    public static function validate($password)
    {
        PasswordPolicyInterfaceTranslator::seed();

        $password = (string) $password;
        $length = function_exists('mb_strlen')
            ? mb_strlen($password, 'UTF-8')
            : strlen($password);

        if ($length < self::MIN_LENGTH) {
            throw new InvalidArgumentException(
                Translator::t(
                    'public.auth.error_password_short',
                    'Пароль має містити щонайменше 10 символів.'
                )
            );
        }

        if (self::isObviouslyWeak($password)) {
            throw new InvalidArgumentException(
                Translator::t(
                    'public.password_policy.error_weak',
                    'Цей пароль надто простий. Оберіть довший і менш передбачуваний пароль.'
                )
            );
        }
    }


    public static function minimumLength()
    {
        return self::MIN_LENGTH;
    }


    private static function isObviouslyWeak($password)
    {
        $value = trim((string) $password);
        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);

        if ($value === '') {
            return true;
        }

        // Убираем разделители только для анализа предсказуемости.
        // Сам пароль при этом не изменяется и хранится в исходном виде.
        $compact = preg_replace('/[\s\p{P}\p{S}]+/u', '', $value);
        $compact = is_string($compact) ? $compact : $value;

        $common = [
            '1234567890',
            '0123456789',
            '0987654321',
            '9876543210',
            'qwertyuiop',
            'asdfghjkl',
            'zxcvbnm',
            'йцукенгшщз',
            'фывапролдж',
            'ячсмитьбю',
            'password123',
            'password1234',
            'qwerty1234',
            'qwerty12345',
            'пароль12345',
            'пароль123456'
        ];

        if (in_array($compact, $common, true)) {
            return true;
        }

        if (preg_match('/^(.)\1{9,}$/us', $value)) {
            return true;
        }

        // Повторяющиеся короткие блоки: 1212121212, abcabcabcabc и т.п.
        if (preg_match('/^(.{1,4})\1{2,}$/us', $value)) {
            return true;
        }

        if (preg_match(
            '/^(password|passw0rd|qwerty|asdfgh|letmein|welcome|admin|administrator|пароль|админ|адміністратор)[0-9]{0,8}$/u',
            $compact
        )) {
            return true;
        }

        return self::isStraightSequence($compact);
    }


    private static function isStraightSequence($value)
    {
        if (!is_string($value) || strlen($value) < self::MIN_LENGTH) {
            return false;
        }

        if (!preg_match('/^[a-z0-9]+$/', $value)) {
            return false;
        }

        $direction = 0;
        $length = strlen($value);

        for ($i = 1; $i < $length; $i++) {
            $difference = ord($value[$i]) - ord($value[$i - 1]);

            if ($difference !== 1 && $difference !== -1) {
                return false;
            }

            if ($direction === 0) {
                $direction = $difference;
            } elseif ($difference !== $direction) {
                return false;
            }
        }

        return true;
    }
}
