<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Головна — Адмін-панель Анабельки</title>

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
        href="/Anabelka/css/admin-dashboard.css?v=2"
    >
</head>
<body>

<?php
$pageTitle = 'Адмін-панель — Головна';
require __DIR__ . '/../partials/header.php';

$counts = is_array($counts ?? null) ? $counts : [];
$recentOrders = is_array($recentOrders ?? null)
    ? $recentOrders
    : [];
$translationSummary = is_array($translationSummary ?? null)
    ? $translationSummary
    : [];
$aiSummary = is_array($aiSummary ?? null)
    ? $aiSummary
    : [];

$formatNumber = function ($value) {
    return number_format((int) $value, 0, ',', ' ');
};

$formatMoney = function ($value, $currency) {
    $symbols = [
        'EUR' => '€',
        'UAH' => '₴',
        'USD' => '$'
    ];

    $currency = strtoupper(trim((string) $currency));

    return number_format((float) $value, 2, ',', ' ')
        . ' '
        . ($symbols[$currency] ?? $currency);
};

$formatDate = function ($value) {
    $timestamp = strtotime((string) $value);

    return $timestamp
        ? date('d.m.Y H:i', $timestamp)
        : '—';
};

$statusLabels = [
    'new' => 'Новий',
    'processing' => 'В обробці',
    'completed' => 'Завершений',
    'cancelled' => 'Скасований'
];

$regularNew = (int) ($counts['regular_new'] ?? 0);
$quickNew = (int) ($counts['quick_new'] ?? 0);
$translationAttention = (int) (
    $translationSummary['attention'] ?? 0
);
$translationPercent = (int) (
    $translationSummary['percent'] ?? 100
);
$translationAvailable = !empty(
    $translationSummary['available']
);
$aiAvailable = !empty($aiSummary['available']);
$aiState = (string) ($aiSummary['state'] ?? 'not_configured');
$aiStateClass = !$aiAvailable
    ? 'is-error'
    : (
        $aiState === 'ready'
            ? 'is-good'
            : ($aiState === 'error' ? 'is-error' : 'is-warning')
    );
?>

