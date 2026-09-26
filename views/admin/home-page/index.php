<?php
$pageTitle = $pageTitle ?? 'Адмін-панель · Головна сторінка';
$blocksByZone = is_array($blocksByZone ?? null) ? $blocksByZone : [];
$zoneLabels = is_array($zoneLabels ?? null) ? $zoneLabels : [];
$message = trim((string) ($message ?? ''));
$error = trim((string) ($error ?? ''));
$csrfToken = (string) ($csrfToken ?? '');

$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$productSourceLabels = [
    'latest' => 'Останні додані',
    'new' => 'Нові без акцій',
    'discounts' => 'Зі знижками'
];

$catalog = is_array($catalog ?? null) ? $catalog : [];
$creatableBlocks = array_filter(
    $catalog,
    static function ($meta) {
        return is_array($meta) && !empty($meta['creatable']);
    }
);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle) ?></title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/admin-home-page.css?v=4">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-home-builder" data-home-builder-root data-csrf="<?= $escape($csrfToken) ?>">
    <section class="admin-home-builder-intro">
        <div>
            <h2>Конструктор головної сторінки</h2>
            <p>
                Перетягуйте блоки за ручку ⠿, змінюйте видимість і налаштування
                без редагування PHP-шаблону. Стрілки ↑/↓ залишаються запасним способом.
            </p>
        </div>
        <a href="/Anabelka/" target="_blank" rel="noopener">Відкрити головну</a>
    </section>

    <section class="admin-home-builder-preview" data-home-builder-preview>
        <div class="admin-home-builder-preview-head">
            <div>
                <h3>Живий перегляд</h3>
                <p>
                    Ліворуч — мобільна головна, праворуч — desktop.
                    Блоки можна перетягувати і в списку, і прямо в preview за ручку ⠿. Порядок синхронізується в обох вікнах ще до збереження.
                </p>
            </div>

            <div class="admin-home-builder-preview-tabs" role="tablist" aria-label="Режим перегляду">
                <button
                    type="button"
                    class="is-active"
                    data-home-builder-preview-tab="mobile"
                    role="tab"
                    aria-selected="true"
                >📱 Mobile</button>
                <button
                    type="button"
                    data-home-builder-preview-tab="desktop"
                    role="tab"
                    aria-selected="false"
                >🖥 Desktop</button>
            </div>
        </div>

        <div class="admin-home-builder-preview-grid">
            <article
                class="admin-home-builder-preview-card is-mobile is-active is-loading"
                data-home-builder-preview-card="mobile"
            >
                <div class="admin-home-builder-preview-label">
                    <strong>Mobile</strong>
                    <span>390 × 844</span>
                </div>
                <div class="admin-home-builder-preview-stage" data-home-builder-preview-stage>
                    <div
                        class="admin-home-builder-preview-surface"
                        data-home-builder-preview-surface
                        data-preview-width="390"
                        data-preview-height="844"
                    >
                        <iframe
                            src="/Anabelka/?builder_preview=home"
                            title="Мобільний перегляд головної сторінки"
                            data-home-builder-preview-frame="mobile"
                            loading="eager"
                        ></iframe>
                    </div>
                </div>
            </article>

            <article
                class="admin-home-builder-preview-card is-desktop is-loading"
                data-home-builder-preview-card="desktop"
            >
                <div class="admin-home-builder-preview-label">
                    <strong>Desktop</strong>
                    <span>1440 × 900</span>
                </div>
                <div class="admin-home-builder-preview-stage" data-home-builder-preview-stage>
                    <div
                        class="admin-home-builder-preview-surface"
                        data-home-builder-preview-surface
                        data-preview-width="1440"
                        data-preview-height="900"
                    >
                        <iframe
                            src="/Anabelka/?builder_preview=home"
                            title="Desktop перегляд головної сторінки"
                            data-home-builder-preview-frame="desktop"
                            loading="eager"
                        ></iframe>
                    </div>
                </div>
            </article>
        </div>
    </section>

    <section class="admin-home-builder-add">
        <div>
            <h3>Додати блок</h3>
            <p>
                Додаткові блоки створюються одразу у сумісній зоні.
                Базові блоки залишаються захищеними від видалення.
            </p>
        </div>

        <div class="admin-home-builder-add-grid">
            <?php foreach (['main', 'right_rail'] as $addZone): ?>
                <?php
                $zoneCreatable = array_filter(
                    $creatableBlocks,
                    static function ($meta) use ($addZone) {
                        return is_array($meta)
                            && in_array(
                                $addZone,
                                $meta['zones'] ?? [],
                                true
                            );
                    }
                );
                ?>

                <?php if (!empty($zoneCreatable)): ?>
                    <form
                        method="post"
                        action="/Anabelka/admin/home-page/create"
                    >
                        <input
                            type="hidden"
                            name="_csrf"
                            value="<?= $escape($csrfToken) ?>"
                        >
                        <input
                            type="hidden"
                            name="zone"
                            value="<?= $escape($addZone) ?>"
                        >

                        <label>
                            <span>
                                <?= $escape(
                                    $zoneLabels[$addZone]
                                    ?? $addZone
                                ) ?>
                            </span>
                            <select name="block_type" required>
                                <?php foreach ($zoneCreatable as $blockType => $meta): ?>
                                    <option value="<?= $escape($blockType) ?>">
                                        <?= $escape(
                                            $meta['label']
                                            ?? $blockType
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <button type="submit">
                            + Додати блок
                        </button>
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <?php if ($message !== ''): ?>
        <div class="admin-home-builder-flash is-success" role="status">
            <?= $escape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="admin-home-builder-flash is-error" role="alert">
            <?= $escape($error) ?>
        </div>
    <?php endif; ?>

    <div
        id="admin-home-builder-dnd-message"
        class="admin-home-builder-flash"
        role="status"
        aria-live="polite"
        hidden
    ></div>

    <?php foreach (['main', 'right_rail'] as $zone): ?>
        <?php
        $zoneBlocks = is_array($blocksByZone[$zone] ?? null)
            ? $blocksByZone[$zone]
            : [];
        ?>
        <section class="admin-home-builder-zone" data-home-builder-zone="<?= $escape($zone) ?>">
            <div class="admin-home-builder-zone-head">
                <div>
                    <h3><?= $escape($zoneLabels[$zone] ?? $zone) ?></h3>
                    <p>
                        <?= $zone === 'main'
                            ? 'Публічні блоки основної колонки у керованому порядку.'
                            : 'Бічна колонка показується на широких екранах.' ?>
                    </p>
                </div>
                <span><?= count($zoneBlocks) ?> блоки</span>
            </div>

            <div class="admin-home-builder-list" data-home-builder-list>
                <?php foreach ($zoneBlocks as $index => $block): ?>
                    <?php
                    $blockId = (int) ($block['id'] ?? 0);
                    $type = (string) ($block['block_type'] ?? '');
                    $meta = is_array($block['type_meta'] ?? null)
                        ? $block['type_meta']
                        : [];
                    $settings = is_array($block['settings'] ?? null)
                        ? $block['settings']
                        : [];
                    $isActive = !empty($block['is_active']);
                    $isSystem = !empty($block['is_system']);
                    ?>
                    <article class="admin-home-builder-block is-collapsed<?= $isActive ? '' : ' is-disabled' ?>" data-block-id="<?= $blockId ?>">
                        <div class="admin-home-builder-order">
                            <button
                                type="button"
                                class="admin-home-builder-drag"
                                data-home-builder-drag-handle
                                aria-label="Перетягнути блок"
                                title="Утримуйте та перетягуйте блок"
                            >
                                <span aria-hidden="true">⠿</span>
                            </button>

                            <?php foreach (['up' => '↑', 'down' => '↓'] as $direction => $symbol): ?>
                                <form method="post" action="/Anabelka/admin/home-page/move">
                                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="block_id" value="<?= $blockId ?>">
                                    <input type="hidden" name="direction" value="<?= $escape($direction) ?>">
                                    <button
                                        type="submit"
                                        data-home-builder-move="<?= $escape($direction) ?>"
                                        aria-label="<?= $direction === 'up' ? 'Перемістити блок вгору' : 'Перемістити блок вниз' ?>"
                                        <?= ($direction === 'up' && $index === 0)
                                            || ($direction === 'down' && $index >= count($zoneBlocks) - 1)
                                            ? 'disabled'
                                            : '' ?>
                                    ><?= $symbol ?></button>
                                </form>
                            <?php endforeach; ?>
                        </div>

                        <div class="admin-home-builder-content">
                            <div class="admin-home-builder-summary">
                                <div class="admin-home-builder-summary-copy">
                                    <div class="admin-home-builder-title-row">
                                        <h4><?= $escape($meta['label'] ?? $type) ?></h4>
                                        <span class="admin-home-builder-kind">
                                            <?= $isSystem ? 'Базовий' : 'Доданий' ?>
                                        </span>
                                    </div>
                                    <span class="admin-home-builder-summary-status<?= $isActive ? ' is-active' : '' ?>">
                                        <?= $isActive ? 'Увімкнено' : 'Вимкнено' ?>
                                    </span>
                                </div>

                                <button
                                    type="button"
                                    class="admin-home-builder-editor-toggle"
                                    data-home-builder-editor-toggle
                                    aria-expanded="false"
                                >
                                    Редагувати
                                </button>
                            </div>

                            <div class="admin-home-builder-block-head">
                                <div>
                                    <div class="admin-home-builder-title-row">
                                        <h4><?= $escape($meta['label'] ?? $type) ?></h4>
                                        <span class="admin-home-builder-kind">
                                            <?= $isSystem ? 'Базовий' : 'Доданий' ?>
                                        </span>
                                    </div>
                                    <p><?= $escape($meta['description'] ?? '') ?></p>
                                </div>

                                <div class="admin-home-builder-block-actions">
                                    <form method="post" action="/Anabelka/admin/home-page/toggle">
                                        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                                        <input type="hidden" name="block_id" value="<?= $blockId ?>">
                                        <button
                                            type="submit"
                                            class="admin-home-builder-status<?= $isActive ? ' is-active' : '' ?>"
                                        >
                                            <?= $isActive ? 'Увімкнено' : 'Вимкнено' ?>
                                        </button>
                                    </form>

                                    <?php if (!$isSystem): ?>
                                        <form
                                            method="post"
                                            action="/Anabelka/admin/home-page/delete"
                                            onsubmit="return confirm('Видалити цей блок з головної сторінки?');"
                                        >
                                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                                            <input type="hidden" name="block_id" value="<?= $blockId ?>">
                                            <button
                                                type="submit"
                                                class="admin-home-builder-delete"
                                            >
                                                Видалити
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (in_array($type, ['product_collection', 'news', 'reviews'], true)): ?>
                                <form
                                    class="admin-home-builder-settings"
                                    method="post"
                                    action="/Anabelka/admin/home-page/update"
                                >
                                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                                    <input type="hidden" name="block_id" value="<?= $blockId ?>">

                                    <?php if ($type === 'product_collection'): ?>
                                        <label>
                                            <span>Джерело товарів</span>
                                            <select name="settings[source]">
                                                <?php foreach ($productSourceLabels as $value => $label): ?>
                                                    <option
                                                        value="<?= $escape($value) ?>"
                                                        <?= ($settings['source'] ?? 'latest') === $value ? 'selected' : '' ?>
                                                    >
                                                        <?= $escape($label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    <?php endif; ?>

                                    <?php if (in_array($type, ['product_collection', 'news', 'reviews'], true)): ?>
                                        <label>
                                            <span>Кількість елементів</span>
                                            <input
                                                type="number"
                                                name="settings[limit]"
                                                min="1"
                                                max="<?= $type === 'product_collection' ? 24 : 10 ?>"
                                                value="<?= (int) ($settings['limit'] ?? 1) ?>"
                                                inputmode="numeric"
                                            >
                                        </label>
                                    <?php endif; ?>

                                    <button type="submit">Зберегти налаштування</button>
                                </form>
                            <?php else: ?>
                                <div class="admin-home-builder-static-note">
                                    У цього блоку поки немає додаткових параметрів.
                                </div>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <section class="admin-home-builder-next">
        <strong>MVP конструктора</strong>
        <p>
            Тип, зона, порядок і JSON-налаштування зберігаються окремо.
            Додаткові екземпляри контентних блоків можна створювати й видаляти
            без редагування PHP-шаблону.
        </p>
    </section>
</main>

<script src="/Anabelka/js/admin-home-page.js?v=5"></script>
</body>
</html>
