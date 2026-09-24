<?php
$pageTitle = $pageTitle
    ?? 'Адмін-панель · Конструктор головної адмін-панелі';
$blocks = is_array($blocks ?? null) ? $blocks : [];
$services = is_array($services ?? null) ? $services : [];
$serviceGroups = is_array($serviceGroups ?? null)
    ? $serviceGroups
    : [];
$usedServiceKeys = array_fill_keys(
    is_array($usedServiceKeys ?? null)
        ? array_map('strval', $usedServiceKeys)
        : [],
    true
);
$csrfToken = (string) ($csrfToken ?? '');
$flash = is_array($flash ?? null) ? $flash : null;

$escape = static function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
};

$formatBadgeCount = static function ($count) {
    $count = max(0, (int) $count);

    return $count > 99 ? '99+' : (string) $count;
};

$badgeLabel = static function ($service) {
    $badge = is_array($service['badge'] ?? null)
        ? $service['badge']
        : [];
    $source = (string) ($badge['source'] ?? '');

    if ($source === 'system_errors') {
        return 'Системний бейдж';
    }

    if ($source === 'admin_notifications') {
        return 'Бейдж сповіщень';
    }

    return '';
};

$availableForAdd = array_filter(
    $services,
    static function ($service, $key) use ($usedServiceKeys) {
        return !isset($usedServiceKeys[(string) $key])
            || !empty($service['allow_multiple']);
    },
    ARRAY_FILTER_USE_BOTH
);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title><?= $escape($pageTitle) ?></title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link
        rel="stylesheet"
        href="/Anabelka/css/admin-dashboard-builder.css?v=4"
    >
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main
    class="admin-dashboard-builder"
    data-dashboard-builder-root
    data-csrf="<?= $escape($csrfToken) ?>"
