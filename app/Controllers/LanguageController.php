<?php

class LanguageController extends Controller
{
    public function change()
    {
        $code =
            trim(
                (string) ($_POST['language_code'] ?? '')
            );

        try {
            Translator::setCurrentLanguage($code);
        } catch (Throwable $e) {
            // При некорректном коде оставляем текущий язык без изменений.
        }

        $returnUrl =
            trim(
                (string) ($_POST['return_url'] ?? '/Anabelka/')
            );

        /*
         * Разрешаем возврат только внутрь проекта,
         * чтобы параметр нельзя было использовать
         * как внешний redirect.
         */
        if (
            strpos($returnUrl, '/Anabelka/') !== 0
            && $returnUrl !== '/Anabelka'
        ) {
            $returnUrl = '/Anabelka/';
        }

        header(
            'Location: ' . $returnUrl
        );

        exit;
    }
}
