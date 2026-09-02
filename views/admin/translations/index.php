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
        href="/Anabelka/css/admin-translations.css?v=4"
    >
</head>
<body>

<?php
$pageTitle = 'Адмін-панель — Переклади';
require __DIR__ . '/../../partials/header.php';

$sourceName = (string) (
    $sourceLanguage['name'] ?? 'Українська'
);

$currentProviderName = (string) (
    $providers[$selectedProvider]['name']
    ?? $selectedProvider
    ?? '—'
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

            <a
                class="translations-language-link"
                href="/Anabelka/admin/languages"
            >
                Керування мовами
            </a>
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

            <div class="translations-summary-card">
                <span class="translations-summary-label">
                    Основний ШІ
                </span>
                <span
                    class="translations-summary-value"
                    data-ai-default-provider-name
                >
                    <?= htmlspecialchars($currentProviderName ?: '—') ?>
                </span>
            </div>
        </div>

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
                                    Потребують перекладу: <?= $missing ?>
                                </a>
                            <?php else: ?>
                                <span class="translation-missing">
                                    Потребують перекладу: <?= $missing ?>
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

        <section class="translations-section">
            <h3 class="translations-section-title">
                Засоби перекладу
            </h3>

            <div class="translation-providers">
                <?php foreach ($providers ?? [] as $code => $provider): ?>
                    <?php
                    $isConfigured = !empty($provider['configured']);
                    $isCurrent = (string) $selectedProvider === (string) $code;
                    ?>

                    <article
                        class="translation-provider-card<?= $isCurrent ? ' is-default' : '' ?>"
                        data-ai-provider-code="<?= htmlspecialchars((string) $code) ?>"
                    >
                        <div class="translation-provider-head">
                            <span class="translation-provider-name">
                                <?= htmlspecialchars(
                                    (string) ($provider['name'] ?? $code)
                                ) ?>
                            </span>

                            <span
                                class="translation-provider-status<?= $isConfigured ? ' ready' : '' ?>"
                            >
                                <?= $isConfigured ? 'Готово' : 'Потрібен ключ' ?>
                            </span>
                        </div>

                        <span
                            class="translation-provider-current"
                            data-ai-provider-current
                        >
                            <?= $isCurrent
                                ? 'За замовчуванням'
                                : ($isConfigured
                                    ? 'Доступний для вибору'
                                    : 'Недоступний без ключа') ?>
                        </span>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

    </section>
</main>

</body>
</html>
