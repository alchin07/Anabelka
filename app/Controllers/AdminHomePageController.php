<?php

class AdminHomePageController extends Controller
{
    public function index()
    {
        $this->view('admin/home-page/index', [
            'pageTitle' => 'Адмін-панель · Головна сторінка',
            'blocksByZone' => HomePageBlock::allForAdmin(),
            'zoneLabels' => HomePageBlock::zoneLabels(),
            'catalog' => HomePageBlock::catalog(),
            'message' => trim((string) ($_GET['message'] ?? '')),
            'error' => trim((string) ($_GET['error'] ?? '')),
            'csrfToken' => AdminAccess::csrfToken()
        ]);
    }


    public function create()
    {
        try {
            HomePageBlock::create(
                $_POST['block_type'] ?? '',
                $_POST['zone'] ?? ''
            );

            $this->redirect(
                'message',
                'Новий блок додано.'
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function delete()
    {
        try {
            HomePageBlock::delete(
                (int) ($_POST['block_id'] ?? 0)
            );

            $this->redirect(
                'message',
                'Блок видалено.'
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function update()
    {
        try {
            HomePageBlock::updateSettings(
                (int) ($_POST['block_id'] ?? 0),
                is_array($_POST['settings'] ?? null)
                    ? $_POST['settings']
                    : []
            );

            $this->redirect('message', 'Налаштування блоку збережено.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function toggle()
    {
        try {
            HomePageBlock::toggle(
                (int) ($_POST['block_id'] ?? 0)
            );

            $this->redirect('message', 'Видимість блоку змінено.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function move()
    {
        try {
            HomePageBlock::move(
                (int) ($_POST['block_id'] ?? 0),
                $_POST['direction'] ?? ''
            );

            $this->redirect('message', 'Порядок блоків оновлено.');
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function reorder()
    {
        try {
            $changed = HomePageBlock::reorder(
                $_POST['zone'] ?? '',
                is_array($_POST['block_ids'] ?? null)
                    ? $_POST['block_ids']
                    : []
            );

            $this->jsonResponse([
                'success' => true,
                'changed' => (bool) $changed,
                'message' => $changed
                    ? 'Порядок блоків збережено.'
                    : 'Порядок блоків не змінився.'
            ]);
        } catch (Throwable $e) {
            if (
                $e instanceof DomainException
                || $e instanceof InvalidArgumentException
            ) {
                $this->jsonResponse([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 422);
            }

            error_log(
                'Home page builder reorder: '
                . get_class($e)
                . ': '
                . $e->getMessage()
            );

            $this->jsonResponse([
                'success' => false,
                'message' => 'Не вдалося зберегти порядок блоків.'
            ], 500);
        }
    }


    private function jsonResponse(array $payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }


    private function redirect($key, $message)
    {
        header(
            'Location: /Anabelka/admin/home-page?'
            . http_build_query([
                $key => trim((string) $message)
            ])
        );
        exit;
    }
}
