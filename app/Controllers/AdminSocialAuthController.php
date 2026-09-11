<?php

class AdminSocialAuthController extends Controller
{
    public function index()
    {
        $this->view('admin/social-auth/index', [
            'pageTitle' => 'Адмін-панель · Авторизація та соцмережі',
            'providers' => SocialAuthProvider::all(),
            'message' => trim((string) ($_GET['message'] ?? '')),
            'error' => trim((string) ($_GET['error'] ?? '')),
            'csrfToken' => AdminAccess::csrfToken()
        ]);
    }


    public function toggle()
    {
        $this->verifyCsrf();

        $provider = strtolower(trim((string) ($_POST['provider'] ?? '')));
        $enabled = !empty($_POST['enabled']);

        try {
            if ($enabled && !SocialAuthProvider::isConfigured($provider)) {
                throw new RuntimeException(
                    'Спочатку налаштуйте ключі та callback для цього провайдера.'
                );
            }

            SocialAuthProvider::setEnabled($provider, $enabled);
            AdminAccess::audit('social_auth.provider_toggled', [
                'provider' => $provider,
                'enabled' => $enabled
            ], AdminAccess::currentId());

            $this->redirectWithMessage(
                $enabled
                    ? 'Спосіб входу увімкнено.'
                    : 'Спосіб входу вимкнено.'
            );
        } catch (Throwable $e) {
            $this->redirectWithError($e->getMessage());
        }
    }


    public function move()
    {
        $this->verifyCsrf();

        $provider = strtolower(trim((string) ($_POST['provider'] ?? '')));
        $direction = strtolower(trim((string) ($_POST['direction'] ?? '')));

        try {
            SocialAuthProvider::move($provider, $direction);
            AdminAccess::audit('social_auth.provider_moved', [
                'provider' => $provider,
                'direction' => $direction
            ], AdminAccess::currentId());

            $this->redirectWithMessage('Порядок способів входу оновлено.');
        } catch (Throwable $e) {
            $this->redirectWithError($e->getMessage());
        }
    }


    private function verifyCsrf()
    {
        if (!AdminAccess::verifyCsrf($_POST['_csrf'] ?? '')) {
            throw new RuntimeException(
                'Сесію форми застаріло. Оновіть сторінку та спробуйте ще раз.'
            );
        }
    }


    private function redirectWithMessage($message)
    {
        header(
            'Location: /Anabelka/admin/social-auth?message='
            . rawurlencode((string) $message)
        );
        exit;
    }


    private function redirectWithError($message)
    {
        header(
            'Location: /Anabelka/admin/social-auth?error='
            . rawurlencode((string) $message)
        );
        exit;
    }
}
