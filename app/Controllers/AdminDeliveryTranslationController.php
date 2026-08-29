<?php

class AdminDeliveryTranslationController extends Controller
{
    public function show()
    {
        $type = trim($_GET['type'] ?? '');
        $id = (int) ($_GET['id'] ?? 0);

        if (
            !in_array($type, ['method', 'service', 'option'], true)
            || $id <= 0
        ) {
            http_response_code(400);
            exit('Некорректные данные.');
        }

        $translations =
            DeliveryTranslator::getForEntity(
                $type,
                $id
            );

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            [
                'success' => true,
                'translations' => $translations
            ],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }


    public function save()
    {
        $type = trim($_POST['type'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        $translationsJson =
            (string) ($_POST['translations'] ?? '{}');

        if (
            !in_array($type, ['method', 'service', 'option'], true)
            || $id <= 0
        ) {
            http_response_code(400);
            exit('Некорректные данные.');
        }

        $translations =
            json_decode(
                $translationsJson,
                true
            );

        if (!is_array($translations)) {
            http_response_code(400);
            exit('Некорректный формат переводов.');
        }

        try {
            foreach ($translations as $languageCode => $translation) {
                if (!is_array($translation)) {
                    continue;
                }

                DeliveryTranslator::saveForEntity(
                    $type,
                    $id,
                    $languageCode,
                    $translation['name'] ?? '',
                    $translation['description'] ?? '',
                    'manual'
                );
            }

            header(
                'Content-Type: application/json; charset=UTF-8'
            );

            echo json_encode(
                ['success' => true],
                JSON_UNESCAPED_UNICODE
            );

        } catch (Throwable $e) {
            http_response_code(400);
            echo $e->getMessage();
        }

        exit;
    }
}
