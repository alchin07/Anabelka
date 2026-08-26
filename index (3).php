<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Каталог — Анабелька</title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?=v8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=3"
    >

</head>

<body>

    <!-- Шапка каталога -->
    <?php

$pageTitle = 'Каталог';

require __DIR__ . '/../partials/header.php';

?>


    <!-- Основная область каталога -->
    <main class="catalog">

        <!-- Категории -->
        <section class="catalog-categories">

            <h2>Категории</h2>

            <div class="category-list">

                <?php foreach ($categories as $category): ?>

                    <a
                        href="/Anabelka/catalog/<?= htmlspecialchars($category['slug']) ?>"
                        class="category-item"
                    >
                        <?= htmlspecialchars($category['name']) ?>
                    </a>

                <?php endforeach; ?>

            </div>

        </section>


        <!-- Товары -->
        <section class="catalog-products">

            <h2>Товары</h2>

            <div class="product-grid">

                <div class="product-card">

                    <div class="product-image">
                        Фото товара
                    </div>

                    <h3>Название товара</h3>

                    <p class="product-price">
                        0 €
                    </p>

                </div>

            </div>

        </section>

    </main>


    <!-- Нижняя часть -->
    <footer class="catalog-footer">

        <a href="/Anabelka/">
            На главную
        </a>

    </footer>

</body>

</html>