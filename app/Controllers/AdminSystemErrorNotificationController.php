<?php

class AdminSystemErrorNotificationController extends Controller
{
    public function status()
    {
        $admin = $this->developer();

        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        echo json_encode(
            [
                'ok' => true,
                'count' => SystemErrorNotification::unreadCount(
                    (int) ($admin['id'] ?? AdminAccess::currentId())
                ),
                'label' => 'Нові системні помилки',
                'url' => '/Anabelka/admin/system/errors'
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }


    private function developer()
    {
        $admin = AdminAccess::current();

        if (!$admin || (string) ($admin['role_slug'] ?? '') !== 'owner') {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(
                [
                    'ok' => false,
                    'error' => 'Недостатньо прав.'
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            exit;
        }

        return $admin;
    }
}
