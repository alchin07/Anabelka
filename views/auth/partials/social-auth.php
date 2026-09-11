<?php
SocialAuthInterfaceTranslator::seed();
$providers = class_exists('SocialAuthProvider')
    ? SocialAuthProvider::publicProviders()
    : [];
?>

<?php if (!empty($providers)): ?>
    <div class="social-auth">
        <div class="social-auth-divider">
            <span><?= htmlspecialchars(
                Translator::t('public.social_auth.or', 'або')
            ) ?></span>
        </div>

        <?php foreach ($providers as $provider): ?>
            <?php
            $code = (string) ($provider['code'] ?? '');
            $label = (string) ($provider['label'] ?? $code);
            $buttonText = sprintf(
                Translator::t(
                    'public.social_auth.continue_with',
                    'Продовжити з %s'
                ),
                $label
            );
            ?>
            <a
                class="social-auth-provider social-auth-google social-auth-provider--<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"
                href="<?= htmlspecialchars((string) ($provider['route'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            >
                <span class="social-auth-provider-mark social-auth-google-mark" aria-hidden="true">
                    <?= htmlspecialchars((string) ($provider['mark'] ?? '')) ?>
                </span>
                <span><?= htmlspecialchars($buttonText) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
