<?php

class DeliveryOptionInputController extends Controller
{
    /**
     * Вернуть настройку дополнительного поля
     * для выбранной опции доставки.
     */
    public function show()
    {
        $methodSlug =
            trim($_GET['method'] ?? '');

        $serviceSlug =
            trim($_GET['service'] ?? '');

        $optionSlug =
            trim($_GET['option'] ?? '');

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        if (
            $methodSlug === ''
            || $serviceSlug === ''
            || $optionSlug === ''
        ) {
            echo json_encode(
                [
                    'success' => true,
                    'is_enabled' => 0,
                    'field_label' => '',
                    'placeholder' => ''
                ],
                JSON_UNESCAPED_UNICODE
            );

            exit;
        }

        $config =
            DeliveryOptionInput::getPublicBySelection(
                $methodSlug,
                $serviceSlug,
                $optionSlug
            );

        echo json_encode(
            [
                'success' => true,
                'is_enabled' =>
                    !empty($config['is_enabled']) ? 1 : 0,
                'field_label' =>
                    $config['field_label'] ?? '',
                'placeholder' =>
                    $config['placeholder'] ?? ''
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }
}
