<?php

class HomeController extends Controller
{
    public function index()
    {
        $builderPreview = false;
        $previewRequest = trim(
            (string) ($_GET['builder_preview'] ?? '')
        );

        if ($previewRequest === 'home') {
            if (!AdminAccess::can('home_page.view')) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=UTF-8');
                echo '403 — preview доступний лише авторизованому адміністратору.';
                return;
            }

            $builderPreview = true;
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Pragma: no-cache');
            header('X-Robots-Tag: noindex, nofollow');
        }

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

        try {
            $homeBlocks = HomePageBlock::activeByZone();
        } catch (Throwable $e) {
            error_log(
                'Home page builder fallback: ' . $e->getMessage()
            );
            $homeBlocks = HomePageBlock::fallbackActiveByZone();
        }

        $homeBlockData = [];

        foreach ($homeBlocks as $zoneBlocks) {
            foreach ($zoneBlocks as $block) {
                $key = (string) ($block['system_key'] ?? '');
                $type = (string) ($block['block_type'] ?? '');

                if ($key === '') {
                    continue;
                }

                try {
                    $homeBlockData[$key] = $this->blockData(
                        $block,
                        $type,
                        $languageCode
                    );
                } catch (Throwable $e) {
                    error_log(
                        'Home block ' . $key . ': ' . $e->getMessage()
                    );
                    $homeBlockData[$key] = [];
                }
            }
        }

        $this->view(
            'home',
            [
                'currentLanguage' => $currentLanguage,
                'directions' => $directions,
                'navigationTree' => $navigationTree,
                'homeBlocks' => $homeBlocks,
                'homeBlockData' => $homeBlockData,
                'builderPreview' => $builderPreview
            ]
        );
    }


    private function blockData(array $block, $type, $languageCode)
    {
        switch ($type) {
            case 'product_collection':
                return $this->productCollectionData(
                    $block,
                    $languageCode
                );

            case 'news':
                return [
                    'items' => SiteNews::latestPublished(
                        HomePageBlock::settingInt(
                            $block,
                            'limit',
                            3,
                            1,
                            10
                        ),
                        $languageCode
                    )
                ];

            case 'reviews':
                return [
                    'items' => ProductReview::latestApprovedStandard(
                        HomePageBlock::settingInt(
                            $block,
                            'limit',
                            2,
                            1,
                            10
                        )
                    )
                ];

            case 'gift_certificate':
                return [];

            default:
                return [];
        }
    }


    private function productCollectionData(
        array $block,
        $languageCode
    ) {
        $source = HomePageBlock::productSource($block);
        $limit = HomePageBlock::settingInt(
            $block,
            'limit',
            8,
            1,
            24
        );

        if ($source === 'latest') {
            $items = ProductTranslator::localizeList(
                HomePage::latestProducts($limit),
                $languageCode
            );

            return [
                'source' => $source,
                'items' => $items
            ];
        }

        $collection = StorefrontProductCollection::page(
            $source === 'discounts'
                ? 'discounts'
                : 'new',
            1,
            $languageCode
        );

        return [
            'source' => $source,
            'items' => array_slice(
                is_array($collection['items'] ?? null)
                    ? $collection['items']
                    : [],
                0,
                $limit
            )
        ];
    }
}
