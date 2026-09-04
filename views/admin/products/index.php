<?php
$products = is_array($products ?? null) ? $products : [];
$categories = is_array($categories ?? null) ? $categories : [];
$priceRanks = is_array($priceRanks ?? null) ? $priceRanks : [];
$languages = is_array($languages ?? null) ? $languages : [];
$summary = is_array($summary ?? null) ? $summary : [];
$filters = AdminProduct::normalizeFilters(
    is_array($filters ?? null) ? $filters : []
);
$csrfToken = (string) ($csrfToken ?? '');
$translationStatusOptions = TranslationWorkflow::statusOptions();
$escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$formatMoney = function ($value) {
    return number_format((float) $value, 2, ',', ' ') . ' €';
};
$categoryById = [];

foreach ($categories as $category) {
    $categoryById[(int) ($category['id'] ?? 0)] = $category;
}

$categoryDepth = function ($category) use (&$categoryById) {
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
$productsUrl = function (array $changes = []) use ($filters) {
    $values = array_merge($filters, $changes);
    $query = [];

    if (($values['status'] ?? 'all') !== 'all') {
        $query['status'] = $values['status'];
    }
    if ((int) ($values['category_id'] ?? 0) > 0) {
        $query['category_id'] = (int) $values['category_id'];
    }
    if (trim((string) ($values['q'] ?? '')) !== '') {
        $query['q'] = trim((string) $values['q']);
    }

    return '/Anabelka/admin/products'
        . (empty($query) ? '' : '?' . http_build_query($query));
};
$statusTabs = [
    'all' => 'Усі',
    'active' => 'На сайті',
    'out_of_stock' => 'Без залишку',
    'hidden' => 'Приховані'
];
$productsJson = json_encode(
    $products,
    JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Товари — Адмін-панель</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/admin-products.css?v=1">
</head>
<body>

<?php
$pageTitle = 'Админ-панель — Товары';
require __DIR__ . '/../../partials/header.php';
?>

<main class="admin-products">
    <section class="admin-products-heading">
        <div>
            <h2>Товари</h2>
            <p>Ціни, залишки, розміри, фотографії та переклади</p>
        </div>

        <button
            type="button"
            class="admin-product-add"
            data-product-create
            <?= empty($categories) ? 'disabled' : '' ?>
        >
            <span aria-hidden="true">＋</span>
            Додати товар
        </button>
    </section>

    <?php if (!empty($flash)): ?>
        <div
            class="admin-product-flash <?= ($flash['type'] ?? '') === 'error' ? 'is-error' : 'is-success' ?>"
            role="status"
        >
            <?= $escape($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if ($productsError !== ''): ?>
        <div class="admin-product-flash is-error" role="alert">
            Дані товарів тимчасово недоступні:
            <?= $escape($productsError) ?>
        </div>
    <?php endif; ?>

    <nav class="admin-product-tabs" aria-label="Стан товарів">
        <?php foreach ($statusTabs as $statusCode => $statusLabel): ?>
            <a
                href="<?= $escape($productsUrl(['status' => $statusCode])) ?>"
                class="<?= $filters['status'] === $statusCode ? 'is-active' : '' ?>"
            >
                <span><?= $escape($statusLabel) ?></span>
                <strong><?= (int) ($summary[$statusCode] ?? 0) ?></strong>
            </a>
        <?php endforeach; ?>
    </nav>

    <form class="admin-product-filters" method="GET" action="/Anabelka/admin/products">
        <?php if ($filters['status'] !== 'all'): ?>
            <input type="hidden" name="status" value="<?= $escape($filters['status']) ?>">
        <?php endif; ?>

        <label>
            <span class="visually-hidden">Пошук товару</span>
            <input
                type="search"
                name="q"
                value="<?= $escape($filters['q']) ?>"
                placeholder="Назва, SKU або адреса"
            >
        </label>

        <label>
            <span class="visually-hidden">Категорія</span>
            <select name="category_id">
                <option value="0">Усі категорії</option>
                <?php foreach ($categories as $category): ?>
                    <?php $depth = $categoryDepth($category); ?>
                    <option
                        value="<?= (int) $category['id'] ?>"
                        <?= $filters['category_id'] === (int) $category['id'] ? 'selected' : '' ?>
                    >
                        <?= str_repeat('— ', $depth) ?><?= $escape($category['name']) ?>
                        <?= empty($category['is_active']) ? ' · прихована' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <button type="submit">Знайти</button>

        <?php if ($filters['q'] !== '' || $filters['category_id'] > 0): ?>
            <a href="<?= $escape($productsUrl(['q' => '', 'category_id' => 0])) ?>">
                Скинути
            </a>
        <?php endif; ?>
    </form>

    <?php if (empty($products) && $productsError === ''): ?>
        <section class="admin-product-empty">
            <strong>Товарів за цим фільтром немає</strong>
            <span>Змініть умови пошуку або додайте новий товар.</span>
        </section>
    <?php else: ?>
        <section class="admin-product-list" aria-label="Список товарів">
            <?php foreach ($products as $product): ?>
                <?php
                $stock = ($product['stock_mode'] ?? 'total') === 'by_size'
                    ? (int) ($product['size_stock'] ?? 0)
                    : (int) ($product['stock'] ?? 0);
                $isActive = !empty($product['is_active']);
                ?>
                <div
                    class="product-admin-row"
                    data-product-id="<?= (int) $product['id'] ?>"
                >
                    <article class="product-admin-card<?= $isActive ? '' : ' is-hidden' ?>">
                        <div class="admin-product-thumb">
                            <?php if (!empty($product['main_image'])): ?>
                                <img
                                    src="<?= $escape($product['main_image']) ?>"
                                    alt=""
                                    loading="lazy"
                                >
                            <?php else: ?>
                                <span aria-hidden="true">Фото</span>
                            <?php endif; ?>
                        </div>

                        <div class="admin-product-copy">
                            <div class="admin-product-badges">
                                <span class="<?= $isActive ? 'is-live' : 'is-muted' ?>">
                                    <?= $isActive ? 'На сайті' : 'Прихований' ?>
                                </span>
                                <?php if ($stock <= 0): ?>
                                    <span class="is-warning">Немає в наявності</span>
                                <?php endif; ?>
                            </div>

                            <h3 class="product-admin-name">
                                <?= $escape($product['name']) ?>
                            </h3>

                            <p class="product-admin-meta">
                                <?= $escape($product['category_name'] ?? 'Без категорії') ?>
                                <?php if (!empty($product['sku'])): ?>
                                    · <?= $escape($product['sku']) ?>
                                <?php endif; ?>
                            </p>

                            <div class="admin-product-numbers">
                                <strong><?= $formatMoney($product['price'] ?? 0) ?></strong>
                                <span>
                                    Залишок: <?= $stock ?>
                                    <?= ($product['stock_mode'] ?? '') === 'by_size' ? 'за розмірами' : 'шт.' ?>
                                </span>
                                <span>Фото: <?= count($product['images'] ?? []) ?></span>
                            </div>
                        </div>

                        <div class="admin-product-actions">
                            <button
                                type="button"
                                class="product-edit-button"
                                data-product-edit
                                data-product-id="<?= (int) $product['id'] ?>"
                            >
                                Редагувати
                            </button>

                            <form
                                method="POST"
                                action="/Anabelka/admin/products/duplicate"
                                data-product-duplicate-form
                            >
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                <input type="hidden" name="filter_status" value="<?= $escape($filters['status']) ?>">
                                <input type="hidden" name="filter_category_id" value="<?= (int) $filters['category_id'] ?>">
                                <input type="hidden" name="filter_q" value="<?= $escape($filters['q']) ?>">
                                <button type="submit">Створити копію</button>
                            </form>

                            <form
                                method="POST"
                                action="/Anabelka/admin/products/toggle"
                                data-product-toggle-form
                                data-next-active="<?= $isActive ? '0' : '1' ?>"
                            >
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                <input type="hidden" name="is_active" value="<?= $isActive ? '0' : '1' ?>">
                                <input type="hidden" name="filter_status" value="<?= $escape($filters['status']) ?>">
                                <input type="hidden" name="filter_category_id" value="<?= (int) $filters['category_id'] ?>">
                                <input type="hidden" name="filter_q" value="<?= $escape($filters['q']) ?>">
                                <button type="submit" class="<?= $isActive ? 'is-danger' : 'is-restore' ?>">
                                    <?= $isActive ? 'Приховати' : 'Повернути на сайт' ?>
                                </button>
                            </form>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

<div id="product-editor" class="product-editor" hidden>
    <button
        type="button"
        class="product-editor-backdrop"
        data-product-close
        aria-label="Закрити редактор"
    ></button>

    <section
        class="product-editor-window"
        role="dialog"
        aria-modal="true"
        aria-labelledby="product-editor-title"
    >
        <header class="product-editor-head">
            <div>
                <span id="product-editor-kicker">Товар</span>
                <h2 id="product-editor-title">Новий товар</h2>
            </div>
            <button type="button" class="product-editor-close" data-product-close>×</button>
        </header>

        <form
            id="product-editor-form"
            action="/Anabelka/admin/products/save"
            method="POST"
            enctype="multipart/form-data"
        >
            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="product_id" id="product-edit-id" value="0">

            <div class="product-editor-body">
                <section class="product-form-section">
                    <div class="product-section-heading">
                        <span>Основне</span>
                        <label class="product-active-switch">
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" id="product-edit-active" value="1">
                            <span>Показувати на сайті</span>
                        </label>
                    </div>

                    <div class="product-form-grid">
                        <label class="product-form-field is-wide">
                            <span>Категорія *</span>
                            <select name="category_id" id="product-edit-category" required>
                                <option value="">Оберіть категорію</option>
                                <?php foreach ($categories as $category): ?>
                                    <?php $depth = $categoryDepth($category); ?>
                                    <option value="<?= (int) $category['id'] ?>">
                                        <?= str_repeat('— ', $depth) ?><?= $escape($category['name']) ?>
                                        <?= empty($category['is_active']) ? ' · прихована' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="product-form-field is-wide">
                            <span>Назва українською *</span>
                            <input type="text" name="name" id="product-edit-name" maxlength="255" required>
                        </label>

                        <label class="product-form-field">
                            <span>SKU / артикул</span>
                            <input type="text" name="sku" id="product-edit-sku" maxlength="100" placeholder="Створиться автоматично">
                        </label>

                        <label class="product-form-field">
                            <span>Адреса товару</span>
                            <input type="text" name="slug" id="product-edit-slug" maxlength="180" placeholder="Створиться автоматично">
                        </label>

                        <label class="product-form-field is-wide">
                            <span>Опис</span>
                            <textarea name="description" id="product-edit-description" rows="5" maxlength="20000"></textarea>
                        </label>
                    </div>
                </section>

                <details class="product-form-section" open>
                    <summary>
                        <span>Ціни</span>
                        <small>Основна, стара та персональні</small>
                    </summary>

                    <div class="product-form-grid product-details-content">
                        <label class="product-form-field">
                            <span>Основна ціна, € *</span>
                            <input type="number" name="price" id="product-edit-price" min="0" step="0.01" inputmode="decimal" required>
                        </label>

                        <label class="product-form-field">
                            <span>Стара ціна, €</span>
                            <input type="number" name="old_price" id="product-edit-old-price" min="0" step="0.01" inputmode="decimal">
                        </label>

                        <?php foreach ($priceRanks as $rank): ?>
                            <?php if (strtolower((string) ($rank['slug'] ?? '')) === 'guest') { continue; } ?>
                            <label class="product-form-field">
                                <span><?= $escape($rank['name']) ?>, €</span>
                                <input
                                    type="number"
                                    name="rank_price[<?= (int) $rank['id'] ?>]"
                                    data-rank-price="<?= (int) $rank['id'] ?>"
                                    data-rank-slug="<?= $escape($rank['slug'] ?? '') ?>"
                                    min="0"
                                    step="0.01"
                                    inputmode="decimal"
                                    placeholder="Не задано"
                                >
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>

                <details class="product-form-section" open>
                    <summary>
                        <span>Залишки та розміри</span>
                        <small>Загальний склад або окремо за розмірами</small>
                    </summary>

                    <div class="product-details-content">
                        <div class="product-form-grid">
                            <label class="product-form-field">
                                <span>Облік залишку</span>
                                <select name="stock_mode" id="product-edit-stock-mode">
                                    <option value="total">Один загальний залишок</option>
                                    <option value="by_size">Окремо за кожним розміром</option>
                                </select>
                            </label>

                            <label class="product-form-field" data-total-stock-field>
                                <span>Загальний залишок, шт.</span>
                                <input type="number" name="stock" id="product-edit-stock" min="0" step="1" inputmode="numeric" value="0">
                            </label>
                        </div>

                        <label class="product-check-row">
                            <input type="hidden" name="show_stock_quantity" value="0">
                            <input type="checkbox" name="show_stock_quantity" id="product-edit-show-stock" value="1">
                            <span>Показувати покупцям точну кількість</span>
                        </label>

                        <div class="product-sizes-head">
                            <div>
                                <strong>Розміри товару *</strong>
                                <span data-size-stock-hint>Для загального залишку кількість задається вище.</span>
                            </div>
                            <button type="button" data-size-add>＋ Додати розмір</button>
                        </div>

                        <div id="product-size-list" class="product-size-list"></div>
                    </div>
                </details>

                <details class="product-form-section" open>
                    <summary>
                        <span>Фотографії</span>
                        <small>Перша або позначена фотографія буде головною</small>
                    </summary>

                    <div class="product-details-content">
                        <div id="product-image-list" class="product-image-list"></div>

                        <label class="product-upload-field">
                            <span>Додати фотографії</span>
                            <input
                                type="file"
                                name="product_images[]"
                                id="product-image-input"
                                accept="image/jpeg,image/png,image/webp"
                                multiple
                            >
                            <small>JPG, PNG або WebP · до 8 МБ кожна · до 8 за раз</small>
                        </label>

                        <div id="product-upload-preview" class="product-upload-preview"></div>
                    </div>
                </details>

                <details class="product-form-section">
                    <summary>
                        <span>Виробник</span>
                        <small>Необов’язкові дані</small>
                    </summary>

                    <div class="product-form-grid product-details-content">
                        <label class="product-form-field">
                            <span>Бренд</span>
                            <input type="text" name="brand" id="product-edit-brand" maxlength="150">
                        </label>

                        <label class="product-form-field">
                            <span>Країна</span>
                            <input type="text" name="country" id="product-edit-country" maxlength="150">
                        </label>
                    </div>
                </details>

                <details class="product-form-section" data-translation-details>
                    <summary>
                        <span>Переклади</span>
                        <small>ШІ-переклад і ручна перевірка</small>
                    </summary>

                    <div class="product-details-content">
                        <div class="product-source-head">
                            Українська · вихідна мова
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
                                data-product-language="<?= $escape($code) ?>"
                            >
                                <div class="product-language-head">
                                    <strong>
                                        <?= $escape($language['name']) ?>
                                        · <?= $escape($language['short_name']) ?>
                                    </strong>
                                    <button
                                        type="button"
                                        class="product-ai-translate"
                                        data-product-ai-translate
                                        data-target-language="<?= $escape($code) ?>"
                                    >
                                        Перекласти через ШІ
                                    </button>
                                </div>

                                <div class="product-translation-workflow">
                                    <input
                                        type="hidden"
                                        name="translation_source[<?= $escape($code) ?>]"
                                        class="product-translation-source"
                                        value="manual"
                                    >
                                    <span class="product-translation-origin">Ручний переклад</span>
                                    <label class="product-translation-status">
                                        <span>Стан</span>
                                        <select name="translation_status[<?= $escape($code) ?>]">
                                            <?php foreach ($translationStatusOptions as $statusCode => $statusLabel): ?>
                                                <option value="<?= $escape($statusCode) ?>">
                                                    <?= $escape($statusLabel) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                </div>

                                <label class="product-form-field">
                                    <span>Назва</span>
                                    <input
                                        type="text"
                                        name="translation_name[<?= $escape($code) ?>]"
                                        class="product-translation-name"
                                        maxlength="255"
                                        autocomplete="off"
                                    >
                                </label>

                                <label class="product-form-field">
                                    <span>Опис</span>
                                    <textarea
                                        name="translation_description[<?= $escape($code) ?>]"
                                        class="product-translation-description"
                                        rows="4"
                                    ></textarea>
                                </label>
                            </section>
                        <?php endforeach; ?>
                    </div>
                </details>
            </div>

            <footer class="product-editor-actions">
                <button type="button" class="product-editor-cancel" data-product-close>
                    Скасувати
                </button>
                <button type="submit" class="product-editor-save">
                    Зберегти товар
                </button>
            </footer>
        </form>
    </section>
</div>

<template id="product-size-template">
    <div class="product-size-row">
        <input type="hidden" name="size_id[]" value="0" data-size-id>
        <label>
            <span>Розмір</span>
            <input type="text" name="size_name[]" maxlength="80" placeholder="75B, M або Універсальний" data-size-name>
        </label>
        <label>
            <span>Залишок</span>
            <input type="number" name="size_stock[]" min="0" step="1" inputmode="numeric" value="0" data-size-stock>
        </label>
        <button type="button" data-size-remove aria-label="Видалити розмір">×</button>
    </div>
</template>

<script id="admin-products-data" type="application/json"><?= $productsJson ?: '[]' ?></script>
<div id="site-message" class="site-message" role="status"></div>
<script src="/Anabelka/js/admin-products.js?v=2"></script>
</body>
</html>
