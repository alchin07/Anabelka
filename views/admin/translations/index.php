<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Переводы — Админ-панель</title>

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

$sourceName = (string) (
    $sourceLanguage['name'] ?? 'Українська'
);

$currentProviderName = (string) (
    $providers[$selectedProvider]['name']
    ?? $selectedProvider
    ?? '—'
);
?>

<main class="catalog">
    <section class="product-card translations-admin">

        <div class="translations-head">
            <div>
                <h2>Переводы</h2>
                <p class="translations-subtitle">
                    Центр управления мультиязычностью Анабельки
                </p>
            </div>

            <a
                class="translations-language-link"
                href="/Anabelka/admin/languages"
            >
                Управление языками
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
                    Исходный язык
                </span>
                <span class="translations-summary-value">
                    <?= htmlspecialchars($sourceName) ?>
                </span>
            </div>

            <div class="translations-summary-card">
                <span class="translations-summary-label">
                    Языков перевода
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
                    Основной ИИ
                </span>
                <span class="translations-summary-value">
                    <?= htmlspecialchars($currentProviderName ?: '—') ?>
                </span>
            </div>
        </div>

        <section class="translations-section">
            <h3 class="translations-section-title">
                Состояние переводов
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
                        ['products', 'categories', 'delivery'],
                        true
                    );
                    ?>

                    <article class="translation-coverage-card">
                        <div class="translation-coverage-head">
                            <span class="translation-coverage-title">
                                <?= htmlspecialchars(
                                    (string) ($item['label'] ?? '')
                                ) ?>
                            </span>

                            <span class="translation-coverage-percent">
                                <?= $percent ?>%
                            </span>
                        </div>

                        <div class="translation-coverage-meta">
                            Элементов: <?= (int) ($item['entity_count'] ?? 0) ?>
                            · Переведено:
                            <?= (int) ($item['translated'] ?? 0) ?>
                            из <?= (int) ($item['required'] ?? 0) ?>
                        </div>

                        <div
                            class="translation-progress"
                            aria-label="Покрытие переводами <?= $percent ?>%"
                        >
                            <div
                                class="translation-progress-bar"
                                style="width: <?= $percent ?>%;"
                            ></div>
                        </div>

                        <div class="translation-coverage-footer">
                            <?php if ($missing === 0): ?>
                                <span class="translation-missing is-complete">
                                    Всё переведено
                                </span>
                            <?php elseif ($hasDetails): ?>
                                <a
                                    class="translation-missing translation-missing-link"
                                    href="/Anabelka/admin/translations/missing?section=<?= htmlspecialchars($section) ?>"
                                >
                                    Требуют перевода: <?= $missing ?>
                                </a>
                            <?php else: ?>
                                <span class="translation-missing">
                                    Требуют перевода: <?= $missing ?>
                                </span>
                            <?php endif; ?>

                            <?php if (!empty($item['url'])): ?>
                                <a
                                    class="translation-coverage-link"
                                    href="<?= htmlspecialchars($item['url']) ?>"
                                >
                                    Открыть раздел
                                </a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="translations-section">
            <h3 class="translations-section-title">
                Средства перевода
            </h3>

            <div class="translation-providers">
                <?php foreach ($providers ?? [] as $code => $provider): ?>
                    <?php
                    $isConfigured = !empty($provider['configured']);
                    $isCurrent = (string) $selectedProvider === (string) $code;
                    ?>

                    <article class="translation-provider-card">
                        <div class="translation-provider-head">
                            <span class="translation-provider-name">
                                <?= htmlspecialchars(
                                    (string) ($provider['name'] ?? $code)
                                ) ?>
                            </span>

                            <span
                                class="translation-provider-status<?= $isConfigured ? ' ready' : '' ?>"
                            >
                                <?= $isConfigured ? 'Готов' : 'Нужен ключ' ?>
                            </span>
                        </div>

                        <span class="translation-provider-current">
                            <?= $isCurrent
                                ? 'Используется сейчас'
                                : 'Доступен для выбора' ?>
                        </span>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

    </section>
</main>

</body>
</html>
