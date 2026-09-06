<?php

PublicInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.order.history_title', 'Мої замовлення');
$orders = is_array($orders ?? null) ? $orders : [];
$statusLabels = [
    'new' => Translator::t('public.order.status_new', 'Нове'),
    'processing' => Translator::t('public.order.status_processing', 'В обробці'),
    'completed' => Translator::t('public.order.status_completed', 'Завершене'),
    'cancelled' => Translator::t('public.order.status_cancelled', 'Скасоване')
];

$escape = function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
};

$formatDate = function ($value) {
    $timestamp = strtotime((string) $value);

    return $timestamp
        ? date('d.m.Y H:i', $timestamp)
        : '—';
};

$currencySymbols = [
    'EUR' => '€',
    'UAH' => '₴',
    'USD' => '$'
];
?>
<!DOCTYPE html>
<html lang="<?= $escape($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/order-summary.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog order-summary-page">
    <section style="margin-bottom:18px;">
        <h2><?= $escape($pageTitle) ?></h2>
        <p style="margin-top:6px;color:#746b78;">
            <?= $escape(
                Translator::t(
                    'public.order.history_text',
                    'Тут зберігаються ваші звичайні та швидкі замовлення.'
                )
            ) ?>
        </p>
    </section>

    <?php if (empty($orders)): ?>
        <section class="order-history-empty">
            <strong>
                <?= $escape(
                    Translator::t(
                        'public.order.history_empty',
                        'У вас ще немає замовлень.'
                    )
                ) ?>
            </strong>

            <div class="order-history-actions" style="justify-content:center;">
                <a class="is-primary" href="/Anabelka/catalog">
                    <?= $escape(
                        Translator::t(
                            'public.order.continue',
                            'Продовжити покупки'
                        )
                    ) ?>
                </a>
            </div>
        </section>
    <?php else: ?>
        <div class="order-history-list">
            <?php foreach ($orders as $order): ?>
                <?php
                $orderType = ($order['order_type'] ?? '') === 'quick'
                    ? 'quick'
                    : 'regular';
                $status = strtolower(trim((string) ($order['status'] ?? 'new')));
                $currency = strtoupper(trim((string) ($order['currency'] ?? 'EUR')));
                $currencySymbol = $currencySymbols[$currency] ?? $currency;
                $orderItems = is_array($order['items'] ?? null)
                    ? $order['items']
                    : [];
                $orderCurrency = $currency;
                ?>

                <article class="order-history-card">
                    <header class="order-history-head">
                        <div class="order-history-title">
                            <strong>
                                <?= $escape(
                                    $orderType === 'quick'
                                        ? Translator::t('public.order.quick_order', 'Швидке замовлення')
                                        : Translator::t('public.order.regular_order', 'Замовлення')
                                ) ?>
                                №<?= (int) ($order['id'] ?? 0) ?>
                            </strong>

                            <small><?= $escape($formatDate($order['created_at'] ?? '')) ?></small>
                        </div>

                        <div class="order-history-side">
                            <span class="order-history-status is-<?= $escape($status) ?>">
                                <?= $escape($statusLabels[$status] ?? $status) ?>
                            </span>

                            <strong>
                                <?= number_format(
                                    (float) ($order['total'] ?? 0),
                                    2,
                                    ',',
                                    ' '
                                ) ?>
                                <?= $escape($currencySymbol) ?>
                            </strong>
                        </div>
                    </header>

                    <details class="order-history-details">
                        <summary>
                            <?= $escape(
                                Translator::t(
                                    'public.order.show_items',
                                    'Показати товари'
                                )
                            ) ?>
                            · <?= count($orderItems) ?>
                        </summary>

                        <div class="order-history-details-body">
                            <?php require __DIR__ . '/partials/items.php'; ?>
                        </div>
                    </details>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="order-history-actions">
            <a class="is-primary" href="/Anabelka/catalog">
                <?= $escape(
                    Translator::t(
                        'public.order.continue',
                        'Продовжити покупки'
                    )
                ) ?>
            </a>
        </div>
    <?php endif; ?>
</main>

</body>
</html>
