<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Категорії — Адмін-панель</title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?v=8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=4"
    >

    <style>
        .category-admin {
            max-width: 900px;
            margin: 0 auto;
            padding: 18px;
        }

        .category-admin-head {
            margin-bottom: 18px;
        }

        .category-admin-head h2 {
            margin: 0 0 5px;
        }

        .category-admin-subtitle {
            margin: 0;
            color: #777;
        }

        .category-admin-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        /*
         * Правило Анабельки:
         * уровни дерева НЕ смещаются вправо.
         * Иерархия показывается только оттенком карточки.
         */
        .category-admin-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 44px;
            gap: 10px;
            align-items: stretch;
            margin-left: 0;
        }

        .category-admin-card {
            min-width: 0;
            min-height: 92px;
            box-sizing: border-box;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 14px;
            background: #faf7ff;
        }

        /* Уровень 1 — самый светлый. */
        .category-admin-row[data-category-level="0"] .category-admin-card {
            background: #faf7ff;
        }

        /* Уровень 2 — темнее. */
        .category-admin-row[data-category-level="1"] .category-admin-card {
            background: #f2e8fc;
        }

        /* Уровень 3 и глубже — ещё темнее. */
        .category-admin-row[data-category-level="2"] .category-admin-card,
        .category-admin-row[data-category-level="3"] .category-admin-card,
        .category-admin-row[data-category-level="4"] .category-admin-card,
        .category-admin-row[data-category-level="5"] .category-admin-card {
            background: #e4cef8;
        }

        .category-admin-name {
            display: block;
            font-weight: 700;
            font-size: 16px;
        }

        .category-admin-description {
            display: block;
            margin-top: 5px;
            color: #777;
            font-size: 13px;
            line-height: 1.4;
        }

        .category-admin-slug {
            display: block;
            margin-top: 6px;
            color: #999;
            font-size: 12px;
        }

        .category-edit-button {
            width: 44px;
            min-height: 92px;
            padding: 0;

            display: flex;
            align-items: center;
            justify-content: center;

            border: 1px solid var(--border-color);
            border-radius: 14px;
            background: #fff;
            color: var(--primary-color);
            font-size: 20px;
            cursor: pointer;
        }

        .category-edit-button:hover {
            background: var(--primary-light-color);
        }

        .category-modal[hidden] {
            display: none;
        }

        .category-modal {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .category-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.35);
        }

        .category-modal-window {
            position: relative;
            width: min(680px, 100%);
            max-height: 88vh;
            overflow-y: auto;
            padding: 20px;
            border-radius: 18px;
            background: #fff;
        }

        .category-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 16px;
        }

        .category-modal-head h3 {
            margin: 0;
        }

        .category-modal-close {
            border: 0;
            background: transparent;
            font-size: 28px;
            cursor: pointer;
        }

        .category-source-head,
        .category-language-head {
            margin: 14px 0 10px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #faf7ff;
            font-weight: 700;
        }

        .category-translation-section {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid #eadcf7;
            scroll-margin: 18px;
        }

        .category-translation-section.is-translation-focus {
            margin-right: -8px;
            margin-left: -8px;
            padding-right: 8px;
            padding-left: 8px;
            border-radius: 12px;
            background: #faf7ff;
            box-shadow: 0 0 0 2px var(--primary-color);
        }

        .category-form-group {
            margin-bottom: 14px;
        }

        .category-form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .category-form-group input,
        .category-form-group textarea {
            width: 100%;
            box-sizing: border-box;
        }

        .category-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        .category-modal-actions button {
            padding: 11px 16px;
            border-radius: 10px;
            cursor: pointer;
        }

        .category-save {
            border: 0;
            background: var(--primary-color);
            color: #fff;
            font-weight: 700;
        }

        .category-cancel {
            border: 1px solid #ddd;
            background: #fff;
        }

        @media (max-width: 650px) {
            .category-admin {
                padding: 12px 7px;
            }

            .category-admin-list {
                gap: 8px;
            }

            .category-admin-row {
                grid-template-columns: minmax(0, 1fr) 42px;
                gap: 8px;
                margin-left: 0;
            }

            .category-admin-card,
            .category-edit-button {
                min-height: 86px;
            }

            .category-edit-button {
                width: 42px;
            }
        }
    </style>
