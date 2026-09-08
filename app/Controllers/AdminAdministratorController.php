<?php

class AdminAdministratorController extends Controller
{
    public function index()
    {
        $this->view('admin/administrators/index', [
            'pageTitle' => 'Адмін-панель · Адміністратори',
            'administrators' => AdminManagement::administrators(),
            'roles' => AdminManagement::roles(),
            'assignableRoles' => AdminManagement::assignableRoles(),
            'permissions' => AdminManagement::permissions(),
            'csrfToken' => AdminAccess::csrfToken(),
            'message' => trim((string) ($_GET['message'] ?? '')),
            'error' => trim((string) ($_GET['error'] ?? ''))
        ]);
    }


    public function profile()
    {
        $this->view('admin/administrators/profile', [
            'pageTitle' => 'Адмін-панель · Профіль',
            'admin' => AdminProfile::current(),
            'csrfToken' => AdminAccess::csrfToken(),
            'message' => trim((string) ($_GET['message'] ?? '')),
            'error' => trim((string) ($_GET['error'] ?? ''))
        ]);
    }


    public function updateProfile()
    {
        try {
            $this->verifyCsrf();
            AdminProfile::updateIdentity(
                $_POST['name'] ?? '',
                $_POST['email'] ?? '',
                $_POST['current_password'] ?? ''
            );

            $this->redirectProfile('message', 'Профіль оновлено.');
        } catch (Throwable $e) {
            $this->redirectProfile('error', $e->getMessage());
        }
    }


    public function changeOwnPassword()
    {
        try {
            $this->verifyCsrf();
            AdminProfile::changePassword(
                $_POST['current_password'] ?? '',
                $_POST['new_password'] ?? '',
                $_POST['new_password_confirmation'] ?? ''
            );

            $this->redirectProfile('message', 'Пароль успішно змінено.');
        } catch (Throwable $e) {
            $this->redirectProfile('error', $e->getMessage());
        }
    }


    public function create()
    {
        try {
            $this->verifyCsrf();
            $result = AdminManagement::createAdministrator(
                $_POST['name'] ?? '',
                $_POST['email'] ?? '',
                $_POST['password'] ?? '',
                $_POST['role_id'] ?? 0
            );

            AdminAccess::audit(
                'admin.created',
                [
                    'created_admin_id' => (int) ($result['id'] ?? 0),
                    'email' => (string) ($result['email'] ?? ''),
                    'role' => (string) ($result['role']['slug'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirect('message', 'Адміністратора створено.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function changeRole()
    {
        try {
            $this->verifyCsrf();
            $result = AdminManagement::changeAdministratorRole(
                $_POST['admin_id'] ?? 0,
                $_POST['role_id'] ?? 0
            );

            if (!empty($result['changed'])) {
                AdminAccess::audit(
                    'admin.role_changed',
                    [
                        'target_admin_id' => (int) ($result['admin']['id'] ?? 0),
                        'old_role' => (string) ($result['admin']['role_slug'] ?? ''),
                        'new_role' => (string) ($result['role']['slug'] ?? '')
                    ],
                    AdminAccess::currentId()
                );
            }

            $this->redirect(
                'message',
                !empty($result['changed'])
                    ? 'Роль адміністратора змінено.'
                    : 'Адміністратор уже має цю роль.'
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function toggle()
    {
        try {
            $this->verifyCsrf();
            $result = AdminManagement::toggleAdministrator(
                $_POST['admin_id'] ?? 0
            );

            AdminAccess::audit(
                'admin.access_toggled',
                [
                    'target_admin_id' => (int) ($result['admin']['id'] ?? 0),
                    'is_active' => (int) ($result['is_active'] ?? 0)
                ],
                AdminAccess::currentId()
            );

            $this->redirect(
                'message',
                !empty($result['is_active'])
                    ? 'Доступ адміністратора увімкнено.'
                    : 'Доступ адміністратора вимкнено.'
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function resetPassword()
    {
        try {
            $this->verifyCsrf();
            $admin = AdminManagement::resetPassword(
                $_POST['admin_id'] ?? 0,
                $_POST['password'] ?? ''
            );

            AdminAccess::audit(
                'admin.password_reset',
                [
                    'target_admin_id' => (int) ($admin['id'] ?? 0),
                    'email' => (string) ($admin['email'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirect('message', 'Пароль адміністратора змінено.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function createRole()
    {
        try {
            $this->verifyCsrf();
            $role = AdminManagement::createCustomRole(
                $_POST['role_name'] ?? '',
                is_array($_POST['permissions'] ?? null)
                    ? $_POST['permissions']
                    : []
            );

            AdminAccess::audit(
                'admin.role_created',
                [
                    'role_id' => (int) ($role['id'] ?? 0),
                    'role_name' => (string) ($role['name'] ?? ''),
                    'role_slug' => (string) ($role['slug'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirect('message', 'Власну роль створено.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function updateRolePermissions()
    {
        try {
            $this->verifyCsrf();
            $role = AdminRolePermission::update(
                $_POST['role_id'] ?? 0,
                is_array($_POST['permissions'] ?? null)
                    ? $_POST['permissions']
                    : []
            );

            AdminAccess::audit(
                'admin.role_permissions_updated',
                [
                    'role_id' => (int) ($role['id'] ?? 0),
                    'role_name' => (string) ($role['name'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirect('message', 'Права ролі збережено.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function audit()
    {
        $this->view('admin/administrators/audit', [
            'pageTitle' => 'Адмін-панель · Журнал дій',
            'entries' => AdminManagement::auditLog(250)
        ]);
    }


    private function verifyCsrf()
    {
        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            throw new RuntimeException(
                'Сесію форми застаріло. Оновіть сторінку та спробуйте ще раз.'
            );
        }
    }


    private function redirect($key, $message)
    {
        header(
            'Location: /Anabelka/admin/administrators?'
            . http_build_query([$key => (string) $message])
        );
        exit;
    }


    private function redirectProfile($key, $message)
    {
        header(
            'Location: /Anabelka/admin/profile?'
            . http_build_query([$key => (string) $message])
        );
        exit;
    }
}
