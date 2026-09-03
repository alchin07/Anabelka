<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Переклади — Адмін-панель</title>

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

$sourceName = (string) (
    $sourceLanguage['name'] ?? 'Українська'
);

$sectionLabels = [
    'products' => 'Товари',
    'categories' => 'Категорії',
    'delivery' => 'Доставка',
    'interface' => 'Інтерфейс'
];
?>

<main class="catalog">
    <section class="product-card translations-admin">

        <div class="translations-head">
            <div>
                <h2>Переклади</h2>
                <p class="translations-subtitle">
                    Центр керування багатомовністю Анабельки
                </p>
            </div>

            <div class="translations-head-actions">
                <a
                    class="translations-language-link"
                    href="/Anabelka/admin/languages"
                >
                    Керування мовами
                </a>

                <a
                    class="translations-language-link"
                    href="/Anabelka/admin/ai-translation"
                >
                    Налаштування ШІ
                </a>
            </div>
        </div>

        <?php if (!empty($dashboardError)): ?>
            <div class="translations-error">
                <?= htmlspecialchars($dashboardError) ?>
            </div>
        <?php endif; ?>

        <div class="translations-summary">
            <div class="translations-summary-card">
                <span class="translations-summary-label">
                    Вихідна мова
                </span>
                <span class="translations-summary-value">
                    <?= htmlspecialchars($sourceName) ?>
                </span>
            </div>

            <div class="translations-summary-card">
                <span class="translations-summary-label">
                    Мов перекладу
                </span>
                <span class="translations-summary-value">
                    <?= count($targetLanguages ?? []) ?>
                </span>

                <?php if (!empty($languages)): ?>
                    <div class="translations-languages">
                        <?php foreach ($languages as $language): ?>
                            <span class="translations-language-chip">
                                <?= htmlspecialchars(
                                    (string) ($language['short_name'] ?? '')
                                ) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

        <?php if (!empty($languageCoverage)): ?>
            <section class="translations-section">
                <h3 class="translations-section-title">
                    Готовність за мовами
                </h3>

                <p class="translations-section-help">
                    Готовими вважаються лише схвалені переклади.
                    Чернетки, перевірка та застарілі тексти потребують уваги.
                </p>

                <div class="translation-language-coverage-grid">
                    <?php foreach ($languageCoverage as $languageItem): ?>
                        <?php
                        $languagePercent = max(
                            0,
                            min(
                                100,
                                (int) ($languageItem['percent'] ?? 0)
                            )
                        );
                        $languageStates = is_array(
                            $languageItem['states'] ?? null
                        )
                            ? $languageItem['states']
                            : [];
                        ?>

                        <article class="translation-language-coverage-card">
                            <div class="translation-coverage-head">
                                <span class="translation-coverage-title">
                                    <?= htmlspecialchars(
                                        (string) ($languageItem['name'] ?? '')
                                    ) ?>
                                    ·
                                    <?= htmlspecialchars(
                                        (string) ($languageItem['short_name'] ?? '')
                                    ) ?>
                                </span>

                                <span class="translation-coverage-percent">
                                    <?= $languagePercent ?>%
                                </span>
                            </div>

                            <div class="translation-coverage-meta">
                                Схвалено:
                                <?= (int) ($languageItem['approved'] ?? 0) ?>
                                з
                                <?= (int) ($languageItem['required'] ?? 0) ?>
                            </div>

                            <div
                                class="translation-progress"
                                aria-label="Готовність мови <?= $languagePercent ?>%"
                            >
                                <div
                                    class="translation-progress-bar"
                                    style="width: <?= $languagePercent ?>%;"
                                ></div>
                            </div>

                            <div class="translation-language-states">
                                <?php if (!empty($languageStates['missing'])): ?>
                                    <span>Відсутні: <?= (int) $languageStates['missing'] ?></span>
                                <?php endif; ?>

                                <?php if (!empty($languageStates['outdated'])): ?>
                                    <span>Оновити: <?= (int) $languageStates['outdated'] ?></span>
                                <?php endif; ?>

                                <?php if (!empty($languageStates['review'])): ?>
                                    <span>Перевірити: <?= (int) $languageStates['review'] ?></span>
                                <?php endif; ?>

                                <?php if (!empty($languageStates['ai_draft'])): ?>
                                    <span>Чернетки ШІ: <?= (int) $languageStates['ai_draft'] ?></span>
                                <?php endif; ?>

                                <?php if (!empty($languageStates['manual_draft'])): ?>
                                    <span>Ручні чернетки: <?= (int) $languageStates['manual_draft'] ?></span>
                                <?php endif; ?>

                                <?php if (empty($languageItem['attention'])): ?>
                                    <span class="is-complete">Усе готово</span>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="translations-section">
            <h3 class="translations-section-title">
                Стан перекладів
            </h3>

            <div class="translation-coverage-list">
                <?php foreach ($coverage ?? [] as $item): ?>
                    <?php
                    $percent = max(
                        0,
                        min(100, (int) ($item['percent'] ?? 0))
                    );
                    $missing = (int) ($item['missing'] ?? 0);
                    $section = (string) ($item['section'] ?? '');
                    $hasDetails = in_array(
                        $section,
                        [
                            'products',
                            'categories',
                            'delivery',
                            'interface'
                        ],
                        true
                    );
                    $displayLabel = $sectionLabels[$section]
                        ?? (string) ($item['label'] ?? '');
                    ?>

                    <article class="translation-coverage-card">
                        <div class="translation-coverage-head">
                            <span class="translation-coverage-title">
                                <?= htmlspecialchars($displayLabel) ?>
                            </span>

                            <span class="translation-coverage-percent">
                                <?= $percent ?>%
                            </span>
                        </div>

                        <div class="translation-coverage-meta">
                            Елементів: <?= (int) ($item['entity_count'] ?? 0) ?>
                            · Перекладено:
                            <?= (int) ($item['translated'] ?? 0) ?>
                            з <?= (int) ($item['required'] ?? 0) ?>
                        </div>

                        <div
                            class="translation-progress"
                            aria-label="Покриття перекладами <?= $percent ?>%"
                        >
                            <div
                                class="translation-progress-bar"
                                style="width: <?= $percent ?>%;"
                            ></div>
                        </div>

                        <div class="translation-coverage-footer">
                            <?php if ($missing === 0): ?>
                                <span class="translation-missing is-complete">
                                    Усе перекладено
                                </span>
                            <?php elseif ($hasDetails): ?>
                                <a
                                    class="translation-missing translation-missing-link"
                                    href="/Anabelka/admin/translations/missing?section=<?= htmlspecialchars($section) ?>"
                                >
                                    Потребують уваги: <?= $missing ?>
                                </a>
                            <?php else: ?>
                                <span class="translation-missing">
                                    Потребують уваги: <?= $missing ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($item['url'])): ?>
                                <a
                                    class="translation-coverage-link"
                                    href="<?= htmlspecialchars($item['url']) ?>"
                                >
                                    Відкрити розділ
                                </a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

    </section>
</main>

</body>
</html>
