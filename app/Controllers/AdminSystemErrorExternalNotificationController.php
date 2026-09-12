<?php

class AdminSystemErrorExternalNotificationController extends Controller
{
    public function index()
    {
        $admin = $this->assertDeveloper();
        $flash = is_array($_SESSION['admin_system_error_external_flash'] ?? null)
            ? $_SESSION['admin_system_error_external_flash']
            : null;
        unset($_SESSION['admin_system_error_external_flash']);

        $settings = SystemErrorExternalNotificationSettings::get();

        $this->view('admin/system/error-external-notifications', [
            'pageTitle' => 'Адмін-панель · Зовнішні сповіщення про помилки',
            'settings' => $settings,
            'transportStatus' => SystemErrorExternalNotifier::status($settings),
            'csrfToken' => AdminAccess::csrfToken(),
            'flash' => $flash,
            'admin' => $admin
        ]);
    }


    public function save()
    {
        $admin = $this->assertDeveloper();

        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            $_SESSION['admin_system_error_external_flash'] = [
                'type' => 'error',
                'message' => 'Сесія застаріла. Оновіть сторінку та спробуйте ще раз.'
            ];
            $this->redirect();
        }

        try {
            $settings = SystemErrorExternalNotificationSettings::save($_POST);

            AdminAccess::audit(
                'system.error_external_notifications.update',
                [
                    'enabled' => !empty($settings['enabled']),
                    'levels' => $settings['levels'] ?? [],
                    'channels' => $settings['channels'] ?? [],
                    'repeat_threshold' => (int) ($settings['repeat_threshold'] ?? 0),
                    'cooldown_minutes' => (int) ($settings['cooldown_minutes'] ?? 0),
                    'critical_immediate' => !empty($settings['critical_immediate'])
                ],
                (int) ($admin['id'] ?? AdminAccess::currentId())
            );

            $_SESSION['admin_system_error_external_flash'] = [
                'type' => 'success',
                'message' => 'Налаштування зовнішніх сповіщень збережено.'
            ];
        } catch (Throwable $e) {
            $_SESSION['admin_system_error_external_flash'] = [
                'type' => 'error',
                'message' => $e->getMessage()
            ];
        }

        $this->redirect();
    }


    public function test()
    {
        $admin = $this->assertDeveloper();

        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            $_SESSION['admin_system_error_external_flash'] = [
                'type' => 'error',
                'message' => 'Сесія застаріла. Оновіть сторінку та спробуйте ще раз.'
            ];
            $this->redirect();
        }

        try {
            $settings = SystemErrorExternalNotificationSettings::get();
            $results = SystemErrorExternalNotifier::sendTest($settings);
            $messages = [];
            $allOk = !empty($results);
            $auditResults = [];

            foreach ($results as $channel => $result) {
                $ok = !empty($result['ok']);
                $allOk = $allOk && $ok;
                $messages[] = (string) ($result['message'] ?? '');
                $auditResults[(string) $channel] = $ok;
            }

            AdminAccess::audit(
                'system.error_external_notifications.test',
                [
                    'channels' => array_keys($results),
                    'results' => $auditResults
                ],
                (int) ($admin['id'] ?? AdminAccess::currentId())
            );

            $_SESSION['admin_system_error_external_flash'] = [
                'type' => $allOk ? 'success' : 'error',
                'message' => implode(' ', array_filter($messages))
            ];
        } catch (Throwable $e) {
            $_SESSION['admin_system_error_external_flash'] = [
                'type' => 'error',
                'message' => $e->getMessage()
            ];
        }

        $this->redirect();
    }


    private function redirect()
    {
        header('Location: /Anabelka/admin/system/error-external-notifications');
        exit;
    }


    private function assertDeveloper()
    {
        $admin = AdminAccess::current();

        if (!$admin) {
            header('Location: /Anabelka/admin/login');
            exit;
        }

        if ((string) ($admin['role_slug'] ?? '') !== 'owner') {
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>Доступ заборонено — Анабелька</title></head>'
                . '<body style="font-family:Arial,sans-serif;padding:24px">'
                . '<h1>403 — Недостатньо прав</h1>'
                . '<p>Налаштування зовнішніх сповіщень доступні лише Розробнику.</p>'
                . '<p><a href="/Anabelka/admin">Повернутися до адмін-панелі</a></p>'
                . '</body></html>';
            exit;
        }

        return $admin;
    }
}
