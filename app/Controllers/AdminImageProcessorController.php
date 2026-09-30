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


    public function process()
    {
        try {
            $imageId = (int) ($_POST['image_id'] ?? 0);

            if ($imageId <= 0) {
                throw new InvalidArgumentException(
                    'Некоректна фотографія товару.'
                );
            }

            $result = ProductImageProcessingService::process($imageId);

            AdminAccess::audit(
                'product.image_processed',
                [
                    'product_id' => (int) ($result['product_id'] ?? 0),
                    'image_id' => $imageId,
                    'job_id' => (string) ($result['job_id'] ?? '')
                ],
                AdminAccess::currentId()
            );

            $this->json([
                'success' => true,
                'image_id' => $imageId,
                'processing' => $result
            ]);
        } catch (Throwable $e) {
            $status = $e instanceof InvalidArgumentException ? 400 : 500;

            error_log(
                'Product image processing: '
                . get_class($e)
                . ': '
                . $e->getMessage()
            );

            $message = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : 'Не вдалося обробити фотографію товару.';

            $host = strtolower(trim((string) (
                $_SERVER['HTTP_HOST'] ?? ''
            )));
            $isLocal = $host === 'localhost'
                || strpos($host, 'localhost:') === 0
                || $host === '127.0.0.1'
                || strpos($host, '127.0.0.1:') === 0;

            if ($isLocal && !($e instanceof InvalidArgumentException)) {
                $message .= ' [' . get_class($e)
                    . ': ' . $e->getMessage() . ']';
            }

            $this->json(
                [
                    'success' => false,
                    'message' => $message
                ],
                $status
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
