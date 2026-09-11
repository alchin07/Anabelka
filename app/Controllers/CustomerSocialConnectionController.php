<?php

class CustomerSocialConnectionController extends Controller
{
    public function status()
    {
        PublicInterfaceTranslator::seed();
        SocialConnectionsInterfaceTranslator::seed();
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $user = CustomerAccount::current();

        if (!$user) {
            http_response_code(401);
            echo json_encode(
                ['authenticated' => false],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            return;
        }

        $userId = (int) $user['id'];
        $identities = CustomerSocialIdentity::allForUser($userId);
        $identityByProvider = [];

        foreach ($identities as $identity) {
            $identityByProvider[(string) ($identity['provider'] ?? '')] = $identity;
        }

        $connectedCount = count($identities);
        $hasPasswordLogin = $this->hasPasswordLogin($userId);
        $providers = [];

        foreach (SocialAuthProvider::all() as $provider) {
            $code = (string) ($provider['code'] ?? '');
            $identity = $identityByProvider[$code] ?? null;
            $linked = is_array($identity);
            $enabled = !empty($provider['enabled']);
            $configured = !empty($provider['configured']);

            $providers[] = [
                'code' => $code,
                'label' => (string) ($provider['label'] ?? $code),
                'mark' => (string) ($provider['mark'] ?? ''),
                'linked' => $linked,
                'linked_email' => $linked
                    ? (string) ($identity['email'] ?? '')
                    : '',
                'enabled' => $enabled,
                'configured' => $configured,
                'available' => $enabled && $configured,
                'connect_url' => (!$linked && $enabled && $configured)
                    ? '/Anabelka/account/social-connect?provider=' . rawurlencode($code)
                    : '',
                'can_disconnect' => $linked
                    && ($connectedCount > 1 || $hasPasswordLogin)
            ];
        }

        echo json_encode(
            [
                'authenticated' => true,
                'providers' => $providers,
                'csrf_token' => CustomerAccount::csrfToken(),
                'strings' => [
                    'title' => Translator::t(
                        'public.social_connections.title',
                        'Способи входу'
                    ),
                    'hint' => Translator::t(
                        'public.social_connections.hint',
                        'Підключайте додаткові способи входу до одного акаунта Анабельки.'
                    ),
                    'connected' => Translator::t(
                        'public.social_connections.connected',
                        'Підключено'
                    ),
                    'not_connected' => Translator::t(
                        'public.social_connections.not_connected',
                        'Не підключено'
                    ),
                    'connect' => Translator::t(
                        'public.social_connections.connect',
                        'Підключити'
                    ),
                    'disconnect' => Translator::t(
                        'public.social_connections.disconnect',
                        'Від’єднати'
                    ),
                    'unavailable' => Translator::t(
                        'public.social_connections.unavailable',
                        'Спосіб входу зараз недоступний.'
                    ),
                    'not_configured' => Translator::t(
                        'public.social_connections.not_configured',
                        'Ще не налаштовано адміністратором.'
                    ),
                    'last_method' => Translator::t(
                        'public.social_connections.last_method',
                        'Щоб не втратити доступ до акаунта, останній підключений спосіб входу від’єднати не можна.'
                    )
                ]
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }


    public function connect()
    {
        SocialConnectionsInterfaceTranslator::seed();
        $user = CustomerAccount::current();

        if (!$user) {
            header('Location: /Anabelka/login');
            exit;
        }

        $provider = strtolower(trim((string) ($_GET['provider'] ?? '')));

        try {
            if (!SocialAuthProvider::exists($provider)) {
                throw new InvalidArgumentException('Невідомий спосіб входу.');
            }

            if (CustomerSocialIdentity::findByUserProvider((int) $user['id'], $provider)) {
                throw new RuntimeException(
                    SocialAuthProvider::label($provider)
                    . ' уже підключено до цього акаунта.'
                );
            }

            header(
                'Location: ' . SocialAuthService::connectionAuthorizationUrl(
                    $provider,
                    (int) $user['id']
                )
            );
            exit;
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function disconnect()
    {
        PublicInterfaceTranslator::seed();
        SocialConnectionsInterfaceTranslator::seed();

        try {
            $this->verifyCsrf();
            $user = CustomerAccount::current();

            if (!$user) {
                throw new RuntimeException('Сесію користувача не знайдено.');
            }

            $userId = (int) $user['id'];
            $provider = strtolower(trim((string) ($_POST['provider'] ?? '')));

            if (!SocialAuthProvider::exists($provider)) {
                throw new InvalidArgumentException('Невідомий спосіб входу.');
            }

            $identity = CustomerSocialIdentity::findByUserProvider(
                $userId,
                $provider
            );

            if (!$identity) {
                throw new RuntimeException('Цей спосіб входу не підключено.');
            }

            $identities = CustomerSocialIdentity::allForUser($userId);
            $hasAlternativeAccess = count($identities) > 1
                || $this->hasPasswordLogin($userId);

            if (!$hasAlternativeAccess) {
                throw new RuntimeException(
                    Translator::t(
                        'public.social_connections.last_method',
                        'Щоб не втратити доступ до акаунта, останній підключений спосіб входу від’єднати не можна.'
                    )
                );
            }

            CustomerSocialIdentity::deleteUserProvider(
                $userId,
                $provider
            );

            $message = str_replace(
                '{provider}',
                SocialAuthProvider::label($provider),
                Translator::t(
                    'public.social_connections.disconnected_success',
                    '{provider} від’єднано від вашого акаунта.'
                )
            );
            $this->redirect('message', $message);
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    private function hasPasswordLogin($userId)
    {
        RegistrationConsent::ensureSchema();

        $stmt = Database::connect()->prepare("
            SELECT source
            FROM customer_registration_consents
            WHERE user_id = :user_id
            ORDER BY id ASC
        ");
        $stmt->execute(['user_id' => (int) $userId]);
        $sources = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Старі локальні акаунти могли бути створені до появи таблиці згод.
        // Для них пароль вважаємо основним способом входу.
        if (empty($sources)) {
            return true;
        }

        foreach ($sources as $source) {
            $source = strtolower(trim((string) $source));

            if (substr($source, -6) !== '_oauth') {
                return true;
            }
        }

        // Акаунт створено тільки через OAuth. У нього є технічний випадковий
        // пароль, який користувач не знає, тому такий пароль не вважаємо
        // резервним способом входу.
        return false;
    }


    private function verifyCsrf()
    {
        if (!CustomerAccount::verifyCsrf($_POST['_csrf'] ?? '')) {
            throw new RuntimeException(
                Translator::t(
                    'public.auth.error_csrf',
                    'Сесію форми застаріло. Оновіть сторінку та спробуйте ще раз.'
                )
            );
        }
    }


    private function redirect($key, $message)
    {
        header(
            'Location: /Anabelka/account?'
            . http_build_query([$key => (string) $message])
        );
        exit;
    }
}
