<?php

class AdminMobileNavigationController extends Controller
{
    public function index()
    {
        $this->requirePermission('mobile_navigation.view', false);

        $items = [];
        $revision = '';
        $migrationRequired = false;
        $loadError = '';

        try {
            $snapshot = MobileNavigation::adminItems();
            $items = is_array($snapshot['items'] ?? null)
                ? $snapshot['items']
                : [];
            $revision = (string) ($snapshot['revision'] ?? '');
        } catch (Throwable $e) {
            error_log(
                'Admin mobile navigation list: '
                . get_class($e)
                . ': '
                . $e->getMessage()
            );
            $migrationRequired = true;
            $loadError =
                'Мобільне меню ще не підготовлено. '
                . 'Після резервної копії застосуйте ручну міграцію.';
        }

        $highlight = filter_var(
            $_GET['highlight'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $this->view('admin/mobile-navigation/index', [
            'items' => $items,
            'revision' => $revision,
            'languages' => Language::active(),
            'csrfToken' => AdminAccess::csrfToken(),
            'canManage' => AdminAccess::can('mobile_navigation.manage'),
            'migrationRequired' => $migrationRequired,
            'loadError' => $loadError,
            'highlight' => $highlight === false ? 0 : (int) $highlight
        ]);
    }


    public function create()
    {
        $adminId = $this->verifyMutationAccess();

        try {
            $itemId = MobileNavigation::create($_POST);
            AdminAccess::audit(
                'mobile_navigation.create',
                ['item_id' => $itemId],
                $adminId
            );
            $this->jsonSuccess('Пункт мобільного меню створено.', [
                'item_id' => $itemId
            ]);
        } catch (Throwable $e) {
            $this->handleMutationFailure($e, 'Не вдалося створити пункт меню.');
        }
    }


    public function update()
    {
        $adminId = $this->verifyMutationAccess();
        $itemId = $this->postedId();

        try {
            MobileNavigation::update($itemId, $_POST);
            AdminAccess::audit(
                'mobile_navigation.update',
                ['item_id' => $itemId],
                $adminId
            );
            $this->jsonSuccess('Пункт мобільного меню збережено.');
        } catch (Throwable $e) {
            $this->handleMutationFailure($e, 'Не вдалося зберегти пункт меню.');
        }
    }


    public function toggle()
    {
        $adminId = $this->verifyMutationAccess();
        $itemId = $this->postedId();
        $active = $_POST['is_active'] ?? 0;

        try {
            MobileNavigation::setActive($itemId, $active);
            AdminAccess::audit(
                'mobile_navigation.toggle',
                [
                    'item_id' => $itemId,
                    'is_active' => !empty($active) ? 1 : 0
                ],
                $adminId
            );
            $this->jsonSuccess('Стан пункту мобільного меню змінено.');
        } catch (Throwable $e) {
            $this->handleMutationFailure($e, 'Не вдалося змінити стан пункту меню.');
        }
    }


    public function move()
    {
        $adminId = $this->verifyMutationAccess();
        $ids = $_POST['ids'] ?? [];

        if (!is_array($ids)) {
            $this->jsonValidationError('Некоректний порядок меню.');
        }

        try {
            $revision = MobileNavigation::reorder(
                $ids,
                $_POST['revision'] ?? ''
            );
            AdminAccess::audit(
                'mobile_navigation.move',
                ['ids' => array_values(array_map('intval', $ids))],
                $adminId
            );
            $this->jsonSuccess('Порядок мобільного меню збережено.', [
                'revision' => $revision
            ]);
        } catch (Throwable $e) {
            $this->handleMutationFailure($e, 'Не вдалося змінити порядок меню.');
        }
    }


    public function delete()
    {
        $adminId = $this->verifyMutationAccess();
        $itemId = $this->postedId();

        try {
            MobileNavigation::delete($itemId);
            AdminAccess::audit(
                'mobile_navigation.delete',
                ['item_id' => $itemId],
                $adminId
            );
            $this->jsonSuccess('Пункт мобільного меню видалено.');
        } catch (Throwable $e) {
            $this->handleMutationFailure($e, 'Не вдалося видалити пункт меню.');
        }
    }


    private function verifyMutationAccess()
    {
        $this->requirePermission('mobile_navigation.manage', true);

        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            $this->jsonCsrfError();
        }

        $adminId = AdminAccess::currentId();

        if ($adminId <= 0) {
            $this->jsonForbidden();
        }

        return $adminId;
    }


    private function requirePermission($permission, $json)
    {
        if (AdminAccess::can((string) $permission)) {
            return;
        }

        http_response_code(403);

        if ($json) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(
                [
                    'ok' => false,
                    'message' => 'Недостатньо прав для цієї дії.'
                ],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        echo '403 — Недостатньо прав';
        exit;
    }


    private function postedId()
    {
        $id = filter_var(
            $_POST['item_id'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($id === false) {
            $this->jsonValidationError('Некоректний пункт мобільного меню.');
        }

        return (int) $id;
    }


    private function handleMutationFailure(Throwable $error, $fallback)
    {
        if (
            $error instanceof InvalidArgumentException
            || $error instanceof DomainException
        ) {
            $this->jsonValidationError($error->getMessage());
        }

        if (
            $error instanceof RuntimeException
            && (
                stripos($error->getMessage(), 'змінився') !== false
                || stripos($error->getMessage(), 'оновіть сторінку') !== false
            )
        ) {
            $this->jsonConflictError($error->getMessage());
        }

        if ($error instanceof RuntimeException) {
            $this->jsonValidationError($error->getMessage());
        }

        error_log(
            'Admin mobile navigation mutation: '
            . get_class($error)
            . ': '
            . $error->getMessage()
        );

        if ($this->isMissingSchemaError($error)) {
            $this->jsonUnavailableError(
                'Мобільне меню ще не підготовлено. Застосуйте ручну міграцію.'
            );
        }

        $this->jsonUnexpectedError((string) $fallback);
    }


    private function isMissingSchemaError(Throwable $error)
    {
        $message = strtolower($error->getMessage());

        return strpos($message, 'mobile_navigation_items') !== false
            || strpos($message, 'mobile_navigation_item_translations') !== false
            || strpos($message, '42s02') !== false
            || strpos($message, 'base table') !== false;
    }


    private function jsonSuccess($message, array $extra = [])
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            array_merge(
                [
                    'ok' => true,
                    'message' => (string) $message
                ],
                $extra
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }


    private function jsonCsrfError()
    {
        http_response_code(419);
        $this->jsonErrorBody('Сесію форми завершено. Оновіть сторінку.');
    }


    private function jsonValidationError($message)
    {
        http_response_code(422);
        $this->jsonErrorBody((string) $message);
    }


    private function jsonConflictError($message)
    {
        http_response_code(409);
        $this->jsonErrorBody((string) $message);
    }


    private function jsonUnavailableError($message)
    {
        http_response_code(503);
        $this->jsonErrorBody((string) $message);
    }


    private function jsonUnexpectedError($message)
    {
        http_response_code(500);
        $this->jsonErrorBody((string) $message);
    }


    private function jsonForbidden()
    {
        http_response_code(403);
        $this->jsonErrorBody('Недостатньо прав для цієї дії.');
    }


    private function jsonErrorBody($message)
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(
            [
                'ok' => false,
                'message' => (string) $message
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}
