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


    public function missing()
    {
        $section = strtolower(
            trim((string) ($_GET['section'] ?? ''))
        );

        try {
            $service = new TranslationDashboardService();
            $data = $service->getMissingTranslations($section);

            $this->view(
                'admin/translations/missing',
                $data
            );

        } catch (InvalidArgumentException $e) {
            http_response_code(400);

            $this->view(
                'admin/translations/missing',
                [
                    'section' => $section,
                    'sectionLabel' => 'Переводы',
                    'sectionUrl' => '/Anabelka/admin/translations',
                    'targetLanguages' => [],
                    'items' => [],
                    'missingError' => $e->getMessage()
                ]
            );
        } catch (Throwable $e) {
            http_response_code(500);

            $this->view(
                'admin/translations/missing',
                [
                    'section' => $section,
                    'sectionLabel' => 'Переводы',
                    'sectionUrl' => '/Anabelka/admin/translations',
                    'targetLanguages' => [],
                    'items' => [],
                    'missingError' => $e->getMessage()
                ]
            );
        }
    }
}
