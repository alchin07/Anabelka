<?php

PublicInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('quick.title', 'Швидке замовлення');
$orderItems = !empty($order['id'])
    ? CustomerOrderHistory::quickItems((int) $order['id'])
    : [];
$orderCurrency = strtoupper(trim((string) ($order['currency'] ?? 'EUR')));

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(
        Translator::t(
            'quick.success_page_title',
            'Швидке замовлення прийнято'
        )
    ) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/order-summary.css?v=1">
</head>

<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog order-summary-page">
    <section class="order-summary-card">
        <h2 style="text-align:center;">
            <?= htmlspecialchars(
                Translator::t(
                    'quick.accepted',
                    'Замовлення прийнято'
                )
            ) ?>
        </h2>

        <p style="margin:12px 0;text-align:center;">
            <?= htmlspecialchars(
                Translator::t('quick.thanks', 'Дякуємо')
            ) ?>,
            <?= htmlspecialchars($order['customer_name'] ?? '') ?>.
        </p>

        <p style="margin:0 0 18px;text-align:center;">
            <?= htmlspecialchars(
                Translator::t(
                    'quick.contact_before',
                    'Ми зв’яжемося з вами за номером'
                )
            ) ?>
            <strong><?= htmlspecialchars($order['customer_phone'] ?? '') ?></strong>
            <?= htmlspecialchars(
                Translator::t(
                    'quick.contact_after',
                    'для уточнення деталей замовлення та доставки.'
                )
            ) ?>
        </p>

        <?php if (!empty($order)): ?>
            <p style="font-weight:bold;text-align:center;">
                <?= htmlspecialchars(
                    Translator::t('public.order.number', 'Номер замовлення')
                ) ?>:
                №<?= (int) $order['id'] ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($orderItems)): ?>
            <h3 style="margin-top:22px;">
                <?= htmlspecialchars(
                    Translator::t('public.order.items', 'Товари')
                ) ?>
            </h3>

            <?php require __DIR__ . '/partials/items.php'; ?>
        <?php endif; ?>

        <?php if (!empty($order['total'])): ?>
            <p
                style="
                    margin-top:20px;
                    font-size:18px;
                    color:var(--primary-color);
                    font-weight:700;
                    text-align:right;
                "
            >
                <?= htmlspecialchars(
                    Translator::t('public.order.sum', 'Сума')
                ) ?>:
                <?= number_format(
                    (float) $order['total'],
                    2,
                    ',',
                    ' '
                ) ?> €
            </p>
        <?php endif; ?>

        <div class="order-history-actions">
            <a class="is-primary" href="/Anabelka/catalog">
                <?= htmlspecialchars(
                    Translator::t(
                        'quick.continue_shopping',
                        'Продовжити покупки'
                    )
                ) ?>
            </a>

            <?php if (!empty($_SESSION['user_id'])): ?>
                <a href="/Anabelka/orders">
                    <?= htmlspecialchars(
                        Translator::t('public.order.history_title', 'Мої замовлення')
                    ) ?>
                </a>
            <?php endif; ?>
        </div>
    </section>
</main>

</body>
</html>
