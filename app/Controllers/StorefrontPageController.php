<?php

class StorefrontPageController extends Controller
{
    public function discounts()
    {
        PublicInterfaceTranslator::seed();
        ContentInterfaceTranslator::seed();

        $currentLanguage = Translator::currentLanguage();
        $languageCode = (string) (
            $currentLanguage['code'] ?? Language::SOURCE_CODE
        );

        try {
            $collection = StorefrontProductCollection::page(
                'discounts',
                $_GET['page'] ?? 1,
                $languageCode
            );
        } catch (Throwable $e) {
            error_log(
                'Storefront discounts: '
                . get_class($e)
                . ': '
                . $e->getMessage()
            );
            http_response_code(503);
            $this->view('errors/public', [
                'errorCode' => 503,
                'errorTitle' => Translator::t(
                    'storefront.error_title',
                    'Розділ тимчасово недоступний'
                ),
                'errorMessage' => Translator::t(
                    'storefront.error_message',
                    'Спробуйте відкрити сторінку трохи пізніше.'
                )
            ]);
            return;
        }

        $this->view('storefront/discounts', [
            'currentLanguage' => $currentLanguage,
            'collection' => $collection,
            'pageTitle' => Translator::t(
                'storefront.discounts.title',
                'Знижки'
            ),
            'collectionPath' => '/Anabelka/discounts'
        ]);
    }


    public function newArrivals()
    {
        PublicInterfaceTranslator::seed();
        ContentInterfaceTranslator::seed();

        $currentLanguage = Translator::currentLanguage();
        $languageCode = (string) (
            $currentLanguage['code'] ?? Language::SOURCE_CODE
        );

        try {
            $collection = StorefrontProductCollection::page(
                'new',
                $_GET['page'] ?? 1,
                $languageCode
            );
        } catch (Throwable $e) {
            error_log(
                'Storefront new arrivals: '
                . get_class($e)
                . ': '
                . $e->getMessage()
            );
            http_response_code(503);
            $this->view('errors/public', [
                'errorCode' => 503,
                'errorTitle' => Translator::t(
                    'storefront.error_title',
                    'Розділ тимчасово недоступний'
                ),
                'errorMessage' => Translator::t(
                    'storefront.error_message',
                    'Спробуйте відкрити сторінку трохи пізніше.'
                )
            ]);
            return;
        }

        $this->view('storefront/new', [
            'currentLanguage' => $currentLanguage,
            'collection' => $collection,
            'pageTitle' => Translator::t(
                'storefront.new.title',
                'Новинки'
            ),
            'collectionPath' => '/Anabelka/new'
        ]);
    }
}
