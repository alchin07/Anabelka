<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Способы доставки — Админ-панель
    </title>


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
        href="/Anabelka/css/admin-tree.css?v=4"
    > 

    <link
        rel="stylesheet"
        href="/Anabelka/css/admin-delivery.css?v=9"
    >

</head>


<body>

    <?php

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

                    <h2>
                        Способы доставки
                    </h2>

                    <p class="admin-subtitle">
                        Управление способами
                        и службами доставки
                    </p>

                </div>
            </div>

            <div class="delivery-columns">

                <span>
                    Название
                </span>

                <span>
                    Статус
                </span>

                <span>
                    Вкл.
                </span>

                <span>
                    Ред.
                </span>

                <span>
                    Удал.
                </span>

            </div>
              
            <div
    class="admin-tree-row"
    style="--level: 1;"
>

    <button
        type="button"
        class="
            admin-tree-add
            add-delivery
        "
        aria-label="Добавить способ доставки"
    >
        +
    </button>

            </div>

            <?php foreach (
                $deliveryMethods
                as $method
            ): ?>

                <?php require __DIR__
                    . '/partials/method-row.php'; ?>

            <?php endforeach; ?>


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
    src="/Anabelka/js/admin-delivery/common.js?v=1"
></script>

<script
    src="/Anabelka/js/admin-delivery/toggle.js?v=1"
></script>

<script
    src="/Anabelka/js/admin-delivery/edit.js?v=1"
></script>

<script
    src="/Anabelka/js/admin-delivery/delete.js?v=1"
></script>

<script
    src="/Anabelka/js/admin-delivery/add.js?v=2"
></script>

<script
    src="/Anabelka/js/admin-delivery/add-service.js?v=1"
></script>

  <script
    src="/Anabelka/js/admin-delivery/add-option.js?v=1"
></script>

  <script
    src="/Anabelka/js/admin-delivery/collapse.js?v=4"
></script>

</body>

</html>