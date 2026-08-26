<!DOCTYPE html>
<html lang="ru">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars($category['name']) ?> — Анабелька
    </title>

    <link
        rel="stylesheet"
        href="/Anabelka/css/style.css?=v8"
    >

    <link
        rel="stylesheet"
        href="/Anabelka/css/catalog.css?v=4"
    >

</head>

<body>

    <!-- Шапка -->
    <?php

$pageTitle = $category['name'];

require __DIR__ . '/../partials/header.php';

?>


    <main class="catalog">

        <!-- Назад в каталог -->
        <p style="margin-bottom: 25px;">

            <a
                href="/Anabelka/catalog"
                style="color: var(--primary-color);"
            >
                ← Каталог
            </a>

        </p>


        <!-- Если есть дочерние категории -->
        <?php if (!empty($children)): ?>

            <section class="catalog-categories">

                <h2>Подкатегории</h2>

                <div class="category-list">

                    <?php foreach ($children as $child): ?>

                        <a
                            href="/Anabelka/catalog/<?= htmlspecialchars($child['slug']) ?>"
                            class="category-item"
                        >
                            <?= htmlspecialchars($child['name']) ?>
                        </a>

                    <?php endforeach; ?>

                </div>

            </section>


        <!-- Если дочерних категорий нет -->
        <?php else: ?>


            <!-- Если есть товары -->
            <?php if (!empty($products)): ?>

                <section class="catalog-products">

                    <h2>Товары</h2>

                    <div class="product-grid">

                        <?php foreach ($products as $product): ?>

                            <a
                                href="/Anabelka/product/<?= htmlspecialchars($product['slug']) ?>"
                                class="product-card"
                            >

                                <!-- Фото товара -->
                                <div class="product-image">

                                    <?php if (!empty($product['main_image'])): ?>

                                        <img
                                            src="<?= htmlspecialchars($product['main_image']) ?>"
                                            alt="<?= htmlspecialchars($product['name']) ?>"
                                        >

                                    <?php else: ?>

                                        Фото товара

                                    <?php endif; ?>

                                </div>


                                <!-- Название -->
                                <h3>
                                    <?= htmlspecialchars($product['name']) ?>
                                </h3>


                                <!-- Цена -->
                                <p class="product-price">

    <?= number_format(
        Product::getCurrentPrice($product),
        2,
        ',',
        ' '
    ) ?> €

</p>

                            </a>

                        <?php endforeach; ?>

                    </div>

                </section>


            <!-- Если нет ни подкатегорий, ни товаров -->
            <?php else: ?>

                <p>
                    В этой категории пока нет товаров.
                </p>

            <?php endif; ?>


        <?php endif; ?>

    </main>

</body>

</html>