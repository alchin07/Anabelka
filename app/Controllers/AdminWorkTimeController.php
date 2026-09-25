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
        $flash = is_array($_SESSION['admin_work_time_flash'] ?? null)
            ? $_SESSION['admin_work_time_flash']
            : null;
        unset($_SESSION['admin_work_time_flash']);

        $this->view('admin/administrators/work-time', [
            'pageTitle' => 'Адмін-панель · Робочий час',
            'report' => $report,
            'period' => (string) (
                $report['range']['period'] ?? 'today'
            ),
            'csrfToken' => AdminAccess::csrfToken(),
            'flash' => $flash
        ]);
    }


    public function saveCompensation()
    {
        $period = trim((string) ($_POST['return_period'] ?? 'today'));
        if (!in_array($period, ['today', 'week', 'month'], true)) {
            $period = 'today';
        }

        try {
            $admin = AdminAccess::current();

            if (!AdminWorkTime::canViewReport($admin)) {
                throw new RuntimeException(
                    'Змінювати умови оплати може лише Розробник або Власник.'
                );
            }

            $targetAdminId = (int) ($_POST['admin_user_id'] ?? 0);

            AdminWorkTime::saveCompensation(
                $targetAdminId,
                $_POST['hourly_rate'] ?? '',
                $_POST['currency'] ?? 'UAH',
                $_POST['payout_type'] ?? 'monthly',
                $_POST['one_time_from'] ?? '',
                $_POST['one_time_to'] ?? '',
                (int) ($admin['id'] ?? 0)
            );

            AdminAccess::audit(
                'admin.compensation_updated',
                [
                    'target_admin_id' => $targetAdminId
                ],
                (int) ($admin['id'] ?? 0)
            );

            $_SESSION['admin_work_time_flash'] = [
                'type' => 'success',
                'message' => 'Умови оплати збережено.'
            ];
        } catch (Throwable $e) {
            $_SESSION['admin_work_time_flash'] = [
                'type' => 'error',
                'message' => $e->getMessage()
            ];
        }

        header(
            'Location: /Anabelka/admin/work-time?'
            . http_build_query(['period' => $period])
        );
        exit;
    }
}