</head>
<body>

<?php
$pageTitle = 'Админ-панель — Категории';
require __DIR__ . '/../../partials/header.php';

$categoryById = [];
foreach ($categories as $categoryItem) {
    $categoryById[(int) $categoryItem['id']] = $categoryItem;
}

$depthFor = function ($category) use (&$categoryById) {
    $depth = 0;
    $parentId = (int) ($category['parent_id'] ?? 0);
    $guard = 0;

    while ($parentId > 0 && isset($categoryById[$parentId]) && $guard < 20) {
        $depth++;
        $parentId = (int) ($categoryById[$parentId]['parent_id'] ?? 0);
        $guard++;
    }

    return $depth;
};
?>

<main class="catalog">
    <section class="product-card category-admin">
        <div class="category-admin-head">
            <h2>Категорії</h2>
            <p class="category-admin-subtitle">
                Редагування українського оригіналу та перекладів
            </p>
        </div>

        <div class="category-admin-list">
            <?php foreach ($categories as $category): ?>
                <?php
                $depth = $depthFor($category);
                $translationsJson = json_encode(
                    $category['translations'] ?? [],
                    JSON_UNESCAPED_UNICODE
                );
                ?>

                <div
                    class="category-admin-row"
                    data-category-level="<?= (int) $depth ?>"
                >
                    <div class="category-admin-card">
                        <span class="category-admin-name">
                            <?= htmlspecialchars($category['name']) ?>
                        </span>

                        <?php if (!empty($category['description'])): ?>
                            <span class="category-admin-description">
                                <?= htmlspecialchars($category['description']) ?>
                            </span>
                        <?php endif; ?>

                        <span class="category-admin-slug">
                            slug: <?= htmlspecialchars($category['slug']) ?>
                        </span>
                    </div>

                    <button
                        type="button"
                        class="category-edit-button"
                        aria-label="Редагувати категорію"
                        data-category-id="<?= (int) $category['id'] ?>"
                        data-category-name="<?= htmlspecialchars(
                            $category['name'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        data-category-description="<?= htmlspecialchars(
                            $category['description'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        data-category-translations="<?= htmlspecialchars(
                            $translationsJson,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >
                        ✎
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<div
    id="category-edit-modal"
    class="category-modal"
    hidden
>
    <div
        class="category-modal-backdrop"
        data-category-close
    ></div>

    <div
        class="category-modal-window"
        role="dialog"
        aria-modal="true"
        aria-labelledby="category-edit-title"
    >
        <div class="category-modal-head">
            <h3 id="category-edit-title">Редагування категорії</h3>
            <button
                type="button"
                class="category-modal-close"
                data-category-close
            >×</button>
        </div>

        <form
            id="category-edit-form"
            action="/Anabelka/admin/categories/update"
            method="POST"
        >
            <input
                type="hidden"
                name="category_id"
                id="category-edit-id"
            >

            <div class="category-source-head">
                Українська · вихідна мова
            </div>

            <div class="category-form-group">
                <label for="category-edit-name">Назва *</label>
                <input
                    type="text"
                    name="name"
                    id="category-edit-name"
                    required
                >
            </div>

            <div class="category-form-group">
                <label for="category-edit-description">Опис</label>
                <textarea
                    name="description"
                    id="category-edit-description"
                    rows="4"
                ></textarea>
            </div>

            <?php foreach ($languages as $language): ?>
                <?php
                $code = strtolower(trim((string) ($language['code'] ?? '')));
                if ($code === '' || $code === Language::SOURCE_CODE) {
                    continue;
                }
                ?>

                <section
                    class="category-translation-section"
                    data-category-language="<?= htmlspecialchars($code) ?>"
                >
                    <div class="category-language-head">
                        <?= htmlspecialchars($language['name']) ?>
                        · <?= htmlspecialchars($language['short_name']) ?>
                    </div>

                    <div class="category-form-group">
                        <label>Название / Name</label>
                        <input
                            type="text"
                            name="translation_name[<?= htmlspecialchars($code) ?>]"
                            class="category-translation-name"
                            autocomplete="off"
                        >
                    </div>

                    <div class="category-form-group">
                        <label>Описание / Description</label>
                        <textarea
                            name="translation_description[<?= htmlspecialchars($code) ?>]"
                            class="category-translation-description"
                            rows="3"
                        ></textarea>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="category-modal-actions">
                <button
                    type="button"
                    class="category-cancel"
                    data-category-close
                >Отмена</button>

                <button
                    type="submit"
                    class="category-save"
                >Сохранить</button>
            </div>
        </form>
    </div>
</div>

<div
    id="site-message"
    class="site-message"
></div>

<script>
(function () {
    const modal = document.getElementById('category-edit-modal');
    const form = document.getElementById('category-edit-form');
    const idField = document.getElementById('category-edit-id');
    const nameField = document.getElementById('category-edit-name');
    const descriptionField = document.getElementById('category-edit-description');

    function getTranslationFocusField(button) {
        const params = new URLSearchParams(window.location.search);
        const languageCode = String(
            params.get('focus_language') || ''
        ).trim().toLowerCase();
        const requestedId = String(
            params.get('highlight') || ''
        ).trim();

        if (
            languageCode === ''
            || !/^\d+$/.test(requestedId)
            || requestedId !== String(button.dataset.categoryId || '')
        ) {
            return null;
        }

        const section = Array.from(
            document.querySelectorAll('[data-category-language]')
        ).find(function (item) {
            return String(
                item.dataset.categoryLanguage || ''
            ).trim().toLowerCase() === languageCode;
        }) || null;

        return section
            ? section.querySelector('.category-translation-name')
            : null;
    }

    function focusTranslationField(field) {
        document
            .querySelectorAll('.category-translation-section.is-translation-focus')
            .forEach(function (section) {
                section.classList.remove('is-translation-focus');
            });

        if (!field) {
            nameField.focus();
            return;
        }

        const section = field.closest('.category-translation-section');

        if (section) {
            section.classList.add('is-translation-focus');
        }

        field.focus();

        if (typeof field.select === 'function') {
            field.select();
        }

        window.setTimeout(function () {
            field.scrollIntoView({
                block: 'center'
            });
        }, 50);
    }

    function getTranslationReturnUrl() {
        const params = new URLSearchParams(window.location.search);
        const requestedId = String(
            params.get('highlight') || ''
        ).trim();
        const languageCode = String(
            params.get('focus_language') || ''
        ).trim();

        if (/^\d+$/.test(requestedId) && languageCode !== '') {
            return '/Anabelka/admin/translations/missing?section=categories';
        }

        return '';
    }

    function closeModal() {
        modal.hidden = true;
    }

    function showMessage(text) {
        const message = document.getElementById('site-message');
        if (!message) {
            return;
        }

        message.textContent = text;
        message.classList.add('show');

        clearTimeout(window.categoryMessageTimer);
        window.categoryMessageTimer = setTimeout(function () {
            message.classList.remove('show');
        }, 2200);
    }

    document.querySelectorAll('.category-edit-button').forEach(function (button) {
        button.addEventListener('click', function () {
            idField.value = button.dataset.categoryId || '';
            nameField.value = button.dataset.categoryName || '';
            descriptionField.value = button.dataset.categoryDescription || '';

            let translations = {};
            try {
                translations = JSON.parse(
                    button.dataset.categoryTranslations || '{}'
                );
            } catch (error) {
                translations = {};
            }

            document.querySelectorAll('[data-category-language]').forEach(function (section) {
                const code = section.dataset.categoryLanguage;
                const translation = translations[code] || {};

                section.querySelector('.category-translation-name').value =
                    translation.name || '';

                section.querySelector('.category-translation-description').value =
                    translation.description || '';
            });

            modal.hidden = false;

            const translationFocusField =
                getTranslationFocusField(button);

            window.setTimeout(function () {
                focusTranslationField(translationFocusField);
            }, 50);
        });
    });

    document.querySelectorAll('[data-category-close]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Не удалось сохранить категорию.');
            }

            showMessage(data.message || 'Сохранено.');
            closeModal();

            setTimeout(function () {
                const returnUrl = getTranslationReturnUrl();

                if (returnUrl) {
                    window.location.replace(returnUrl);
                    return;
                }

                window.location.reload();
            }, 400);

        } catch (error) {
            showMessage(error.message || 'Не удалось сохранить категорию.');
        }
    });
})();
</script>

</body>
</html>
