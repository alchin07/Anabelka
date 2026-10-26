<?php

class AdminErrorDisplayController extends Controller
{
    public function index()
    {
        $this->assertDeveloper();
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Robots-Tag: noindex, nofollow, noarchive');
        }
        $this->view('admin/system/error-display', [
            'pageTitle' => 'Адмін-панель · Режими відображення помилок',
            'mode' => ErrorDisplayMode::current(),
            'csrfToken' => AdminAccess::csrfToken()
        ]);
    }


    public function save()
    {
        $this->assertDeveloper();
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            PublicErrorPage::renderGeneric(405);
            return;
        }
        Csrf::enforce('admin');
        try {
            ErrorDisplayMode::save($_POST['mode'] ?? null, $_POST['confirmed'] ?? null);
            if (PublicErrorPage::expectsJson()) {
                header('Content-Type: application/json; charset=UTF-8');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('X-Robots-Tag: noindex, nofollow, noarchive');
                echo json_encode(['ok' => true, 'mode' => $_POST['mode']]);
                return;
            }
            AnabelkaFlash::success('Режим відображення помилок збережено.');
            header('Location: /Anabelka/admin/system/error-display', true, 303);
        } catch (InvalidArgumentException $e) {
            if (PublicErrorPage::expectsJson()) {
                PublicErrorPage::renderGeneric(422);
                return;
            }
            http_response_code(422);
            AnabelkaFlash::error($e->getMessage());
            $this->index();
        } catch (Throwable $e) {
            try {
                ErrorHandler::report($e, 'error', 'error_display_mode_save');
            } catch (Throwable $loggingError) {
                // The response must stay safe even when logging is unavailable.
            }
            PublicErrorPage::renderGeneric(503);
        }
    }


    private function assertDeveloper()
    {
        $admin = AdminAccess::currentForDiagnostics();
        if (!$admin || ($admin['role_slug'] ?? '') !== 'owner') {
            PublicErrorPage::renderGeneric(403, ['permission' => 'system.owner']);
            exit;
        }
        return $admin;
    }
}
