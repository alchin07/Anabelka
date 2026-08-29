<?php

class AdminLanguageController extends Controller
{
    public function index()
    {
        $languages = Language::all();
        $availableLanguages = Language::availableCatalog();

        $this->view(
            'admin/languages/index',
            [
                'languages' => $languages,
                'availableLanguages' => $availableLanguages,
                'message' => $_SESSION['language_message'] ?? '',
                'error' => $_SESSION['language_error'] ?? ''
            ]
        );

        unset($_SESSION['language_message']);
        unset($_SESSION['language_error']);
    }


    public function create()
    {
        try {
            Language::createFromCatalog(
                $_POST['language_code'] ?? ''
            );

            $_SESSION['language_message'] =
                'Язык добавлен.';

        } catch (Throwable $e) {
            $_SESSION['language_error'] =
                $this->friendlyError($e);
        }

        $this->redirect();
    }


    public function update()
    {
        try {
            Language::updateName(
                (int) ($_POST['language_id'] ?? 0),
                $_POST['name'] ?? ''
            );

            $_SESSION['language_message'] =
                'Название языка сохранено.';

        } catch (Throwable $e) {
            $_SESSION['language_error'] =
                $this->friendlyError($e);
        }

        $this->redirect();
    }


    public function toggle()
    {
        try {
            $id =
                (int) ($_POST['language_id'] ?? 0);

            $language =
                Language::findById($id);

            if (!$language) {
                throw new RuntimeException(
                    'Язык не найден.'
                );
            }

            Language::setActive(
                $id,
                empty($language['is_active'])
            );

            $_SESSION['language_message'] =
                empty($language['is_active'])
                    ? 'Язык включён.'
                    : 'Язык отключён.';

        } catch (Throwable $e) {
            $_SESSION['language_error'] =
                $this->friendlyError($e);
        }

        $this->redirect();
    }


    public function setDefault()
    {
        try {
            Language::setDefault(
                (int) ($_POST['language_id'] ?? 0)
            );

            $_SESSION['language_message'] =
                'Основной язык сайта изменён.';

        } catch (Throwable $e) {
            $_SESSION['language_error'] =
                $this->friendlyError($e);
        }

        $this->redirect();
    }


    public function delete()
    {
        try {
            Language::delete(
                (int) ($_POST['language_id'] ?? 0)
            );

            $_SESSION['language_message'] =
                'Язык удалён.';

        } catch (Throwable $e) {
            $_SESSION['language_error'] =
                $this->friendlyError($e);
        }

        $this->redirect();
    }


    private function redirect()
    {
        header(
            'Location: /Anabelka/admin/languages'
        );

        exit;
    }


    private function friendlyError(Throwable $e)
    {
        if ($e instanceof PDOException) {
            if ((string) $e->getCode() === '23000') {
                return 'Этот язык уже добавлен.';
            }

            return 'Не удалось сохранить язык в базе данных.';
        }

        return $e->getMessage();
    }
}
