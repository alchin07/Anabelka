<?php
$departments = is_array($departments ?? null) ? $departments : [];
$categories = is_array($categories ?? null) ? $categories : [];
$categoryForest = is_array($categoryForest ?? null) ? $categoryForest : [];
$languages = is_array($languages ?? null) ? $languages : [];
$csrfToken = (string) ($csrfToken ?? '');
$translationStatusOptions = TranslationWorkflow::statusOptions();
$escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$json = json_encode([
    'categories' => $categories,
    'departments' => $departments
], JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT);

$renderNodes = null;
$renderNodes = function (array $nodes, $level = 0) use (
    &$renderNodes,
    $escape
) {
    foreach ($nodes as $category) {
        $id = (int) ($category['id'] ?? 0);
        $children = is_array($category['children'] ?? null)
            ? $category['children']
            : [];
        $childCount = (int) ($category['child_count'] ?? count($children));
        $productCount = (int) ($category['product_count'] ?? 0);
        $translationCount = (int) ($category['translation_count'] ?? 0);
        $canDelete = $childCount === 0 && $productCount === 0;
        ?>
        <div
            class="category-admin-row"
            data-category-row="<?= $id ?>"
            data-category-level="<?= (int) $level ?>"
        >
            <div class="category-tree-controls" aria-label="Керування положенням">
                <?php if (!empty($children)): ?>
                    <button
                        type="button"
                        class="category-tree-button category-collapse-button"
                        data-category-collapse="<?= $id ?>"
                        aria-expanded="true"
                        aria-controls="category-children-<?= $id ?>"
                        title="Згорнути гілку"
                    >⌄</button>
                <?php else: ?>
                    <span class="category-tree-placeholder" aria-hidden="true"></span>
                <?php endif; ?>

                <button
                    type="button"
                    class="category-tree-button"
                    data-category-reorder="up"
                    data-category-id="<?= $id ?>"
                    aria-label="Перемістити вище"
                    title="Вище"
                >↑</button>
                <button
                    type="button"
                    class="category-tree-button"
                    data-category-reorder="down"
                    data-category-id="<?= $id ?>"
                    aria-label="Перемістити нижче"
                    title="Нижче"
                >↓</button>
            </div>

            <article class="category-admin-card">
                <div class="category-admin-card-head">
                    <div>
                        <span class="category-admin-name">
                            <?= $escape($category['name'] ?? '') ?>
                        </span>
                        <span class="category-admin-path">
                            /catalog/<?= $escape($category['department_slug'] ?? '') ?>/<?= $escape($category['slug'] ?? '') ?>
                        </span>
                    </div>

                    <div class="category-statuses">
                        <span class="category-status <?= !empty($category['is_active']) ? 'is-on' : 'is-off' ?>">
                            <?= !empty($category['is_active']) ? 'Увімкнено' : 'Вимкнено' ?>
                        </span>
                        <?php if (empty($category['effective_active']) && !empty($category['is_active'])): ?>
                            <span class="category-status is-inherited">Приховано предком</span>
                        <?php endif; ?>
                        <?php if (!empty($category['effective_adult'])): ?>
                            <span class="category-status is-adult">
                                18+<?= empty($category['is_adult']) ? ' успадковано' : '' ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($category['tree_invalid'])): ?>
                            <span class="category-status is-error">Помилка дерева</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($category['description'])): ?>
                    <span class="category-admin-description">
                        <?= $escape($category['description']) ?>
                    </span>
                <?php endif; ?>

                <div class="category-admin-counts">
                    <span>Дочірніх: <?= $childCount ?></span>
                    <span>Товарів: <?= $productCount ?></span>
                    <span>Перекладів: <?= $translationCount ?></span>
                </div>

                <div class="category-admin-actions">
                    <button
                        type="button"
                        class="category-action category-edit-button"
                        data-category-edit="<?= $id ?>"
                        data-category-id="<?= $id ?>"
                    >Редагувати</button>
                    <button
                        type="button"
                        class="category-action"
                        data-category-move="<?= $id ?>"
                    >Перемістити</button>
                    <button
                        type="button"
                        class="category-action"
                        data-category-toggle="is_active"
                        data-category-id="<?= $id ?>"
                        data-category-value="<?= !empty($category['is_active']) ? 0 : 1 ?>"
                    ><?= !empty($category['is_active']) ? 'Вимкнути' : 'Увімкнути' ?></button>
                    <button
                        type="button"
                        class="category-action"
                        data-category-toggle="is_adult"
                        data-category-id="<?= $id ?>"
                        data-category-value="<?= !empty($category['is_adult']) ? 0 : 1 ?>"
                    ><?= !empty($category['is_adult']) ? 'Зняти 18+' : 'Позначити 18+' ?></button>
                    <button
                        type="button"
                        class="category-action is-danger"
                        data-category-delete="<?= $id ?>"
                        <?= $canDelete ? '' : 'disabled' ?>
                        title="<?= $canDelete
                            ? 'Видалити порожню листову категорію'
                            : 'Видалення заблоковано: є дочірні категорії або товари' ?>"
                    >Видалити</button>
                </div>
            </article>
        </div>

        <div
            class="category-children"
            id="category-children-<?= $id ?>"
            data-category-children="<?= $id ?>"
        >
            <button
                type="button"
                class="category-add-level"
                data-category-create
                data-parent-id="<?= $id ?>"
                data-department-id="<?= (int) ($category['department_id'] ?? 0) ?>"
            >+ Додати підкатегорію до «<?= $escape($category['name'] ?? '') ?>»</button>

            <?php if (empty($children)): ?>
                <div class="category-level-empty">Підкатегорій поки немає.</div>
            <?php endif; ?>

            <?php $renderNodes($children, $level + 1); ?>
        </div>
        <?php
    }
};
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Категорії — Адмін-панель</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/admin-categories.css?v=1">
</head>
<body>

