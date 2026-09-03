<?php

class AdminDashboardController extends Controller
{
    public function index()
    {
        $overview = [
            'counts' => [],
            'recent_orders' => []
        ];
        $dashboardError = '';

        try {
            $overview = AdminDashboard::overview();
        } catch (Throwable $e) {
            $dashboardError = $e->getMessage();
        }

        $translationSummary = $this->translationSummary();
        $aiSummary = $this->aiSummary();

        $counts = is_array($overview['counts'] ?? null)
            ? $overview['counts']
            : [];

        $this->view(
            'admin/index',
            [
                'counts' => $counts,
                'recentOrders' => $overview['recent_orders'] ?? [],
                'translationSummary' => $translationSummary,
                'aiSummary' => $aiSummary,
                'dashboardError' => $dashboardError,
                'navBadges' => [
                    'regular_orders' => (int) ($counts['regular_new'] ?? 0),
                    'quick_orders' => (int) ($counts['quick_new'] ?? 0),
                    'translations' => (int) (
                        $translationSummary['attention'] ?? 0
                    )
                ]
            ]
        );
    }


    private function translationSummary()
    {
        $summary = [
            'available' => false,
            'attention' => 0,
            'approved' => 0,
            'required' => 0,
            'percent' => 100,
            'languages' => 0
        ];

        try {
            $data = (new TranslationDashboardService())
                ->getDashboardData();

            foreach ($data['coverage'] ?? [] as $item) {
                $summary['approved'] += (int) (
                    $item['translated'] ?? 0
                );
                $summary['required'] += (int) (
                    $item['required'] ?? 0
                );
            }

            foreach ($data['languageCoverage'] ?? [] as $language) {
                $summary['attention'] += (int) (
                    $language['attention'] ?? 0
                );
            }

            $summary['languages'] = count(
                $data['targetLanguages'] ?? []
            );
            $summary['percent'] = $summary['required'] > 0
                ? (int) round(
                    ($summary['approved'] / $summary['required']) * 100
                )
                : 100;
            $summary['available'] = true;
        } catch (Throwable $e) {
            // Інші блоки дашборда мають працювати незалежно.
        }

        return $summary;
    }


    private function aiSummary()
    {
        $summary = [
            'available' => false,
            'configured' => 0,
            'total' => 0,
            'current_code' => '',
            'current_name' => 'Не налаштовано',
            'state' => 'not_configured',
            'state_label' => 'Потрібен API-ключ'
        ];

        try {
            $service = new AITranslationService();
            $providers = $service->getProviders();
            $currentCode = $service->getCurrentProviderCode();
            $health = AITranslationProviderHealth::all();

            foreach ($providers as $provider) {
                if (!empty($provider['configured'])) {
                    $summary['configured']++;
                }
            }

            $summary['total'] = count($providers);
            $summary['current_code'] = $currentCode;
            $summary['current_name'] = (string) (
                $providers[$currentCode]['name'] ?? $currentCode
            );
            $summary['available'] = true;

            if ($summary['configured'] === 0) {
                return $summary;
            }

            $currentHealth = is_array($health[$currentCode] ?? null)
                ? $health[$currentCode]
                : [];

            if (!empty($currentHealth['is_success'])) {
                $summary['state'] = 'ready';
                $summary['state_label'] = 'Підключення працює';
            } elseif (!empty($currentHealth['checked_at'])) {
                $summary['state'] = 'error';
                $summary['state_label'] = 'Потрібна перевірка';
            } else {
                $summary['state'] = 'configured';
                $summary['state_label'] = 'Готовий до перевірки';
            }
        } catch (Throwable $e) {
            // Інші блоки дашборда мають працювати незалежно.
        }

        return $summary;
    }
}
