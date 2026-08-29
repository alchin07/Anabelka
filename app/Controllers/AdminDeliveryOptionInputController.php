<?php

class AdminDeliveryOptionInputController extends Controller
{
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


        if ($optionId <= 0) {
            http_response_code(400);
            echo 'Некорректная опция доставки.';
            exit;
        }


        try {
            DeliveryOptionInput::save(
                $optionId,
                $isEnabled,
                $fieldLabel,
                $placeholder
            );

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

        } catch (PDOException $e) {
            http_response_code(500);
            echo 'Не удалось сохранить настройку поля доставки.';
            exit;
        }
    }
}