<?php
$pageTitle = 'Адмін-панель — Категорії';
require __DIR__ . '/../../partials/header.php';
?>

<main class="category-manager">
    <header class="category-manager-head">
        <div>
            <h2>Дерево категорій</h2>
            <p>
                Slug стабільний. Видимість та 18+ успадковуються від предків.
            </p>
        </div>
    </header>

    <?php if (empty($departments)): ?>
        <div class="category-manager-empty">Спочатку створіть підрозділ каталогу.</div>
    <?php endif; ?>

    <?php foreach ($departments as $department): ?>
        <?php
        $departmentId = (int) ($department['id'] ?? 0);
        $roots = $categoryForest[$departmentId] ?? [];
        ?>
        <section class="category-department" data-department-id="<?= $departmentId ?>">
            <div class="category-department-head">
                <div>
                    <h3><?= $escape($department['name'] ?? '') ?></h3>
                    <span>/catalog/<?= $escape($department['slug'] ?? '') ?>/…</span>
                </div>
                <span class="category-status <?= !empty($department['is_active']) ? 'is-on' : 'is-off' ?>">
                    <?= !empty($department['is_active']) ? 'Підрозділ активний' : 'Підрозділ вимкнено' ?>
                </span>
            </div>

            <div class="category-admin-list">
                <button
                    type="button"
                    class="category-add-level is-root"
                    data-category-create
                    data-parent-id=""
                    data-department-id="<?= $departmentId ?>"
                >+ Додати кореневу категорію</button>

                <?php if (empty($roots)): ?>
                    <div class="category-level-empty">Категорій у підрозділі поки немає.</div>
                <?php endif; ?>

                <?php $renderNodes($roots); ?>
            </div>
        </section>
    <?php endforeach; ?>
</main>

