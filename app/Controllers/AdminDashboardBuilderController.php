<?php

class AdminDashboardBuilderController extends Controller
{
    public function index()
    {
        $flash = is_array(
            $_SESSION['admin_dashboard_builder_flash'] ?? null
        )
            ? $_SESSION['admin_dashboard_builder_flash']
            : null;
        unset($_SESSION['admin_dashboard_builder_flash']);

        AdminDashboardLayout::ensureSchema();

        $this->view(
            'admin/dashboard-builder/index',
            [
                'pageTitle' =>
                    'Адмін-панель · Конструктор головної адмін-панелі',
                'blocks' => AdminDashboardLayout::allForAdmin(),
                'services' =>
                    AdminDashboardServiceRegistry::availableWithBadges(),
                'serviceGroups' =>
                    AdminDashboardServiceRegistry::groups(),
                'usedServiceKeys' =>
                    AdminDashboardLayout::usedServiceKeys(),
                'csrfToken' => AdminAccess::csrfToken(),
                'flash' => $flash
            ]
        );
    }


    public function createBlock()
    {
        try {
            $id = AdminDashboardLayout::createBlock(
                $_POST['title'] ?? ''
            );

            $this->audit(
                'dashboard.builder.block.create',
                ['block_id' => $id]
            );
            $this->success('Блок створено.');
        } catch (Throwable $e) {
            $this->failure($e);
        }
    }


    public function updateBlock()
    {
        try {
            $blockId = (int) ($_POST['block_id'] ?? 0);

            AdminDashboardLayout::updateBlock(
                $blockId,
                $_POST['title'] ?? ''
            );

            $this->audit(
                'dashboard.builder.block.update',
                ['block_id' => $blockId]
            );
            $this->success('Назву блоку збережено.');
        } catch (Throwable $e) {
            $this->failure($e);
        }
    }


    public function toggleBlock()
    {
        try {
            $blockId = (int) ($_POST['block_id'] ?? 0);

            AdminDashboardLayout::toggleBlock($blockId);

            $this->audit(
                'dashboard.builder.block.toggle',
                ['block_id' => $blockId]
            );
            $this->success('Видимість блоку змінено.');
        } catch (Throwable $e) {
            $this->failure($e);
        }
    }


    public function deleteBlock()
    {
        try {
            $blockId = (int) ($_POST['block_id'] ?? 0);

            AdminDashboardLayout::deleteBlock($blockId);

            $this->audit(
                'dashboard.builder.block.delete',
                ['block_id' => $blockId]
            );
            $this->success(
                'Блок і його ярлики видалено. Служби та їх дані не змінювалися.'
            );
        } catch (Throwable $e) {
            $this->failure($e);
        }
    }


    public function createLink()
    {
        try {
            $blockId = (int) ($_POST['block_id'] ?? 0);
            $serviceKey = $_POST['service_key'] ?? '';
            $linkId = AdminDashboardLayout::createLink(
                $blockId,
                $serviceKey,
                $_POST['label_override'] ?? ''
            );

            $this->audit(
                'dashboard.builder.link.create',
                [
                    'link_id' => $linkId,
                    'block_id' => $blockId,
                    'service_key' => (string) $serviceKey
                ]
            );
            $this->success('Службу додано до блоку.');
        } catch (Throwable $e) {
            $this->failure($e);
        }
    }


    public function updateLink()
    {
        try {
            $linkId = (int) ($_POST['link_id'] ?? 0);
            $serviceKey = $_POST['service_key'] ?? '';

            AdminDashboardLayout::updateLink(
                $linkId,
                $serviceKey,
                $_POST['label_override'] ?? ''
            );

            $this->audit(
                'dashboard.builder.link.update',
                [
                    'link_id' => $linkId,
                    'service_key' => (string) $serviceKey
                ]
            );
            $this->success('Посилання служби збережено.');
        } catch (Throwable $e) {
            $this->failure($e);
        }
    }