<main class="admin-dashboard">
    <section class="dashboard-welcome">
        <div>
            <h2>Панель керування магазином</h2>
            <p>
                Найважливіше про замовлення, каталог і переклади
                на одному екрані.
            </p>
        </div>

        <time class="dashboard-date" datetime="<?= date('Y-m-d') ?>">
            <?= date('d.m.Y') ?>
        </time>
    </section>

    <?php if (!empty($dashboardError)): ?>
        <div class="dashboard-error" role="alert">
            Дані тимчасово недоступні:
            <?= htmlspecialchars((string) $dashboardError) ?>
        </div>
    <?php endif; ?>

    <section
        class="dashboard-alert-grid"
        aria-label="Нові замовлення"
    >
        <a
            class="dashboard-order-alert"
            href="/Anabelka/admin/orders?type=regular&amp;status=new"
        >
            <span class="dashboard-alert-dot" aria-hidden="true">З</span>

            <span class="dashboard-alert-copy">
                <strong class="dashboard-alert-title-full">
                    Нові замовлення
                </strong>
                <strong class="dashboard-alert-title-short">
                    Звичайні
                </strong>
                <span>
                    Звичайне оформлення з доставкою
                </span>
            </span>

            <span class="dashboard-alert-count">
                <?= $formatNumber($regularNew) ?>
            </span>
        </a>

        <a
            class="dashboard-order-alert is-quick"
            href="/Anabelka/admin/orders?type=quick&amp;status=new"
        >
            <span class="dashboard-alert-dot" aria-hidden="true">Ш</span>

            <span class="dashboard-alert-copy">
                <strong class="dashboard-alert-title-full">
                    Швидкі замовлення
                </strong>
                <strong class="dashboard-alert-title-short">
                    Швидкі
                </strong>
                <span>
                    Ім’я, телефон і товари з кошика
                </span>
            </span>

            <span class="dashboard-alert-count">
                <?= $formatNumber($quickNew) ?>
            </span>
        </a>
    </section>

    <section class="dashboard-section">
        <div class="dashboard-section-head">
            <div>
                <h2>Магазин сьогодні</h2>
                <p>Актуальні дані з бази Анабельки</p>
            </div>
        </div>

        <div class="dashboard-metrics">
            <a
                class="dashboard-metric-card"
                href="/Anabelka/admin/products?status=active"
            >
                <span class="dashboard-metric-label">Активні товари</span>
                <span class="dashboard-metric-value">
                    <?= $formatNumber($counts['products_active'] ?? 0) ?>
                </span>
                <span class="dashboard-metric-meta">
                    Усього: <?= $formatNumber($counts['products_total'] ?? 0) ?>
                    · без залишку:
                    <?= $formatNumber($counts['products_out_of_stock'] ?? 0) ?>
                </span>
            </a>

            <a
                class="dashboard-metric-card"
                href="/Anabelka/admin/categories"
            >
                <span class="dashboard-metric-label">Категорії</span>
                <span class="dashboard-metric-value">
                    <?= $formatNumber($counts['categories_total'] ?? 0) ?>
                </span>
                <span class="dashboard-metric-meta">
                    Структура каталогу
                </span>
            </a>

            <a
                class="dashboard-metric-card"
                href="/Anabelka/admin/languages"
            >
                <span class="dashboard-metric-label">Активні мови</span>
                <span class="dashboard-metric-value">
                    <?= $formatNumber($counts['languages_active'] ?? 0) ?>
                </span>
                <span class="dashboard-metric-meta">
                    Додано: <?= $formatNumber($counts['languages_total'] ?? 0) ?>
                </span>
            </a>

            <a
                class="dashboard-metric-card"
                href="/Anabelka/admin/delivery"
            >
                <span class="dashboard-metric-label">Способи доставки</span>
                <span class="dashboard-metric-value">
                    <?= $formatNumber($counts['delivery_active'] ?? 0) ?>
                </span>
                <span class="dashboard-metric-meta">
                    Усього: <?= $formatNumber($counts['delivery_total'] ?? 0) ?>
                </span>
            </a>
        </div>
    </section>

    <div class="dashboard-content-grid">
        <section
            id="latest-orders"
            class="dashboard-panel"
        >
            <div class="dashboard-section-head">
                <div>
                    <h2>Останні замовлення</h2>
                    <p>Звичайні та швидкі — за часом надходження</p>
                </div>

                <a
                    class="dashboard-section-link"
                    href="/Anabelka/admin/orders"
                >
                    Усі замовлення
                </a>
            </div>

            <?php if (empty($recentOrders)): ?>
                <div class="dashboard-empty">
                    Замовлень поки немає.
                </div>
            <?php else: ?>
                <div class="dashboard-orders-list">
                    <?php foreach ($recentOrders as $order): ?>
                        <?php
                        $isQuick = ($order['order_type'] ?? '') === 'quick';
                        $orderStatus = strtolower(
                            trim((string) ($order['status'] ?? 'new'))
                        );
                        ?>

                        <article class="dashboard-order-row">
                            <div class="dashboard-order-main">
                                <div class="dashboard-order-heading">
                                    <span
                                        class="dashboard-order-type<?= $isQuick ? ' is-quick' : '' ?>"
                                    >
                                        <?= $isQuick ? 'Швидке' : 'Звичайне' ?>
                                    </span>

                                    <strong>
                                        #<?= (int) ($order['id'] ?? 0) ?>
                                        ·
                                        <?= htmlspecialchars(
                                            (string) ($order['customer_name'] ?? '')
                                        ) ?>
                                    </strong>
                                </div>

                                <div class="dashboard-order-contact">
                                    <?php if (!empty($order['customer_phone'])): ?>
                                        <a
                                            href="tel:<?= htmlspecialchars(
                                                (string) $order['customer_phone']
                                            ) ?>"
                                        >
                                            <?= htmlspecialchars(
                                                (string) $order['customer_phone']
                                            ) ?>
                                        </a>
                                    <?php endif; ?>

                                    <span>
                                        <?= htmlspecialchars(
                                            $statusLabels[$orderStatus]
                                            ?? $orderStatus
                                        ) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="dashboard-order-side">
                                <span class="dashboard-order-total">
                                    <?= $formatMoney(
                                        $order['total'] ?? 0,
                                        $order['currency'] ?? 'EUR'
                                    ) ?>
                                </span>

                                <time class="dashboard-order-date">
                                    <?= $formatDate($order['created_at'] ?? '') ?>
                                </time>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <div class="dashboard-side-column">
            <section class="dashboard-panel">
                <div class="dashboard-section-head">
                    <div>
                        <h2>Потребують уваги</h2>
                        <p>Стан важливих служб</p>
                    </div>
                </div>

                <div class="dashboard-status-list">
                    <a
                        class="dashboard-status-card"
                        href="/Anabelka/admin/translations"
                    >
                        <span>
                            <strong>Переклади</strong>
                            <span>
                                <?php if ($translationAvailable): ?>
                                    Готовність <?= $translationPercent ?>%
                                    · мов перекладу:
                                    <?= (int) ($translationSummary['languages'] ?? 0) ?>
                                <?php else: ?>
                                    Дані тимчасово недоступні
                                <?php endif; ?>
                            </span>
                        </span>

                        <span
                            class="dashboard-status-value <?= !$translationAvailable ? 'is-error' : ($translationAttention > 0 ? 'is-warning' : 'is-good') ?>"
                        >
                            <?= $translationAvailable
                                ? $formatNumber($translationAttention)
                                : '—' ?>
                        </span>
                    </a>

                    <a
                        class="dashboard-status-card"
                        href="/Anabelka/admin/ai-translation"
                    >
                        <span>
                            <strong>ШІ-переклад</strong>
                            <span>
                                <?php if ($aiAvailable): ?>
                                    <?= htmlspecialchars(
                                        (string) ($aiSummary['state_label'] ?? '')
                                    ) ?>
                                    · налаштовано:
                                    <?= (int) ($aiSummary['configured'] ?? 0) ?>
                                    з
                                    <?= (int) ($aiSummary['total'] ?? 0) ?>
                                <?php else: ?>
                                    Дані тимчасово недоступні
                                <?php endif; ?>
                            </span>
                        </span>

                        <span
                            class="dashboard-status-value <?= $aiStateClass ?>"
                            title="Поточний ШІ"
                        >
                            <?= $aiAvailable
                                ? htmlspecialchars(
                                    (string) ($aiSummary['current_name'] ?? '—')
                                )
                                : '—' ?>
                        </span>
                    </a>
                </div>
            </section>

            <section class="dashboard-panel">
                <div class="dashboard-section-head">
                    <div>
                        <h2>Швидкі переходи</h2>
                        <p>Основні розділи адмін-панелі</p>
                    </div>
                </div>

                <div class="dashboard-quick-links">
                    <a class="dashboard-quick-link" href="/Anabelka/admin/products">
                        Товари
                    </a>
                    <a class="dashboard-quick-link" href="/Anabelka/admin/categories">
                        Категорії
                    </a>
                    <a class="dashboard-quick-link" href="/Anabelka/admin/delivery">
                        Доставка
                    </a>
                    <a class="dashboard-quick-link" href="/Anabelka/admin/languages">
                        Мови
                    </a>
                    <a class="dashboard-quick-link" href="/Anabelka/admin/translations">
                        Переклади
                    </a>
                    <a class="dashboard-quick-link" href="/Anabelka/admin/ai-translation">
                        Налаштування ШІ
                    </a>
                </div>
            </section>
        </div>
    </div>
</main>

</body>
</html>
