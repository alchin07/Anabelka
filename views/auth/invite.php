<?php
PasswordPolicyInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$invite = is_array($invite ?? null) ? $invite : null;
$error = trim((string) ($error ?? ''));
$token = trim((string) ($token ?? ''));
$passwordMinLength = PasswordPolicy::minimumLength();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Запрошення — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog">
    <section
        class="product-card"
        style="max-width:520px;margin:0 auto;padding:22px;"
    >
        <h2 style="margin-top:0;">Запрошення до Анабельки</h2>

        <?php if ($error !== ''): ?>
            <div
                style="margin-bottom:16px;padding:12px;border-radius:12px;background:#fff0f2;color:#8e3748;"
            >
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($invite): ?>
            <p style="line-height:1.5;">
                Акаунт для <strong><?= htmlspecialchars($invite['name'] ?? '') ?></strong>
                уже створено. Встановіть пароль для першого входу.
            </p>

            <p style="margin-top:-4px;color:#746c78;font-size:14px;">
                Логін: <?= htmlspecialchars($invite['email'] ?? '') ?>
            </p>

            <form method="post" action="/Anabelka/invite">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                <label style="display:block;margin-bottom:14px;">
                    <span style="display:block;margin-bottom:6px;font-weight:700;">Новий пароль</span>
                    <input
                        type="password"
                        name="password"
                        minlength="<?= $passwordMinLength ?>"
                        required
                        autocomplete="new-password"
                        style="width:100%;box-sizing:border-box;padding:12px;border:1px solid var(--border-color);border-radius:10px;"
                    >
                    <small style="display:block;margin-top:6px;color:#746c78;line-height:1.4;">
                        <?= htmlspecialchars(Translator::t('public.password_policy.hint', 'Щонайменше 10 символів. Не використовуйте прості паролі або послідовності.')) ?>
                    </small>
                </label>

                <label style="display:block;margin-bottom:18px;">
                    <span style="display:block;margin-bottom:6px;font-weight:700;">Повторіть пароль</span>
                    <input
                        type="password"
                        name="password_confirm"
                        minlength="<?= $passwordMinLength ?>"
                        required
                        autocomplete="new-password"
                        style="width:100%;box-sizing:border-box;padding:12px;border:1px solid var(--border-color);border-radius:10px;"
                    >
                </label>

                <button
                    type="submit"
                    style="width:100%;padding:13px;border:0;border-radius:11px;background:var(--primary-color);color:#fff;font-weight:800;cursor:pointer;"
                >
                    Активувати акаунт
                </button>
            </form>
        <?php else: ?>
            <a
                href="/Anabelka/"
                style="display:inline-block;padding:11px 18px;border-radius:10px;background:var(--primary-color);color:#fff;text-decoration:none;font-weight:800;"
            >
                На головну
            </a>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
