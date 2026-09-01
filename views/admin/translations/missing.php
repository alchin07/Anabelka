<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Неповні переклади — Адмін-панель</title>

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
$pageTitle = 'Адмін-панель — Переклади';
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
    'category' => 'Категорія',
    'method' => 'Спосіб доставки',
    'service' => 'Служба доставки',
    'option' => 'Опція',
    'delivery' => 'Доставка',
    'option_input' => 'Поле покупця'
];

$sectionLabels = [
    'products' => 'Товари',
    'categories' => 'Категорії',
    'delivery' => 'Доставка'
];

$displaySectionLabel = $sectionLabels[(string) ($section ?? '')]
    ?? (string) ($sectionLabel ?? 'Переклади');
?>

<main class="catalog">
    <section class="product-card translations-admin">

        <div class="translations-head">
            <div>
                <h2>
                    Потребують перекладу ·
                    <?= htmlspecialchars($displaySectionLabel) ?>
                </h2>

                <p class="translations-subtitle">
                    Кожен елемент показано один раз. Позначки праворуч — мови,
                    для яких переклад відсутній.
                </p>
            </div>

            <a
                class="translations-language-link"
                href="/Anabelka/admin/translations"
            >
                ← До центру перекладів
            </a>
        </div>

        <?php if (!empty($missingError)): ?>
            <div class="translations-error">
                <?= htmlspecialchars((string) $missingError) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($items)): ?>
            <div class="translation-missing-empty">
                У цьому розділі немає відсутніх перекладів.
            </div>
        <?php else: ?>
            <div class="translation-missing-list">
                <?php foreach ($items as $item): ?>
                    <?php
                    $itemName = str_replace(
                        ' — поле покупателя',
                        ' — поле покупця',
                        (string) ($item['name'] ?? '')
                    );
                    ?>

                    <article class="translation-missing-card">
                        <div class="translation-missing-main">
                            <span class="translation-missing-type">
                                <?= htmlspecialchars(
                                    $typeLabels[$item['type'] ?? '']
                                    ?? (string) ($item['type'] ?? '')
                                ) ?>
                            </span>

                            <strong class="translation-missing-name">
                                <?= htmlspecialchars($itemName) ?>
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
                                Відкрити розділ
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
