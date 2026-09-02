<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Способы доставки — Админ-панель</title>

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
        href="/Anabelka/css/admin-tree.css?v=7"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/admin-delivery.css?v=9"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/admin-delivery-translation-target.css?v=3"
    >
</head>

<body>

    <?php

    $translationHighlightId = 0;
    $translationHighlightType = '';

    $rawHighlightId =
        trim((string) ($_GET['highlight'] ?? ''));

    $rawHighlightType = strtolower(
        trim((string) ($_GET['highlight_type'] ?? ''))
    );

    if (ctype_digit($rawHighlightId)) {
        $translationHighlightId =
            (int) $rawHighlightId;
    }

    if ($rawHighlightType === 'option_input') {
        $rawHighlightType = 'option';
    }

    if (in_array(
        $rawHighlightType,
        ['method', 'service', 'option'],
        true
    )) {
        $translationHighlightType =
            $rawHighlightType;
    }

    if (
        $translationHighlightId <= 0
        || $translationHighlightType === ''
    ) {
        $translationHighlightId = 0;
        $translationHighlightType = '';
    }

    $pageTitle =
        'Админ-панель — Доставка';

    require __DIR__
        . '/../../partials/header.php';

    ?>

    <main class="catalog">

        <section
            class="
                product-card
                delivery-admin
                admin-tree
            "
        >

            <div class="admin-head">
                <div>
                    <h2>Способы доставки</h2>

                    <p class="admin-subtitle">
                        Управление способами
                        и службами доставки
                    </p>
                </div>
            </div>

            <div class="delivery-columns">
                <span>Название</span>
                <span>Статус</span>
                <span>Вкл.</span>
                <span>Ред.</span>
                <span>Удал.</span>
            </div>

            <?php if (empty($deliveryMethods)): ?>

                <div
                    class="admin-tree-row"
                    style="--level: 1;"
                >
                    <button
                        type="button"
                        class="admin-tree-add add-delivery"
                        aria-label="Добавить способ доставки"
                    >
                        +
                    </button>

                    <div
                        class="
                            admin-tree-item
                            delivery-row
                            method-row
                            admin-tree-empty
                        "
                    >
                        <div class="admin-tree-main">
                            <div class="admin-tree-text">
                                <span class="delivery-name">
                                    Создать способ доставки
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>

                <?php

                $isFirstMethod = true;

                foreach ($deliveryMethods as $method):

                    require __DIR__
                        . '/partials/method-row.php';

                    $isFirstMethod = false;

                endforeach;

                ?>

            <?php endif; ?>

            <?php require __DIR__
                . '/partials/legend.php'; ?>

        </section>

    </main>

    <div
        id="site-message"
        class="site-message"
    ></div>

    <?php require __DIR__
        . '/partials/edit-modal.php'; ?>

    <?php require __DIR__
        . '/partials/delete-modal.php'; ?>

    <?php require __DIR__
        . '/partials/add-modal.php'; ?>

    <?php require __DIR__
        . '/partials/add-service-modal.php'; ?>

    <?php require __DIR__
        . '/partials/add-option-modal.php'; ?>

    <script
        src="/Anabelka/js/admin-delivery/common.js?v=3"
    ></script>

    <script
        src="/Anabelka/js/admin-delivery/toggle.js?v=1"
    ></script>

    <script
        src="/Anabelka/js/admin-delivery/edit.js?v=5"
    ></script>

    <script
        src="/Anabelka/js/admin-delivery/delete.js?v=2"
    ></script>

    <script
        src="/Anabelka/js/admin-delivery/add.js?v=3"
    ></script>

    <script
        src="/Anabelka/js/admin-delivery/add-service.js?v=1"
    ></script>

    <script
        src="/Anabelka/js/admin-delivery/add-option.js?v=2"
    ></script>

    <script
        src="/Anabelka/js/admin-delivery/collapse.js?v=9"
    ></script>

    <script
        src="/Anabelka/js/admin-delivery/translation-target.js?v=4"
    ></script>

</body>
</html>
