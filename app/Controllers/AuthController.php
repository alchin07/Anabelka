<?php

class AuthController extends Controller
{
    public function registerForm()
    {
        $this->view('auth/register');
    }


    public function register()
    {
        PublicInterfaceTranslator::seed();

        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($name === '' || $email === '' || $password === '') {
            die(
                Translator::t(
                    'public.auth.error_all_fields',
                    'Заповніть усі поля.'
                )
            );
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            die(
                Translator::t(
                    'public.auth.error_email',
                    'Некоректний email.'
                )
            );
        }

        $existingUser = User::findByEmailAnyStatus($email);

        if ($existingUser) {
            die(
                Translator::t(
                    'public.auth.error_email_exists',
                    'Користувач із таким email уже існує.'
                )
            );
        }

        $userId = User::create(
            $name,
            $email,
            $password
        );

        $user = User::findByEmail($email);

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_rank_slug'] = $user['rank_slug'];

        Cart::getOrCreateByUserId(
            $_SESSION['user_id']
        );

        Cart::mergeSessionCart(
            $_SESSION['user_id'],
            $_SESSION['cart'] ?? []
        );

        Favorite::mergeSessionToUser(
            $_SESSION['user_id']
        );

        $_SESSION['cart'] = [];

        header('Location: /Anabelka/');
        exit;
    }


    public function loginForm()
    {
        $this->view('auth/login', [
            'error' => '',
            'email' => ''
        ]);
    }


    public function login()
    {
        PublicInterfaceTranslator::seed();

        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $this->showLoginError(
                Translator::t(
                    'public.auth.error_login_fields',
                    'Заповніть email і пароль.'
                ),
                $email
            );
            return;
        }

        $user = User::findByEmailAnyStatus($email);

        if (!$user) {
            $this->showLoginError(
                Translator::t(
                    'public.auth.error_user_not_found',
                    'Користувача не знайдено.'
                ),
                $email
            );
            return;
        }

        if (!password_verify($password, $user['password'])) {
            $this->showLoginError(
                Translator::t(
                    'public.auth.error_password',
                    'Невірний пароль.'
                ),
                $email
            );
            return;
        }

        if (empty($user['is_active'])) {
            $this->showLoginError(
                Translator::t(
                    'public.auth.error_account_inactive',
                    'Акаунт деактивовано. Зверніться до адміністратора магазину.'
                ),
                $email
            );
            return;
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_rank_slug'] = $user['rank_slug'];

        try {
            UserInvitation::markAccepted((int) $user['id']);
        } catch (Throwable $e) {
            // Статус запрошення не повинен блокувати успішний вхід.
        }

        Cart::getOrCreateByUserId(
            $_SESSION['user_id']
        );

        Cart::mergeSessionCart(
            $_SESSION['user_id'],
            $_SESSION['cart'] ?? []
        );

        Favorite::mergeSessionToUser(
            $_SESSION['user_id']
        );

        $_SESSION['cart'] = [];

        header('Location: /Anabelka/');
        exit;
    }


    public function logout()
    {
        $cart = $_SESSION['cart'] ?? [];

        unset(
            $_SESSION['user_id'],
            $_SESSION['user_name'],
            $_SESSION['user_rank_slug']
        );

        $_SESSION['cart'] = $cart;

        session_regenerate_id(true);

        header('Location: /Anabelka/');
        exit;
    }


    private function showLoginError($message, $email)
    {
        http_response_code(422);

        $this->view('auth/login', [
            'error' => trim((string) $message),
            'email' => trim((string) $email)
        ]);
    }
}
