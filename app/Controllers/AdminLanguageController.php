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
                'Мову додано.';

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
                'Назву мови збережено.';

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
                    'Мову не знайдено.'
                );
            }

            Language::setActive(
                $id,
                empty($language['is_active'])
            );

            $_SESSION['language_message'] =
                empty($language['is_active'])
                    ? 'Мову увімкнено.'
                    : 'Мову вимкнено.';

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
                'Основну мову сайту змінено.';

        } catch (Throwable $e) {
            $_SESSION['language_error'] =
                $this->friendlyError($e);
        }

        $this->redirect();
    }


    public function delete()
    {
        try {
            $id =
                (int) ($_POST['language_id'] ?? 0);

            $language =
                Language::findById($id);

            if (!$language) {
                throw new RuntimeException(
                    'Мову не знайдено.'
                );
            }

            Language::delete($id);

            Translator::deleteForLanguage(
                $language['code'] ?? ''
            );

            $_SESSION['language_message'] =
                'Мову та її переклади видалено.';

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
                return 'Цю мову вже додано.';
            }

            return 'Не вдалося зберегти мову в базі даних.';
        }

        return $e->getMessage();
    }
}
