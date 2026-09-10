<?php

class EmailVerificationController extends Controller
{
    public function status()
    {
        $this->seedTranslations();
        header('Content-Type: application/json; charset=UTF-8');

        $user = CustomerAccount::current();

        if (!$user) {
            http_response_code(401);
            $this->json(['authenticated' => false]);
            return;
        }

        try {
            $status = EmailVerificationService::statusForUser($user);

            $this->json([
                'authenticated' => true,
                'email' => (string) ($user['email'] ?? ''),
                'verified' => !empty($status['verified']),
                'verified_at' => $status['verified_at'] ?? null,
                'can_resend' => !empty($status['can_resend']),
                'retry_after' => max(0, (int) ($status['retry_after'] ?? 0)),
                'preview_url' => (string) ($status['preview_url'] ?? ''),
                'labels' => $this->labels()
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            $this->json([
                'authenticated' => true,
                'error' => $e->getMessage(),
                'labels' => $this->labels()
            ]);
        }
    }


    public function resend()
    {
        $this->seedTranslations();
        header('Content-Type: application/json; charset=UTF-8');

        try {
            if (!CustomerAccount::verifyCsrf($_POST['_csrf'] ?? '')) {
                throw new RuntimeException(
                    Translator::t(
                        'public.auth.error_csrf',
                        'Сесію форми застаріло. Оновіть сторінку та спробуйте ще раз.'
                    )
                );
            }

            $user = CustomerAccount::current();

            if (!$user) {
                http_response_code(401);
                $this->json(['authenticated' => false]);
                return;
            }

            $delivery = EmailVerificationService::issueForUser($user);
            $status = EmailVerificationService::statusForUser($user);

            $message = !empty($delivery['local'])
                ? Translator::t(
                    'public.email_verification.local_ready',
                    'Локальний режим: тестове посилання готове нижче.'
                )
                : (
                    !empty($delivery['sent'])
                        ? Translator::t(
                            'public.email_verification.sent',
                            'Лист підтвердження надіслано.'
                        )
                        : Translator::t(
                            'public.email_verification.mail_unavailable',
                            'Лист не вдалося передати поштовому серверу. Спробуйте пізніше.'
                        )
                );

            $this->json([
                'ok' => true,
                'message' => $message,
                'verified' => false,
                'can_resend' => !empty($status['can_resend']),
                'retry_after' => max(0, (int) ($status['retry_after'] ?? 0)),
                'preview_url' => (string) ($status['preview_url'] ?? ''),
                'labels' => $this->labels()
            ]);
        } catch (Throwable $e) {
            http_response_code(422);
            $this->json([
                'ok' => false,
                'error' => $e->getMessage(),
                'labels' => $this->labels()
            ]);
        }
    }


    public function verify()
    {
        $this->seedTranslations();

        try {
            $result = EmailVerificationService::verify($_GET['token'] ?? '');
            $currentUserId = CustomerAccount::currentId();

            if ($currentUserId > 0 && $currentUserId === (int) ($result['user_id'] ?? 0)) {
                header(
                    'Location: /Anabelka/account?'
                    . http_build_query([
                        'message' => Translator::t(
                            'public.email_verification.success',
                            'Email успішно підтверджено.'
                        )
                    ])
                );
                exit;
            }

            header('Location: /Anabelka/login?email_verified=1');
            exit;
        } catch (Throwable $e) {
            if (CustomerAccount::current()) {
                header(
                    'Location: /Anabelka/account?'
                    . http_build_query([
                        'error' => $e->getMessage()
                    ])
                );
                exit;
            }

            header('Location: /Anabelka/login?email_verification_error=1');
            exit;
        }
    }


    private function seedTranslations()
    {
        PublicInterfaceTranslator::seed();
        CustomerAccountInterfaceTranslator::seed();
        CustomerEmailVerificationInterfaceTranslator::seed();
    }


    private function labels()
    {
        return [
            'title' => Translator::t(
                'public.email_verification.title',
                'Підтвердження email'
            ),
            'verified' => Translator::t(
                'public.email_verification.verified',
                'Email підтверджено'
            ),
            'unverified' => Translator::t(
                'public.email_verification.unverified',
                'Email не підтверджено'
            ),
            'verified_hint' => Translator::t(
                'public.email_verification.verified_hint',
                'Ця адреса підтверджена та належить вашому акаунту.'
            ),
            'unverified_hint' => Translator::t(
                'public.email_verification.unverified_hint',
                'Підтвердження email не блокує покупки, але підвищує безпеку акаунта.'
            ),
            'send' => Translator::t(
                'public.email_verification.send',
                'Надіслати лист підтвердження'
            ),
            'resend' => Translator::t(
                'public.email_verification.resend',
                'Надіслати ще раз'
            ),
            'local_link' => Translator::t(
                'public.email_verification.local_link',
                'Відкрити тестове посилання підтвердження'
            ),
            'local_hint' => Translator::t(
                'public.email_verification.local_hint',
                'Це посилання показується лише на локальному сервері для тестування.'
            ),
            'wait' => Translator::t(
                'public.email_verification.wait',
                'Повторне надсилання стане доступним приблизно через хвилину.'
            )
        ];
    }


    private function json(array $data)
    {
        echo json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
