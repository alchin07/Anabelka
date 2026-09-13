<?php

class AdultController extends Controller
{
    public function entry($departmentSlug, $categorySlug)
    {
        $category = Category::findByDepartmentAndSlug(
            $departmentSlug,
            $categorySlug
        );

        $this->showGate($category);
    }


    public function confirm($departmentSlug, $categorySlug)
    {
        $category = Category::findByDepartmentAndSlug(
            $departmentSlug,
            $categorySlug
        );

        $this->confirmCategory($category);
    }


    public function legacyEntry($slug)
    {
        $category = $this->legacyCategory($slug);
        $url = AdultAccess::gateUrl(
            $category,
            $_GET['return'] ?? ''
        );

        header('Location: ' . $url, true, 301);
        exit;
    }


    public function legacyConfirm($slug)
    {
        $this->confirmCategory($this->legacyCategory($slug));
    }


    private function showGate($category)
    {
        HomeInterfaceTranslator::seed();
        $this->assertAdultCategory($category);
        $defaultReturn = Category::catalogUrl($category);
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

        $this->view('adult/gate', [
            'category' => $category,
            'returnUrl' => $returnUrl,
            'currentLanguage' => $currentLanguage,
            'accessDenied' => AdultAccess::isKnownUnderage(),
            'csrfToken' => CustomerAccount::csrfToken()
        ]);
    }


    private function confirmCategory($category)
    {
        $this->assertAdultCategory($category);
        $defaultReturn = Category::catalogUrl($category);
        $returnUrl = AdultAccess::safeReturnUrl(
            $_POST['return_url'] ?? $defaultReturn
        );

        if ($returnUrl === '') {
            $returnUrl = $defaultReturn;
        }

        if (!CustomerAccount::verifyCsrf($_POST['_csrf'] ?? '')) {
            header(
                'Location: '
                . AdultAccess::gateUrl($category, $returnUrl)
            );
            exit;
        }

        if (AdultAccess::isKnownUnderage()) {
            AdultAccess::clearConfirmation();
            header(
                'Location: '
                . AdultAccess::gateUrl($category, $returnUrl)
            );
            exit;
        }

        AdultAccess::confirm();
        header('Location: ' . $returnUrl);
        exit;
    }


    private function legacyCategory($slug)
    {
        $category = Category::findUniqueActiveBySlug($slug);
        $this->assertAdultCategory($category);

        return $category;
    }


    private function assertAdultCategory($category)
    {
        if ($category && !empty($category['effective_adult'])) {
            return;
        }

        http_response_code(404);
        die('Розділ не знайдено');
    }
}
