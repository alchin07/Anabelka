<?php

class CustomerAccountController extends Controller
{
    public function index()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();
        CustomerRankRequestInterfaceTranslator::seed();
        CustomerNotificationInterfaceTranslator::seed();

        $user = CustomerAccount::current();

        if (!$user) {
            header('Location: /Anabelka/login');
            exit;
        }

        $notifications = CustomerNotification::recentForUser((int) $user['id'], 10);
        $unreadCount = CustomerNotification::unreadCount((int) $user['id']);
        $unreadIds = [];

        foreach ($notifications as $notification) {
            if (empty($notification['is_read'])) {
                $unreadIds[] = (int) ($notification['id'] ?? 0);
            }
        }

        CustomerNotification::markRead((int) $user['id'], $unreadIds);

        $this->view('account/index', [
            'user' => $user,
            'addresses' => CustomerAddress::allForUser((int) $user['id']),
            'rankRequestState' => CustomerRankRequest::stateForUser((int) $user['id']),
            'notifications' => $notifications,
            'notificationUnreadCount' => $unreadCount,
            'csrfToken' => CustomerAccount::csrfToken(),
            'message' => trim((string) ($_GET['message'] ?? '')),
            'error' => trim((string) ($_GET['error'] ?? ''))
        ]);
    }


    public function requestRankUpgrade()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();
        CustomerRankRequestInterfaceTranslator::seed();

        try {
            $this->verifyCsrf();
            $user = CustomerAccount::current();

            if (!$user) {
                throw new RuntimeException('Сесію користувача не знайдено.');
            }

            CustomerRankRequest::createForUser((int) $user['id']);

            $this->redirect(
                'message',
                Translator::t(
                    'public.rank_request.sent',
                    'Запит на підвищення рангу надіслано адміністратору.'
                )
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function updateProfile()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();

        try {
            $this->verifyCsrf();
            CustomerAccount::updateIdentity(
                $_POST['name'] ?? '',
                $_POST['email'] ?? '',
                $_POST['phone'] ?? '',
                $_POST['current_password'] ?? ''
            );

            $this->redirect(
                'message',
                Translator::t(
                    'public.account.profile_saved',
                    'Дані профілю збережено.'
                )
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function updateAdultPreferences()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();

        try {
            $this->verifyCsrf();
            CustomerAccount::updateAdultPreferences(
                $_POST['birth_date'] ?? '',
                !empty($_POST['adult_confirmed']),
                !empty($_POST['show_adult']),
                $_POST['current_password'] ?? ''
            );

            $current = CustomerAccount::current();

            if (!$current || empty($current['adult_section_access'])) {
                AdultAccess::clearConfirmation();
            }

            $this->redirect(
                'message',
                Translator::t(
                    'public.account.adult_preferences_saved',
                    'Налаштування 18+ збережено.'
                )
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function changePassword()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();

        try {
            $this->verifyCsrf();
            CustomerAccount::changePassword(
                $_POST['current_password'] ?? '',
                $_POST['new_password'] ?? '',
                $_POST['new_password_confirmation'] ?? ''
            );

            $this->redirect(
                'message',
                Translator::t(
                    'public.account.password_saved',
                    'Пароль успішно змінено.'
                )
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function socialConnectionsStatus()
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

        $identities = CustomerSocialIdentity::allForUser((int) $user['id']);
        $identityByProvider = [];

        foreach ($identities as $identity) {
            $identityByProvider[(string) ($identity['provider'] ?? '')] = $identity;
        }

        $connectedCount = count($identities);
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
                'linked_email' => $linked ? (string) ($identity['email'] ?? '') : '',
                'enabled' => $enabled,
                'configured' => $configured,
                'available' => $enabled && $configured,
                'connect_url' => (!$linked && $enabled && $configured)
                    ? '/Anabelka/account/social-connect?provider=' . rawurlencode($code)
                    : '',
                'can_disconnect' => $linked && $connectedCount > 1
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


    public function connectSocial()
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
                    SocialAuthProvider::label($provider) . ' уже підключено до цього акаунта.'
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


    public function disconnectSocial()
    {
        PublicInterfaceTranslator::seed();
        SocialConnectionsInterfaceTranslator::seed();

        try {
            $this->verifyCsrf();
            $user = CustomerAccount::current();

            if (!$user) {
                throw new RuntimeException('Сесію користувача не знайдено.');
            }

            $provider = strtolower(trim((string) ($_POST['provider'] ?? '')));

            if (!SocialAuthProvider::exists($provider)) {
                throw new InvalidArgumentException('Невідомий спосіб входу.');
            }

            $identity = CustomerSocialIdentity::findByUserProvider(
                (int) $user['id'],
                $provider
            );

            if (!$identity) {
                throw new RuntimeException('Цей спосіб входу не підключено.');
            }

            $identities = CustomerSocialIdentity::allForUser((int) $user['id']);

            if (count($identities) <= 1) {
                throw new RuntimeException(
                    Translator::t(
                        'public.social_connections.last_method',
                        'Щоб не втратити доступ до акаунта, останній підключений спосіб входу від’єднати не можна.'
                    )
                );
            }

            CustomerSocialIdentity::deleteUserProvider(
                (int) $user['id'],
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


    public function createAddress()
    {
        $this->addressAction(function ($userId) {
            CustomerAddress::create($userId, $this->addressData());
        }, 'public.account.address_added', 'Адресу додано.');
    }


    public function updateAddress()
    {
        $this->addressAction(function ($userId) {
            CustomerAddress::update(
                $userId,
                $_POST['address_id'] ?? 0,
                $this->addressData()
            );
        }, 'public.account.address_saved', 'Адресу збережено.');
    }


    public function setDefaultAddress()
    {
        $this->addressAction(function ($userId) {
            CustomerAddress::setDefault(
                $userId,
                $_POST['address_id'] ?? 0
            );
        }, 'public.account.address_default_saved', 'Основну адресу змінено.');
    }


    public function deleteAddress()
    {
        $this->addressAction(function ($userId) {
            CustomerAddress::delete(
                $userId,
                $_POST['address_id'] ?? 0
            );
        }, 'public.account.address_deleted', 'Адресу видалено.');
    }


    public function checkoutProfile()
    {
        header('Content-Type: application/json; charset=UTF-8');
        $user = CustomerAccount::current();

        if (!$user) {
            echo json_encode(
                ['authenticated' => false],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            return;
        }

        $address = CustomerAddress::defaultForUser((int) $user['id']);
        echo json_encode(
            [
                'authenticated' => true,
                'name' => (string) ($user['name'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'phone' => (string) ($user['phone'] ?? ''),
                'address' => $address ? [
                    'country' => (string) ($address['country'] ?? ''),
                    'city' => (string) ($address['city'] ?? ''),
                    'address' => (string) ($address['address'] ?? ''),
                    'postcode' => (string) ($address['postcode'] ?? '')
                ] : null
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }


    private function addressAction(callable $callback, $translationKey, $fallback)
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();

        try {
            $this->verifyCsrf();
            $user = CustomerAccount::current();

            if (!$user) {
                throw new RuntimeException('Сесію користувача не знайдено.');
            }

            $callback((int) $user['id']);
            $this->redirect(
                'message',
                Translator::t($translationKey, $fallback)
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    private function addressData()
    {
        return [
            'label' => $_POST['label'] ?? '',
            'country' => $_POST['country'] ?? '',
            'city' => $_POST['city'] ?? '',
            'address' => $_POST['address'] ?? '',
            'postcode' => $_POST['postcode'] ?? '',
            'is_default' => !empty($_POST['is_default'])
        ];
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
