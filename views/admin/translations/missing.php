<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Неполные переводы — Админ-панель</title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?v=8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=4"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/admin-translations.css?v=2"
    >
</head>
<body>

<?php
$pageTitle = 'Админ-панель — Переводы';
require __DIR__ . '/../../partials/header.php';

$languageMap = [];
foreach ($targetLanguages ?? [] as $language) {
    $code = strtolower(trim((string) ($language['code'] ?? '')));
    if ($code === '') {
        continue;
    }

    $languageMap[$code] = [
        'name' => (string) ($language['name'] ?? $code),
        'short_name' => (string) ($language['short_name'] ?? strtoupper($code))
    ];
}

$typeLabels = [
    'product' => 'Товар',
    'category' => 'Категория',
    'method' => 'Способ доставки',
    'service' => 'Служба доставки',
    'option' => 'Опция',
    'delivery' => 'Delivery',
    'option_input' => 'Поле покупателя'
];
?>

<main class="catalog">
    <section class="product-card translations-admin">

        <div class="translations-head">
            <div>
                <h2>
                    Требуют перевода ·
                    <?= htmlspecialchars((string) ($sectionLabel ?? '')) ?>
                </h2>

                <p class="translations-subtitle">
                    Один элемент показан один раз. Значки справа — языки,
                    для которых перевод отсутствует.
                </p>
            </div>

            <a
                class="translations-language-link"
                href="/Anabelka/admin/translations"
            >
                ← К центру переводов
            </a>
        </div>

        <?php if (!empty($missingError)): ?>
            <div class="translations-error">
                <?= htmlspecialchars((string) $missingError) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($items)): ?>
            <div class="translation-missing-empty">
                Для этого раздела отсутствующих переводов нет.
            </div>
        <?php else: ?>
            <div class="translation-missing-list">
                <?php foreach ($items as $item): ?>
                    <article class="translation-missing-card">
                        <div class="translation-missing-main">
                            <span class="translation-missing-type">
                                <?= htmlspecialchars(
                                    $typeLabels[$item['type'] ?? '']
                                    ?? (string) ($item['type'] ?? '')
                                ) ?>
                            </span>

                            <strong class="translation-missing-name">
                                <?= htmlspecialchars(
                                    (string) ($item['name'] ?? '')
                                ) ?>
                            </strong>
                        </div>

                        <div class="translation-missing-languages">
                            <?php foreach ($item['missing_languages'] ?? [] as $code): ?>
                                <?php
                                $language = $languageMap[$code] ?? [
                                    'name' => strtoupper((string) $code),
                                    'short_name' => strtoupper((string) $code)
                                ];
                                ?>

                                <span
                                    class="translation-missing-language"
                                    title="<?= htmlspecialchars($language['name']) ?>"
                                >
                                    <?= htmlspecialchars($language['short_name']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>

                        <?php if (!empty($item['url'])): ?>
                            <a
                                class="translation-missing-open"
                                href="<?= htmlspecialchars((string) $item['url']) ?>"
                            >
                                Открыть раздел
                            </a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </section>
</main>

</body>
</html>
