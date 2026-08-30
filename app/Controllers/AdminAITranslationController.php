<?php

class AdminAITranslationController extends Controller
{
    public function suggest()
    {
        $targetLanguage =
            strtolower(
                trim((string) ($_POST['target_language'] ?? ''))
            );

        $name =
            trim((string) ($_POST['name'] ?? ''));

        $description =
            trim((string) ($_POST['description'] ?? ''));

        $context =
            trim((string) ($_POST['context'] ?? 'catalog'));

        if ($targetLanguage === '') {
            $this->jsonError(
                'Не выбран язык перевода.',
                400
            );
        }

        try {
            $service = new AITranslationService();

            $translation = $service->suggest(
                $targetLanguage,
                $name,
                $description,
                $context
            );

            header(
                'Content-Type: application/json; charset=UTF-8'
            );

            echo json_encode(
                [
                    'success' => true,
                    'translation' => $translation
                ],
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
            exit;

        } catch (Throwable $e) {
            $this->jsonError(
                $e->getMessage(),
                500
            );
        }
    }


    private function jsonError($message, $status)
    {
        http_response_code((int) $status);

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        echo json_encode(
            [
                'success' => false,
                'message' => (string) $message
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}
