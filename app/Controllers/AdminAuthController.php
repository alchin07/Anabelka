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

            $returnTo = '/Anabelka/admin';

            if (!empty($_SESSION['admin_return_to_validated'])) {
                $returnTo = $this->safeAdminReturnTo(
                    (string) (
                        $_SESSION['admin_return_to']
                        ?? '/Anabelka/admin'
                    ),
                    $admin
                );
            }

            unset(
                $_SESSION['admin_return_to'],
                $_SESSION['admin_return_to_validated']
            );

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

        unset(
            $_SESSION['admin_return_to'],
            $_SESSION['admin_return_to_validated']
        );

        AdminAccess::logout();

        header('Location: /Anabelka/admin/login');
        exit;
    }


    private function safeAdminReturnTo($returnTo, array $admin)
    {
        $fallback = '/Anabelka/admin';
        $returnTo = trim((string) $returnTo);

        if (strpos($returnTo, '/Anabelka/admin') !== 0) {
            return $fallback;
        }

        $path = parse_url($returnTo, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return $fallback;
        }

        $projectPrefix = '/Anabelka';
        if (strpos($path, $projectPrefix) === 0) {
            $path = substr($path, strlen($projectPrefix));
        }

        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        if (
            $path === '/admin/work-time'
            || strpos($path, '/admin/work-time/') === 0
        ) {
            return in_array(
                (string) ($admin['role_slug'] ?? ''),
                ['owner', 'store_owner'],
                true
            ) && $path === '/admin/work-time'
                ? $returnTo
                : $fallback;
        }

        if (
            $path === '/admin/system'
            || strpos($path, '/admin/system/') === 0
        ) {
            return (string) ($admin['role_slug'] ?? '') === 'owner'
                ? $returnTo
                : $fallback;
        }

        if (
            in_array(
                $path,
                [
                    '/admin/login',
                    '/admin/logout',
                    '/admin/setup',
                    '/admin/audit/seen',
                    '/admin/audit/seen-all'
                ],
                true
            )
        ) {
            return $fallback;
        }

        if (
            $path === '/admin/profile'
            || strpos($path, '/admin/profile/') === 0
        ) {
            return $returnTo;
        }

        if (
            $path === '/admin/audit'
            || strpos($path, '/admin/audit/') === 0
        ) {
            return AdminAccess::can('audit.view')
                ? $returnTo
                : $fallback;
        }

        if (
            $path === '/admin/administrators'
            || strpos($path, '/admin/administrators/') === 0
        ) {
            return AdminAccess::can('administrators.view')
                ? $returnTo
                : $fallback;
        }

        $permission = AdminAccess::permissionForRequest(
            'GET',
            $path
        );

        return AdminAccess::can($permission)
            ? $returnTo
            : $fallback;
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
