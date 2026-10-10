<?php
HomeInterfaceTranslator::seed();
$currentLanguage = $currentLanguage ?? Translator::currentLanguage();
$pageTitle = Translator::t('home.gift_title', 'Подарунковий сертифікат');
$escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="<?= $escape($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=6">
    <link rel="stylesheet" href="/Anabelka/css/gift-certificates.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="gift-certificate-page">
    <section class="gift-certificate-card">
        <div class="gift-certificate-art" aria-hidden="true">
            <span class="gift-certificate-ribbon">Анабелька</span>
            <span class="gift-certificate-lace"></span>
        </div>

        <div class="gift-certificate-copy">
            <span class="gift-certificate-kicker">
                <?= $escape(Translator::t('home.gift_kicker', 'Подарунок, який обирає одержувач')) ?>
            </span>
            <h2><?= $escape($pageTitle) ?></h2>
            <p>
                <?= $escape(Translator::t(
                    'home.gift_page_text',
                    'Ми готуємо електронний подарунковий сертифікат Анабельки, який можна буде передати іншій людині. Деталі оформлення та використання з’являться після завершення окремого безпечного модуля сертифікатів.'
                )) ?>
            </p>

            <div class="gift-certificate-note">
                <?= $escape(Translator::t(
                    'home.gift_coming_soon',
                    'Сервіс готується. На цій сторінці поки немає оформлення замовлення чи видачі сертифіката.'
                )) ?>
            </div>

            <a class="gift-certificate-back" href="/Anabelka/">
                ← <?= $escape(Translator::t('home.gift_back', 'Повернутися на головну')) ?>
            </a>
        </div>
    </section>
</main>
</body>
</html>
