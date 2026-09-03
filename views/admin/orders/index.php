<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Замовлення — Адмін-панель</title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?v=8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/admin-orders.css?v=1"
    >
</head>
<body>

<?php
$pageTitle = 'Адмін-панель — Замовлення';
require __DIR__ . '/../../partials/header.php';

$orders = is_array($orders ?? null) ? $orders : [];
$summary = is_array($summary ?? null) ? $summary : [];
$filters = is_array($filters ?? null)
    ? $filters
    : ['type' => 'all', 'status' => 'all', 'q' => ''];
$statusOptions = is_array($statusOptions ?? null)
    ? $statusOptions
    : AdminOrder::statusOptions();
$csrfToken = (string) ($csrfToken ?? '');

$escape = function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
};

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

$buildUrl = function (array $changes = []) use ($filters) {
    $next = AdminOrder::normalizeFilters(
        array_merge($filters, $changes)
    );
    $query = [];

    if ($next['type'] !== 'all') {
        $query['type'] = $next['type'];
    }

    if ($next['status'] !== 'all') {
        $query['status'] = $next['status'];
    }

    if ($next['q'] !== '') {
        $query['q'] = $next['q'];
    }

    return '/Anabelka/admin/orders'
        . (empty($query) ? '' : '?' . http_build_query($query));
};

$statusTabs = [
    'all' => 'Усі',
    'new' => 'Нові',
    'processing' => 'В обробці',
    'completed' => 'Завершені',
    'cancelled' => 'Скасовані'
];

$typeOptions = [
    'all' => 'Усі замовлення',
    'regular' => 'Звичайні',
    'quick' => 'Швидкі'
];

$paymentStatusLabels = [
    'pending' => 'Очікує оплати',
    'paid' => 'Оплачено',
    'failed' => 'Помилка оплати',
    'refunded' => 'Повернено'
];
?>

