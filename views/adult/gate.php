<?php
HomeInterfaceTranslator::seed();
$currentLanguage = $currentLanguage
    ?? Translator::currentLanguage();
$pageTitle = Translator::t('home.adult_gate_title', 'Підтвердження віку');

$escape = function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
};
?>
<!DOCTYPE html>
<html lang="<?= $escape($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/adult-gate.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="adult-gate-page">
    <section class="adult-gate-card">
        <div class="adult-gate-badge">18+</div>

        <h1>
            <?= $escape(
                Translator::t(
                    'home.adult_gate_heading',
                    'Цей розділ призначений лише для повнолітніх'
                )
            ) ?>
        </h1>

        <p>
            <?= $escape(
                Translator::t(
                    'home.adult_gate_text',
                    'Підтвердьте, що вам виповнилося 18 років, щоб перейти до розділу.'
                )
            ) ?>
        </p>

        <?php if (!empty($category['name'])): ?>
            <div class="adult-gate-section-name">
                <?= $escape($category['name']) ?>
            </div>
        <?php endif; ?>

        <form
            action="/Anabelka/18-plus/<?= $escape($category['slug'] ?? '') ?>"
            method="post"
            class="adult-gate-actions"
        >
            <input
                type="hidden"
                name="return_url"
                value="<?= $escape($returnUrl ?? '') ?>"
            >

            <button type="submit" class="adult-gate-confirm">
                <?= $escape(
                    Translator::t(
                        'home.adult_confirm',
                        'Мені вже є 18 років'
                    )
                ) ?>
            </button>

            <a href="/Anabelka/" class="adult-gate-leave">
                <?= $escape(
                    Translator::t(
                        'home.adult_leave',
                        'Повернутися на головну'
                    )
                ) ?>
            </a>
        </form>

        <small>
            <?= $escape(
                Translator::t(
                    'home.adult_gate_note',
                    'Після підтвердження доступ діятиме протягом поточного сеансу.'
                )
            ) ?>
        </small>
    </section>
</main>

</body>
</html>
