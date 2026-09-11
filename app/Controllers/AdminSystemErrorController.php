<?php

class AdminSystemErrorController extends Controller
{
    public function index()
    {
        $this->assertDeveloper();

        $filters = [
            'level' => strtolower(trim((string) ($_GET['level'] ?? 'all'))),
            'date' => trim((string) ($_GET['date'] ?? '')),
            'q' => trim((string) ($_GET['q'] ?? ''))
        ];

        if (!in_array($filters['level'], ['all', 'info', 'warning', 'error', 'critical'], true)) {
            $filters['level'] = 'all';
        }

        if ($filters['date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date'])) {
            $filters['date'] = '';
        }

        $items = SystemErrorLog::recent($filters, 120);
        $reference = trim((string) ($_GET['ref'] ?? ''));
        $selected = $reference !== ''
            ? SystemErrorLog::findByReference($reference)
            : null;

        $this->view('admin/system/errors', [
            'pageTitle' => 'Адмін-панель · Системні помилки',
            'items' => $items,
            'summary' => SystemErrorLog::summary($items),
            'filters' => $filters,
            'availableDates' => SystemErrorLog::availableDates(),
            'selected' => $selected,
            'selectedReference' => $reference
        ]);
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
    }
}