<div id="category-edit-modal" class="category-modal" hidden>
    <div class="category-modal-backdrop" data-category-close></div>
    <div class="category-modal-window" role="dialog" aria-modal="true" aria-labelledby="category-edit-title">
        <div class="category-modal-head">
            <h3 id="category-edit-title">Редагування категорії</h3>
            <button type="button" class="category-modal-close" data-category-close aria-label="Закрити">×</button>
        </div>

        <form id="category-edit-form" action="/Anabelka/admin/categories/update" method="post">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="category_id" id="category-edit-id">

            <div class="category-source-head">Українська · вихідна мова</div>

            <div class="category-form-group">
                <label for="category-edit-name">Назва *</label>
                <input type="text" name="name" id="category-edit-name" maxlength="150" required>
            </div>

            <div class="category-form-group">
                <label for="category-edit-description">Опис</label>
                <textarea name="description" id="category-edit-description" rows="4"></textarea>
            </div>

            <div class="category-check-grid">
                <label>
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" id="category-edit-active" value="1">
                    Категорія активна
                </label>
                <label>
                    <input type="hidden" name="is_adult" value="0">
                    <input type="checkbox" name="is_adult" id="category-edit-adult" value="1">
                    Власна позначка 18+
                </label>
            </div>

            <p class="category-form-note">
                Slug і положення в дереві тут не змінюються.
            </p>

            <?php foreach ($languages as $language): ?>
                <?php
                $code = strtolower(trim((string) ($language['code'] ?? '')));
                if ($code === '' || $code === Language::SOURCE_CODE) {
                    continue;
                }
                ?>
                <section class="category-translation-section" data-category-language="<?= $escape($code) ?>">
                    <div class="category-language-head">
                        <?= $escape($language['name'] ?? '') ?> · <?= $escape($language['short_name'] ?? '') ?>
                    </div>
                    <div class="category-translation-workflow">
                        <input type="hidden" name="translation_source[<?= $escape($code) ?>]" class="category-translation-source" value="manual">
                        <span class="category-translation-origin">Ручний переклад</span>
                        <label class="category-translation-status">
                            <span>Стан</span>
                            <select name="translation_status[<?= $escape($code) ?>]">
                                <?php foreach ($translationStatusOptions as $statusCode => $statusLabel): ?>
                                    <option value="<?= $escape($statusCode) ?>"><?= $escape($statusLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="category-form-group">
                        <label>Назва перекладу</label>
                        <input type="text" name="translation_name[<?= $escape($code) ?>]" class="category-translation-name" maxlength="255" autocomplete="off">
                    </div>
                    <div class="category-form-group">
                        <label>Опис перекладу</label>
                        <textarea name="translation_description[<?= $escape($code) ?>]" class="category-translation-description" rows="3"></textarea>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="category-modal-actions">
                <button type="button" class="category-cancel" data-category-close>Скасувати</button>
                <button type="submit" class="category-save">Зберегти</button>
            </div>
        </form>
    </div>
</div>

<div id="category-create-modal" class="category-modal" hidden>
    <div class="category-modal-backdrop" data-category-close></div>
    <div class="category-modal-window is-compact" role="dialog" aria-modal="true" aria-labelledby="category-create-title">
        <div class="category-modal-head">
            <h3 id="category-create-title">Нова категорія</h3>
            <button type="button" class="category-modal-close" data-category-close aria-label="Закрити">×</button>
        </div>
        <form id="category-create-form" action="/Anabelka/admin/categories/create" method="post">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="parent_id" id="category-create-parent">
            <div class="category-form-group">
                <label for="category-create-department">Підрозділ</label>
                <select name="department_id" id="category-create-department" required>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) ($department['id'] ?? 0) ?>"><?= $escape($department['name'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="category-form-note" id="category-create-context"></p>
            <div class="category-form-group">
                <label for="category-create-name">Назва *</label>
                <input type="text" name="name" id="category-create-name" maxlength="150" required>
            </div>
            <div class="category-form-group">
                <label for="category-create-description">Опис</label>
                <textarea name="description" id="category-create-description" rows="4"></textarea>
            </div>
            <div class="category-check-grid">
                <label><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Активна</label>
                <label><input type="hidden" name="is_adult" value="0"><input type="checkbox" name="is_adult" value="1"> Власна позначка 18+</label>
            </div>
            <p class="category-form-note">Slug буде створено один раз із назви та залишиться стабільним.</p>
            <div class="category-modal-actions">
                <button type="button" class="category-cancel" data-category-close>Скасувати</button>
                <button type="submit" class="category-save">Створити</button>
            </div>
        </form>
    </div>
</div>

<div id="category-move-modal" class="category-modal" hidden>
    <div class="category-modal-backdrop" data-category-close></div>
    <div class="category-modal-window is-compact" role="dialog" aria-modal="true" aria-labelledby="category-move-title">
        <div class="category-modal-head">
            <h3 id="category-move-title">Переміщення гілки</h3>
            <button type="button" class="category-modal-close" data-category-close aria-label="Закрити">×</button>
        </div>
        <form id="category-move-form" action="/Anabelka/admin/categories/move" method="post">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="category_id" id="category-move-id">
            <p class="category-form-note" id="category-move-context"></p>
            <div class="category-form-group">
                <label for="category-move-parent">Нова батьківська категорія</label>
                <select name="parent_id" id="category-move-parent"></select>
            </div>
            <div class="category-form-group">
                <label for="category-move-department">Підрозділ кореневої категорії</label>
                <select name="department_id" id="category-move-department">
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= (int) ($department['id'] ?? 0) ?>"><?= $escape($department['name'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="category-form-note">
                Для дочірньої категорії підрозділ визначається новим предком. Переміщується вся гілка; конфлікт slug скасує операцію.
            </p>
            <div class="category-modal-actions">
                <button type="button" class="category-cancel" data-category-close>Скасувати</button>
                <button type="submit" class="category-save">Перемістити</button>
            </div>
        </form>
    </div>
</div>

<div id="category-delete-modal" class="category-modal" hidden>
    <div class="category-modal-backdrop" data-category-close></div>
    <div class="category-modal-window is-compact" role="dialog" aria-modal="true" aria-labelledby="category-delete-title">
        <div class="category-modal-head">
            <h3 id="category-delete-title">Видалити категорію?</h3>
            <button type="button" class="category-modal-close" data-category-close aria-label="Закрити">×</button>
        </div>
        <form id="category-delete-form" action="/Anabelka/admin/categories/delete" method="post">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <input type="hidden" name="category_id" id="category-delete-id">
            <p id="category-delete-context"></p>
            <p class="category-form-note">Дозволено лише для категорії без дітей і товарів. Її переклади буде видалено разом із нею.</p>
            <div class="category-modal-actions">
                <button type="button" class="category-cancel" data-category-close>Скасувати</button>
                <button type="submit" class="category-delete-confirm">Видалити</button>
            </div>
        </form>
    </div>
</div>

<div id="site-message" class="site-message" role="status" aria-live="polite"></div>
<script id="category-manager-data" type="application/json"><?= $json ?: '{"categories":[],"departments":[]}' ?></script>
<script src="/Anabelka/js/admin-categories.js?v=1"></script>
</body>
</html>
