<?php

class SocialAuthService
{
    private const PENDING_SESSION_KEY = 'social_auth_pending';
    private const STATE_TTL_SECONDS = 600;
    private const PENDING_TTL_SECONDS = 900;


    public static function authorizationUrl($provider)
    {
        $provider = self::normalizeProvider($provider);
        $driver = self::providerDriver($provider);

        if (!SocialAuthProvider::isEnabled($provider)) {
            throw new RuntimeException(
                'Цей спосіб входу вимкнено адміністратором магазину.'
            );
        }

        if (!SocialAuthProvider::isConfigured($provider)) {
            throw new RuntimeException(
                SocialAuthProvider::label($provider) . ' OAuth ще не налаштовано.'
            );
        }

        $state = bin2hex(random_bytes(24));
        $_SESSION[self::stateSessionKey($provider)] = [
            'value' => $state,
            'expires_at' => time() + self::STATE_TTL_SECONDS
        ];
        unset($_SESSION[self::PENDING_SESSION_KEY]);

        return $driver::authorizationUrl($state);
    }


    public static function googleAuthorizationUrl()
    {
        return self::authorizationUrl('google');
    }


    public static function handleCallback($provider, $code, $state)
    {
        $provider = self::normalizeProvider($provider);
        $driver = self::providerDriver($provider);

        if (!SocialAuthProvider::isEnabled($provider)) {
            throw new RuntimeException(
                'Цей спосіб входу вимкнено адміністратором магазину.'
            );
        }

        self::assertState($provider, $state);
        unset($_SESSION[self::stateSessionKey($provider)]);

        $code = trim((string) $code);

        if ($code === '') {
            throw new RuntimeException(
                SocialAuthProvider::label($provider)
                . ' не повернув код авторизації.'
            );
        }

        $accessToken = $driver::exchangeCode($code);
        $identity = $driver::userIdentity($accessToken);
        CustomerSocialIdentity::ensureSchema();
        CustomerEmailVerification::ensureSchema();

        $linked = CustomerSocialIdentity::findByProviderIdentity(
            $provider,
            $identity['provider_user_id']
        );

        if ($linked) {
            $user = User::findById((int) $linked['user_id']);
            self::assertActiveUser($user);
            self::assertSameEmail($user, $identity['email'], $provider);
            CustomerSocialIdentity::link(
                (int) $user['id'],
                $provider,
                $identity['provider_user_id'],
                $identity['email'],
                $identity['profile']
            );
            CustomerEmailVerification::markVerifiedByTrustedProvider(
                (int) $user['id'],
                $identity['email']
            );

            return [
                'status' => 'login',
                'user' => $user,
                'new_account' => false,
                'provider' => $provider
            ];
        }

        $user = User::findByEmailAnyStatus($identity['email']);

        if ($user) {
            self::assertActiveUser($user);
            CustomerSocialIdentity::link(
                (int) $user['id'],
                $provider,
                $identity['provider_user_id'],
                $identity['email'],
                $identity['profile']
            );
            CustomerEmailVerification::markVerifiedByTrustedProvider(
                (int) $user['id'],
                $identity['email']
            );

            return [
                'status' => 'login',
                'user' => $user,
                'new_account' => false,
                'provider' => $provider
            ];
        }

        $_SESSION[self::PENDING_SESSION_KEY] = [
            'provider' => $provider,
            'provider_user_id' => (string) $identity['provider_user_id'],
            'email' => (string) $identity['email'],
            'name' => trim((string) $identity['name']),
            'profile' => $identity['profile'],
            'expires_at' => time() + self::PENDING_TTL_SECONDS
        ];

        return [
            'status' => 'pending_registration',
            'new_account' => true,
            'provider' => $provider
        ];
    }


    public static function handleGoogleCallback($code, $state)
    {
        return self::handleCallback('google', $code, $state);
    }


    public static function pendingRegistration()
    {
        $pending = is_array($_SESSION[self::PENDING_SESSION_KEY] ?? null)
            ? $_SESSION[self::PENDING_SESSION_KEY]
            : [];

        if (empty($pending)) {
            return null;
        }

        if ((int) ($pending['expires_at'] ?? 0) < time()) {
            unset($_SESSION[self::PENDING_SESSION_KEY]);
            return null;
        }

        $provider = self::normalizeProvider($pending['provider'] ?? '');

        if (
            !SocialAuthProvider::exists($provider)
            || empty($pending['provider_user_id'])
            || !filter_var($pending['email'] ?? '', FILTER_VALIDATE_EMAIL)
        ) {
            unset($_SESSION[self::PENDING_SESSION_KEY]);
            return null;
        }

        return $pending;
    }


