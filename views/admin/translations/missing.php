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
        href="/Anabelka/css/admin-translations.css?v=7"
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

$sectionCode = (string) ($section ?? '');
$isInterfaceSection = $sectionCode === 'interface';
$filters = is_array($filters ?? null) ? $filters : [];
$selectedLanguage = strtolower(
    trim((string) ($filters['language'] ?? ''))
);
$keyFilter = trim((string) ($filters['key'] ?? ''));
$sourceFilter = trim((string) ($filters['source'] ?? ''));
$visibleItemCount = count($items ?? []);
$totalItemCount = max(
    $visibleItemCount,
    (int) ($totalItems ?? $visibleItemCount)
);
$hasActiveFilters = $selectedLanguage !== ''
    || $keyFilter !== ''
    || $sourceFilter !== '';

$listParams = ['section' => $sectionCode];

if ($selectedLanguage !== '') {
    $listParams['language'] = $selectedLanguage;
}

if ($isInterfaceSection && $keyFilter !== '') {
    $listParams['translation_key'] = $keyFilter;
}

if ($sourceFilter !== '') {
    $listParams['source_text'] = $sourceFilter;
}

$currentListUrl = '/Anabelka/admin/translations/missing?'
    . http_build_query(
        $listParams,
        '',
        '&',
        PHP_QUERY_RFC3986
    );

$resetUrl = '/Anabelka/admin/translations/missing?section='
    . rawurlencode($sectionCode);
?>

<main class="catalog">
    <section class="product-card translations-admin">

        <div class="translations-head">
            <div>
                <h2>
                    Потребують уваги ·
                    <?= htmlspecialchars($displaySectionLabel) ?>
                </h2>

                <p class="translations-subtitle">
                    Кожен елемент показано один раз. Позначки праворуч
                    показують мову та стан перекладу.
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

        <?php if (empty($missingError)): ?>

        <section class="translation-filters">
            <div class="translation-filters-head">
                <div>
                    <h3>Пошук і фільтри</h3>
                    <p>
                        Знайдено: <?= $visibleItemCount ?>
                        із <?= $totalItemCount ?>
                    </p>
                </div>

                <?php if ($hasActiveFilters): ?>
                    <a href="<?= htmlspecialchars($resetUrl) ?>">
                        Очистити
                    </a>
                <?php endif; ?>
            </div>

            <form
                class="translation-filter-form"
                method="get"
                action="/Anabelka/admin/translations/missing"
            >
                <input
                    type="hidden"
                    name="section"
                    value="<?= htmlspecialchars($sectionCode) ?>"
                >

                <div class="translation-filter-grid<?= $isInterfaceSection ? '' : ' is-compact' ?>">
                    <label>
                        <span>Мова перекладу</span>

                        <select name="language">
                            <option value="">Усі мови</option>

                            <?php foreach ($targetLanguages ?? [] as $language): ?>
                                <?php
                                $code = strtolower(
                                    trim(
                                        (string) ($language['code'] ?? '')
                                    )
                                );

                                if ($code === '') {
                                    continue;
                                }
                                ?>

                                <option
                                    value="<?= htmlspecialchars($code) ?>"
                                    <?= $selectedLanguage === $code
                                        ? 'selected'
                                        : '' ?>
                                >
                                    <?= htmlspecialchars(
                                        (string) ($language['name'] ?? $code)
                                    ) ?> · <?= htmlspecialchars(
                                        (string) (
                                            $language['short_name']
                                            ?? strtoupper($code)
                                        )
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <?php if ($isInterfaceSection): ?>
                        <label>
                            <span>Ключ інтерфейсу</span>

                            <input
                                type="search"
                                name="translation_key"
                                value="<?= htmlspecialchars($keyFilter) ?>"
                                maxlength="190"
                                placeholder="Наприклад: header.cart"
                                autocomplete="off"
                            >
                        </label>
                    <?php endif; ?>

                    <label>
                        <span>Український текст</span>

                        <input
                            type="search"
                            name="source_text"
                            value="<?= htmlspecialchars($sourceFilter) ?>"
                            maxlength="250"
                            placeholder="Введіть частину тексту"
                            autocomplete="off"
                        >
                    </label>
                </div>

                <button type="submit">
                    Застосувати
                </button>
            </form>
        </section>

        <?php if (empty($items)): ?>
            <div class="translation-missing-empty">
                <?= $hasActiveFilters
                    ? 'За заданими фільтрами нічого не знайдено.'
                    : 'У цьому розділі всі переклади схвалено.' ?>
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
                    $languageStates = is_array(
                        $item['language_states'] ?? null
                    )
                        ? $item['language_states']
                        : [];

                    $focusLanguage = strtolower(
                        trim(
                            (string) ($missingLanguages[0] ?? '')
                        )
                    );

                    if (
                        $selectedLanguage !== ''
                        && in_array(
                            $selectedLanguage,
                            $missingLanguages,
                            true
                        )
                    ) {
                        $focusLanguage = $selectedLanguage;
                    }

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
                            )
                            . '&return_url='
                            . rawurlencode($currentListUrl);

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
                                $translationState = (string) (
                                    $languageStates[$code] ?? 'missing'
                                );
                                $translationStateLabel =
                                    TranslationWorkflow::stateLabel(
                                        $translationState
                                    );
                                ?>

                                <span
                                    class="translation-missing-language<?= $selectedLanguage === $code ? ' is-selected' : '' ?>"
                                    title="<?= htmlspecialchars(
                                        $language['name'] . ' — '
                                        . $translationStateLabel
                                    ) ?>"
                                >
                                    <strong>
                                        <?= htmlspecialchars($language['short_name']) ?>
                                    </strong>
                                    <small>
                                        <?= htmlspecialchars($translationStateLabel) ?>
                                    </small>
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

        <?php endif; ?>

    </section>
</main>

</body>
</html>
