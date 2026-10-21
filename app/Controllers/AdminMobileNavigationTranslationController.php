<?php

class AdminMobileNavigationTranslationController extends AdminTranslationController
{
    public function index()
    {
        try {
            $service = new MobileNavigationTranslationDashboardService();
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
                    'coverage' => [],
                    'languageCoverage' => [],
                    'dashboardError' => $e->getMessage()
                ]
            );
        }
    }


    public function missing()
    {
        header(
            'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
        );
        header('Pragma: no-cache');

        $section = strtolower(
            trim((string) ($_GET['section'] ?? ''))
        );
        $filters = [
            'language' => $_GET['language'] ?? '',
            'key' => $_GET['translation_key'] ?? '',
            'source' => $_GET['source_text'] ?? ''
        ];

        try {
            $service = new MobileNavigationTranslationDashboardService();
            $data = $service->getMissingTranslations(
                $section,
                $filters
            );

            $this->view(
                'admin/translations/missing',
                $data
            );
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            $this->renderMissingError($section, $e->getMessage());
        } catch (Throwable $e) {
            http_response_code(500);
            $this->renderMissingError($section, $e->getMessage());
        }
    }


    private function renderMissingError($section, $message)
    {
        $this->view(
            'admin/translations/missing',
            [
                'section' => (string) $section,
                'sectionLabel' => 'Переклади',
                'sectionUrl' => '/Anabelka/admin/translations',
                'targetLanguages' => [],
                'items' => [],
                'totalItems' => 0,
                'filters' => [
                    'language' => '',
                    'key' => '',
                    'source' => ''
                ],
                'missingError' => (string) $message
            ]
        );
    }
}
