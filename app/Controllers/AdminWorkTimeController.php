<?php

class AdminWorkTimeController extends Controller
{
    public function heartbeat()
    {
        try {
            $admin = AdminAccess::current();

            if (!$admin) {
                throw new RuntimeException(
                    'Адміністратора не знайдено.'
                );
            }

            $result = AdminWorkTime::heartbeat(
                (int) ($admin['id'] ?? 0),
                $_POST['surface'] ?? '',
                $_POST['active_seconds'] ?? 0
            );

            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(
                ['ok' => true] + $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            exit;
        } catch (Throwable $e) {
            http_response_code(400);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(
                [
                    'ok' => false,
                    'message' => $e->getMessage()
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            exit;
        }
    }


    public function index()
    {
        $admin = AdminAccess::current();

        if (!AdminWorkTime::canViewReport($admin)) {
            http_response_code(403);

            if (
                class_exists('PublicErrorPage')
                && method_exists('PublicErrorPage', 'renderGeneric')
            ) {
                PublicErrorPage::renderGeneric(403);
                exit;
            }

            echo 'Доступ заборонено.';
            exit;
        }

        $period = trim((string) ($_GET['period'] ?? 'today'));
        $report = AdminWorkTime::report($period);

        $this->view('admin/administrators/work-time', [
            'pageTitle' => 'Адмін-панель · Робочий час',
            'report' => $report,
            'period' => (string) (
                $report['range']['period'] ?? 'today'
            )
        ]);
    }
}
