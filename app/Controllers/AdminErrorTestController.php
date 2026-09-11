<?php

class AdminErrorTestController extends Controller
{
    public function index()
    {
        $this->assertDeveloper();

        $this->view('admin/system/error-test', [
            'csrfToken' => AdminAccess::csrfToken()
        ]);
    }


    public function trigger()
    {
        $this->assertDeveloper();

        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            throw new RuntimeException(
                'Сесію тесту помилки застаріло. Оновіть сторінку та спробуйте ще раз.'
            );
        }

        AdminAccess::audit('system.error_test', [
            'source' => 'admin_error_test'
        ], AdminAccess::currentId());

        throw new RuntimeException(
            'Тестова помилка системного обробника Анабельки.'
        );
    }


    private function assertDeveloper()
    {
        $admin = AdminAccess::current();

        if (!$admin || (string) ($admin['role_slug'] ?? '') !== 'owner') {
            http_response_code(403);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!DOCTYPE html><html lang="uk"><head><meta charset="UTF-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>Доступ заборонено — Анабелька</title></head>'
                . '<body style="font-family:Arial,sans-serif;padding:24px">'
                . '<h1>403 — Недостатньо прав</h1>'
                . '<p>Тест системного обробника помилок доступний лише Розробнику.</p>'
                . '<p><a href="/Anabelka/admin">Повернутися до адмін-панелі</a></p>'
                . '</body></html>';
            exit;
        }
    }
}
