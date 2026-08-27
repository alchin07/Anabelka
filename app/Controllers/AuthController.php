<?php

class AuthController extends Controller
{
    /**
     * Показать форму регистрации.
     */
    public function registerForm()
    {
        $this->view('auth/register');
    }


    /**
     * Обработать регистрацию.
     */
    public function register()
    {
        $name =
            trim($_POST['name'] ?? '');

        $email =
            trim($_POST['email'] ?? '');

        $password =
            $_POST['password'] ?? '';


        // Проверяем заполнение
        if (
            $name === '' ||
            $email === '' ||
            $password === ''
        ) {
            die('Заполните все поля');
        }


        // Проверяем email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            die('Некорректный email');
        }


        // Проверяем, существует ли пользователь
        $existingUser =
            User::findByEmail($email);

        if ($existingUser) {
            die('Пользователь с таким email уже существует');
        }


        // Создаём пользователя
        $userId =
            User::create(
                $name,
                $email,
                $password
            );


        // Получаем созданного пользователя
        $user =
            User::findByEmail($email);


        // Авторизуем сразу после регистрации
        $_SESSION['user_id'] =
            $userId;

        $_SESSION['user_name'] =
            $user['name'];

        $_SESSION['user_rank_slug'] =
            $user['rank_slug'];
            
        Cart::getOrCreateByUserId(
          $_SESSION['user_id']
        );
        
        Cart::mergeSessionCart(
          $_SESSION['user_id'],
          $_SESSION['cart'] ?? []
        );
        
        $_SESSION['cart'] = [];


        // Возвращаем на главную
        header(
            'Location: /Anabelka/'
        );

        exit;
    }
    /**
 * Показать форму входа.
 */
public function loginForm()
{
    $this->view('auth/login');
}


/**
 * Обработать вход.
 */
public function login()
{
    $email =
        trim($_POST['email'] ?? '');

    $password =
        $_POST['password'] ?? '';


    if (
        $email === '' ||
        $password === ''
    ) {
        die('Заполните email и пароль');
    }


    $user =
        User::findByEmail($email);


    if (!$user) {
        die('Пользователь не найден');
    }


    if (
        !password_verify(
            $password,
            $user['password']
        )
    ) {
        die('Неверный пароль');
    }


    /*
     * Защита от фиксации сессии.
     */
    session_regenerate_id(true);


    /*
     * Запоминаем пользователя.
     */
    $_SESSION['user_id'] =
        (int) $user['id'];

    $_SESSION['user_name'] =
        $user['name'];

    $_SESSION['user_rank_slug'] =
        $user['rank_slug'];
    
    Cart::getOrCreateByUserId(
      $_SESSION['user_id']
    );
    
    Cart::mergeSessionCart(
      $_SESSION['user_id'],
      $_SESSION['cart'] ?? []
    );
    
    $_SESSION['cart'] = [];


    /*
     * Корзину специально НЕ очищаем.
     * Гостевые товары сохраняются,
     * но их цена автоматически
     * пересчитается по рангу пользователя.
     */
    header(
        'Location: /Anabelka/'
    );

    exit;
}


/**
 * Выход из аккаунта.
 */
public function logout()
{
    /*
     * Сохраняем корзину.
     */
    $cart =
        $_SESSION['cart'] ?? [];


    /*
     * Удаляем данные авторизации.
     */
    unset(
        $_SESSION['user_id'],
        $_SESSION['user_name'],
        $_SESSION['user_rank_slug']
    );


    /*
     * Корзина остаётся в сессии.
     */
    $_SESSION['cart'] =
        $cart;


    session_regenerate_id(true);


    header(
        'Location: /Anabelka/'
    );

    exit;
}
}