<?php

class AdminCategoryController extends Controller
{
    public function index()
    {
        // Runtime table creation, where still needed by older installations,
        // happens before any manager transaction is opened.
        CategoryTranslator::getForCategory(0);

        $departments = Department::allForAdmin();
        $categories = Category::getAllForAdmin();
        $translations = CategoryTranslator::getForCategories(
            array_column($categories, 'id')
        );

        foreach ($categories as &$category) {
            $categoryId = (int) ($category['id'] ?? 0);
            $category['translations'] = $translations[$categoryId] ?? [];
        }
        unset($category);

        $this->view('admin/categories/index', [
            'departments' => $departments,
            'categories' => $categories,
            'categoryForest' => Category::buildAdminForest($categories),
            'languages' => Language::active(),
            'csrfToken' => AdminAccess::csrfToken()
        ]);
    }


    public function create()
    {
        $this->verifyCsrf();

        try {
            $category = CategoryManager::create($_POST);

            $this->jsonSuccess(
                'Категорію створено.',
                ['category' => $category]
            );
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }


    public function update()
    {
        $this->verifyCsrf();

        // Both helpers may perform legacy CREATE TABLE IF NOT EXISTS work.
        // Resolve it before CategoryManager starts the data transaction.
        $activeLanguages = Language::active();
        CategoryTranslator::getForCategory(0);

        try {
            CategoryManager::update(
                (int) ($_POST['category_id'] ?? 0),
                $_POST,
                $activeLanguages
            );

            $this->jsonSuccess('Категорію та переклади збережено.');
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }


    public function move()
    {
        $this->verifyCsrf();

        try {
            $direction = trim((string) ($_POST['direction'] ?? ''));
            $categoryId = (int) ($_POST['category_id'] ?? 0);

            if ($direction !== '') {
                $changed = CategoryManager::reorder(
                    $categoryId,
                    $direction
                );

                $this->jsonSuccess(
                    $changed
                        ? 'Порядок категорій змінено.'
                        : 'Категорія вже займає крайню позицію.'
                );
            }

            $result = CategoryManager::move(
                $categoryId,
                $_POST['parent_id'] ?? null,
                (int) ($_POST['department_id'] ?? 0)
            );

            $this->jsonSuccess(
                'Гілку категорій переміщено.',
                ['move' => $result]
            );
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }


    public function toggle()
    {
        $this->verifyCsrf();

        try {
            CategoryManager::toggle(
                (int) ($_POST['category_id'] ?? 0),
                $_POST['field'] ?? '',
                $_POST['value'] ?? 0
            );

            $this->jsonSuccess('Стан категорії змінено.');
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }


    public function delete()
    {
        $this->verifyCsrf();
        CategoryTranslator::getForCategory(0);

        try {
            CategoryManager::delete(
                (int) ($_POST['category_id'] ?? 0)
            );

            $this->jsonSuccess('Категорію видалено.');
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }


    private function verifyCsrf()
    {
        if (AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            return;
        }

        $this->jsonError('Сесію форми завершено. Оновіть сторінку.', 419);
    }


    private function jsonSuccess($message, array $extra = [])
    {
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(
            array_merge([
                'success' => true,
                'message' => (string) $message
            ], $extra),
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }


    private function handleFailure(Throwable $error)
    {
        if (
            $error instanceof DomainException
            || $error instanceof InvalidArgumentException
        ) {
            $this->jsonError($error->getMessage(), 422);
        }

        error_log(
            'Category manager: '
            . get_class($error)
            . ': '
            . $error->getMessage()
        );

        $this->jsonError(
            'Не вдалося виконати операцію з категорією. Перевірте журнал помилок.',
            500
        );
    }


    private function jsonError($message, $status)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode([
            'success' => false,
            'message' => (string) $message
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
