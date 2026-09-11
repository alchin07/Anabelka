<?php

class SocialAuthController extends Controller
{
    public function google()
    {
        $this->startProvider('google');
    }


    public function googleCallback()
    {
        $this->providerCallback('google');
    }


    public function facebook()
    {
        $this->startProvider('facebook');
    }


    public function facebookCallback()
    {
        $this->providerCallback('facebook');
    }


    public function apple()
    {
        $this->startProvider('apple');
    }


    public function appleCallback()
    {
        $this->providerCallback('apple');
    }


    public function completeForm()
    {
        SocialAuthInterfaceTranslator::seed();
        RegistrationInterfaceTranslator::seed();

        if (CustomerAccount::current()) {
            header('Location: /Anabelka/account');
            exit;
        }

        $pending = SocialAuthService::pendingRegistration();

        if (!$pending) {
            header('Location: /Anabelka/login?social_error=1');
            exit;
        }

        $this->view('auth/social-complete', [
            'pending' => $pending,
            'error' => '',
            'termsAccepted' => false,
            'marketingConsent' => false,
            'csrfToken' => CustomerAccount::csrfToken()
        ]);
    }


    public function complete()
    {
        SocialAuthInterfaceTranslator::seed();
        RegistrationInterfaceTranslator::seed();

        $termsAccepted = !empty($_POST['accept_terms']);
        $marketingConsent = !empty($_POST['marketing_consent']);
        $pending = SocialAuthService::pendingRegistration();

        try {
            if (!CustomerAccount::verifyCsrf($_POST['_csrf'] ?? '')) {
                throw new RuntimeException(
                    Translator::t(
                        'public.auth.error_csrf',
                        'Сесію форми застаріло. Оновіть сторінку та спробуйте ще раз.'
                    )
                );
            }

            $user = SocialAuthService::completePendingRegistration(
                $termsAccepted,
                $marketingConsent
            );

            CustomerAccount::startSession($user);
            $this->mergeGuestData((int) $user['id']);

            header('Location: /Anabelka/account');
            exit;
        } catch (Throwable $e) {
            $pending = SocialAuthService::pendingRegistration();

            if (!$pending) {
                header('Location: /Anabelka/login?social_error=1');
                exit;
            }

            http_response_code(422);
            $this->view('auth/social-complete', [
                'pending' => $pending,
                'error' => $e->getMessage(),
                'termsAccepted' => $termsAccepted,
                'marketingConsent' => $marketingConsent,
                'csrfToken' => CustomerAccount::csrfToken()
            ]);
        }
    }


    private function startProvider($provider)
    {
        SocialAuthInterfaceTranslator::seed();
        $provider = strtolower(trim((string) $provider));
        $label = SocialAuthProvider::exists($provider)
            ? SocialAuthProvider::label($provider)
            : ucfirst($provider);

        if (CustomerAccount::current()) {
            header('Location: /Anabelka/account');
            exit;
        }

        try {
            header('Location: ' . SocialAuthService::authorizationUrl($provider));
            exit;
        } catch (Throwable $e) {
            error_log($label . ' OAuth start error: ' . $e->getMessage());
            $this->redirectToLoginError($provider);
        }
    }


    private function providerCallback($provider)
    {
        SocialAuthInterfaceTranslator::seed();
        SocialConnectionsInterfaceTranslator::seed();
        $provider = strtolower(trim((string) $provider));
        $label = SocialAuthProvider::exists($provider)
            ? SocialAuthProvider::label($provider)
            : ucfirst($provider);

        try {
            if (!empty($_GET['error'])) {
                throw new RuntimeException(
                    $label . ' OAuth cancelled: ' . (string) $_GET['error']
                );
            }

            $result = SocialAuthService::handleCallback(
                $provider,
                $_GET['code'] ?? '',
                $_GET['state'] ?? ''
            );

            if (($result['status'] ?? '') === 'connected') {
                $message = str_replace(
                    '{provider}',
                    $label,
                    Translator::t(
                        'public.social_connections.connected_success',
                        '{provider} успішно підключено до вашого акаунта.'
                    )
                );
                header(
                    'Location: /Anabelka/account?message='
                    . rawurlencode($message)
                );
                exit;
            }

            if (($result['status'] ?? '') === 'pending_registration') {
                header('Location: /Anabelka/auth/social/complete');
                exit;
            }

            $user = is_array($result['user'] ?? null) ? $result['user'] : null;

            if (!$user) {
                throw new RuntimeException(
                    $label . ' OAuth не повернув користувача.'
                );
            }

            CustomerAccount::startSession($user);
            $this->mergeGuestData((int) $user['id']);

            header('Location: /Anabelka/');
            exit;
        } catch (Throwable $e) {
            error_log($label . ' OAuth callback error: ' . $e->getMessage());

            if (CustomerAccount::current()) {
                header(
                    'Location: /Anabelka/account?error='
                    . rawurlencode($e->getMessage())
                );
                exit;
            }

            $this->redirectToLoginError($provider);
        }
    }


    private function redirectToLoginError($provider)
    {
        header(
            'Location: /Anabelka/login?social_error=1&social_provider='
            . rawurlencode((string) $provider)
        );
        exit;
    }


    private function mergeGuestData($userId)
    {
        Cart::getOrCreateByUserId($userId);
        Cart::mergeSessionCart(
            $userId,
            $_SESSION['cart'] ?? []
        );
        Favorite::mergeSessionToUser($userId);
        $_SESSION['cart'] = [];
    }
}
