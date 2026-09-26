<?php
$currentLanguage = $currentLanguage
    ?? Translator::currentLanguage();
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="<?= $escape($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle ?? Translator::t('storefront.contacts.title', 'Контакти')) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/storefront-pages.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="storefront-page">
    <div class="storefront-page-shell">
        <header class="storefront-page-head">
            <h1><?= $escape(Translator::t(
                'storefront.contacts.title',
                'Контакти'
            )) ?></h1>
            <p><?= $escape(Translator::t(
                'storefront.contacts.intro',
                'Контактна інформація магазину.'
            )) ?></p>
        </header>

        <section class="storefront-info-card storefront-contacts-card">
            <h2><?= $escape(Translator::t(
                'storefront.contacts.details',
                'Зв’язок з Анабелькою'
            )) ?></h2>
            <p><?= $escape(Translator::t(
                'storefront.contacts.pending',
                'Контактні дані ще не опубліковані.'
            )) ?></p>
        </section>
    </div>
</main>
</body>
</html>
