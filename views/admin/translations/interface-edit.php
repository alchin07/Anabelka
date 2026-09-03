<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Переклад інтерфейсу — Адмін-панель</title>

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
        href="/Anabelka/css/admin-translations.css?v=6"
    >
</head>
<body>

<?php
$pageTitle = 'Адмін-панель — Переклади';
require __DIR__ . '/../../partials/header.php';

$translationKey = (string) ($translationKey ?? '');
$translations = is_array($translations ?? null)
    ? $translations
    : [];
$missingLanguages = is_array($missingLanguages ?? null)
    ? $missingLanguages
    : [];
$focusLanguage = strtolower(
    trim((string) ($focusLanguage ?? ''))
);
$sourceValue = (string) (
    $sourceTranslation['value'] ?? ''
);
$returnUrl = (string) (
    $returnUrl
    ?? '/Anabelka/admin/translations/missing?section=interface'
);
?>

<main class="catalog">
    <section class="product-card translations-admin">

        <div class="translations-head">
            <div>
                <h2>Редагування тексту інтерфейсу</h2>

                <?php if ($translationKey !== ''): ?>
                    <code class="interface-editor-key">
                        <?= htmlspecialchars($translationKey) ?>
                    </code>
                <?php endif; ?>
            </div>

            <a
                class="translations-language-link"
                href="<?= htmlspecialchars($returnUrl) ?>"
            >
                ← До списку
            </a>
        </div>

        <?php if (!empty($editorError)): ?>
            <div class="translations-error">
                <?= htmlspecialchars((string) $editorError) ?>
            </div>
        <?php else: ?>

            <p class="interface-editor-help">
                Українська — вихідна мова. Порожній переклад буде
                позначено як відсутній, а на сайті тимчасово з’явиться
                український текст.
            </p>

            <form
                id="interface-translation-form"
                class="interface-editor-form"
                action="/Anabelka/admin/translations/interface/save"
                method="post"
                data-focus-language="<?= htmlspecialchars($focusLanguage) ?>"
            >
                <input
                    type="hidden"
                    name="translation_key"
                    value="<?= htmlspecialchars($translationKey) ?>"
                >

                <input
                    type="hidden"
                    name="return_url"
                    value="<?= htmlspecialchars($returnUrl) ?>"
                >

                <section class="interface-editor-source">
                    <div class="interface-language-head">
                        <strong>Українська · вихідна мова</strong>
                    </div>

                    <label for="interface-source-value">
                        Вихідний текст
                    </label>

                    <textarea
                        id="interface-source-value"
                        name="source_value"
                        rows="3"
                        required
                    ><?= htmlspecialchars($sourceValue) ?></textarea>
                </section>

                <?php foreach ($targetLanguages ?? [] as $language): ?>
                    <?php
                    $code = strtolower(
                        trim((string) ($language['code'] ?? ''))
                    );

                    if ($code === '') {
                        continue;
                    }

                    $translation = $translations[$code] ?? [];
                    $value = (string) ($translation['value'] ?? '');
                    $isMissing = in_array(
                        $code,
                        $missingLanguages,
                        true
                    );
                    ?>

                    <section
                        class="interface-editor-language<?= $isMissing ? ' is-missing' : '' ?><?= $code === $focusLanguage ? ' is-focus-target' : '' ?>"
                        data-interface-language="<?= htmlspecialchars($code) ?>"
                    >
                        <div class="interface-language-head">
                            <strong>
                                <?= htmlspecialchars(
                                    (string) ($language['name'] ?? $code)
                                ) ?>
                                ·
                                <?= htmlspecialchars(
                                    (string) (
                                        $language['short_name']
                                        ?? strtoupper($code)
                                    )
                                ) ?>
                            </strong>

                            <button
                                type="button"
                                class="interface-ai-translate"
                                data-interface-ai-translate
                                data-target-language="<?= htmlspecialchars($code) ?>"
                            >
                                Перекласти через ШІ
                            </button>
                        </div>

                        <label for="interface-value-<?= htmlspecialchars($code) ?>">
                            Переклад
                        </label>

                        <textarea
                            id="interface-value-<?= htmlspecialchars($code) ?>"
                            class="interface-translation-value"
                            name="translation_value[<?= htmlspecialchars($code) ?>]"
                            rows="3"
                            autocomplete="off"
                        ><?= htmlspecialchars($value) ?></textarea>

                        <?php if ($isMissing): ?>
                            <span class="interface-missing-note">
                                Переклад відсутній
                            </span>
                        <?php endif; ?>
                    </section>

                <?php endforeach; ?>

                <div class="interface-editor-actions">
                    <a
                        class="interface-editor-cancel"
                        href="<?= htmlspecialchars($returnUrl) ?>"
                    >
                        Скасувати
                    </a>

                    <button
                        type="submit"
                        class="interface-editor-save"
                    >
                        Зберегти
                    </button>
                </div>
            </form>

        <?php endif; ?>

    </section>
</main>

<div
    id="site-message"
    class="site-message"
    role="status"
    aria-live="polite"
></div>

<script
    src="/Anabelka/js/admin-interface-translations.js?v=1"
></script>

</body>
</html>
