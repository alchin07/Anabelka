<?php

class AuthController extends Controller
{
    public function registerForm()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();

        if (CustomerAccount::current()) {
            header('Location: /Anabelka/account');
            exit;
        }

        $this->view('auth/register', [
            'error' => '',
            'name' => '',
            'email' => '',
            'csrfToken' => CustomerAccount::csrfToken()
        ]);
    }


    public function register()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        try {
            $this->verifyCsrf();

            if ($name === '' || $email === '' || $password === '' || $confirmation === '') {
                throw new InvalidArgumentException(
                    Translator::t(
                        'public.auth.error_all_fields',
                        'Заповніть усі поля.'
                    )
                );
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(
                    Translator::t(
                        'public.auth.error_email',
                        'Некоректний email.'
                    )
                );
            }

            $nameLength = function_exists('mb_strlen')
                ? mb_strlen($name, 'UTF-8')
                : strlen($name);

            if ($nameLength > 120) {
                throw new InvalidArgumentException('Ім’я занадто довге.');
            }

            CustomerAccount::validateRegistrationPassword($password);

            if (!hash_equals($password, $confirmation)) {
                throw new InvalidArgumentException(
                    Translator::t(
                        'public.auth.error_password_mismatch',
                        'Паролі не збігаються.'
                    )
                );
            }

            if (User::findByEmailAnyStatus($email)) {
                throw new RuntimeException(
                    Translator::t(
                        'public.auth.error_email_exists',
                        'Користувач із таким email уже існує.'
                    )
                );
            }

            $userId = User::create($name, $email, $password);
            $user = User::findById($userId);

            if (!$user) {
                throw new RuntimeException('Не вдалося відкрити створений акаунт.');
            }

            CustomerAccount::startSession($user);
            $this->mergeGuestData((int) $userId);

            header('Location: /Anabelka/account');
            exit;
        } catch (Throwable $e) {
            $this->showRegisterError($e->getMessage(), $name, $email);
        }
    }


    public function loginForm()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();

        if (CustomerAccount::current()) {
            header('Location: /Anabelka/account');
            exit;
        }

        $this->view('auth/login', [
            'error' => '',
            'email' => '',
            'csrfToken' => CustomerAccount::csrfToken()
        ]);
    }


    public function login()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');

        try {
            $this->verifyCsrf();

            if ($email === '' || $password === '') {
                throw new InvalidArgumentException(
                    Translator::t(
                        'public.auth.error_login_fields',
                        'Заповніть email і пароль.'
                    )
                );
            }

            $user = User::findByEmailAnyStatus($email);

            if (!$user) {
                throw new RuntimeException(
                    Translator::t(
                        'public.auth.error_user_not_found',
                        'Користувача не знайдено.'
                    )
                );
            }

            if (!password_verify($password, (string) ($user['password'] ?? ''))) {
                throw new RuntimeException(
                    Translator::t(
                        'public.auth.error_password',
                        'Невірний пароль.'
                    )
                );
            }

            if (empty($user['is_active'])) {
                throw new RuntimeException(
                    Translator::t(
                        'public.auth.error_account_inactive',
                        'Акаунт деактивовано. Зверніться до адміністратора магазину.'
                    )
                );
            }

            if (password_needs_rehash((string) $user['password'], PASSWORD_DEFAULT)) {
                User::rehashPassword((int) $user['id'], $password);
            }

            CustomerAccount::startSession($user);

            try {
                UserInvitation::markAccepted((int) $user['id']);
            } catch (Throwable $e) {
                // Статус запрошення не повинен блокувати успішний вхід.
            }

            $this->mergeGuestData((int) $user['id']);

            header('Location: /Anabelka/');
            exit;
        } catch (Throwable $e) {
            $this->showLoginError($e->getMessage(), $email);
        }
    }


    public function logout()
    {
        if (
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
            && !CustomerAccount::verifyCsrf($_POST['_csrf'] ?? '')
        ) {
            header('Location: /Anabelka/account');
            exit;
        }

        $cart = $_SESSION['cart'] ?? [];
        CustomerAccount::clearSession();
        $_SESSION['cart'] = $cart;
        session_regenerate_id(true);

        header('Location: /Anabelka/');
        exit;
    }


    private function mergeGuestData($userId)
    {
        Cart::getOrCreateByUserId($userId);
        Cart::mergeSessionCart(
            $userId,
            $_SESSION['cart'] ?? []
        );
        Favorite::mergeSessionToUser($userId);
        $_SESSION['cart'] = [];
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


    private function showLoginError($message, $email)
    {
        http_response_code(422);

        $this->view('auth/login', [
            'error' => trim((string) $message),
            'email' => trim((string) $email),
            'csrfToken' => CustomerAccount::csrfToken()
        ]);
    }


    private function showRegisterError($message, $name, $email)
    {
        http_response_code(422);

        $this->view('auth/register', [
            'error' => trim((string) $message),
            'name' => trim((string) $name),
            'email' => trim((string) $email),
            'csrfToken' => CustomerAccount::csrfToken()
        ]);
    }
}
