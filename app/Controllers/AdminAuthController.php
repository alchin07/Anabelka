<?php

class AdminAuthController extends Controller
{
    public function setupForm()
    {
        AdminAccess::ensureSchema();

        if (AdminAccess::hasAdmins()) {
            header('Location: /Anabelka/admin/login');
            exit;
        }

        $this->view('admin/auth/setup', [
            'csrfToken' => AdminAccess::csrfToken(),
            'error' => ''
        ]);
    }


    public function setup()
    {
        AdminAccess::ensureSchema();

        if (AdminAccess::hasAdmins()) {
            header('Location: /Anabelka/admin/login');
            exit;
        }

        try {
            $this->verifyCsrf();

            AdminAccess::createOwner(
                $_POST['name'] ?? '',
                $_POST['email'] ?? '',
                $_POST['password'] ?? ''
            );

            header('Location: /Anabelka/admin');
            exit;
        } catch (Throwable $e) {
            http_response_code(422);

            $this->view('admin/auth/setup', [
                'csrfToken' => AdminAccess::csrfToken(),
                'error' => $e->getMessage(),
                'name' => trim((string) ($_POST['name'] ?? '')),
                'email' => trim((string) ($_POST['email'] ?? ''))
            ]);
        }
    }


    public function loginForm()
    {
        AdminAccess::ensureSchema();

        if (!AdminAccess::hasAdmins()) {
            header('Location: /Anabelka/admin/setup');
            exit;
        }

        if (AdminAccess::current()) {
            header('Location: /Anabelka/admin');
            exit;
        }

        $this->view('admin/auth/login', [
            'csrfToken' => AdminAccess::csrfToken(),
            'error' => ''
        ]);
    }


    public function login()
    {
        AdminAccess::ensureSchema();

        if (!AdminAccess::hasAdmins()) {
            header('Location: /Anabelka/admin/setup');
            exit;
        }

        try {
            $this->verifyCsrf();

            $admin = AdminAccess::authenticate(
                $_POST['email'] ?? '',
                $_POST['password'] ?? ''
            );

            if (!$admin) {
                throw new RuntimeException('Невірний email або пароль.');
            }

            $returnTo = (string) (
                $_SESSION['admin_return_to'] ?? '/Anabelka/admin'
            );
            unset($_SESSION['admin_return_to']);

            if (strpos($returnTo, '/Anabelka/admin') !== 0) {
                $returnTo = '/Anabelka/admin';
            }

            header('Location: ' . $returnTo);
            exit;
        } catch (Throwable $e) {
            http_response_code(422);

            $this->view('admin/auth/login', [
                'csrfToken' => AdminAccess::csrfToken(),
                'error' => $e->getMessage(),
                'email' => trim((string) ($_POST['email'] ?? ''))
            ]);
        }
    }


    public function logout()
    {
        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            http_response_code(419);
            echo 'Сесію форми застаріло. Оновіть сторінку та спробуйте ще раз.';
            return;
        }

        AdminAccess::logout();

        header('Location: /Anabelka/admin/login');
        exit;
    }


    private function verifyCsrf()
    {
        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            throw new RuntimeException(
                'Сесію форми застаріло. Оновіть сторінку та спробуйте ще раз.'
            );
        }
    }
}
