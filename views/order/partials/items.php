<?php

$orderItems = is_array($orderItems ?? null) ? $orderItems : [];
$orderCurrency = strtoupper(trim((string) ($orderCurrency ?? 'EUR')));
$currencySymbols = [
    'EUR' => '€',
    'UAH' => '₴',
    'USD' => '$'
];
$currencySymbol = $currencySymbols[$orderCurrency] ?? $orderCurrency;

$escapeOrderItem = function ($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
};
?>

<div class="order-summary-items">
    <?php foreach ($orderItems as $item): ?>
        <?php
        $colorName = trim((string) ($item['color_name'] ?? ''));
        $colorHex = strtolower(trim((string) ($item['color_hex'] ?? '')));

        if (!preg_match('/^#[0-9a-f]{6}$/', $colorHex)) {
            $colorHex = '#b8b0bd';
        }
        ?>

        <article class="order-summary-item">
            <div class="order-summary-item-main">
                <strong class="order-summary-item-name">
                    <?= $escapeOrderItem($item['product_name'] ?? '') ?>
                </strong>

                <?php if (!empty($item['sku'])): ?>
                    <small class="order-summary-item-sku">
                        SKU: <?= $escapeOrderItem($item['sku']) ?>
                    </small>
                <?php endif; ?>

                <div class="order-variant-chips">
                    <?php if (!empty($item['size_name'])): ?>
                        <span class="order-variant-chip">
                            <?= $escapeOrderItem(
                                Translator::t('public.order.size', 'Розмір')
                            ) ?>:
                            <?= $escapeOrderItem($item['size_name']) ?>
                        </span>
                    <?php endif; ?>

                    <?php if ($colorName !== ''): ?>
                        <span class="order-variant-chip">
                            <span
                                class="order-color-dot"
                                style="--order-color: <?= $escapeOrderItem($colorHex) ?>"
                                aria-hidden="true"
                            ></span>
                            <?= $escapeOrderItem(
                                Translator::t('public.order.color', 'Колір')
                            ) ?>:
                            <?= $escapeOrderItem($colorName) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="order-summary-item-side">
                <small>
                    <?= (int) ($item['quantity'] ?? 0) ?>
                    <?= $escapeOrderItem(
                        Translator::t('public.order.pieces', 'шт.')
                    ) ?>
                </small>

                <strong>
                    <?= number_format(
                        (float) ($item['line_total'] ?? 0),
                        2,
                        ',',
                        ' '
                    ) ?>
                    <?= $escapeOrderItem($currencySymbol) ?>
                </strong>
            </div>
        </article>
    <?php endforeach; ?>
</div>
