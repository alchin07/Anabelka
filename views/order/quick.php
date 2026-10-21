<?php

$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('quick.title', 'Швидке замовлення');
// The visible form heading is inside the card, so don't repeat it in the site header.
$hidePublicPageHeading = true;

$quickValidationMessages = [
    'required' => Translator::t(
        'public.order.error_fill_field',
        'Заповніть поле «{field}».'
    ),
    'invalid' => Translator::t(
        'checkout.validation_invalid',
        'Перевірте значення поля «{field}».'
    ),
    'labels' => [
        'customer_name' => Translator::t('quick.name', 'Ім’я'),
        'customer_phone' => Translator::t('quick.phone', 'Номер телефону')
    ]
];

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title><?= htmlspecialchars($pageTitle) ?> — Анабелька</title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?v=8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=4"
    >
    <link rel="stylesheet" href="/Anabelka/css/checkout-validation.css?v=3">
</head>

<body class="checkout-validation-page">

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="catalog">

    <section
        class="product-card"
        style="
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        "
    >

        <h2>
            <?= htmlspecialchars(
                Translator::t(
                    'quick.title',
                    'Швидке замовлення'
                )
            ) ?>
        </h2>

        <p
            style="
                margin: 0 0 20px;
                opacity: 0.75;
            "
        >
            <?= htmlspecialchars(
                Translator::t(
                    'quick.intro',
                    'Залиште ім’я та номер телефону. Ми зв’яжемося з вами для уточнення доставки.'
                )
            ) ?>
        </p>

        <?php if (!empty($productDraftToken)): ?>
            <div
                style="
                    margin-bottom: 18px;
                    padding: 14px;
                    border: 1px solid var(--border-color);
                    border-radius: 12px;
                "
            >
                <?php foreach ($items as $item): ?>
                    <div style="margin-bottom: 6px;">
                        <strong><?= htmlspecialchars(
                            (string) ($item['product']['name'] ?? ''),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?></strong>
                        — <?= htmlspecialchars(
                            (string) ($item['size']['value'] ?? ''),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                        <?php if (!empty($item['color_name'])): ?>
                            · <?= htmlspecialchars(
                                (string) $item['color_name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        <?php endif; ?>
                        · 1 шт.
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form
            action="<?= !empty($productDraftToken)
                ? '/Anabelka/quick-order/product'
                : '/Anabelka/quick-order' ?>"
            method="POST"
            autocomplete="on"
        >
            <?php if (!empty($productDraftToken)): ?>
                <input
                    type="hidden"
                    name="draft_token"
                    value="<?= htmlspecialchars(
                        $productDraftToken,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >
            <?php endif; ?>

            <div style="margin-bottom: 15px;">
                <label>
                    <?= htmlspecialchars(
                        Translator::t(
                            'quick.name',
                            'Ім’я'
                        )
                    ) ?>
                    <span
                        style="
                            color: var(--primary-color);
                            font-weight: bold;
                        "
                    >*</span>
                </label>

                <input
                    type="text"
                    name="customer_name"
                    autocomplete="name"
                    required
                    value="<?= htmlspecialchars(
                        $_SESSION['user_name'] ?? ''
                    ) ?>"
                    style="
                        width: 100%;
                        box-sizing: border-box;
                        padding: 12px;
                        margin-top: 6px;
                        border: 1px solid var(--border-color);
                        border-radius: 10px;
                    "
                >
            </div>

            <div style="margin-bottom: 15px;">
                <label>
                    <?= htmlspecialchars(
                        Translator::t(
                            'quick.phone',
                            'Номер телефону'
                        )
                    ) ?>
                    <span
                        style="
                            color: var(--primary-color);
                            font-weight: bold;
                        "
                    >*</span>
                </label>

                <input
                    type="tel"
                    name="customer_phone"
                    required
                    autocomplete="tel"
                    style="
                        width: 100%;
                        box-sizing: border-box;
                        padding: 12px;
                        margin-top: 6px;
                        border: 1px solid var(--border-color);
                        border-radius: 10px;
                    "
                >
            </div>

            <div style="margin-bottom: 20px;">
                <label>
                    <?= htmlspecialchars(
                        Translator::t(
                            'quick.comment',
                            'Коментар'
                        )
                    ) ?>
                </label>

                <textarea
                    name="comment"
                    rows="4"
                    style="
                        width: 100%;
                        box-sizing: border-box;
                        padding: 12px;
                        margin-top: 6px;
                        border: 1px solid var(--border-color);
                        border-radius: 10px;
                        resize: vertical;
                    "
                ></textarea>
            </div>

            <div
                style="
                    margin-bottom: 18px;
                    padding: 14px;
                    border-radius: 12px;
                    background: var(--primary-light-color);
                "
            >
                <strong>
                    <?= htmlspecialchars(
                        Translator::t(
                            'quick.total',
                            'Сума замовлення'
                        )
                    ) ?>:
                    <?= number_format(
                        (float) $total,
                        2,
                        ',',
                        ' '
                    ) ?> ₴
                </strong>
            </div>

            <button
                type="submit"
                style="
                    width: 100%;
                    padding: 14px;
                    border: 0;
                    border-radius: 12px;
                    background: var(--primary-color);
                    color: #fff;
                    font-size: 16px;
                    font-weight: bold;
                    cursor: pointer;
                "
            >
                <?= htmlspecialchars(
                    Translator::t(
                        'quick.submit',
                        'Надіслати швидке замовлення'
                    )
                ) ?>
            </button>

        </form>

    </section>

</main>

<script type="application/json" id="checkout-validation-messages"><?= json_encode(
    $quickValidationMessages,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
) ?: '{}' ?></script>
<script src="/Anabelka/js/checkout-validation.js?v=3"></script>

</body>
</html>
