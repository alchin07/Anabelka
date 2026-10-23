<?php

class AdminVisitorStatsController extends Controller
{
    public function index()
    {
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');

        if (!AdminAccess::can('visitor_stats.view')) {
            http_response_code(403);
            if (class_exists('PublicErrorPage') && method_exists('PublicErrorPage', 'renderGeneric')) {
                PublicErrorPage::renderGeneric(403);
                return;
            }
            echo 'Доступ заборонено.';
            return;
        }

        $period = filter_var($_GET['period'] ?? 30, FILTER_VALIDATE_INT);
        $period = in_array($period, [7, 30, 90], true) ? $period : 30;
        $report = null;
        $reportUnavailable = false;
        try {
            $config = require __DIR__ . '/../../config/visitor-stats.php';
            $timezoneName = trim((string) ($config['timezone'] ?? 'Europe/Kyiv'));
            $timezone = new DateTimeZone($timezoneName !== '' ? $timezoneName : 'Europe/Kyiv');
            $report = (new VisitorStats(Database::connect(), $timezone))->report($period);
        } catch (Throwable $error) {
            $reportUnavailable = true;
        }

        $this->view('admin/visitor-stats/index', [
            'pageTitle' => 'Адмін-панель · Статистика відвідуваності',
            'report' => $report,
            'reportUnavailable' => $reportUnavailable,
            'period' => $period
        ]);
    }
}
