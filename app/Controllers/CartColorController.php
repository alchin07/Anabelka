<?php

class CartColorController extends Controller
{
    public function options()
    {
        header('Content-Type: application/json; charset=utf-8');

        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Потрібно увійти до акаунта.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $cartKey = trim((string) ($_GET['cart_key'] ?? ''));

        if ($cartKey === '') {
            echo json_encode([
                'success' => true,
                'requires_color' => false,
                'options' => []
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        try {
            $result = CartColorMigration::describeUserCartItem(
                (int) $_SESSION['user_id'],
                $cartKey
            );

            echo json_encode(
                array_merge(['success' => true], $result),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        exit;
    }


    public function assign()
    {
        header('Content-Type: application/json; charset=utf-8');

        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Потрібно увійти до акаунта.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $productId = (int) ($_POST['product_id'] ?? 0);
        $sizeId = (int) ($_POST['size_id'] ?? 0);
        $colorKey = trim((string) ($_POST['color_key'] ?? ''));

        try {
            CartColorMigration::assignUserVariantColor(
                (int) $_SESSION['user_id'],
                $productId,
                $sizeId,
                $colorKey
            );

            echo json_encode([
                'success' => true
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        exit;
    }
}
