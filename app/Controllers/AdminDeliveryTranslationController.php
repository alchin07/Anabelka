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

        $db = null;

        try {
            $storedBefore = DeliveryTranslator::getForEntity(
                $type,
                $id
            );
            $db = Database::connect();
            $db->beginTransaction();

            foreach ($translations as $languageCode => $translation) {
                if (!is_array($translation)) {
                    continue;
                }

                $languageCode = strtolower(
                    trim((string) $languageCode)
                );

                $stored = is_array(
                    $storedBefore[$languageCode] ?? null
                )
                    ? $storedBefore[$languageCode]
                    : [];

                $name = trim((string) (
                    $translation['name'] ?? ''
                ));
                $description = trim((string) (
                    $translation['description'] ?? ''
                ));
                $source = TranslationWorkflow::normalizeSource(
                    $translation['source']
                    ?? ($stored['source'] ?? 'manual')
                );
                $status = TranslationWorkflow::normalizeStatus(
                    $translation['status']
                    ?? ($stored['status'] ?? 'approved'),
                    $name !== '' || $description !== ''
                );
                $originalStatus = TranslationWorkflow::normalizeStatus(
                    $translation['original_status']
                    ?? ($stored['status'] ?? 'approved'),
                    $name !== '' || $description !== ''
                );

                $translationChanged =
                    TranslationWorkflow::translationChanged(
                        $stored,
                        $name,
                        $description
                    );

                /*
                 * Джерело Delivery зберігається окремим запитом і вже
                 * могло зробити запис outdated. Не скасовуємо це
                 * автоматично, якщо користувач не змінював переклад
                 * або статус у відкритій формі.
                 */
                if (
                    ($stored['status'] ?? '') === 'outdated'
                    && !$translationChanged
                    && $status === $originalStatus
                ) {
                    continue;
                }

                DeliveryTranslator::saveForEntity(
                    $type,
                    $id,
                    $languageCode,
                    $name,
                    $description,
                    $source,
                    $status
                );
            }

            $db->commit();

            header(
                'Content-Type: application/json; charset=UTF-8'
            );

            echo json_encode(
                ['success' => true],
                JSON_UNESCAPED_UNICODE
            );

        } catch (Throwable $e) {
            if ($db instanceof PDO && $db->inTransaction()) {
                $db->rollBack();
            }

            http_response_code(400);
            echo $e->getMessage();
        }

        exit;
    }
}
