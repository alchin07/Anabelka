<?php
SocialAuthInterfaceTranslator::seed();
$googleConfigured = class_exists('GoogleOAuthProvider')
    && GoogleOAuthProvider::isConfigured();
?>

<div class="social-auth">
    <div class="social-auth-divider">
        <span><?= htmlspecialchars(
            Translator::t('public.social_auth.or', 'або')
        ) ?></span>
    </div>

    <?php if ($googleConfigured): ?>
        <a class="social-auth-google" href="/Anabelka/auth/google">
            <span class="social-auth-google-mark" aria-hidden="true">G</span>
            <span><?= htmlspecialchars(
                Translator::t(
                    'public.social_auth.google',
                    'Продовжити з Google'
                )
            ) ?></span>
        </a>
    <?php else: ?>
        <span class="social-auth-google is-disabled" aria-disabled="true">
            <span class="social-auth-google-mark" aria-hidden="true">G</span>
            <span><?= htmlspecialchars(
                Translator::t(
                    'public.social_auth.google',
                    'Продовжити з Google'
                )
            ) ?></span>
        </span>
        <small class="social-auth-hint">
            <?= htmlspecialchars(
                Translator::t(
                    'public.social_auth.google_unavailable',
                    'Вхід через Google ще не налаштовано'
                )
            ) ?>
        </small>
    <?php endif; ?>
</div>
