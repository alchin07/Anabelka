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

        // Нове сповіщення показується як непрочитане один раз.
        // Після відкриття сторінки акаунта воно переходить у прочитані.
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
