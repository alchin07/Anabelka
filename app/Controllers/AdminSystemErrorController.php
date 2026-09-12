<?php

class AdminSystemErrorController extends Controller
{
    public function index()
    {
        $admin = $this->assertDeveloper();

        if (class_exists('SystemErrorNotification')) {
            SystemErrorNotification::markSeen((int) ($admin['id'] ?? AdminAccess::currentId()));
        }

        $filters = [
            'level' => strtolower(trim((string) ($_GET['level'] ?? 'all'))),
            'status' => strtolower(trim((string) ($_GET['status'] ?? 'all'))),
            'date' => trim((string) ($_GET['date'] ?? '')),
            'q' => trim((string) ($_GET['q'] ?? ''))
        ];

        if (!in_array($filters['level'], ['all', 'info', 'warning', 'error', 'critical'], true)) {
            $filters['level'] = 'all';
        }

        if (!SystemErrorStatus::validFilterStatus($filters['status'])) {
            $filters['status'] = 'all';
        }

        if ($filters['date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date'])) {
            $filters['date'] = '';
        }

        $logFilters = [
            'level' => $filters['level'],
            'date' => $filters['date'],
            'q' => $filters['q']
        ];

        $rawItems = SystemErrorLog::recent($logFilters, 300);
        $items = SystemErrorNote::decorateGroups(
            SystemErrorStatus::decorateItems(
                SystemErrorLog::groupItems($rawItems, 300)
            )
        );

        $reference = trim((string) ($_GET['ref'] ?? ''));
        $selected = $reference !== ''
            ? SystemErrorLog::groupForReference($reference)
            : null;

        if (is_array($selected)) {
            $selected = SystemErrorStatus::decorateItems([$selected])[0] ?? $selected;
            $selected = SystemErrorNote::decorateGroups([$selected])[0] ?? $selected;

            if (($selected['workflow_status'] ?? 'new') === 'new') {
                SystemErrorStatus::markViewed(
                    (string) ($selected['reference'] ?? ''),
                    (int) ($admin['id'] ?? AdminAccess::currentId())
                );
                $selected = SystemErrorStatus::decorateItems([$selected])[0] ?? $selected;
                $selected = SystemErrorNote::decorateGroups([$selected])[0] ?? $selected;

                foreach ($items as &$item) {
                    if (($item['group_key'] ?? '') === ($selected['group_key'] ?? '')) {
                        $item['workflow_status'] = 'viewed';
                        $item['workflow_updated_at'] = (string) ($selected['workflow_updated_at'] ?? '');
                        $item['workflow_updated_by'] = (int) ($selected['workflow_updated_by'] ?? 0);
                        break;
                    }
                }
                unset($item);
            }
        }

        if ($filters['status'] !== 'all') {
            $wantedStatus = $filters['status'];
            $items = array_values(array_filter(
                $items,
                static function ($item) use ($wantedStatus) {
                    return (string) ($item['workflow_status'] ?? 'new') === $wantedStatus;
                }
            ));
        }

        $items = array_slice($items, 0, 120);

        $flash = is_array($_SESSION['admin_system_error_flash'] ?? null)
            ? $_SESSION['admin_system_error_flash']
            : null;
        unset($_SESSION['admin_system_error_flash']);

