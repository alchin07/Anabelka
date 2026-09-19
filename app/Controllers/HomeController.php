<?php

class HomeController extends Controller
{
    public function index()
    {
        PublicInterfaceTranslator::seed();
        HomeInterfaceTranslator::seed();

        $currentLanguage = Translator::currentLanguage();
        $languageCode = $currentLanguage['code']
            ?? Language::SOURCE_CODE;

        $directions = CategoryTranslator::localizeList(
            HomePage::directions(),
            $languageCode
        );

        $navigationTree = HomePage::localizedNavigationTree(
            $languageCode
        );

        $latestProducts = ProductTranslator::localizeList(
            HomePage::latestProducts(8),
            $languageCode
        );

        $homeNews = [];
        $homeReviews = [];

        try {
            $homeNews = SiteNews::latestPublished(
                3,
                $languageCode
            );
        } catch (Throwable $e) {
            error_log('Home news: ' . $e->getMessage());
            $homeNews = [];
        }

        try {
            $homeReviews = ProductReview::latestApprovedStandard(2);
        } catch (Throwable $e) {
            error_log('Home reviews: ' . $e->getMessage());
            $homeReviews = [];
        }

        $this->view(
            'home',
            [
                'currentLanguage' => $currentLanguage,
                'directions' => $directions,
                'navigationTree' => $navigationTree,
                'latestProducts' => $latestProducts,
                'homeNews' => $homeNews,
                'homeReviews' => $homeReviews
            ]
        );
    }
}
