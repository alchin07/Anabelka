<?php
$users = is_array($users ?? null) ? $users : [];
$ranks = is_array($ranks ?? null) ? $ranks : [];
$history = is_array($history ?? null) ? $history : [];
$summary = is_array($summary ?? null) ? $summary : [];
$filters = is_array($filters ?? null) ? $filters : [];
$returnQuery = http_build_query([
    'q' => $filters['q'] ?? '',
    'rank_id' => $filters['rank_id'] ?? 0,
    'status' => $filters['status'] ?? '',
    'page' => $page ?? 1
]);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Користувачі') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-users.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-users-page">
    <section class="admin-users-summary" aria-label="Статистика користувачів">
        <div>
            <span>Усього користувачів</span>
            <strong><?= (int) ($summary['total'] ?? 0) ?></strong>
        </div>
        <div>
            <span>Активні</span>
            <strong><?= (int) ($summary['active'] ?? 0) ?></strong>
        </div>
        <div>
            <span>Неактивні</span>
            <strong><?= (int) ($summary['inactive'] ?? 0) ?></strong>
        </div>
    </section>

    <?php if (!empty($message)): ?>
        <div class="admin-users-message is-success">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="admin-users-message is-error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <section class="admin-users-panel">
        <div class="admin-users-panel-head">
            <div>
                <h2>Користувачі та ранги</h2>
                <p>Пошук, поточний ранг і безпечна зміна рангу користувача.</p>
            </div>
            <span class="admin-users-total">
                Знайдено: <?= (int) ($total ?? 0) ?>
            </span>
        </div>

        <form class="admin-users-filters" method="get" action="/Anabelka/admin/users">
            <label>
                <span>Ім’я або email</span>
                <input
                    type="search"
                    name="q"
                    value="<?= htmlspecialchars($filters['q'] ?? '') ?>"
                    placeholder="Пошук користувача"
                >
            </label>

            <label>
                <span>Ранг</span>
                <select name="rank_id">
                    <option value="0">Усі ранги</option>
                    <?php foreach ($ranks as $rank): ?>
                        <option
                            value="<?= (int) ($rank['id'] ?? 0) ?>"
                            <?= (int) ($filters['rank_id'] ?? 0) === (int) ($rank['id'] ?? 0) ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars($rank['name'] ?? '') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Статус</span>
                <select name="status">
                    <option value="">Усі</option>
                    <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Активні</option>
                    <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Неактивні</option>
                </select>
            </label>

            <div class="admin-users-filter-actions">
                <button type="submit">Застосувати</button>
                <a href="/Anabelka/admin/users">Скинути</a>
            </div>
        </form>

        <?php if (empty($users)): ?>
            <div class="admin-users-empty">Користувачів за цими умовами не знайдено.</div>
        <?php else: ?>
            <div class="admin-users-list">
                <?php foreach ($users as $user): ?>
                    <?php
                    $userId = (int) ($user['id'] ?? 0);
                    $isActive = !empty($user['is_active']);
                    ?>
                    <article class="admin-user-card">
                        <div class="admin-user-main">
                            <div class="admin-user-identity">
                                <strong><?= htmlspecialchars($user['name'] ?? '') ?></strong>
                                <span><?= htmlspecialchars($user['email'] ?? '') ?></span>
                                <small>ID: <?= $userId ?></small>
                            </div>

                            <div class="admin-user-statuses">
                                <span class="admin-user-rank">
                                    <?= htmlspecialchars($user['rank_name'] ?? '') ?>
                                </span>
                                <span class="admin-user-status <?= $isActive ? 'is-active' : 'is-inactive' ?>">
                                    <?= $isActive ? 'Активний' : 'Неактивний' ?>
                                </span>
                            </div>
                        </div>

                        <form
                            class="admin-user-rank-form"
                            method="post"
                            action="/Anabelka/admin/users/rank"
                            onsubmit="return confirm('Змінити ранг цього користувача?');"
                        >
                            <input type="hidden" name="user_id" value="<?= $userId ?>">
                            <input
                                type="hidden"
                                name="return_query"
                                value="<?= htmlspecialchars($returnQuery, ENT_QUOTES, 'UTF-8') ?>"
                            >

                            <label>
                                <span>Новий ранг</span>
                                <select name="rank_id" required>
                                    <?php foreach ($ranks as $rank): ?>
                                        <option
                                            value="<?= (int) ($rank['id'] ?? 0) ?>"
                                            <?= (int) ($user['rank_id'] ?? 0) === (int) ($rank['id'] ?? 0) ? 'selected' : '' ?>
                                        >
                                            <?= htmlspecialchars($rank['name'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label class="admin-user-note">
                                <span>Причина / примітка</span>
                                <input
                                    type="text"
                                    name="note"
                                    maxlength="255"
                                    placeholder="Необов’язково"
                                >
                            </label>

                            <button type="submit">Змінити ранг</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (($pages ?? 1) > 1): ?>
            <nav class="admin-users-pagination" aria-label="Сторінки користувачів">
                <?php for ($number = 1; $number <= (int) $pages; $number++): ?>
                    <?php
                    $pageQuery = $filters;
                    $pageQuery['page'] = $number;
                    ?>
                    <a
                        href="/Anabelka/admin/users?<?= htmlspecialchars(http_build_query($pageQuery)) ?>"
                        class="<?= $number === (int) ($page ?? 1) ? 'is-current' : '' ?>"
                    ><?= $number ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    </section>

    <section class="admin-users-panel admin-rank-history">
        <div class="admin-users-panel-head">
            <div>
                <h2>Історія зміни рангів</h2>
                <p>Останні 100 змін. Історія не видаляється при наступній зміні рангу.</p>
            </div>
        </div>

        <?php if (empty($history)): ?>
            <div class="admin-users-empty">Змін рангів ще не було.</div>
        <?php else: ?>
            <div class="admin-rank-history-list">
                <?php foreach ($history as $item): ?>
                    <article class="admin-rank-history-item">
                        <div>
                            <strong><?= htmlspecialchars($item['user_name'] ?? '') ?></strong>
                            <span><?= htmlspecialchars($item['user_email'] ?? '') ?></span>
                        </div>
                        <div class="admin-rank-history-change">
                            <span><?= htmlspecialchars($item['old_rank_name'] ?? '—') ?></span>
                            <b>→</b>
                            <strong><?= htmlspecialchars($item['new_rank_name'] ?? '') ?></strong>
                        </div>
                        <div class="admin-rank-history-meta">
                            <time><?= htmlspecialchars($item['created_at'] ?? '') ?></time>
                            <?php if (!empty($item['changed_by_name'])): ?>
                                <span>Змінив: <?= htmlspecialchars($item['changed_by_name']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($item['note'])): ?>
                                <span>Примітка: <?= htmlspecialchars($item['note']) ?></span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
