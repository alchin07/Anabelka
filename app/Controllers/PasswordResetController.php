<?php

class PasswordResetController extends Controller
{
    public function requestForm()
    {
        PasswordResetInterfaceTranslator::seed();

        if (CustomerAccount::current()) {
            header('Location: /Anabelka/account');
            exit;
        }

        $this->view('auth/forgot-password', [
            'message' => '',
            'error' => '',
            'email' => '',
            'previewUrl' => '',
            'csrfToken' => CustomerAccount::csrfToken()
        ]);
    }


    public function requestLink()
    {
        PasswordResetInterfaceTranslator::seed();

        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $message = '';
        $error = '';
        $previewUrl = '';

        try {
            $this->verifyCsrf();

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(
                    Translator::t(
                        'public.password_reset.invalid_email',
                        'Вкажіть коректний email.'
                    )
                );
            }

            $result = PasswordResetService::requestForEmail($email);
            $previewUrl = (string) ($result['preview_url'] ?? '');
            $message = Translator::t(
                'public.password_reset.request_done',
                'Якщо акаунт з таким email існує, посилання для відновлення пароля надіслано.'
            );
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (RuntimeException $e) {
            // Не розкриваємо, чи існує акаунт. Виняток cooldown також
            // показує той самий нейтральний результат.
            $message = Translator::t(
                'public.password_reset.request_done',
                'Якщо акаунт з таким email існує, посилання для відновлення пароля надіслано.'
            );
        } catch (Throwable $e) {
            error_log('Password reset request error: ' . $e->getMessage());
            $error = 'Сервіс відновлення пароля тимчасово недоступний. Спробуйте пізніше.';
        }

        $this->view('auth/forgot-password', [
            'message' => $message,
            'error' => $error,
            'email' => $email,
            'previewUrl' => $previewUrl,
            'csrfToken' => CustomerAccount::csrfToken()
        ]);
    }


    public function resetForm()
    {
        PasswordResetInterfaceTranslator::seed();
        PasswordPolicyInterfaceTranslator::seed();
        $token = trim((string) ($_GET['token'] ?? ''));
        $error = '';
        $valid = false;

        try {
            PasswordResetService::validate($token);
            $valid = true;
        } catch (Throwable $e) {
            $error = Translator::t(
                'public.password_reset.invalid_link',
                'Посилання відновлення недійсне, використане або строк його дії завершився.'
            );
        }

        $this->view('auth/reset-password', [
            'token' => $token,
            'valid' => $valid,
            'error' => $error,
            'csrfToken' => CustomerAccount::csrfToken()
        ]);
    }


    public function reset()
    {
        PasswordResetInterfaceTranslator::seed();
        PasswordPolicyInterfaceTranslator::seed();

        $token = trim((string) ($_POST['token'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        try {
            $this->verifyCsrf();

            if (!hash_equals($password, $confirmation)) {
                throw new InvalidArgumentException(
                    Translator::t(
                        'public.password_reset.password_mismatch',
                        'Паролі не збігаються.'
                    )
                );
            }

            PasswordResetService::reset($token, $password);

            if (CustomerAccount::currentId() > 0) {
                CustomerAccount::clearSession();
                session_regenerate_id(true);
            }

            header('Location: /Anabelka/login?password_reset=1');
            exit;
        } catch (Throwable $e) {
            $valid = true;

            try {
                PasswordResetService::validate($token);
            } catch (Throwable $validationError) {
                $valid = false;
            }

            $this->view('auth/reset-password', [
                'token' => $token,
                'valid' => $valid,
                'error' => $valid
                    ? $e->getMessage()
                    : Translator::t(
                        'public.password_reset.invalid_link',
                        'Посилання відновлення недійсне, використане або строк його дії завершився.'
                    ),
                'csrfToken' => CustomerAccount::csrfToken()
            ]);
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
}