        $this->view('admin/system/errors', [
            'pageTitle' => 'Адмін-панель · Системні помилки',
            'items' => $items,
            'summary' => SystemErrorLog::summary($items),
            'workflowSummary' => SystemErrorStatus::summary($items),
            'filters' => $filters,
            'availableDates' => SystemErrorLog::availableDates(),
            'selected' => $selected,
            'selectedReference' => $reference,
            'csrfToken' => AdminAccess::csrfToken(),
            'flash' => $flash
        ]);
    }


    public function updateStatus()
    {
        $admin = $this->assertDeveloper();

        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            $_SESSION['admin_system_error_flash'] = [
                'type' => 'error',
                'message' => 'Сесія застаріла. Оновіть сторінку та спробуйте ще раз.'
            ];
            $this->redirectBack();
        }

        $reference = trim((string) ($_POST['reference'] ?? ''));
        $status = strtolower(trim((string) ($_POST['status'] ?? '')));
        $item = $reference !== ''
            ? SystemErrorLog::findByReference($reference)
            : null;

        if (!is_array($item)) {
            $_SESSION['admin_system_error_flash'] = [
                'type' => 'error',
                'message' => 'Запис системної помилки не знайдено.'
            ];
            $this->redirectBack();
        }

        if (!in_array($status, ['viewed', 'resolved', 'ignored'], true)) {
            $_SESSION['admin_system_error_flash'] = [
                'type' => 'error',
                'message' => 'Некоректний статус системної помилки.'
            ];
            $this->redirectBack($reference);
        }

        SystemErrorStatus::setStatus(
            $reference,
            $status,
            (int) ($admin['id'] ?? AdminAccess::currentId())
        );

        AdminAccess::audit(
            'system.error_status.update',
            [
                'reference' => $reference,
                'status' => $status
            ],
            (int) ($admin['id'] ?? AdminAccess::currentId())
        );

        $labels = [
            'viewed' => 'Помилку повернуто в роботу.',
            'resolved' => 'Помилку позначено як вирішену.',
            'ignored' => 'Помилку позначено як проігноровану.'
        ];

        $_SESSION['admin_system_error_flash'] = [
            'type' => 'success',
            'message' => $labels[$status] ?? 'Статус оновлено.'
        ];

        $this->redirectBack($reference);
    }


    public function updateNote()
    {
        $admin = $this->assertDeveloper();

        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            $_SESSION['admin_system_error_flash'] = [
                'type' => 'error',
                'message' => 'Сесія застаріла. Оновіть сторінку та спробуйте ще раз.'
            ];
            $this->redirectBack();
        }

        $reference = trim((string) ($_POST['reference'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));
        $group = $reference !== ''
            ? SystemErrorLog::groupForReference($reference)
            : null;

        if (!is_array($group)) {
            $_SESSION['admin_system_error_flash'] = [
                'type' => 'error',
                'message' => 'Групу системної помилки не знайдено.'
            ];
            $this->redirectBack();
        }

        $groupKey = (string) ($group['group_key'] ?? '');

        try {
            $result = SystemErrorNote::save(
                $groupKey,
                $note,
                (int) ($admin['id'] ?? AdminAccess::currentId())
            );
        } catch (InvalidArgumentException $e) {
            $_SESSION['admin_system_error_flash'] = [
                'type' => 'error',
                'message' => $e->getMessage()
            ];
            $this->redirectBack($reference);
        }

        AdminAccess::audit(
            'system.error_note.update',
            [
                'reference' => $reference,
                'group_key' => $groupKey,
                'action' => $result === 'deleted' ? 'deleted' : 'saved'
            ],
            (int) ($admin['id'] ?? AdminAccess::currentId())
        );

        $_SESSION['admin_system_error_flash'] = [
            'type' => 'success',
            'message' => $result === 'deleted'
                ? 'Нотатку розробника видалено.'
                : 'Нотатку розробника збережено.'
        ];

        $this->redirectBack($reference);
    }


    private function redirectBack($reference = '')
    {
        $params = [
            'level' => trim((string) ($_POST['filter_level'] ?? 'all')),
            'status' => trim((string) ($_POST['filter_status'] ?? 'all')),
            'date' => trim((string) ($_POST['filter_date'] ?? '')),
            'q' => trim((string) ($_POST['filter_q'] ?? '')),
            'ref' => trim((string) $reference)
        ];

        $params = array_filter(
            $params,
            static function ($value, $key) {
                if (($key === 'level' || $key === 'status') && $value === 'all') {
                    return false;
                }

                return $value !== '';
            },
            ARRAY_FILTER_USE_BOTH
        );

        $url = '/Anabelka/admin/system/errors';

        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        header('Location: ' . $url);
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
                . '<p>Журнал системних помилок доступний лише Розробнику.</p>'
                . '<p><a href="/Anabelka/admin">Повернутися до адмін-панелі</a></p>'
                . '</body></html>';
            exit;
        }

        return $admin;
    }
}
