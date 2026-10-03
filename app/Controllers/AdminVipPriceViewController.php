<?php

class AdminVipPriceViewController extends Controller
{
    public function index()
    {
        $filters = VipPriceViewLog::normalizeFilters($_GET);
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 50;
        $total = VipPriceViewLog::count($filters);
        $pages = max(1, (int) ceil($total / $perPage));

        if ($page > $pages) {
            $page = $pages;
        }

        $rows = VipPriceViewLog::page(
            $filters,
            $page,
            $perPage
        );

        $this->view('admin/security/vip-price-views', [
            'pageTitle' => 'Адмін-панель · VIP-ціни',
            'rows' => $rows,
            'filters' => $filters,
            'summary' => VipPriceViewLog::summary($filters),
            'rankOptions' => VipPriceViewLog::rankOptions(),
            'surfaceOptions' => VipPriceViewLog::surfaceOptions(),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'perPage' => $perPage
        ]);
    }
}
