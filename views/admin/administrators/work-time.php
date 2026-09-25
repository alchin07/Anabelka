<?php
$report = is_array($report ?? null) ? $report : [];
$range = is_array($report['range'] ?? null)
    ? $report['range']
    : [];
$administrators = is_array($report['administrators'] ?? null)
    ? $report['administrators']
    : [];
$dailyByAdmin = is_array($report['daily_by_admin'] ?? null)
    ? $report['daily_by_admin']
    : [];
$period = (string) ($period ?? 'today');

$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$formatDuration = static function ($seconds) {
    $seconds = max(0, (int) $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    if ($hours > 0) {
        return $hours . ' год ' . $minutes . ' хв';
    }

    if ($minutes > 0) {
        return $minutes . ' хв';
    }

    return $seconds > 0 ? $seconds . ' с' : '0 хв';
};

$formatTime = static function ($value) {
    $value = trim((string) $value);

    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('d.m.Y H:i', $timestamp)
        : $value;
};

$periodLabels = [
    'today' => 'Сьогодні',
    'week' => '7 днів',
    'month' => 'Цей місяць'
];
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle ?? 'Робочий час') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-work-time.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-work-time-page">
    <section class="admin-work-time-hero">
        <div>
            <span>Адміністратори</span>
            <h2>Робочий час</h2>
            <p>
                Враховується активна робота по всій Анабельці:
                в адмін-панелі та на публічній частині сайту.
                Простій понад 5 хвилин не рахується.
            </p>
        </div>

        <a href="/Anabelka/admin/administrators">
            Адміністратори
        </a>
    </section>

    <nav class="admin-work-time-periods" aria-label="Період звіту">
        <?php foreach ($periodLabels as $key => $label): ?>
            <a
                href="/Anabelka/admin/work-time?period=<?= $escape($key) ?>"
                class="<?= $period === $key ? 'is-active' : '' ?>"
            >
                <?= $escape($label) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <section class="admin-work-time-range">
        <span>Період</span>
        <strong>
            <?= $escape($range['date_from'] ?? '') ?>
            —
            <?= $escape($range['date_to'] ?? '') ?>
        </strong>
    </section>

    <section class="admin-work-time-list">
        <?php foreach ($administrators as $admin): ?>
            <?php
            $adminId = (int) ($admin['admin_user_id'] ?? 0);
            $totalSeconds = (int) ($admin['total_seconds'] ?? 0);
            $adminSeconds = (int) ($admin['admin_seconds'] ?? 0);
            $publicSeconds = (int) ($admin['public_seconds'] ?? 0);
            $dailyRows = is_array($dailyByAdmin[$adminId] ?? null)
                ? $dailyByAdmin[$adminId]
                : [];
            ?>
            <article class="admin-work-time-card">
                <div class="admin-work-time-card-head">
                    <div>
                        <strong><?= $escape($admin['admin_name'] ?? '') ?></strong>
                        <span><?= $escape($admin['role_name'] ?? '') ?></span>
                        <small><?= $escape($admin['admin_email'] ?? '') ?></small>
                    </div>

                    <b><?= $escape($formatDuration($totalSeconds)) ?></b>
                </div>

                <div class="admin-work-time-metrics">
                    <div>
                        <span>Адмін-панель</span>
                        <strong><?= $escape($formatDuration($adminSeconds)) ?></strong>
                    </div>
                    <div>
                        <span>Сайт</span>
                        <strong><?= $escape($formatDuration($publicSeconds)) ?></strong>
                    </div>
                    <div>
                        <span>Сесій</span>
                        <strong><?= (int) ($admin['session_count'] ?? 0) ?></strong>
                    </div>
                    <div>
                        <span>Активних днів</span>
                        <strong><?= (int) ($admin['active_days'] ?? 0) ?></strong>
                    </div>
                </div>

                <div class="admin-work-time-boundaries">
                    <span>
                        Перша активність:
                        <b><?= $escape($formatTime($admin['first_activity_at'] ?? '')) ?></b>
                    </span>
                    <span>
                        Остання активність:
                        <b><?= $escape($formatTime($admin['last_activity_at'] ?? '')) ?></b>
                    </span>
                </div>

                <?php if (!empty($dailyRows)): ?>
                    <details class="admin-work-time-days">
                        <summary>
                            По днях
                            <span><?= count($dailyRows) ?></span>
                        </summary>

                        <div class="admin-work-time-day-list">
                            <?php foreach ($dailyRows as $day): ?>
                                <div class="admin-work-time-day">
                                    <strong><?= $escape($day['work_date'] ?? '') ?></strong>
                                    <span>
                                        Разом:
                                        <?= $escape($formatDuration($day['total_seconds'] ?? 0)) ?>
                                    </span>
                                    <span>
                                        Адмін:
                                        <?= $escape($formatDuration($day['admin_seconds'] ?? 0)) ?>
                                    </span>
                                    <span>
                                        Сайт:
                                        <?= $escape($formatDuration($day['public_seconds'] ?? 0)) ?>
                                    </span>
                                    <span>
                                        Сесій:
                                        <?= (int) ($day['session_count'] ?? 0) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>

    <p class="admin-work-time-note">
        Це оціночний активний час у браузері, а не юридичний табель робочого часу.
        Якщо сторінка прихована або адміністратор не проявляє активності понад
        5 хвилин, час не додається.
    </p>
</main>

</body>
</html>
