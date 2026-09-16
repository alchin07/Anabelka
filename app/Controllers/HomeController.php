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

        $this->view(
            'home',
            [
                'currentLanguage' => $currentLanguage,
                'directions' => $directions,
                'navigationTree' => $navigationTree,
                'latestProducts' => $latestProducts
            ]
        );
    }
}
