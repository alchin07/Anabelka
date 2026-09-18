<?php
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$items = is_array($items ?? null) ? $items : [];
$languages = is_array($languages ?? null) ? $languages : [];
$revision = (string) ($revision ?? '');
$csrfToken = (string) ($csrfToken ?? '');
$canManage = !empty($canManage);
$migrationRequired = !empty($migrationRequired);
$highlight = (int) ($highlight ?? 0);
$requestedFocusLanguage = strtolower(
    trim((string) ($_GET['focus_language'] ?? ''))
);
$targetLanguages = [];

foreach ($languages as $language) {
    $code = strtolower(trim((string) ($language['code'] ?? '')));

    if ($code === '' || $code === Language::SOURCE_CODE) {
        continue;
    }

    $targetLanguages[] = $language;
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мобільне меню — Адмін-панель</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/anabelka-notify.css?v=1">
    <link rel="stylesheet" href="/Anabelka/css/admin-mobile-navigation.css?v=3">
</head>
<body>
<?php
$pageTitle = 'Адмін-панель — Мобільне меню';
require __DIR__ . '/../partials/header.php';
?>

<main class="mobile-navigation-admin">
    <section
        class="mobile-navigation-admin-shell"
        data-mobile-navigation-root
        data-revision="<?= $escape($revision) ?>"
        data-csrf="<?= $escape($csrfToken) ?>"
    >
        <header class="mobile-navigation-admin-head">
            <div>
                <h2>Мобільне меню</h2>
                <p>
                    Пункти кнопки ☰ у мобільній версії сайту. Назва українською
                    є вихідною, переклади використовують активні мови Анабельки.
                </p>
            </div>

            <button
                type="button"
                class="mobile-navigation-add"
                data-mobile-navigation-add
                <?= (!$canManage || $migrationRequired) ? 'disabled' : '' ?>
            >
                + Додати пункт
            </button>
        </header>

        <?php if ($migrationRequired): ?>
            <div class="mobile-navigation-migration-required" role="alert">
                <strong>Потрібна міграція.</strong>
                <span>
                    <?= $escape(
                        $loadError
                        ?? 'Спочатку застосуйте ручну міграцію мобільного меню.'
                    ) ?>
                </span>
            </div>
        <?php endif; ?>

        <?php if (!$canManage && !$migrationRequired): ?>
            <div class="mobile-navigation-readonly" role="status">
                У вас є доступ до перегляду, але немає права змінювати мобільне меню.
            </div>
        <?php endif; ?>

        <section
            class="mobile-navigation-create-panel"
            data-mobile-navigation-create
            hidden
        >
            <h3>Новий пункт</h3>
            <form
                method="post"
                action="/Anabelka/admin/mobile-navigation/create"
                data-mobile-navigation-form="create"
            >
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">

                <div class="mobile-navigation-form-grid">
                    <label>
                        <span>Назва українською</span>
                        <input
                            type="text"
                            name="name_uk"
                            maxlength="160"
                            required
                            autocomplete="off"
                        >
                    </label>

                    <label>
                        <span>URL</span>
                        <input
                            type="text"
                            name="url"
                            maxlength="1000"
                            placeholder="/Anabelka/... або https://..."
                            required
                            autocomplete="off"
                        >
                    </label>
                </div>

                <label class="mobile-navigation-switch-row">
                    <input type="checkbox" name="is_active" value="1" checked>
                    <span>Показувати пункт у меню</span>
                </label>

                <?php if (!empty($targetLanguages)): ?>
                    <div class="mobile-navigation-translations">
                        <h4>Переклади</h4>
                        <?php foreach ($targetLanguages as $language): ?>
                            <?php
                            $code = strtolower(trim((string) ($language['code'] ?? '')));
                            ?>
                            <fieldset>
                                <legend>
                                    <?= $escape($language['name'] ?? strtoupper($code)) ?>
                                    · <?= $escape($language['short_name'] ?? strtoupper($code)) ?>
                                </legend>
                                <label>
                                    <span>Назва</span>
                                    <input
                                        type="text"
                                        name="translations[<?= $escape($code) ?>][name]"
                                        maxlength="160"
                                        autocomplete="off"
                                    >
                                </label>
                                <input
                                    type="hidden"
                                    name="translations[<?= $escape($code) ?>][source]"
                                    value="manual"
                                >
                                <input
                                    type="hidden"
                                    name="translations[<?= $escape($code) ?>][status]"
                                    value="approved"
                                >
                            </fieldset>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="mobile-navigation-form-actions">
                    <button type="submit">Створити</button>
                    <button type="button" data-mobile-navigation-create-cancel>
                        Скасувати
                    </button>
                </div>
            </form>
        </section>

        <div class="mobile-navigation-list" data-mobile-navigation-list>
            <?php foreach ($items as $index => $item): ?>
                <?php
                $itemId = (int) ($item['id'] ?? 0);
                $isActive = !empty($item['is_active']);
                $translations = is_array($item['translations'] ?? null)
                    ? $item['translations']
                    : [];
                $isHighlighted = $itemId > 0 && $itemId === $highlight;
                ?>
                <article
                    id="mobile-navigation-item-<?= $itemId ?>"
                    class="mobile-navigation-item<?= $isHighlighted ? ' is-highlighted' : '' ?>"
                    data-mobile-navigation-item
                    data-item-id="<?= $itemId ?>"
                >
                    <div class="mobile-navigation-item-summary">
                        <?php if ($canManage && !$migrationRequired): ?>
                            <button
                                type="button"
                                class="mobile-navigation-drag-handle"
                                data-mobile-navigation-drag-handle
                                aria-label="Перетягнути <?= $escape($item['name_uk'] ?? '') ?>"
                            >
                                ⋮⋮
                            </button>
                        <?php endif; ?>

                        <div class="mobile-navigation-item-copy">
                            <strong><?= $escape($item['name_uk'] ?? '') ?></strong>
                            <span><?= $escape($item['url'] ?? '') ?></span>
                        </div>

                        <span class="mobile-navigation-status<?= $isActive ? ' is-active' : '' ?>">
                            <?= $isActive ? 'Увімкнено' : 'Вимкнено' ?>
                        </span>
                    </div>

                    <?php if ($canManage && !$migrationRequired): ?>
                        <div class="mobile-navigation-row-actions">
                            <button
                                type="button"
                                data-mobile-navigation-move="up"
                                <?= $index === 0 ? 'disabled' : '' ?>
                            >
                                ↑ Вище
                            </button>
                            <button
                                type="button"
                                data-mobile-navigation-move="down"
                                <?= $index === count($items) - 1 ? 'disabled' : '' ?>
                            >
                                ↓ Нижче
                            </button>
                            <button
                                type="button"
                                data-mobile-navigation-toggle
                                data-next-active="<?= $isActive ? '0' : '1' ?>"
                            >
                                <?= $isActive ? 'Вимкнути' : 'Увімкнути' ?>
                            </button>
                            <button
                                type="button"
                                class="is-danger"
                                data-mobile-navigation-delete
                            >
                                Видалити
                            </button>
                        </div>
                    <?php endif; ?>

                    <details
                        class="mobile-navigation-editor"
                        <?= $isHighlighted ? 'open' : '' ?>
                    >
                        <summary>Редагувати</summary>

                        <form
                            method="post"
                            action="/Anabelka/admin/mobile-navigation/update"
                            data-mobile-navigation-form="update"
                        >
                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="item_id" value="<?= $itemId ?>">

                            <div class="mobile-navigation-form-grid">
                                <label>
                                    <span>Назва українською</span>
                                    <input
                                        type="text"
                                        name="name_uk"
                                        value="<?= $escape($item['name_uk'] ?? '') ?>"
                                        maxlength="160"
                                        required
                                        autocomplete="off"
                                        <?= $canManage ? '' : 'readonly' ?>
                                    >
                                </label>

                                <label>
                                    <span>URL</span>
                                    <input
                                        type="text"
                                        name="url"
                                        value="<?= $escape($item['url'] ?? '') ?>"
                                        maxlength="1000"
                                        required
                                        autocomplete="off"
                                        <?= $canManage ? '' : 'readonly' ?>
                                    >
                                </label>
                            </div>

                            <label class="mobile-navigation-switch-row">
                                <input
                                    type="checkbox"
                                    name="is_active"
                                    value="1"
                                    <?= $isActive ? 'checked' : '' ?>
                                    <?= $canManage ? '' : 'disabled' ?>
                                >
                                <span>Показувати пункт у меню</span>
                            </label>

                            <?php if (!empty($targetLanguages)): ?>
                                <div class="mobile-navigation-translations">
                                    <h4>Переклади</h4>

                                    <?php foreach ($targetLanguages as $language): ?>
                                        <?php
                                        $code = strtolower(trim((string) ($language['code'] ?? '')));
                                        $translation = is_array($translations[$code] ?? null)
                                            ? $translations[$code]
                                            : [];
                                        $status = (string) ($translation['status'] ?? 'approved');
                                        $source = (string) ($translation['source'] ?? 'manual');
                                        $shouldFocus = $isHighlighted
                                            && $requestedFocusLanguage === $code;
                                        ?>
                                        <fieldset
                                            data-mobile-navigation-language="<?= $escape($code) ?>"
                                        >
                                            <legend>
                                                <?= $escape($language['name'] ?? strtoupper($code)) ?>
                                                · <?= $escape($language['short_name'] ?? strtoupper($code)) ?>
                                            </legend>

                                            <?php if ($canManage): ?>
                                                <button
                                                    type="button"
                                                    class="mobile-navigation-ai-translate"
                                                    data-mobile-navigation-ai-translate
                                                    data-target-language="<?= $escape($code) ?>"
                                                >
                                                    Перекласти через ШІ
                                                </button>
                                            <?php endif; ?>

                                            <label>
                                                <span>Назва</span>
                                                <input
                                                    type="text"
                                                    name="translations[<?= $escape($code) ?>][name]"
                                                    value="<?= $escape($translation['name'] ?? '') ?>"
                                                    maxlength="160"
                                                    autocomplete="off"
                                                    data-mobile-navigation-translation-name
                                                    <?= $canManage ? '' : 'readonly' ?>
                                                    <?= $shouldFocus ? 'autofocus' : '' ?>
                                                >
                                            </label>

                                            <input
                                                type="hidden"
                                                name="translations[<?= $escape($code) ?>][source]"
                                                value="<?= $escape($source) ?>"
                                                data-mobile-navigation-translation-source
                                            >

                                            <div class="mobile-navigation-translation-meta">
                                                <label>
                                                    <span>Статус</span>
                                                    <select
                                                        name="translations[<?= $escape($code) ?>][status]"
                                                        data-mobile-navigation-translation-status
                                                        <?= $canManage ? '' : 'disabled' ?>
                                                    >
                                                        <?php foreach (TranslationWorkflow::statusOptions() as $statusValue => $statusLabel): ?>
                                                            <option
                                                                value="<?= $escape($statusValue) ?>"
                                                                <?= $status === $statusValue ? 'selected' : '' ?>
                                                            >
                                                                <?= $escape($statusLabel) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                            </div>
                                        </fieldset>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($canManage && !$migrationRequired): ?>
                                <div class="mobile-navigation-form-actions">
                                    <button type="submit">Зберегти</button>
                                </div>
                            <?php endif; ?>
                        </form>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if (empty($items) && !$migrationRequired): ?>
            <div class="mobile-navigation-empty">
                <strong>Пунктів поки немає.</strong>
                <span>Скористайтеся кнопкою «+ Додати пункт».</span>
            </div>
        <?php endif; ?>
    </section>
</main>

<div
    id="site-message"
    class="site-message anabelka-notify"
    role="status"
    aria-live="polite"
></div>
<script src="/Anabelka/js/anabelka-notify.js?v=1"></script>
<script src="/Anabelka/js/admin-mobile-navigation.js?v=1"></script>
</body>
</html>
