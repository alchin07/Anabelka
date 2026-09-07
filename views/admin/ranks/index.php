<?php
$ranks = is_array($ranks ?? null) ? $ranks : [];
$summary = is_array($summary ?? null) ? $summary : [];
$defaultRank = is_array($defaultRank ?? null) ? $defaultRank : null;
$escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$orderableRankIds = [];
foreach ($ranks as $rankItem) {
    if (($rankItem['slug'] ?? '') === 'guest') {
        continue;
    }
    $orderableRankIds[] = (int) ($rankItem['id'] ?? 0);
}

$firstOrderableId = $orderableRankIds[0] ?? 0;
$lastOrderableId = !empty($orderableRankIds)
    ? $orderableRankIds[count($orderableRankIds) - 1]
    : 0;
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle ?? 'Адмін-панель · Ранги') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-ranks.css?v=5">
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

    <form
        class="admin-rank-create"
        method="post"
        action="/Anabelka/admin/ranks/create"
        autocomplete="off"
    >
        <div class="admin-rank-create-field">
            <input
                type="text"
                name="rank_name"
                maxlength="100"
                placeholder="Назва нового рангу"
                autocomplete="off"
                autocorrect="off"
                spellcheck="false"
                required
            >
            <small>Новий ранг додається в кінець списку. Порядок змінюється стрілками ↑ ↓.</small>
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
            $canMoveUp = !$isGuest && $rankId !== $firstOrderableId;
            $canMoveDown = !$isGuest && $rankId !== $lastOrderableId;
            ?>
            <article class="admin-rank-card">
                <div class="admin-rank-card-head">
                    <div>
                        <strong><?= $escape($rank['name'] ?? '') ?></strong>
                        <div class="admin-rank-meta">
                            <span>slug: <?= $escape($rank['slug'] ?? '') ?></span>
                            <span>Користувачів: <?= (int) ($rank['user_count'] ?? 0) ?></span>
                            <span>Товарів із ціною: <?= (int) ($rank['priced_product_count'] ?? 0) ?></span>
                            <?php if ($isGuest): ?>
                                <span>Системний ранг</span>
                            <?php endif; ?>
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
                    <button class="admin-rank-button" type="submit">
                        Зберегти
                    </button>
                </form>

                <div class="admin-rank-actions">
                    <?php if (!$isGuest): ?>
                        <div class="admin-rank-order-actions" aria-label="Змінити порядок рангу">
                            <form method="post" action="/Anabelka/admin/ranks/move">
                                <input type="hidden" name="rank_id" value="<?= $rankId ?>">
                                <input type="hidden" name="direction" value="up">
                                <button
                                    class="admin-rank-order-button"
                                    type="submit"
                                    aria-label="Перемістити ранг вище"
                                    title="Перемістити вище"
                                    <?= $canMoveUp ? '' : 'disabled' ?>
                                >↑</button>
                            </form>
                            <form method="post" action="/Anabelka/admin/ranks/move">
                                <input type="hidden" name="rank_id" value="<?= $rankId ?>">
                                <input type="hidden" name="direction" value="down">
                                <button
                                    class="admin-rank-order-button"
                                    type="submit"
                                    aria-label="Перемістити ранг нижче"
                                    title="Перемістити нижче"
                                    <?= $canMoveDown ? '' : 'disabled' ?>
                                >↓</button>
                            </form>
                        </div>
                    <?php endif; ?>

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
