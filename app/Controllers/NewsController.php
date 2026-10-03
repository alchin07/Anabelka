<?php

class NewsController extends Controller
{
    public function index()
    {
        PublicInterfaceTranslator::seed();
        ContentInterfaceTranslator::seed();
        $currentLanguage = Translator::currentLanguage();
        $languageCode = $currentLanguage['code'] ?? Language::SOURCE_CODE;
        $newsItems = [];

        try {
            $newsItems = SiteNews::publishedPage($languageCode);
        } catch (Throwable $e) {
            error_log('Public news list: ' . $e->getMessage());
        }

        $this->view('news/index', [
            'currentLanguage' => $currentLanguage,
            'newsItems' => $newsItems
        ]);
    }


    public function show($slug)
    {
        PublicInterfaceTranslator::seed();
        ContentInterfaceTranslator::seed();
        $currentLanguage = Translator::currentLanguage();
        $languageCode = $currentLanguage['code'] ?? Language::SOURCE_CODE;
        $news = null;

        try {
            $news = SiteNews::findPublishedBySlug(
                (string) $slug,
                $languageCode
            );
        } catch (Throwable $e) {
            error_log('Public news item: ' . $e->getMessage());
        }

        if (!$news) {
            http_response_code(404);
            $this->view('errors/public', [
                'errorCode' => 404,
                'errorTitle' => Translator::t(
                    'news.not_found',
                    'Новину не знайдено'
                ),
                'errorMessage' => Translator::t(
                    'news.not_found_text',
                    'Ця публікація недоступна або ще не опублікована.'
                )
            ]);
            return;
        }

        $this->view('news/show', [
            'currentLanguage' => $currentLanguage,
            'news' => $news
        ]);
    }
}
