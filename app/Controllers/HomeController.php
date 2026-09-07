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

        $navigationTree = $this->localizeCategoryTree(
            HomePage::navigationTree(),
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


    private function localizeCategoryTree(array $nodes, $languageCode)
    {
        foreach ($nodes as &$node) {
            $children = is_array($node['children'] ?? null)
                ? $node['children']
                : [];

            unset($node['children']);

            $node = CategoryTranslator::localize(
                $node,
                $languageCode
            );

            $node['children'] = $this->localizeCategoryTree(
                $children,
                $languageCode
            );
        }
        unset($node);

        return $nodes;
    }
}
