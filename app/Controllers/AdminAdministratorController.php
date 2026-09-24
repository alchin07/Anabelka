<?php

class AdminAdministratorController extends Controller
{
    public function index()
    {
        $inviteFlash = $_SESSION['admin_administrator_invite_flash'] ?? null;
        unset($_SESSION['admin_administrator_invite_flash']);

        $this->view('admin/administrators/index', [
            'pageTitle' => 'Адмін-панель · Адміністратори',
            'administrators' => AdminManagement::administrators(),
            'roles' => AdminManagement::roles(),
            'assignableRoles' => AdminManagement::assignableRoles(),
            'permissions' => AdminManagement::permissions(),
            'inviteFlash' => is_array($inviteFlash) ? $inviteFlash : null,
            'csrfToken' => AdminAccess::csrfToken(),
            'message' => trim((string) ($_GET['message'] ?? '')),
            'error' => trim((string) ($_GET['error'] ?? ''))
        ]);
    }


    public function profile()
    {
        $admin = AdminProfile::current();
        $canCustomizeNotificationBadge = false;
        $notificationBadgeOptions = [];

        if (class_exists('AdminNotificationCenter')) {
            try {
                $canCustomizeNotificationBadge =
                    AdminNotificationCenter::canCustomizeBadge($admin);

                if ($canCustomizeNotificationBadge) {
                    $notificationBadgeOptions =
                        AdminNotificationCenter::badgeOptions(
                            (int) ($admin['id'] ?? 0)
                        );
                }
            } catch (Throwable $e) {
                $canCustomizeNotificationBadge = false;
                $notificationBadgeOptions = [];
            }
        }

        $this->view('admin/administrators/profile', [
            'pageTitle' => 'Адмін-панель · Профіль',
            'admin' => $admin,
            'csrfToken' => AdminAccess::csrfToken(),
            'canCustomizeNotificationBadge' => $canCustomizeNotificationBadge,
            'notificationBadgeOptions' => $notificationBadgeOptions,
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


    public function updateNotificationBadge()
    {
        try {
            $this->verifyCsrf();

            if (!class_exists('AdminNotificationCenter')) {
                throw new RuntimeException('Центр сповіщень недоступний.');
            }

            $channels = is_array($_POST['badge_channels'] ?? null)
                ? $_POST['badge_channels']
                : [];

            AdminNotificationCenter::saveBadgePreferences($channels);

            AdminAccess::audit(
                'admin.notification_badge_preferences_updated',
                [
                    'channels' => array_values(array_map(
                        'strval',
                        $channels
                    ))
                ],
                AdminAccess::currentId()
            );

            $this->redirectProfile(
                'message',
                'Налаштування бейджа сповіщень збережено.'
            );
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


    public function createInvitation()
    {
        try {
            $this->verifyCsrf();

            $result = AdminInvitation::create(
                $_POST['name'] ?? '',
                $_POST['email'] ?? '',
                $_POST['role_id'] ?? 0,
                $_POST['invite_channel'] ?? 'other',
                $_POST['invite_contact'] ?? '',
                AdminAccess::currentId()
            );

            $_SESSION['admin_administrator_invite_flash'] = $result;

            AdminAccess::audit(
                'admin.invitation_created',
                [
                    'created_admin_id' => (int) ($result['admin_id'] ?? 0),
                    'email' => (string) ($result['email'] ?? ''),
                    'role' => (string) ($result['role_slug'] ?? ''),
                    'channel' => (string) ($result['channel'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirect(
                'message',
                'Запрошення адміністратора створено.'
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function reissueInvitation()
    {
        try {
            $this->verifyCsrf();

            $result = AdminInvitation::reissue(
                $_POST['admin_id'] ?? 0,
                AdminAccess::currentId()
            );

            $_SESSION['admin_administrator_invite_flash'] = $result;

            AdminAccess::audit(
                'admin.invitation_reissued',
                [
                    'target_admin_id' => (int) ($result['admin_id'] ?? 0),
                    'email' => (string) ($result['email'] ?? ''),
                    'role' => (string) ($result['role_slug'] ?? ''),
                    'channel' => (string) ($result['channel'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirect(
                'message',
                'Нове одноразове запрошення створено. Попереднє посилання більше не працює.'
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function revokeInvitation()
    {
        try {
            $this->verifyCsrf();

            $result = AdminInvitation::revoke(
                $_POST['admin_id'] ?? 0,
                AdminAccess::currentId()
            );

            AdminAccess::audit(
                'admin.invitation_revoked',
                [
                    'target_admin_id' => (int) ($result['admin_id'] ?? 0),
                    'email' => (string) ($result['email'] ?? ''),
                    'role' => (string) ($result['role_slug'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirect(
                'message',
                'Запрошення адміністратора відкликано.'
            );
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


    public function deleteAdministrator()
    {
        try {
            $this->verifyCsrf();
            $admin = AdminManagement::deleteAdministrator(
                $_POST['admin_id'] ?? 0
            );

            AdminAccess::audit(
                'admin.deleted',
                [
                    'target_admin_id' => (int) ($admin['id'] ?? 0),
                    'email' => (string) ($admin['email'] ?? ''),
                    'role' => (string) ($admin['role_slug'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirect(
                'message',
                'Обліковий запис адміністратора видалено.'
            );
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


    public function renameRole()
    {
        try {
            $this->verifyCsrf();
            $role = AdminManagement::renameCustomRole(
                $_POST['role_id'] ?? 0,
                $_POST['role_name'] ?? ''
            );

            AdminAccess::audit(
                'admin.role_renamed',
                [
                    'role_id' => (int) ($role['id'] ?? 0),
                    'old_name' => (string) ($role['old_name'] ?? ''),
                    'role_name' => (string) ($role['name'] ?? ''),
                    'role_slug' => (string) ($role['slug'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirect('message', 'Назву ролі змінено.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function deleteRole()
    {
        try {
            $this->verifyCsrf();
            $role = AdminManagement::deleteCustomRole(
                $_POST['role_id'] ?? 0
            );

            AdminAccess::audit(
                'admin.role_deleted',
                [
                    'role_id' => (int) ($role['id'] ?? 0),
                    'role_name' => (string) ($role['name'] ?? ''),
                    'role_slug' => (string) ($role['slug'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->redirect('message', 'Власну роль видалено.');
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


    public function inviteForm()
    {
        $token = trim((string) ($_GET['token'] ?? ''));
        $this->renderInvitation(
            $token,
            $token === '' ? 'Посилання запрошення неповне.' : ''
        );
    }


    public function acceptInvitation()
    {
        $token = trim((string) ($_POST['token'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

        if ($password !== $passwordConfirm) {
            $this->renderInvitation($token, 'Паролі не співпадають.');
            return;
        }

        try {
            $admin = AdminInvitation::accept($token, $password);

            AdminAccess::audit(
                'admin.invitation_accepted',
                [
                    'target_admin_id' => (int) ($admin['id'] ?? 0),
                    'email' => (string) ($admin['email'] ?? ''),
                    'role' => (string) ($admin['role_slug'] ?? '')
                ],
                (int) ($admin['id'] ?? 0)
            );

            $authenticated = AdminAccess::authenticate(
                $admin['email'] ?? '',
                $password
            );

            if (!$authenticated) {
                header('Location: /Anabelka/admin/login');
                exit;
            }

            header('Location: /Anabelka/admin');
            exit;
        } catch (Throwable $e) {
            $this->renderInvitation($token, $e->getMessage());
        }
    }


    public function audit()
    {
        $filters = AdminManagement::normalizeAuditFilters([
            'admin_id' => $_GET['admin_id'] ?? 0,
            'action' => $_GET['action'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? ''
        ]);

        $auditUnreadState = [
            'total' => 0,
            'cursor' => 0,
            'max_id' => 0,
            'by_actor' => []
        ];

        if (class_exists('AdminNotificationCenter')) {
            $auditUnreadState = AdminNotificationCenter::auditUnreadState(
                AdminAccess::currentId()
            );
        }

        $entries = AdminManagement::auditLog($filters, 250);
        $recentEntries = array_slice($entries, 0, 3);
        $olderAuditGroups = AdminManagement::groupAuditEntriesByAdministrator(
            array_slice($entries, 3)
        );

        $isFullJournal =
            (int) ($filters['admin_id'] ?? 0) === 0
            && (string) ($filters['action'] ?? '') === ''
            && (string) ($filters['date_from'] ?? '') === ''
            && (string) ($filters['date_to'] ?? '') === '';

        if ($isFullJournal && class_exists('AdminNotificationCenter')) {
            AdminNotificationCenter::markAuditSeen(
                AdminAccess::currentId(),
                (int) ($auditUnreadState['max_id'] ?? 0)
            );
        }

        $this->view('admin/administrators/audit', [
            'pageTitle' => 'Адмін-панель · Журнал дій',
            'entries' => $entries,
            'recentEntries' => $recentEntries,
            'olderAuditGroups' => $olderAuditGroups,
            'auditUnreadTotal' => (int) ($auditUnreadState['total'] ?? 0),
            'auditUnreadByActor' => is_array(
                $auditUnreadState['by_actor'] ?? null
            ) ? $auditUnreadState['by_actor'] : [],
            'auditAdministrators' => AdminManagement::auditAdministrators(),
            'auditActions' => AdminManagement::auditActions(),
            'filters' => $filters
        ]);
    }


    private function renderInvitation($token, $error = '')
    {
        $token = trim((string) $token);
        $invite = $token !== ''
            ? AdminInvitation::findByToken($token)
            : null;

        if (!$invite && trim((string) $error) === '') {
            $error = 'Запрошення недійсне або строк його дії закінчився.';
        }

        $this->view('admin/auth/invite', [
            'token' => $token,
            'invite' => $invite,
            'csrfToken' => AdminAccess::csrfToken(),
            'error' => trim((string) $error)
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
