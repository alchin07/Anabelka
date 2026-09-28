<?php

class AdminImageProcessorController extends Controller
{
    public function health()
    {
        try {
            $health = ImageProcessorClient::health();

            $this->json([
                'ok' => true,
                'processor' => $health
            ]);
        } catch (Throwable $e) {
            error_log(
                'Image processor health: '
                . $e->getMessage()
            );

            $this->json(
                [
                    'ok' => false,
                    'error' =>
                        'Локальний сервіс обробки зображень недоступний.'
                ],
                503
            );
        }
    }


    private function json(array $payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
