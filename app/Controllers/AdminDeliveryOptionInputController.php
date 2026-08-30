<?php

class AdminDeliveryOptionInputController extends Controller
{
    public function show()
    {
        $optionId =
            (int) (
                $_GET['option_id']
                ?? 0
            );

        if ($optionId <= 0) {
            http_response_code(400);
            exit('Некорректная опция доставки.');
        }

        $config =
            DeliveryOptionInput::getForOption(
                $optionId
            );

        $translations =
            DeliveryOptionInput::getTranslationsForOption(
                $optionId
            );

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            [
                'success' => true,
                'config' => $config,
                'translations' => $translations
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }


    /**
     * Сохранить настройку дополнительного поля,
     * которое покупатель заполняет для опции доставки.
     */
    public function save()
    {
        $optionId =
            (int) (
                $_POST['option_id']
                ?? 0
            );

        $isEnabled =
            (int) (
                $_POST['is_enabled']
                ?? 0
            ) === 1;

        $fieldLabel =
            trim(
                $_POST['field_label']
                ?? ''
            );

        $placeholder =
            trim(
                $_POST['placeholder']
                ?? ''
            );

        $translationsJson =
            (string) (
                $_POST['translations']
                ?? '{}'
            );


        if ($optionId <= 0) {
            http_response_code(400);
            echo 'Некорректная опция доставки.';
            exit;
        }

        $translations =
            json_decode(
                $translationsJson,
                true
            );

        if (!is_array($translations)) {
            http_response_code(400);
            exit('Некорректный формат переводов поля.');
        }


        try {
            DeliveryOptionInput::save(
                $optionId,
                $isEnabled,
                $fieldLabel,
                $placeholder
            );

            foreach ($translations as $languageCode => $translation) {
                if (!is_array($translation)) {
                    continue;
                }

                DeliveryOptionInput::saveTranslation(
                    $optionId,
                    $languageCode,
                    $translation['field_label'] ?? '',
                    $translation['placeholder'] ?? '',
                    'manual'
                );
            }

            header(
                'Content-Type: application/json; charset=UTF-8'
            );

            echo json_encode(
                [
                    'success' => true,
                    'option_id' => $optionId,
                    'is_enabled' => $isEnabled ? 1 : 0,
                    'field_label' => $fieldLabel,
                    'placeholder' => $placeholder
                ],
                JSON_UNESCAPED_UNICODE
            );

            exit;

        } catch (Throwable $e) {
            http_response_code(500);
            echo $e->getMessage();
            exit;
        }
    }
}