    public static function completePendingRegistration($termsAccepted, $marketingOptIn)
    {
        if (!$termsAccepted) {
            throw new InvalidArgumentException(
                'Для створення акаунта потрібно погодитися з Умовами користування та Політикою конфіденційності.'
            );
        }

        $pending = self::pendingRegistration();

        if (!$pending) {
            throw new RuntimeException(
                'Сесію соціальної реєстрації завершено. Спробуйте увійти ще раз.'
            );
        }

        $provider = self::normalizeProvider($pending['provider'] ?? '');

        CustomerSocialIdentity::ensureSchema();
        CustomerEmailVerification::ensureSchema();
        RegistrationConsent::ensureSchema();
        UserRank::defaultRegistrationRankId();

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $existingIdentity = CustomerSocialIdentity::findByProviderIdentity(
                $provider,
                $pending['provider_user_id']
            );

            if ($existingIdentity) {
                $user = User::findById((int) $existingIdentity['user_id']);
                self::assertActiveUser($user);
            } else {
                $user = User::findByEmailAnyStatus($pending['email']);

                if ($user) {
                    self::assertActiveUser($user);
                } else {
                    $name = trim((string) ($pending['name'] ?? ''));
                    if ($name === '') {
                        $name = strstr((string) $pending['email'], '@', true)
                            ?: 'Користувач';
                    }

                    $temporaryPassword = bin2hex(random_bytes(32));
                    $userId = User::create(
                        $name,
                        (string) $pending['email'],
                        $temporaryPassword
                    );
                    RegistrationConsent::recordRegistration(
                        $userId,
                        (bool) $marketingOptIn,
                        $provider . '_oauth'
                    );
                    $user = User::findById($userId);
                    self::assertActiveUser($user);
                }

                CustomerSocialIdentity::link(
                    (int) $user['id'],
                    $provider,
                    (string) $pending['provider_user_id'],
                    (string) $pending['email'],
                    is_array($pending['profile'] ?? null)
                        ? $pending['profile']
                        : []
                );
            }

            CustomerEmailVerification::markVerifiedByTrustedProvider(
                (int) $user['id'],
                (string) $pending['email']
            );

            $db->commit();
            unset($_SESSION[self::PENDING_SESSION_KEY]);

            return $user;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }


    public static function clearPending()
    {
        unset($_SESSION[self::PENDING_SESSION_KEY]);
    }


    private static function assertState($provider, $state)
    {
        $sessionKey = self::stateSessionKey($provider);
        $stored = is_array($_SESSION[$sessionKey] ?? null)
            ? $_SESSION[$sessionKey]
            : [];
        $state = trim((string) $state);
        $storedValue = trim((string) ($stored['value'] ?? ''));
        $expiresAt = (int) ($stored['expires_at'] ?? 0);

        if (
            $state === ''
            || $storedValue === ''
            || $expiresAt < time()
            || !hash_equals($storedValue, $state)
        ) {
            unset($_SESSION[$sessionKey]);
            throw new RuntimeException(
                'Сесію входу через '
                . SocialAuthProvider::label($provider)
                . ' не підтверджено.'
            );
        }
    }


    private static function assertActiveUser($user)
    {
        if (!$user || empty($user['is_active'])) {
            throw new RuntimeException(
                'Акаунт недоступний. Зверніться до адміністратора магазину.'
            );
        }
    }


    private static function assertSameEmail(array $user, $email, $provider)
    {
        if (
            strtolower(trim((string) ($user['email'] ?? '')))
            !== strtolower(trim((string) $email))
        ) {
            throw new RuntimeException(
                'Email '
                . SocialAuthProvider::label($provider)
                . ' не збігається з email прив’язаного акаунта.'
            );
        }
    }


    private static function providerDriver($provider)
    {
        if (!SocialAuthProvider::exists($provider)) {
            throw new InvalidArgumentException(
                'Невідомий провайдер соціальної авторизації.'
            );
        }

        $driver = SocialAuthProvider::driverClass($provider);

        if ($driver === '' || !class_exists($driver)) {
            throw new RuntimeException(
                'Провайдер '
                . SocialAuthProvider::label($provider)
                . ' ще не підключено до коду магазину.'
            );
        }

        return $driver;
    }


    private static function stateSessionKey($provider)
    {
        return 'social_auth_' . self::normalizeProvider($provider) . '_state';
    }


    private static function normalizeProvider($provider)
    {
        return strtolower(trim((string) $provider));
    }
}
