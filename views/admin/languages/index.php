<!DOCTYPE html>
<html lang="uk">

<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Мови — Адмін-панель</title>

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
$pageTitle = 'Адмін-панель — Мови';
require __DIR__ . '/../../partials/header.php';
?>

<main class="catalog">

    <section class="product-card languages-admin">

        <div class="languages-head">
            <div>
                <h2>Мови сайту</h2>

                <p class="languages-subtitle">
                    Вихідна мова контенту — українська.
                    Технічні параметри мови призначаються автоматично.
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
            <h3>Додати мову</h3>

            <?php if (!empty($availableLanguages)): ?>

                <div class="language-create-row">
                    <div class="language-field">
                        <label for="language-create-select">
                            Мова
                        </label>

                        <select
                            id="language-create-select"
                            name="language_code"
                            required
                        >
                            <option value="">
                                Оберіть мову
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
                        Додати мову
                    </button>
                </div>

            <?php else: ?>

                <p class="language-help">
                    Усі мови з вбудованого довідника вже додано.
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
                                <?= $isActive ? 'Увімкнена' : 'Вимкнена' ?>
                            </span>

                            <?php if ($isSource): ?>
                                <span class="language-badge">
                                    Вихідна
                                </span>
                            <?php endif; ?>

                            <?php if ($isDefault): ?>
                                <span class="language-badge">
                                    Основна
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
                                Назва
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
                            Зберегти
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
                                <?= $isActive ? 'Вимкнути' : 'Увімкнути' ?>
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
                                Зробити основною
                            </button>
                        </form>

                        <form
                            action="/Anabelka/admin/languages/delete"
                            method="post"
                            onsubmit="return confirm('Видалити цю мову разом з усіма її перекладами?');"
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
                                Видалити
                            </button>
                        </form>
                    </div>

                    <?php if ($isSource): ?>
                        <p class="language-help">
                            Українська мова є вихідною, тому її не можна вимкнути або видалити.
                        </p>
                    <?php endif; ?>
                </article>

            <?php endforeach; ?>

        </div>

    </section>

</main>

</body>
</html>
