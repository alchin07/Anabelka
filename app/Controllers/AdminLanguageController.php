<?php

class AdminLanguageController extends Controller
{
    public function index()
    {
        $languages = Language::all();
        $availableLanguages = Language::availableCatalog();

        // Carry an unread success from the previous notification mechanism
        // into the shared one-time queue. Errors remain visible on the page.
        $legacyMessage = $_SESSION['language_message'] ?? '';
        if (is_string($legacyMessage) && trim($legacyMessage) !== '') {
            AnabelkaFlash::success($legacyMessage);
        }

        $error = $_SESSION['language_error'] ?? '';
        unset($_SESSION['language_message'], $_SESSION['language_error']);

        $this->view(
            'admin/languages/index',
            [
                'languages' => $languages,
                'availableLanguages' => $availableLanguages,
                'error' => is_string($error) ? $error : ''
            ]
        );
    }


    public function create()
    {
        try {
            Language::createFromCatalog(
                $_POST['language_code'] ?? ''
            );

            $this->flashSuccess('Мову додано.');

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

            $this->flashSuccess('Назву мови збережено.');

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

            $this->flashSuccess(
                empty($language['is_active'])
                    ? 'Мову увімкнено.'
                    : 'Мову вимкнено.'
            );

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

            $this->flashSuccess('Основну мову сайту змінено.');

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

            $this->flashSuccess('Мову та її переклади видалено.');

        } catch (Throwable $e) {
            $_SESSION['language_error'] =
                $this->friendlyError($e);
        }

        $this->redirect();
    }


    private function flashSuccess($message)
    {
        // A later successful action replaces any stale pre-redirect error.
        unset($_SESSION['language_message'], $_SESSION['language_error']);
        AnabelkaFlash::success((string) $message);
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
