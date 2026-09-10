<?php

class SocialAuthService
{
    private const STATE_SESSION_KEY = 'social_auth_google_state';
    private const PENDING_SESSION_KEY = 'social_auth_pending';
    private const STATE_TTL_SECONDS = 600;
    private const PENDING_TTL_SECONDS = 900;


    public static function googleAuthorizationUrl()
    {
        $state = bin2hex(random_bytes(24));
        $_SESSION[self::STATE_SESSION_KEY] = [
            'value' => $state,
            'expires_at' => time() + self::STATE_TTL_SECONDS
        ];
        unset($_SESSION[self::PENDING_SESSION_KEY]);

        return GoogleOAuthProvider::authorizationUrl($state);
    }


    public static function handleGoogleCallback($code, $state)
    {
        self::assertState($state);
        unset($_SESSION[self::STATE_SESSION_KEY]);

        $code = trim((string) $code);

        if ($code === '') {
            throw new RuntimeException('Google не повернув код авторизації.');
        }

        $accessToken = GoogleOAuthProvider::exchangeCode($code);
        $identity = GoogleOAuthProvider::userIdentity($accessToken);
        CustomerSocialIdentity::ensureSchema();
        CustomerEmailVerification::ensureSchema();

        $linked = CustomerSocialIdentity::findByProviderIdentity(
            'google',
            $identity['provider_user_id']
        );

        if ($linked) {
            $user = User::findById((int) $linked['user_id']);
            self::assertActiveUser($user);
            self::assertSameEmail($user, $identity['email']);
            CustomerSocialIdentity::link(
                (int) $user['id'],
                'google',
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
                'new_account' => false
            ];
        }

        $user = User::findByEmailAnyStatus($identity['email']);

        if ($user) {
            self::assertActiveUser($user);
            CustomerSocialIdentity::link(
                (int) $user['id'],
                'google',
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
                'new_account' => false
            ];
        }

        $_SESSION[self::PENDING_SESSION_KEY] = [
            'provider' => 'google',
            'provider_user_id' => (string) $identity['provider_user_id'],
            'email' => (string) $identity['email'],
            'name' => trim((string) $identity['name']),
            'profile' => $identity['profile'],
            'expires_at' => time() + self::PENDING_TTL_SECONDS
        ];

        return [
            'status' => 'pending_registration',
            'new_account' => true
        ];
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

        if (
            ($pending['provider'] ?? '') !== 'google'
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
            throw new RuntimeException('Сесію реєстрації через Google завершено. Спробуйте увійти ще раз.');
        }

        CustomerSocialIdentity::ensureSchema();
        CustomerEmailVerification::ensureSchema();
        RegistrationConsent::ensureSchema();
        UserRank::defaultRegistrationRankId();

        $db = Database::connect();
        $db->beginTransaction();

        try {
            $existingIdentity = CustomerSocialIdentity::findByProviderIdentity(
                'google',
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
                        $name = strstr((string) $pending['email'], '@', true) ?: 'Користувач';
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
                        'google_oauth'
                    );
                    $user = User::findById($userId);
                    self::assertActiveUser($user);
                }

                CustomerSocialIdentity::link(
                    (int) $user['id'],
                    'google',
                    (string) $pending['provider_user_id'],
                    (string) $pending['email'],
                    is_array($pending['profile'] ?? null) ? $pending['profile'] : []
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


    private static function assertState($state)
    {
        $stored = is_array($_SESSION[self::STATE_SESSION_KEY] ?? null)
            ? $_SESSION[self::STATE_SESSION_KEY]
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
            unset($_SESSION[self::STATE_SESSION_KEY]);
            throw new RuntimeException('Сесію входу через Google не підтверджено.');
        }
    }


    private static function assertActiveUser($user)
    {
        if (!$user || empty($user['is_active'])) {
            throw new RuntimeException('Акаунт недоступний. Зверніться до адміністратора магазину.');
        }
    }


    private static function assertSameEmail(array $user, $email)
    {
        if (
            strtolower(trim((string) ($user['email'] ?? '')))
            !== strtolower(trim((string) $email))
        ) {
            throw new RuntimeException('Email Google не збігається з email прив’язаного акаунта.');
        }
    }
}
