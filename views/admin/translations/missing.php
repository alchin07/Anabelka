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
        href="/Anabelka/css/admin-translations.css?v=3"
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
    'option_input' => 'Поле покупця',
    'interface' => 'Інтерфейс'
];

$sectionLabels = [
    'products' => 'Товари',
    'categories' => 'Категорії',
    'delivery' => 'Доставка',
    'interface' => 'Інтерфейс'
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
                    $itemType = (string) ($item['type'] ?? '');
                    $itemId = (int) ($item['id'] ?? 0);
                    $itemKey = trim(
                        (string) ($item['key'] ?? '')
                    );

                    $missingLanguages = array_values(
                        $item['missing_languages'] ?? []
                    );

                    $focusLanguage = strtolower(
                        trim(
                            (string) ($missingLanguages[0] ?? '')
                        )
                    );

                    $itemName = str_replace(
                        ' — поле покупателя',
                        ' — поле покупця',
                        (string) ($item['name'] ?? '')
                    );

                    $openUrl = (string) ($item['url'] ?? '');

                    if (
                        $itemType === 'interface'
                        && $itemKey !== ''
                    ) {
                        $openUrl =
                            '/Anabelka/admin/translations/interface?key='
                            . rawurlencode($itemKey)
                            . (
                                $focusLanguage !== ''
                                ? '&focus_language='
                                    . rawurlencode($focusLanguage)
                                : ''
                            );

                    } elseif ($itemId > 0) {
                        if ($itemType === 'category') {
                            $openUrl =
                                '/Anabelka/admin/categories?highlight='
                                . $itemId
                                . (
                                    $focusLanguage !== ''
                                    ? '&focus_language='
                                        . rawurlencode($focusLanguage)
                                    : ''
                                );
                        } elseif ($itemType === 'product') {
                            $openUrl =
                                '/Anabelka/admin/products?highlight='
                                . $itemId
                                . (
                                    $focusLanguage !== ''
                                    ? '&focus_language='
                                        . rawurlencode($focusLanguage)
                                    : ''
                                );
                        } elseif (in_array(
                            $itemType,
                            ['method', 'service', 'option', 'option_input'],
                            true
                        )) {
                            $deliveryAnchorType =
                                $itemType === 'option_input'
                                ? 'option'
                                : $itemType;

                            $openUrl =
                                '/Anabelka/admin/delivery?highlight='
                                . $itemId
                                . '&highlight_type='
                                . rawurlencode($itemType)
                                . (
                                    $focusLanguage !== ''
                                    ? '&focus_language='
                                        . rawurlencode($focusLanguage)
                                    : ''
                                )
                                . '#delivery-'
                                . rawurlencode($deliveryAnchorType)
                                . '-'
                                . $itemId;
                        }
                    }
                    ?>

                    <article class="translation-missing-card">
                        <div class="translation-missing-main">
                            <span class="translation-missing-type">
                                <?= htmlspecialchars(
                                    $typeLabels[$itemType]
                                    ?? $itemType
                                ) ?>
                            </span>

                            <strong class="translation-missing-name">
                                <?= htmlspecialchars($itemName) ?>
                            </strong>

                            <?php if ($itemKey !== ''): ?>
                                <code class="translation-missing-key">
                                    <?= htmlspecialchars($itemKey) ?>
                                </code>
                            <?php endif; ?>
                        </div>

                        <div class="translation-missing-languages">
                            <?php foreach ($missingLanguages as $code): ?>
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

                        <?php if ($openUrl !== ''): ?>
                            <a
                                class="translation-missing-open"
                                href="<?= htmlspecialchars($openUrl) ?>"
                            >
                                Відкрити для редагування
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
