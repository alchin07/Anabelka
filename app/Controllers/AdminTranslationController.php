<?php

class AdminTranslationController extends Controller
{
    public function index()
    {
        try {
            $service = new TranslationDashboardService();
            $data = $service->getDashboardData();

            $this->view(
                'admin/translations/index',
                $data
            );

        } catch (Throwable $e) {
            http_response_code(500);

            $this->view(
                'admin/translations/index',
                [
                    'sourceLanguage' => null,
                    'languages' => [],
                    'targetLanguages' => [],
                    'providers' => [],
                    'selectedProvider' => '',
                    'coverage' => [],
                    'dashboardError' => $e->getMessage()
                ]
            );
        }
    }
}
