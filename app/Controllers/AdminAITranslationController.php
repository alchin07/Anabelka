<?php

class AdminAITranslationController extends Controller
{
    public function suggest()
    {
        $targetLanguage = strtolower(
            trim((string) ($_POST['target_language'] ?? ''))
        );

        $name = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $context = trim((string) ($_POST['context'] ?? 'catalog'));
        $provider = strtolower(
            trim((string) ($_POST['provider'] ?? ''))
        );

        if ($targetLanguage === '') {
            $this->jsonError('Не выбран язык перевода.', 400);
        }

        try {
            $service = new AITranslationService();

            $translation = $service->suggest(
                $targetLanguage,
                $name,
                $description,
                $context,
                $provider !== '' ? $provider : null
            );

            $this->jsonSuccess([
                'translation' => $translation,
                'selected_provider' => $service->getDefaultProviderCode(),
                'providers' => $service->getProviders()
            ]);

        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 400);
        } catch (Throwable $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }


    public function providers()
    {
        try {
            $service = new AITranslationService();

            $this->jsonSuccess([
                'selected_provider' => $service->getDefaultProviderCode(),
                'providers' => $service->getProviders()
            ]);

        } catch (Throwable $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }


    public function setProvider()
    {
        $provider = strtolower(
            trim((string) ($_POST['provider'] ?? ''))
        );

        if ($provider === '') {
            $this->jsonError('Не выбран провайдер ИИ.', 400);
        }

        try {
            $service = new AITranslationService();
            $selected = $service->setDefaultProviderCode($provider);

            $this->jsonSuccess([
                'selected_provider' => $selected,
                'providers' => $service->getProviders()
            ]);

        } catch (InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 400);
        } catch (Throwable $e) {
            $this->jsonError($e->getMessage(), 500);
        }
    }


    private function jsonSuccess(array $data)
    {
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(
            array_merge(['success' => true], $data),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }


    private function jsonError($message, $status)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=UTF-8');

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