    public function toggleLink()
    {
        try {
            $linkId = (int) ($_POST['link_id'] ?? 0);

            AdminDashboardLayout::toggleLink($linkId);

            $this->audit(
                'dashboard.builder.link.toggle',
                ['link_id' => $linkId]
            );
            $this->success('Видимість посилання змінено.');
        } catch (Throwable $e) {
            $this->failure($e);
        }
    }


    public function deleteLink()
    {
        try {
            $linkId = (int) ($_POST['link_id'] ?? 0);

            AdminDashboardLayout::deleteLink($linkId);

            $this->audit(
                'dashboard.builder.link.delete',
                ['link_id' => $linkId]
            );
            $this->success(
                'Ярлик видалено. Сама служба та її дані залишилися без змін.'
            );
        } catch (Throwable $e) {
            $this->failure($e);
        }
    }


    public function reorderBlocks()
    {
        try {
            $blockIds = is_array($_POST['block_ids'] ?? null)
                ? $_POST['block_ids']
                : [];
            $changed = AdminDashboardLayout::reorderBlocks(
                $blockIds
            );

            $this->audit(
                'dashboard.builder.blocks.reorder',
                [
                    'block_ids' => array_values(
                        array_map('intval', $blockIds)
                    )
                ]
            );

            $this->jsonSuccess(
                'Порядок блоків збережено.',
                ['changed' => (bool) $changed]
            );
        } catch (Throwable $e) {
            $this->jsonFailure($e);
        }
    }


    public function reorderLinks()
    {
        try {
            $rawLayout = trim(
                (string) ($_POST['layout'] ?? '')
            );
            $layout = $rawLayout !== ''
                ? json_decode($rawLayout, true)
                : null;

            if (
                $rawLayout === ''
                || !is_array($layout)
                || json_last_error() !== JSON_ERROR_NONE
            ) {
                throw new InvalidArgumentException(
                    'Некоректна розкладка ярликів.'
                );
            }

            $changed = AdminDashboardLayout::reorderLinks(
                $layout
            );

            $this->audit(
                'dashboard.builder.links.reorder',
                [
                    'blocks' => count($layout)
                ]
            );

            $this->jsonSuccess(
                'Розкладку ярликів збережено.',
                ['changed' => (bool) $changed]
            );
        } catch (Throwable $e) {
            $this->jsonFailure($e);
        }
    }


    private function jsonSuccess($message, array $extra = [])
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(
            array_merge(
                [
                    'success' => true,
                    'message' => (string) $message
                ],
                $extra
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }


    private function jsonFailure(Throwable $error)
    {
        $expected = $error instanceof DomainException
            || $error instanceof InvalidArgumentException;

        if (!$expected) {
            error_log(
                'Admin dashboard builder reorder: '
                . get_class($error)
                . ': '
                . $error->getMessage()
            );
        }

        http_response_code($expected ? 422 : 500);
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(
            [
                'success' => false,
                'message' => $expected
                    ? $error->getMessage()
                    : 'Не вдалося зберегти новий порядок.'
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }


    private function audit($action, array $details)
    {
        if (!class_exists('AdminAccess')) {
            return;
        }

        AdminAccess::audit(
            (string) $action,
            $details,
            AdminAccess::currentId()
        );
    }


    private function success($message)
    {
        $_SESSION['admin_dashboard_builder_flash'] = [
            'type' => 'success',
            'message' => (string) $message
        ];

        $this->redirect();
    }


    private function failure(Throwable $error)
    {
        if (
            !($error instanceof DomainException)
            && !($error instanceof InvalidArgumentException)
        ) {
            error_log(
                'Admin dashboard builder: '
                . get_class($error)
                . ': '
                . $error->getMessage()
            );
        }

        $_SESSION['admin_dashboard_builder_flash'] = [
            'type' => 'error',
            'message' => (
                $error instanceof DomainException
                || $error instanceof InvalidArgumentException
            )
                ? $error->getMessage()
                : 'Не вдалося виконати операцію конструктора.'
        ];

        $this->redirect();
    }


    private function redirect()
    {
        header(
            'Location: /Anabelka/admin/dashboard-builder'
        );
        exit;
    }
}
