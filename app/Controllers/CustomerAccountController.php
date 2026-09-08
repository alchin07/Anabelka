<?php

class CustomerAccountController extends Controller
{
    public function index()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();

        $user = CustomerAccount::current();

        if (!$user) {
            header('Location: /Anabelka/login');
            exit;
        }

        $this->view('account/index', [
            'user' => $user,
            'csrfToken' => CustomerAccount::csrfToken(),
            'message' => trim((string) ($_GET['message'] ?? '')),
            'error' => trim((string) ($_GET['error'] ?? ''))
        ]);
    }


    public function updateProfile()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();

        try {
            $this->verifyCsrf();
            CustomerAccount::updateIdentity(
                $_POST['name'] ?? '',
                $_POST['email'] ?? '',
                $_POST['current_password'] ?? ''
            );

            $this->redirect(
                'message',
                Translator::t(
                    'public.account.profile_saved',
                    'Дані профілю збережено.'
                )
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    public function changePassword()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();

        try {
            $this->verifyCsrf();
            CustomerAccount::changePassword(
                $_POST['current_password'] ?? '',
                $_POST['new_password'] ?? '',
                $_POST['new_password_confirmation'] ?? ''
            );

            $this->redirect(
                'message',
                Translator::t(
                    'public.account.password_saved',
                    'Пароль успішно змінено.'
                )
            );
        } catch (Throwable $e) {
            $this->redirect('error', $e->getMessage());
        }
    }


    private function verifyCsrf()
    {
        if (!CustomerAccount::verifyCsrf($_POST['_csrf'] ?? '')) {
            throw new RuntimeException(
                Translator::t(
                    'public.auth.error_csrf',
                    'Сесію форми застаріло. Оновіть сторінку та спробуйте ще раз.'
                )
            );
        }
    }


    private function redirect($key, $message)
    {
        header(
            'Location: /Anabelka/account?'
            . http_build_query([$key => (string) $message])
        );
        exit;
    }
}
