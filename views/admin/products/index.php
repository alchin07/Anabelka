<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Товари — Адмін-панель</title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?v=8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=4"
    >

    <style>
        .product-admin {
            max-width: 900px;
            margin: 0 auto;
            padding: 18px;
        }

        .product-admin-head {
            margin-bottom: 18px;
        }

        .product-admin-head h2 {
            margin: 0 0 5px;
        }

        .product-admin-subtitle {
            margin: 0;
            color: #777;
        }

        .product-admin-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .product-admin-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 44px;
            gap: 10px;
            align-items: stretch;
        }

        .product-admin-card {
            min-width: 0;
            padding: 14px;
            border: 1px solid #eadcf7;
            border-radius: 14px;
            background: #faf7ff;
        }

        .product-admin-name {
            display: block;
            font-weight: 700;
            font-size: 17px;
        }

        .product-admin-meta,
        .product-admin-description {
            display: block;
            margin-top: 5px;
            color: #777;
            font-size: 14px;
        }

        .product-admin-slug {
            display: block;
            margin-top: 6px;
            color: #999;
            font-size: 12px;
        }

        .product-edit-button {
            border: 1px solid #eadcf7;
            border-radius: 12px;
            background: #fff;
            font-size: 20px;
            cursor: pointer;
        }

        .product-modal[hidden] {
            display: none;
        }

        .product-modal {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .product-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.35);
        }

        .product-modal-window {
            position: relative;
            width: min(680px, 100%);
            max-height: 88vh;
            overflow-y: auto;
            padding: 20px;
            border-radius: 18px;
            background: #fff;
        }

        .product-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 16px;
        }

        .product-modal-head h3 {
            margin: 0;
        }

        .product-modal-close {
            border: 0;
            background: transparent;
            font-size: 28px;
            cursor: pointer;
        }

        .product-source-head,
        .product-language-head {
            margin: 14px 0 10px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #faf7ff;
            font-weight: 700;
        }

        .product-language-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .product-ai-translate {
            border: 1px solid var(--primary-color);
            border-radius: 9px;
            padding: 7px 10px;
            background: #fff;
            color: var(--primary-color);
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }

        .product-ai-translate:disabled {
            opacity: 0.55;
            cursor: wait;
        }

        .product-translation-section {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid #eadcf7;
        }

        .product-form-group {
            margin-bottom: 14px;
        }

        .product-form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 700;
        }

        .product-form-group input,
        .product-form-group textarea {
            width: 100%;
            box-sizing: border-box;
        }

        .product-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }

        .product-modal-actions button {
            padding: 11px 16px;
            border-radius: 10px;
            cursor: pointer;
        }

        .product-save {
            border: 0;
            background: var(--primary-color);
            color: #fff;
            font-weight: 700;
        }

        .product-cancel {
            border: 1px solid #ddd;
            background: #fff;
        }
    </style>
</head>
<body>

<?php
$pageTitle = 'Админ-панель — Товары';
require __DIR__ . '/../../partials/header.php';
?>