>
    <section class="dashboard-builder-intro">
        <div>
            <h2>Конструктор головної адмін-панелі</h2>
            <p>
                Блоки містять ярлики на зареєстровані служби Анабельки.
                Перетягуйте блоки й ярлики за ручку ⠿. URL не зберігаються —
                кожне посилання працює через <code>service_key</code>.
            </p>
        </div>

        <a href="/Anabelka/admin">Відкрити головну адмінки</a>
    </section>

    <?php if ($flash): ?>
        <div
            class="dashboard-builder-flash <?= ($flash['type'] ?? '') === 'error'
                ? 'is-error'
                : 'is-success' ?>"
            role="<?= ($flash['type'] ?? '') === 'error' ? 'alert' : 'status' ?>"
        >
            <?= $escape($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <div
        id="dashboard-builder-dnd-message"
        class="dashboard-builder-flash"
        role="status"
        aria-live="polite"
        hidden
    ></div>

    <section class="dashboard-builder-create">
        <div>
            <h3>Новий блок</h3>
            <p>
                Наприклад: Продажі, Каталог, Контент або Система.
            </p>
        </div>

        <form
            method="post"
            action="/Anabelka/admin/dashboard-builder/blocks/create"
        >
            <input
                type="hidden"
                name="_csrf"
                value="<?= $escape($csrfToken) ?>"
            >
            <label>
                <span>Назва блоку</span>
                <input
                    type="text"
                    name="title"
                    maxlength="120"
                    required
                    placeholder="Наприклад, Продажі"
                >
            </label>
            <button type="submit">+ Створити блок</button>
        </form>
    </section>

    <?php if (empty($blocks)): ?>
        <section class="dashboard-builder-empty">
            <strong>Блоків поки немає.</strong>
            <p>
                Створіть перший блок, а потім додайте до нього
                готові служби Анабельки.
            </p>
        </section>
    <?php endif; ?>

    <div
        class="dashboard-builder-blocks"
        data-dashboard-builder-block-list
    >
        <?php foreach ($blocks as $block): ?>
            <?php
            $blockId = (int) ($block['id'] ?? 0);
            $blockActive = !empty($block['is_active']);
            $links = is_array($block['links'] ?? null)
                ? $block['links']
                : [];
            ?>
            <section
                class="dashboard-builder-block<?= $blockActive
                    ? ''
                    : ' is-disabled' ?>"
                data-dashboard-builder-block-id="<?= $blockId ?>"
            >
                <button
                    type="button"
                    class="dashboard-builder-drag dashboard-builder-block-drag"
                    data-dashboard-builder-block-handle
                    aria-label="Перетягнути блок"
                    title="Утримуйте та перетягуйте блок"
                >
                    <span aria-hidden="true">⠿</span>
                </button>

                <div class="dashboard-builder-block-head">
                    <form
                        class="dashboard-builder-title-form"
                        method="post"
                        action="/Anabelka/admin/dashboard-builder/blocks/update"
                    >
                        <input
                            type="hidden"
                            name="_csrf"
                            value="<?= $escape($csrfToken) ?>"
                        >
                        <input
                            type="hidden"
                            name="block_id"
                            value="<?= $blockId ?>"
                        >
                        <label>
                            <span>Назва блоку</span>
                            <input
                                type="text"
                                name="title"
                                maxlength="120"
                                required
                                value="<?= $escape($block['title'] ?? '') ?>"
                            >
                        </label>
                        <button type="submit">Зберегти</button>
                    </form>

                    <div class="dashboard-builder-block-actions">
                        <form
                            method="post"
                            action="/Anabelka/admin/dashboard-builder/blocks/toggle"
                        >
                            <input
                                type="hidden"
                                name="_csrf"
                                value="<?= $escape($csrfToken) ?>"
                            >
                            <input
                                type="hidden"
                                name="block_id"
                                value="<?= $blockId ?>"
                            >
                            <button
                                type="submit"
                                class="dashboard-builder-toggle<?= $blockActive
                                    ? ' is-active'
                                    : '' ?>"
                            >
                                <?= $blockActive ? 'Увімкнено' : 'Вимкнено' ?>
                            </button>
                        </form>

                        <form
                            method="post"
                            action="/Anabelka/admin/dashboard-builder/blocks/delete"
                            onsubmit="return confirm('Видалити блок і всі його ярлики? Самі служби та їх дані не видаляються.');"
                        >
                            <input
                                type="hidden"
                                name="_csrf"
                                value="<?= $escape($csrfToken) ?>"
                            >
                            <input
                                type="hidden"
                                name="block_id"
                                value="<?= $blockId ?>"
                            >
                            <button
                                type="submit"
                                class="dashboard-builder-delete"
                            >
                                Видалити блок
                            </button>
                        </form>
                    </div>
                </div>

                <div
                    class="dashboard-builder-links"
                    data-dashboard-builder-link-list
                    data-dashboard-builder-block-id="<?= $blockId ?>"
                >
                    <div
                        class="dashboard-builder-links-empty"
                        data-dashboard-builder-links-empty
                        <?= empty($links) ? '' : 'hidden' ?>
                    >
                        У цьому блоці ще немає служб.
                    </div>

                    <?php foreach ($links as $link): ?>
                        <?php
                        $linkId = (int) ($link['id'] ?? 0);
                        $linkActive = !empty($link['is_active']);
                        $currentKey = (string) (
                            $link['service_key'] ?? ''
                        );
                        $service = is_array($services[$currentKey] ?? null)
                            ? $services[$currentKey]
                            : (
                                is_array($link['service'] ?? null)
                                    ? $link['service']
                                    : []
                            );
                        $badgeCount = max(
                            0,
                            (int) ($service['badge_count'] ?? 0)
                        );
                        $badgeTone = (string) (
                            $service['badge']['tone']
                            ?? 'notification'
                        );
                        ?>
                        <article
                            class="dashboard-builder-link<?= $linkActive
                                ? ''
                                : ' is-disabled' ?>"
                            data-dashboard-builder-link-id="<?= $linkId ?>"
                        >
                            <button
                                type="button"
                                class="dashboard-builder-drag dashboard-builder-link-drag"
                                data-dashboard-builder-link-handle
                                aria-label="Перетягнути ярлик"
                                title="Утримуйте та перетягуйте ярлик"
                            >
                                <span aria-hidden="true">⠿</span>
                            </button>

                            <div class="dashboard-builder-link-meta">
                                <div>
                                    <strong>
                                        <?= $escape(
                                            $link['display_label']
                                            ?? $currentKey
                                        ) ?>
                                    </strong>
                                    <span>
                                        <?= $escape(
                                            $service['group_label']
                                            ?? 'Служба'
                                        ) ?>
                                        ·
                                        <code><?= $escape($currentKey) ?></code>
                                    </span>
                                </div>

                                <div class="dashboard-builder-badge-area">
                                    <?php if ($badgeCount > 0): ?>
                                        <span
                                            class="dashboard-builder-live-badge<?= $badgeTone === 'error'
                                                ? ' is-error'
                                                : '' ?>"
                                            aria-label="<?= $escape(
                                                'Нових сповіщень: ' . $badgeCount
                                            ) ?>"
                                            title="<?= $escape(
                                                'Нових сповіщень: ' . $badgeCount
                                            ) ?>"
                                        >
                                            <?= $escape(
                                                $formatBadgeCount($badgeCount)
                                            ) ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($badgeLabel($service) !== ''): ?>
                                        <span class="dashboard-builder-badge-source">
                                            <?= $escape($badgeLabel($service)) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <form
                                class="dashboard-builder-link-edit"
                                method="post"
                                action="/Anabelka/admin/dashboard-builder/links/update"
                            >
                                <input
                                    type="hidden"
                                    name="_csrf"
                                    value="<?= $escape($csrfToken) ?>"
                                >
                                <input
                                    type="hidden"
                                    name="link_id"
                                    value="<?= $linkId ?>"
                                >

                                <label>
                                    <span>Служба</span>
                                    <select name="service_key" required>
                                        <?php foreach ($serviceGroups as $groupKey => $groupLabel): ?>
                                            <?php
                                            $groupServices = array_filter(
                                                $services,
                                                static function ($candidate) use (
                                                    $groupKey,
                                                    $currentKey,
                                                    $usedServiceKeys
                                                ) {
                                                    $candidateKey = (string) (
                                                        $candidate['key'] ?? ''
                                                    );

                                                    if (
                                                        (string) (
                                                            $candidate['group']
                                                            ?? ''
                                                        )
                                                        !== (string) $groupKey
                                                    ) {
                                                        return false;
                                                    }

                                                    return $candidateKey
                                                        === $currentKey
                                                        || !isset(
                                                            $usedServiceKeys[
                                                                $candidateKey
                                                            ]
                                                        )
                                                        || !empty(
                                                            $candidate[
                                                                'allow_multiple'
                                                            ]
                                                        );
                                                }
                                            );
                                            ?>

                                            <?php if (!empty($groupServices)): ?>
                                                <optgroup
                                                    label="<?= $escape($groupLabel) ?>"
                                                >
                                                    <?php foreach ($groupServices as $candidate): ?>
                                                        <?php
                                                        $candidateKey = (string) (
                                                            $candidate['key']
                                                            ?? ''
                                                        );
                                                        ?>
                                                        <option
                                                            value="<?= $escape($candidateKey) ?>"
                                                            <?= $candidateKey === $currentKey
                                                                ? 'selected'
                                                                : '' ?>
                                                        >
                                                            <?= $escape(
                                                                $candidate[
                                                                    'label'
                                                                ]
                                                                ?? $candidateKey
                                                            ) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <label>
                                    <span>Своя назва, необов’язково</span>
                                    <input
                                        type="text"
                                        name="label_override"
                                        maxlength="120"
                                        placeholder="<?= $escape(
                                            $service['label']
                                            ?? 'Назва служби'
                                        ) ?>"
                                        value="<?= $escape(
                                            $link['label_override'] ?? ''
                                        ) ?>"
                                    >
                                </label>

                                <button type="submit">
                                    Зберегти посилання
                                </button>
                            </form>

                            <div class="dashboard-builder-link-actions">
                                <form
                                    method="post"
                                    action="/Anabelka/admin/dashboard-builder/links/toggle"
                                >
                                    <input
                                        type="hidden"
                                        name="_csrf"
                                        value="<?= $escape($csrfToken) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="link_id"
                                        value="<?= $linkId ?>"
                                    >
                                    <button
                                        type="submit"
                                        class="dashboard-builder-toggle<?= $linkActive
                                            ? ' is-active'
                                            : '' ?>"
                                    >
                                        <?= $linkActive
                                            ? 'Увімкнено'
                                            : 'Вимкнено' ?>
                                    </button>
                                </form>

                                <form
                                    method="post"
                                    action="/Anabelka/admin/dashboard-builder/links/delete"
                                    onsubmit="return confirm('Прибрати цей ярлик з головної адмінки? Служба та її дані залишаться без змін.');"
                                >
                                    <input
                                        type="hidden"
                                        name="_csrf"
                                        value="<?= $escape($csrfToken) ?>"
                                    >
                                    <input
                                        type="hidden"
                                        name="link_id"
                                        value="<?= $linkId ?>"
                                    >
                                    <button
                                        type="submit"
                                        class="dashboard-builder-delete"
                                    >
                                        Прибрати ярлик
                                    </button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="dashboard-builder-add-service">
                    <div>
                        <strong>Додати службу</strong>
                        <span>
                            Один service_key за замовчуванням можна
                            використати лише один раз на всій головній.
                        </span>
                    </div>

                    <?php if (empty($availableForAdd)): ?>
                        <p class="dashboard-builder-all-used">
                            Усі доступні служби вже додано.
                        </p>
                    <?php else: ?>
                        <form
                            method="post"
                            action="/Anabelka/admin/dashboard-builder/links/create"
                        >
                            <input
                                type="hidden"
                                name="_csrf"
                                value="<?= $escape($csrfToken) ?>"
                            >
                            <input
                                type="hidden"
                                name="block_id"
                                value="<?= $blockId ?>"
                            >

                            <label>
                                <span>Служба</span>
                                <select name="service_key" required>
                                    <?php foreach ($serviceGroups as $groupKey => $groupLabel): ?>
                                        <?php
                                        $groupServices = array_filter(
                                            $availableForAdd,
                                            static function ($candidate) use (
                                                $groupKey
                                            ) {
                                                return (
                                                    $candidate['group'] ?? ''
                                                ) === $groupKey;
                                            }
                                        );
                                        ?>

                                        <?php if (!empty($groupServices)): ?>
                                            <optgroup
                                                label="<?= $escape($groupLabel) ?>"
                                            >
                                                <?php foreach ($groupServices as $candidate): ?>
                                                    <option
                                                        value="<?= $escape(
                                                            $candidate['key']
                                                            ?? ''
                                                        ) ?>"
                                                    >
                                                        <?= $escape(
                                                            $candidate['label']
                                                            ?? $candidate['key']
                                                            ?? ''
                                                        ) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                <span>Своя назва, необов’язково</span>
                                <input
                                    type="text"
                                    name="label_override"
                                    maxlength="120"
                                    placeholder="Наприклад, Нові замовлення"
                                >
                            </label>

                            <button type="submit">
                                + Додати службу
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <section class="dashboard-builder-note">
        <strong>Наступний етап</strong>
        <p>
            Живі бейджі та блоки вже працюють на головній адмін-панелі.
            Порядок блоків і ярликів, включно з переносом ярлика між
            блоками, зберігається через drag-and-drop.
        </p>
    </section>
</main>

<script src="/Anabelka/js/admin-dashboard-builder.js?v=1"></script>
</body>
</html>
