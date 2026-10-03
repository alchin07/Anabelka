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
    <title><?= $escape($pageTitle ?? Translator::t('storefront.delivery_payment.title', 'Доставка, оплата і повернення')) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/storefront-pages.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="storefront-page">
    <div class="storefront-page-shell">
        <header class="storefront-page-head">
            <h1><?= $escape(Translator::t(
                'storefront.delivery_payment.title',
                'Доставка, оплата і повернення'
            )) ?></h1>
            <p><?= $escape(Translator::t(
                'storefront.delivery_payment.intro',
                'Інформація про умови покупки в одному місці.'
            )) ?></p>
        </header>

        <div class="storefront-info-stack">
            <section class="storefront-info-card" id="delivery">
                <h2><?= $escape(Translator::t(
                    'storefront.delivery_payment.delivery',
                    'Доставка'
                )) ?></h2>
                <p><?= $escape(Translator::t(
                    'storefront.info.pending',
                    'Інформація уточнюється.'
                )) ?></p>
            </section>

            <section class="storefront-info-card" id="payment">
                <h2><?= $escape(Translator::t(
                    'storefront.delivery_payment.payment',
                    'Оплата'
                )) ?></h2>
                <p><?= $escape(Translator::t(
                    'storefront.info.pending',
                    'Інформація уточнюється.'
                )) ?></p>
            </section>

            <section class="storefront-info-card" id="returns">
                <h2><?= $escape(Translator::t(
                    'storefront.delivery_payment.returns',
                    'Повернення'
                )) ?></h2>
                <p><?= $escape(Translator::t(
                    'storefront.info.pending',
                    'Інформація уточнюється.'
                )) ?></p>
            </section>
        </div>
    </div>
</main>
</body>
</html>
