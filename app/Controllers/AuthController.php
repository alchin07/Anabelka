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

        $existingUser = User::findByEmail($email);

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

        $_SESSION['cart'] = [];

        header('Location: /Anabelka/');
        exit;
    }


    public function loginForm()
    {
        $this->view('auth/login');
    }


    public function login()
    {
        PublicInterfaceTranslator::seed();

        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            die(
                Translator::t(
                    'public.auth.error_login_fields',
                    'Заповніть email і пароль.'
                )
            );
        }

        $user = User::findByEmail($email);

        if (!$user) {
            die(
                Translator::t(
                    'public.auth.error_user_not_found',
                    'Користувача не знайдено.'
                )
            );
        }

        if (!password_verify($password, $user['password'])) {
            die(
                Translator::t(
                    'public.auth.error_password',
                    'Невірний пароль.'
                )
            );
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_rank_slug'] = $user['rank_slug'];

        Cart::getOrCreateByUserId(
            $_SESSION['user_id']
        );

        Cart::mergeSessionCart(
            $_SESSION['user_id'],
            $_SESSION['cart'] ?? []
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
}