<main class="catalog">
    <section class="product-card product-admin">
        <div class="product-admin-head">
            <h2>Товари</h2>
            <p class="product-admin-subtitle">
                Редагування українського оригіналу та перекладів
            </p>
        </div>

        <div class="product-admin-list">
            <?php foreach ($products as $product): ?>
                <?php
                $translationsJson = json_encode(
                    $product['translations'] ?? [],
                    JSON_UNESCAPED_UNICODE
                );
                ?>

                <div class="product-admin-row">
                    <div class="product-admin-card">
                        <span class="product-admin-name">
                            <?= htmlspecialchars($product['name']) ?>
                        </span>

                        <span class="product-admin-meta">
                            <?= htmlspecialchars($product['category_name'] ?? 'Без категорії') ?>
                            <?php if (!empty($product['sku'])): ?>
                                · SKU: <?= htmlspecialchars($product['sku']) ?>
                            <?php endif; ?>
                        </span>

                        <?php if (!empty($product['description'])): ?>
                            <span class="product-admin-description">
                                <?= htmlspecialchars($product['description']) ?>
                            </span>
                        <?php endif; ?>

                        <span class="product-admin-slug">
                            slug: <?= htmlspecialchars($product['slug']) ?>
                        </span>
                    </div>

                    <button
                        type="button"
                        class="product-edit-button"
                        aria-label="Редагувати товар"
                        data-product-id="<?= (int) $product['id'] ?>"
                        data-product-name="<?= htmlspecialchars(
                            $product['name'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        data-product-description="<?= htmlspecialchars(
                            $product['description'] ?? '',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        data-product-translations="<?= htmlspecialchars(
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
    id="product-edit-modal"
    class="product-modal"
    hidden
>
    <div
        class="product-modal-backdrop"
        data-product-close
    ></div>

    <div
        class="product-modal-window"
        role="dialog"
        aria-modal="true"
        aria-labelledby="product-edit-title"
    >
        <div class="product-modal-head">
            <h3 id="product-edit-title">Редагування товару</h3>
            <button
                type="button"
                class="product-modal-close"
                data-product-close
            >×</button>
        </div>

        <form
            id="product-edit-form"
            action="/Anabelka/admin/products/update"
            method="POST"
        >
            <input
                type="hidden"
                name="product_id"
                id="product-edit-id"
            >

            <div class="product-source-head">
                Українська · вихідна мова
            </div>

            <div class="product-form-group">
                <label for="product-edit-name">Назва *</label>
                <input
                    type="text"
                    name="name"
                    id="product-edit-name"
                    required
                >
            </div>

            <div class="product-form-group">
                <label for="product-edit-description">Опис</label>
                <textarea
                    name="description"
                    id="product-edit-description"
                    rows="5"
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
                    class="product-translation-section"
                    data-product-language="<?= htmlspecialchars($code) ?>"
                >
                    <div class="product-language-head">
                        <span>
                            <?= htmlspecialchars($language['name']) ?>
                            · <?= htmlspecialchars($language['short_name']) ?>
                        </span>

                        <button
                            type="button"
                            class="product-ai-translate"
                            data-product-ai-translate
                            data-target-language="<?= htmlspecialchars($code) ?>"
                        >
                            Перевести через ИИ
                        </button>
                    </div>

                    <div class="product-form-group">
                        <label>Название / Name</label>
                        <input
                            type="text"
                            name="translation_name[<?= htmlspecialchars($code) ?>]"
                            class="product-translation-name"
                            autocomplete="off"
                        >
                    </div>

                    <div class="product-form-group">
                        <label>Описание / Description</label>
                        <textarea
                            name="translation_description[<?= htmlspecialchars($code) ?>]"
                            class="product-translation-description"
                            rows="4"
                        ></textarea>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="product-modal-actions">
                <button
                    type="button"
                    class="product-cancel"
                    data-product-close
                >Отмена</button>

                <button
                    type="submit"
                    class="product-save"
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
    const modal = document.getElementById('product-edit-modal');
    const form = document.getElementById('product-edit-form');
    const idField = document.getElementById('product-edit-id');
    const nameField = document.getElementById('product-edit-name');
    const descriptionField = document.getElementById('product-edit-description');

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

        clearTimeout(window.productMessageTimer);
        window.productMessageTimer = setTimeout(function () {
            message.classList.remove('show');
        }, 3000);
    }

    document.querySelectorAll('.product-edit-button').forEach(function (button) {
        button.addEventListener('click', function () {
            idField.value = button.dataset.productId || '';
            nameField.value = button.dataset.productName || '';
            descriptionField.value = button.dataset.productDescription || '';

            let translations = {};
            try {
                translations = JSON.parse(
                    button.dataset.productTranslations || '{}'
                );
            } catch (error) {
                translations = {};
            }

            document.querySelectorAll('[data-product-language]').forEach(function (section) {
                const code = section.dataset.productLanguage;
                const translation = translations[code] || {};

                section.querySelector('.product-translation-name').value =
                    translation.name || '';

                section.querySelector('.product-translation-description').value =
                    translation.description || '';
            });

            modal.hidden = false;
        });
    });

    document.querySelectorAll('[data-product-close]').forEach(function (button) {
        button.addEventListener('click', closeModal);
    });

    document.querySelectorAll('[data-product-ai-translate]').forEach(function (button) {
        button.addEventListener('click', async function () {
            const section = button.closest('[data-product-language]');

            if (!section) {
                return;
            }

            if (
                !window.AnabelkaAITranslation
                || typeof window.AnabelkaAITranslation.suggest !== 'function'
            ) {
                showMessage('Система ИИ-перевода ещё загружается. Попробуйте ещё раз.');
                return;
            }

            const targetLanguage = button.dataset.targetLanguage || '';
            const translationName =
                section.querySelector('.product-translation-name');
            const translationDescription =
                section.querySelector('.product-translation-description');

            const originalText = button.textContent;

            button.disabled = true;
            button.textContent = 'Перевод…';

            try {
                const translation =
                    await window.AnabelkaAITranslation.suggest({
                        targetLanguage: targetLanguage,
                        name: nameField.value,
                        description: descriptionField.value,
                        context: 'product'
                    });

                if (translationName) {
                    translationName.value = translation.name || '';
                }

                if (translationDescription) {
                    translationDescription.value =
                        translation.description || '';
                }

                showMessage('ИИ-перевод получен. Проверьте его и нажмите «Сохранить».');

            } catch (error) {
                showMessage(
                    error.message || 'Не удалось получить ИИ-перевод.'
                );
            } finally {
                button.disabled = false;
                button.textContent = originalText;
            }
        });
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
                throw new Error(data.message || 'Не удалось сохранить товар.');
            }

            showMessage(data.message || 'Сохранено.');
            closeModal();

            setTimeout(function () {
                window.location.reload();
            }, 400);

        } catch (error) {
            showMessage(error.message || 'Не удалось сохранить товар.');
        }
    });
})();
</script>

</body>
</html>
