<?php

class AdminNewsController extends Controller
{
    public function index()
    {
        $items = [];
        $loadError = '';

        try {
            $items = SiteNews::adminAll();
            foreach ($items as &$item) {
                $item['translations'] = SiteNewsTranslator::allForNews(
                    (int) ($item['id'] ?? 0)
                );
            }
            unset($item);
        } catch (Throwable $e) {
            error_log('Admin news list: ' . $e->getMessage());
            $loadError = 'Таблиці новин ще не підготовлені. Застосуйте міграцію після резервної копії.';
        }

        $flash = is_array($_SESSION['admin_news_flash'] ?? null)
            ? $_SESSION['admin_news_flash']
            : null;
        unset($_SESSION['admin_news_flash']);

        $this->view('admin/news/index', [
            'items' => $items,
            'languages' => Language::active(),
            'csrfToken' => AdminAccess::csrfToken(),
            'flash' => $flash,
            'loadError' => $loadError
        ]);
    }


    public function create()
    {
        $this->verifyCsrf();

        try {
            $newsId = SiteNews::createDraft($_POST);
            AdminAccess::audit('news.create', ['news_id' => $newsId]);
            $this->flash('success', 'Чернетку новини створено.');
        } catch (Throwable $e) {
            $this->handleFailure($e, 'Не вдалося створити новину.');
        }

        $this->redirect();
    }


    public function update()
    {
        $this->verifyCsrf();
        $newsId = (int) ($_POST['news_id'] ?? 0);
        $db = Database::connect();

        try {
            $activeLanguages = Language::active();
            $db->beginTransaction();
            SiteNews::update($newsId, $_POST);
            $translations = is_array($_POST['translations'] ?? null)
                ? $_POST['translations']
                : [];

            foreach ($activeLanguages as $language) {
                $code = strtolower(trim((string) ($language['code'] ?? '')));
                if ($code === '' || $code === Language::SOURCE_CODE) {
                    continue;
                }

                SiteNewsTranslator::save(
                    $newsId,
                    $code,
                    is_array($translations[$code] ?? null)
                        ? $translations[$code]
                        : []
                );
            }

            $db->commit();
            AdminAccess::audit('news.update', ['news_id' => $newsId]);
            $this->flash('success', 'Новину та переклади збережено.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            $this->handleFailure($e, 'Не вдалося зберегти новину.');
        }

        $this->redirect();
    }


    public function publish()
    {
        $this->verifyCsrf();
        $newsId = (int) ($_POST['news_id'] ?? 0);
        $published = !empty($_POST['published']);

        try {
            SiteNews::setPublished($newsId, $published);
            AdminAccess::audit('news.publish', [
                'news_id' => $newsId,
                'published' => $published ? 1 : 0
            ]);
            $this->flash(
                'success',
                $published ? 'Новину опубліковано.' : 'Новину повернуто в чернетки.'
            );
        } catch (Throwable $e) {
            $this->handleFailure($e, 'Не вдалося змінити стан публікації.');
        }

        $this->redirect();
    }


    public function delete()
    {
        $this->verifyCsrf();
        $newsId = (int) ($_POST['news_id'] ?? 0);

        try {
            SiteNews::delete($newsId);
            AdminAccess::audit('news.delete', ['news_id' => $newsId]);
            $this->flash('success', 'Новину видалено.');
        } catch (Throwable $e) {
            $this->handleFailure($e, 'Не вдалося видалити новину.');
        }

        $this->redirect();
    }


    private function verifyCsrf()
    {
        if (AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            return;
        }

        http_response_code(419);
        $this->flash('error', 'Сесію форми завершено. Оновіть сторінку.');
        $this->redirect();
    }


    private function handleFailure(Throwable $error, $fallback)
    {
        if (
            $error instanceof InvalidArgumentException
            || $error instanceof DomainException
        ) {
            $this->flash('error', $error->getMessage());
            return;
        }

        error_log('Admin news: ' . get_class($error) . ': ' . $error->getMessage());
        $this->flash('error', (string) $fallback);
    }


    private function flash($type, $message)
    {
        $_SESSION['admin_news_flash'] = [
            'type' => (string) $type,
            'message' => (string) $message
        ];
    }


    private function redirect()
    {
        header('Location: /Anabelka/admin/news');
        exit;
    }
}