<main class="admin-orders">
    <section class="orders-heading">
        <div>
            <h2>Центр замовлень</h2>
            <p>
                Звичайні та швидкі замовлення в одному місці
            </p>
        </div>

        <span class="orders-total">
            <?= $formatNumber($summary['total'] ?? 0) ?>
            <small>усього</small>
        </span>
    </section>

    <?php if (!empty($flash['message'])): ?>
        <div
            class="orders-message <?= ($flash['type'] ?? '') === 'error' ? 'is-error' : 'is-success' ?>"
            role="status"
        >
            <?= $escape($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($ordersError)): ?>
        <div class="orders-message is-error" role="alert">
            Не вдалося завантажити замовлення:
            <?= $escape($ordersError) ?>
        </div>
    <?php endif; ?>

    <nav class="orders-status-tabs" aria-label="Фільтр за станом">
        <?php foreach ($statusTabs as $statusCode => $statusLabel): ?>
            <?php
            $isActive = ($filters['status'] ?? 'all') === $statusCode;
            $statusCount = $statusCode === 'all'
                ? (int) ($summary['total'] ?? 0)
                : (int) ($summary[$statusCode] ?? 0);
            ?>

            <a
                class="orders-status-tab<?= $isActive ? ' is-active' : '' ?>"
                href="<?= $escape($buildUrl(['status' => $statusCode])) ?>"
                <?= $isActive ? 'aria-current="page"' : '' ?>
            >
                <span><?= $escape($statusLabel) ?></span>
                <strong><?= $formatNumber($statusCount) ?></strong>
            </a>
        <?php endforeach; ?>
    </nav>

    <form
        class="orders-filters"
        action="/Anabelka/admin/orders"
        method="GET"
    >
        <?php if (($filters['status'] ?? 'all') !== 'all'): ?>
            <input
                type="hidden"
                name="status"
                value="<?= $escape($filters['status']) ?>"
            >
        <?php endif; ?>

        <label class="orders-filter-field">
            <span>Тип</span>
            <select name="type">
                <?php foreach ($typeOptions as $typeCode => $typeLabel): ?>
                    <option
                        value="<?= $escape($typeCode) ?>"
                        <?= ($filters['type'] ?? 'all') === $typeCode ? 'selected' : '' ?>
                    >
                        <?= $escape($typeLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="orders-filter-field is-search">
            <span>Пошук</span>
            <input
                type="search"
                name="q"
                value="<?= $escape($filters['q'] ?? '') ?>"
                placeholder="Номер, ім’я, телефон або email"
                autocomplete="off"
            >
        </label>

        <button class="orders-filter-submit" type="submit">
            Знайти
        </button>

        <a class="orders-filter-reset" href="/Anabelka/admin/orders">
            Скинути
        </a>
    </form>

    <div class="orders-result-line">
        <span>
            Знайдено: <strong><?= $formatNumber(count($orders)) ?></strong>
        </span>

        <?php if (($filters['type'] ?? 'all') === 'all'): ?>
            <span>
                Звичайні: <?= $formatNumber($summary['regular_total'] ?? 0) ?>
                · Швидкі: <?= $formatNumber($summary['quick_total'] ?? 0) ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (empty($orders) && empty($ordersError)): ?>
        <section class="orders-empty">
            <strong>Замовлень не знайдено</strong>
            <span>Змініть фільтр або пошуковий запит.</span>
        </section>
    <?php else: ?>
        <section class="orders-list" aria-label="Список замовлень">
            <?php foreach ($orders as $order): ?>
                <?php
                $orderId = (int) ($order['id'] ?? 0);
                $orderType = ($order['order_type'] ?? '') === 'quick'
                    ? 'quick'
                    : 'regular';
                $isQuick = $orderType === 'quick';
                $orderStatus = strtolower(
                    trim((string) ($order['status'] ?? 'new'))
                );

                if (!isset($statusOptions[$orderStatus])) {
                    $orderStatus = 'new';
                }

                $items = is_array($order['items'] ?? null)
                    ? $order['items']
                    : [];
                $customerName = trim(
                    (string) ($order['customer_name'] ?? '')
                );
                $customerPhone = trim(
                    (string) ($order['customer_phone'] ?? '')
                );
                $customerEmail = trim(
                    (string) ($order['customer_email'] ?? '')
                );
                $paymentStatus = strtolower(
                    trim((string) ($order['payment_status'] ?? ''))
                );

                if ($orderStatus === 'new') {
                    $statusActions = [
                        'processing' => 'Взяти в роботу',
                        'cancelled' => 'Скасувати'
                    ];
                } elseif ($orderStatus === 'processing') {
                    $statusActions = [
                        'completed' => 'Завершити',
                        'cancelled' => 'Скасувати'
                    ];
                } else {
                    $statusActions = [
                        'processing' => 'Повернути в роботу'
                    ];
                }
                ?>

                <article
                    id="order-<?= $escape($orderType) ?>-<?= $orderId ?>"
                    class="admin-order-card is-<?= $escape($orderStatus) ?>"
                >
                    <header class="admin-order-head">
                        <div class="admin-order-main">
                            <div class="admin-order-title-line">
                                <span class="admin-order-type<?= $isQuick ? ' is-quick' : '' ?>">
                                    <?= $isQuick ? 'Швидке' : 'Звичайне' ?>
                                </span>

                                <strong>
                                    №<?= $orderId ?>
                                    · <?= $escape($customerName !== '' ? $customerName : 'Без імені') ?>
                                </strong>
                            </div>

                            <div class="admin-order-contact-line">
                                <?php if ($customerPhone !== ''): ?>
                                    <a href="tel:<?= $escape($customerPhone) ?>">
                                        <?= $escape($customerPhone) ?>
                                    </a>
                                <?php endif; ?>

                                <?php if ($customerEmail !== ''): ?>
                                    <a href="mailto:<?= $escape($customerEmail) ?>">
                                        <?= $escape($customerEmail) ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="admin-order-side">
                            <span class="admin-order-status is-<?= $escape($orderStatus) ?>">
                                <?= $escape($statusOptions[$orderStatus]) ?>
                            </span>

                            <strong class="admin-order-total">
                                <?= $escape($formatMoney(
                                    $order['total'] ?? 0,
                                    $order['currency'] ?? 'EUR'
                                )) ?>
                            </strong>

                            <time class="admin-order-date">
                                <?= $escape($formatDate($order['created_at'] ?? '')) ?>
                            </time>
                        </div>
                    </header>

                    <details class="admin-order-details">
                        <summary>
                            <span>Деталі замовлення</span>
                            <small>
                                Позицій: <?= $formatNumber(count($items)) ?>
                            </small>
                        </summary>

                        <div class="admin-order-details-body">
                            <section class="admin-order-info-card">
                                <h3>Покупець</h3>

                                <dl>
                                    <div>
                                        <dt>Ім’я</dt>
                                        <dd><?= $escape($customerName !== '' ? $customerName : '—') ?></dd>
                                    </div>

                                    <div>
                                        <dt>Телефон</dt>
                                        <dd>
                                            <?php if ($customerPhone !== ''): ?>
                                                <a href="tel:<?= $escape($customerPhone) ?>">
                                                    <?= $escape($customerPhone) ?>
                                                </a>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </dd>
                                    </div>

                                    <?php if ($customerEmail !== ''): ?>
                                        <div>
                                            <dt>Email</dt>
                                            <dd>
                                                <a href="mailto:<?= $escape($customerEmail) ?>">
                                                    <?= $escape($customerEmail) ?>
                                                </a>
                                            </dd>
                                        </div>
                                    <?php endif; ?>
                                </dl>
                            </section>

                            <?php if (!$isQuick): ?>
                                <section class="admin-order-info-card">
                                    <h3>Доставка</h3>

                                    <dl>
                                        <div>
                                            <dt>Спосіб</dt>
                                            <dd><?= $escape($order['delivery_method_name'] ?: '—') ?></dd>
                                        </div>

                                        <?php if (!empty($order['delivery_service_name'])): ?>
                                            <div>
                                                <dt>Служба</dt>
                                                <dd><?= $escape($order['delivery_service_name']) ?></dd>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($order['delivery_option_name'])): ?>
                                            <div>
                                                <dt>Варіант</dt>
                                                <dd><?= $escape($order['delivery_option_name']) ?></dd>
                                            </div>
                                        <?php endif; ?>

                                        <div>
                                            <dt>Місто</dt>
                                            <dd><?= $escape($order['delivery_city'] ?: '—') ?></dd>
                                        </div>

                                        <div>
                                            <dt>Адреса / відділення</dt>
                                            <dd><?= $escape($order['delivery_address'] ?: '—') ?></dd>
                                        </div>

                                        <?php if (!empty($order['delivery_country'])): ?>
                                            <div>
                                                <dt>Країна</dt>
                                                <dd><?= $escape($order['delivery_country']) ?></dd>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($order['delivery_postcode'])): ?>
                                            <div>
                                                <dt>Індекс</dt>
                                                <dd><?= $escape($order['delivery_postcode']) ?></dd>
                                            </div>
                                        <?php endif; ?>
                                    </dl>
                                </section>
                            <?php endif; ?>

                            <section class="admin-order-info-card">
                                <h3>Оплата і коментар</h3>

                                <dl>
                                    <div>
                                        <dt>Сума</dt>
                                        <dd>
                                            <?= $escape($formatMoney(
                                                $order['total'] ?? 0,
                                                $order['currency'] ?? 'EUR'
                                            )) ?>
                                        </dd>
                                    </div>

                                    <?php if (!$isQuick): ?>
                                        <div>
                                            <dt>Оплата</dt>
                                            <dd>
                                                <?= $escape(
                                                    $paymentStatusLabels[$paymentStatus]
                                                    ?? ($paymentStatus !== '' ? $paymentStatus : '—')
                                                ) ?>
                                            </dd>
                                        </div>
                                    <?php endif; ?>

                                    <div>
                                        <dt>Коментар</dt>
                                        <dd><?= $escape($order['comment'] ?: '—') ?></dd>
                                    </div>
                                </dl>
                            </section>

                            <section class="admin-order-items-card">
                                <h3>Товари</h3>

                                <?php if (empty($items)): ?>
                                    <p class="admin-order-no-items">
                                        Позиції замовлення не знайдені.
                                    </p>
                                <?php else: ?>
                                    <div class="admin-order-items">
                                        <?php foreach ($items as $item): ?>
                                            <div class="admin-order-item">
                                                <div>
                                                    <strong>
                                                        <?= $escape($item['product_name'] ?? '') ?>
                                                    </strong>

                                                    <span>
                                                        <?php if (!empty($item['sku'])): ?>
                                                            SKU: <?= $escape($item['sku']) ?> ·
                                                        <?php endif; ?>

                                                        <?php if (!empty($item['size_name'])): ?>
                                                            Розмір: <?= $escape($item['size_name']) ?> ·
                                                        <?php endif; ?>

                                                        <?= (int) ($item['quantity'] ?? 0) ?> шт.
                                                    </span>
                                                </div>

                                                <strong>
                                                    <?= $escape($formatMoney(
                                                        $item['line_total'] ?? 0,
                                                        $order['currency'] ?? 'EUR'
                                                    )) ?>
                                                </strong>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </section>
                        </div>
                    </details>

                    <form
                        class="admin-order-actions"
                        action="/Anabelka/admin/orders/status"
                        method="POST"
                    >
                        <input type="hidden" name="order_id" value="<?= $orderId ?>">
                        <input type="hidden" name="order_type" value="<?= $escape($orderType) ?>">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="filter_type" value="<?= $escape($filters['type'] ?? 'all') ?>">
                        <input type="hidden" name="filter_status" value="<?= $escape($filters['status'] ?? 'all') ?>">
                        <input type="hidden" name="filter_q" value="<?= $escape($filters['q'] ?? '') ?>">

                        <span>Змінити стан:</span>

                        <div>
                            <?php foreach ($statusActions as $nextStatus => $actionLabel): ?>
                                <button
                                    type="submit"
                                    name="status"
                                    value="<?= $escape($nextStatus) ?>"
                                    class="is-<?= $escape($nextStatus) ?>"
                                    <?= $nextStatus === 'cancelled'
                                        ? 'onclick="return window.confirm(\'Скасувати це замовлення?\')"'
                                        : '' ?>
                                >
                                    <?= $escape($actionLabel) ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

</body>
</html>
