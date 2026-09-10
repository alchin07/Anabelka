<?php

class SocialAuthController extends Controller
{
    public function google()
    {
        SocialAuthInterfaceTranslator::seed();

        if (CustomerAccount::current()) {
            header('Location: /Anabelka/account');
            exit;
        }

        try {
            header('Location: ' . SocialAuthService::googleAuthorizationUrl());
            exit;
        } catch (Throwable $e) {
            error_log('Google OAuth start error: ' . $e->getMessage());
            header('Location: /Anabelka/login?social_error=1');
            exit;
        }
    }


    public function googleCallback()
    {
        SocialAuthInterfaceTranslator::seed();

        try {
            if (!empty($_GET['error'])) {
                throw new RuntimeException('Google OAuth cancelled: ' . (string) $_GET['error']);
            }

            $result = SocialAuthService::handleGoogleCallback(
                $_GET['code'] ?? '',
                $_GET['state'] ?? ''
            );

            if (($result['status'] ?? '') === 'pending_registration') {
                header('Location: /Anabelka/auth/social/complete');
                exit;
            }

            $user = is_array($result['user'] ?? null) ? $result['user'] : null;

            if (!$user) {
                throw new RuntimeException('Google OAuth не повернув користувача.');
            }

            CustomerAccount::startSession($user);
            $this->mergeGuestData((int) $user['id']);

            header('Location: /Anabelka/');
            exit;
        } catch (Throwable $e) {
            error_log('Google OAuth callback error: ' . $e->getMessage());
            header('Location: /Anabelka/login?social_error=1');
            exit;
        }
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
