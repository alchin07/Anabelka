<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Языки — Админ-панель</title>

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
        href="/Anabelka/css/admin-languages.css?v=2"
    >
</head>

<body>

<?php
$pageTitle = 'Админ-панель — Языки';
require __DIR__ . '/../../partials/header.php';
?>

<main class="catalog">

    <section class="product-card languages-admin">

        <div class="languages-head">
            <div>
                <h2>Языки сайта</h2>

                <p class="languages-subtitle">
                    Исходный язык контента — украинский.
                    Технические параметры языка назначаются автоматически.
                </p>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="languages-notice">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="languages-notice error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form
            class="language-create"
            action="/Anabelka/admin/languages/create"
            method="post"
        >
            <h3>Добавить язык</h3>

            <?php if (!empty($availableLanguages)): ?>

                <div class="language-create-row">
                    <div class="language-field">
                        <label for="language-create-select">
                            Язык
                        </label>

                        <select
                            id="language-create-select"
                            name="language_code"
                            required
                        >
                            <option value="">
                                Выберите язык
                            </option>

                            <?php foreach ($availableLanguages as $code => $languageData): ?>
                                <option value="<?= htmlspecialchars($code) ?>">
                                    <?= htmlspecialchars($languageData['name']) ?>
                                    (<?= htmlspecialchars($languageData['short_name']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button
                        class="language-button"
                        type="submit"
                    >
                        Добавить язык
                    </button>
                </div>

            <?php else: ?>

                <p class="language-help">
                    Все языки из встроенного справочника уже добавлены.
                </p>

            <?php endif; ?>
        </form>

        <div class="language-list">

            <?php foreach ($languages as $language): ?>

                <?php
                $isSource = !empty($language['is_source']);
                $isDefault = !empty($language['is_default']);
                $isActive = !empty($language['is_active']);
                ?>

                <article
                    class="language-card<?= $isSource ? ' is-source' : '' ?>"
                >
                    <div class="language-card-head">
                        <div class="language-card-title">
                            <strong>
                                <?= htmlspecialchars($language['name']) ?>
                            </strong>

                            <span class="language-short">
                                <?= htmlspecialchars($language['short_name']) ?>
                            </span>

                            <span
                                class="language-badge <?= $isActive ? 'active' : 'inactive' ?>"
                            >
                                <?= $isActive ? 'Включён' : 'Отключён' ?>
                            </span>

                            <?php if ($isSource): ?>
                                <span class="language-badge">
                                    Исходный
                                </span>
                            <?php endif; ?>

                            <?php if ($isDefault): ?>
                                <span class="language-badge">
                                    Основной
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form
                        class="language-name-form"
                        action="/Anabelka/admin/languages/update"
                        method="post"
                    >
                        <input
                            type="hidden"
                            name="language_id"
                            value="<?= (int) $language['id'] ?>"
                        >

                        <div class="language-field">
                            <label>
                                Название
                            </label>

                            <input
                                name="name"
                                type="text"
                                value="<?= htmlspecialchars($language['name']) ?>"
                                required
                            >
                        </div>

                        <button
                            class="language-button"
                            type="submit"
                        >
                            Сохранить
                        </button>
                    </form>

                    <div class="language-inline-actions">
                        <form
                            action="/Anabelka/admin/languages/toggle"
                            method="post"
                        >
                            <input
                                type="hidden"
                                name="language_id"
                                value="<?= (int) $language['id'] ?>"
                            >

                            <button
                                class="language-button secondary"
                                type="submit"
                                <?= ($isSource || $isDefault) && $isActive ? 'disabled' : '' ?>
                            >
                                <?= $isActive ? 'Отключить' : 'Включить' ?>
                            </button>
                        </form>

                        <form
                            action="/Anabelka/admin/languages/default"
                            method="post"
                        >
                            <input
                                type="hidden"
                                name="language_id"
                                value="<?= (int) $language['id'] ?>"
                            >

                            <button
                                class="language-button secondary"
                                type="submit"
                                <?= (!$isActive || $isDefault) ? 'disabled' : '' ?>
                            >
                                Сделать основным
                            </button>
                        </form>

                        <form
                            action="/Anabelka/admin/languages/delete"
                            method="post"
                            onsubmit="return confirm('Удалить этот язык? Все будущие переводы этого языка также будут связаны с ним.');"
                        >
                            <input
                                type="hidden"
                                name="language_id"
                                value="<?= (int) $language['id'] ?>"
                            >

                            <button
                                class="language-button danger"
                                type="submit"
                                <?= ($isSource || $isDefault) ? 'disabled' : '' ?>
                            >
                                Удалить
                            </button>
                        </form>
                    </div>

                    <?php if ($isSource): ?>
                        <p class="language-help">
                            Украинский язык является исходным и не может быть отключён или удалён.
                        </p>
                    <?php endif; ?>
                </article>

            <?php endforeach; ?>

        </div>

    </section>

</main>

</body>
</html>
