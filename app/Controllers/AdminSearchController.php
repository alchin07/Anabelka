<?php

class AdminSearchController extends Controller
{
    public function index()
    {
        $filters = SearchQueryLog::normalizeFilters($_GET);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 100;
        $rows = SearchQueryLog::page($filters, $page, $perPage);
        $total = SearchQueryLog::count($filters);
        $summary = SearchQueryLog::summary();
        $periodAnalytics = SearchQueryLog::periodAnalytics();
        $popularQueries = SearchQueryLog::popularQueries(30, 10);
        $popularZeroQueries = SearchQueryLog::popularZeroQueries(30, 10);
        $pages = max(1, (int) ceil($total / $perPage));

        if ($page > $pages) {
            $page = $pages;
            $rows = SearchQueryLog::page($filters, $page, $perPage);
        }

        $this->view('admin/search/index', [
            'pageTitle' => 'Адмін-панель · Пошук',
            'rows' => $rows,
            'filters' => $filters,
            'summary' => $summary,
            'periodAnalytics' => $periodAnalytics,
            'popularQueries' => $popularQueries,
            'popularZeroQueries' => $popularZeroQueries,
            'page' => $page,
            'pages' => $pages,
            'total' => $total
        ]);
    }
}
