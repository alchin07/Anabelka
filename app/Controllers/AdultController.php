<?php

class AdultController extends Controller
{
    public function entry($slug)
    {
        HomeInterfaceTranslator::seed();

        $category = Category::findBySlug($slug);

        if (
            !$category
            || !HomePage::isAdultCategoryId((int) ($category['id'] ?? 0))
        ) {
            http_response_code(404);
            die('Розділ не знайдено');
        }

        $defaultReturn = '/Anabelka/catalog/'
            . rawurlencode((string) $category['slug']);
        $returnUrl = AdultAccess::safeReturnUrl(
            $_GET['return'] ?? $defaultReturn
        );

        if ($returnUrl === '') {
            $returnUrl = $defaultReturn;
        }

        if (AdultAccess::isConfirmed()) {
            header('Location: ' . $returnUrl);
            exit;
        }

        $currentLanguage = Translator::currentLanguage();
        $category = CategoryTranslator::localize(
            $category,
            $currentLanguage['code'] ?? Language::SOURCE_CODE
        );

        $this->view(
            'adult/gate',
            [
                'category' => $category,
                'returnUrl' => $returnUrl,
                'currentLanguage' => $currentLanguage
            ]
        );
    }


    public function confirm($slug)
    {
        $category = Category::findBySlug($slug);

        if (
            !$category
            || !HomePage::isAdultCategoryId((int) ($category['id'] ?? 0))
        ) {
            http_response_code(404);
            die('Розділ не знайдено');
        }

        AdultAccess::confirm();

        $defaultReturn = '/Anabelka/catalog/'
            . rawurlencode((string) $category['slug']);
        $returnUrl = AdultAccess::safeReturnUrl(
            $_POST['return_url'] ?? $defaultReturn
        );

        if ($returnUrl === '') {
            $returnUrl = $defaultReturn;
        }

        header('Location: ' . $returnUrl);
        exit;
    }
}
