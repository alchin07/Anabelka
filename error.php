<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Ошибка заказа — Анабелька
    </title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?v=8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=4"
    >

</head>

<body>

    <?php

    $pageTitle =
        'Заказ';

    require __DIR__
        . '/../partials/header.php';

    ?>


    <main class="catalog">

        <section
            class="product-card"
            style="
                max-width: 600px;
                margin: 0 auto;
                padding: 30px 25px;
                text-align: center;
            "
        >

            <div
                style="
                    width: 58px;
                    height: 58px;

                    display: flex;
                    align-items: center;
                    justify-content: center;

                    margin: 0 auto 18px;

                    border-radius: 50%;

                    background:
                        var(--primary-light-color);

                    color:
                        var(--primary-color);

                    font-size: 30px;
                    font-weight: bold;
                "
            >
                !
            </div>


            <h2
                style="
                    margin-bottom: 12px;
                "
            >
                Заказ не найден
            </h2>


            <p
                style="
                    margin-bottom: 25px;
                    line-height: 1.5;
                "
            >
                Возможно, ссылка устарела
                или была изменена.
            </p>


            <a
                href="/Anabelka/catalog"
                style="
                    display: inline-block;

                    padding: 12px 22px;

                    border-radius: 12px;

                    background:
                        var(--primary-color);

                    color: #fff;

                    text-decoration: none;
                    font-weight: bold;
                "
            >
                Вернуться в каталог
            </a>

        </section>

    </main>

</body>

</html>  