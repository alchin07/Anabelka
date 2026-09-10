<?php
RegistrationInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$document = ($document ?? '') === 'privacy' ? 'privacy' : 'terms';
$version = (string) ($version ?? '');
$isPrivacy = $document === 'privacy';
$title = Translator::t(
    $isPrivacy ? 'public.legal.privacy_title' : 'public.legal.terms_title',
    $isPrivacy ? 'Політика конфіденційності' : 'Умови користування'
);

$sections = $isPrivacy
    ? [
        ['public.legal.privacy_data_title', 'public.legal.privacy_data_text'],
        ['public.legal.privacy_purpose_title', 'public.legal.privacy_purpose_text'],
        ['public.legal.privacy_marketing_title', 'public.legal.privacy_marketing_text']
    ]
    : [
        ['public.legal.terms_account_title', 'public.legal.terms_account_text'],
        ['public.legal.terms_orders_title', 'public.legal.terms_orders_text'],
        ['public.legal.terms_adult_title', 'public.legal.terms_adult_text']
    ];
$introKey = $isPrivacy
    ? 'public.legal.privacy_intro'
    : 'public.legal.terms_intro';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/legal.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="legal-page">
    <article class="legal-card">
        <div class="legal-draft">
            <strong><?= htmlspecialchars(Translator::t('public.legal.draft_badge', 'Чернетка для розробки')) ?></strong>
            <p><?= htmlspecialchars(Translator::t('public.legal.draft_notice', 'Документ потребує фінальної юридичної перевірки перед запуском.')) ?></p>
        </div>

        <h1><?= htmlspecialchars($title) ?></h1>
        <p class="legal-version">
            <?= htmlspecialchars(Translator::t('public.legal.version', 'Версія')) ?>:
            <strong><?= htmlspecialchars($version) ?></strong>
        </p>

        <p class="legal-intro">
            <?= htmlspecialchars(Translator::t($introKey, '')) ?>
        </p>

        <?php foreach ($sections as [$titleKey, $textKey]): ?>
            <section>
                <h2><?= htmlspecialchars(Translator::t($titleKey, '')) ?></h2>
                <p><?= htmlspecialchars(Translator::t($textKey, '')) ?></p>
            </section>
        <?php endforeach; ?>

        <a class="legal-back" href="/Anabelka/register">
            ← <?= htmlspecialchars(Translator::t('public.legal.back_register', 'Повернутися до реєстрації')) ?>
        </a>
    </article>
</main>
</body>
</html>
