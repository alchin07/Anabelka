<?php
$ranks = is_array($ranks ?? null) ? $ranks : [];
$summary = is_array($summary ?? null) ? $summary : [];
$defaultRank = is_array($defaultRank ?? null) ? $defaultRank : null;
$escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle ?? 'Адмін-панель · Ранги') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-ranks.css?v=4">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-ranks-page">
    <?php if (!empty($message)): ?>
        <div class="admin-rank-message"><?= $escape($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="admin-rank-message is-error"><?= $escape($error) ?></div>
    <?php endif; ?>

    <section class="admin-ranks-summary" aria-label="Статистика рангів">
        <div class="admin-rank-stat">
            <strong><?= (int) ($summary['total'] ?? 0) ?></strong>
            <span>Усього</span>
        </div>
        <div class="admin-rank-stat">
            <strong><?= (int) ($summary['active'] ?? 0) ?></strong>
            <span>Активних</span>
        </div>
        <div class="admin-rank-stat">
            <strong><?= (int) ($summary['inactive'] ?? 0) ?></strong>
            <span>Вимкнених</span>
        </div>
    </section>

    <section class="admin-rank-default">
        <strong>Ранг для нової реєстрації</strong>
        <span>
            <?= $defaultRank
                ? $escape($defaultRank['name'] ?? '')
                : 'Не визначено' ?>
        </span>
    </section>

    <form class="admin-rank-create" method="post" action="/Anabelka/admin/ranks/create">
        <div class="admin-rank-create-field">
            <input
                type="text"
                name="name"
                maxlength="100"
                placeholder="Назва нового рангу"
                required
            >
            <small>Рівень нового рангу визначається автоматично.</small>
        </div>
        <button class="admin-rank-button is-primary" type="submit">
            Створити ранг
        </button>
    </form>

    <section class="admin-rank-list">
        <?php foreach ($ranks as $rank): ?>
            <?php
            $rankId = (int) ($rank['id'] ?? 0);
            $isActive = !empty($rank['is_active']);
            $isGuest = ($rank['slug'] ?? '') === 'guest';
            $isDefault = $defaultRank
                && (int) ($defaultRank['id'] ?? 0) === $rankId;
            ?>
            <article class="admin-rank-card">
                <div class="admin-rank-card-head">
                    <div>
                        <strong><?= $escape($rank['name'] ?? '') ?></strong>
                        <div class="admin-rank-meta">
                            <span>slug: <?= $escape($rank['slug'] ?? '') ?></span>
                            <span>Рівень: <?= (int) ($rank['level'] ?? 0) ?></span>
                            <span>Користувачів: <?= (int) ($rank['user_count'] ?? 0) ?></span>
                            <span>Товарів із ціною: <?= (int) ($rank['priced_product_count'] ?? 0) ?></span>
                            <?php if ($isDefault): ?>
                                <span>За замовчуванням для реєстрації</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <span class="admin-rank-status <?= $isActive ? 'is-active' : 'is-inactive' ?>">
                        <?= $isActive ? 'Активний' : 'Вимкнений' ?>
                    </span>
                </div>

                <form class="admin-rank-edit" method="post" action="/Anabelka/admin/ranks/update">
                    <input type="hidden" name="rank_id" value="<?= $rankId ?>">
                    <input
                        type="text"
                        name="name"
                        maxlength="100"
                        value="<?= $escape($rank['name'] ?? '') ?>"
                        required
                    >

                    <?php if ($isGuest): ?>
                        <input type="hidden" name="level" value="<?= (int) ($rank['level'] ?? 0) ?>">
                        <input
                            type="number"
                            value="<?= (int) ($rank['level'] ?? 0) ?>"
                            aria-label="Рівень системного рангу"
                            disabled
                        >
                    <?php else: ?>
                        <input
                            type="number"
                            name="level"
                            min="1"
                            step="1"
                            value="<?= (int) ($rank['level'] ?? 1) ?>"
                            aria-label="Рівень"
                            required
                        >
                    <?php endif; ?>

                    <button class="admin-rank-button" type="submit">
                        Зберегти
                    </button>
                </form>

                <div class="admin-rank-actions">
                    <?php if ($isActive && !$isGuest && !$isDefault): ?>
                        <form method="post" action="/Anabelka/admin/ranks/default">
                            <input type="hidden" name="rank_id" value="<?= $rankId ?>">
                            <button class="admin-rank-button" type="submit">
                                Зробити рангом нових користувачів
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if (!$isGuest): ?>
                        <form method="post" action="/Anabelka/admin/ranks/toggle">
                            <input type="hidden" name="rank_id" value="<?= $rankId ?>">
                            <button
                                class="admin-rank-button <?= $isActive ? 'is-danger' : '' ?>"
                                type="submit"
                            >
                                <?= $isActive ? 'Вимкнути' : 'Увімкнути' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</main>

</body>
</html>
