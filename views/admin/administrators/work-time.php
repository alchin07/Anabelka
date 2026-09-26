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
$csrfToken = (string) ($csrfToken ?? '');
$flash = is_array($flash ?? null) ? $flash : null;

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

$currencySymbols = [
    'UAH' => '₴',
    'EUR' => '€',
    'USD' => '$',
    'PLN' => 'zł'
];

$formatMoney = static function ($minor, $currency) use ($currencySymbols) {
    $minor = max(0, (int) $minor);
    $currency = strtoupper(trim((string) $currency));
    $symbol = $currencySymbols[$currency] ?? $currency;

    return number_format(
        $minor / 100,
        2,
        ',',
        ' '
    ) . ' ' . $symbol;
};

$periodLabels = [
    'today' => 'Сьогодні',
    'week' => '7 днів',
    'month' => 'Цей місяць'
];

$payoutLabels = [
    'one_time' => 'Разова виплата',
    'weekly' => 'Щотижнева виплата',
    'monthly' => 'Щомісячна виплата'
];
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle ?? 'Робочий час') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-work-time.css?v=3">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-work-time-page">
    <section class="admin-work-time-hero">
        <div>
            <span>Адміністратори</span>
            <h2>Робочий час і нарахування</h2>
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

    <?php if ($flash): ?>
        <div
            class="admin-work-time-message <?= ($flash['type'] ?? '') === 'error' ? 'is-error' : 'is-success' ?>"
            role="status"
        >
            <?= $escape($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

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
        <div>
            <span>Період звіту робочого часу</span>
            <strong>
                <?= $escape($range['date_from'] ?? '') ?>
                —
                <?= $escape($range['date_to'] ?? '') ?>
            </strong>
        </div>
        <small>
            Нарахування нижче рахуються за періодом договору,
            незалежно від цього фільтра.
        </small>
    </section>

    <section class="admin-work-time-list">
        <?php foreach ($administrators as $admin): ?>
            <?php
            $adminId = (int) ($admin['admin_user_id'] ?? 0);
            $totalSeconds = (int) ($admin['total_seconds'] ?? 0);
            $adminSeconds = (int) ($admin['admin_seconds'] ?? 0);
            $publicSeconds = (int) ($admin['public_seconds'] ?? 0);
            $androidSeconds = (int) ($admin['android_seconds'] ?? 0);
            $iosSeconds = (int) ($admin['ios_seconds'] ?? 0);
            $dailyRows = is_array($dailyByAdmin[$adminId] ?? null)
                ? $dailyByAdmin[$adminId]
                : [];
            $agreement = is_array($admin['compensation'] ?? null)
                ? $admin['compensation']
                : [];
            $earnings = is_array($admin['earnings'] ?? null)
                ? $admin['earnings']
                : [];
            $currency = strtoupper((string) (
                $agreement['currency'] ?? 'UAH'
            ));
            $payoutType = (string) (
                $agreement['payout_type'] ?? 'monthly'
            );
            $hourlyRateMinor = max(
                0,
                (int) ($agreement['hourly_rate_minor'] ?? 0)
            );
            $hourlyRateInput = number_format(
                $hourlyRateMinor / 100,
                2,
                '.',
                ''
            );
            $earningSeconds = max(
                0,
                (int) ($earnings['seconds'] ?? 0)
            );
            $earningAmountMinor = max(
                0,
                (int) ($earnings['amount_minor'] ?? 0)
            );
            $earningFrom = trim((string) (
                $earnings['date_from'] ?? ''
            ));
            $earningTo = trim((string) (
                $earnings['date_to'] ?? ''
            ));
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
                        <span>Android</span>
                        <strong><?= $escape($formatDuration($androidSeconds)) ?></strong>
                    </div>
                    <div>
                        <span>iPhone</span>
                        <strong><?= $escape($formatDuration($iosSeconds)) ?></strong>
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

                <section class="admin-work-compensation-summary">
                    <div class="admin-work-compensation-earned">
                        <span>Нараховано</span>
                        <strong>
                            <?= $escape(
                                $formatMoney(
                                    $earningAmountMinor,
                                    $currency
                                )
                            ) ?>
                        </strong>
                        <small>
                            <?= $escape(
                                $payoutLabels[$payoutType]
                                    ?? 'Щомісячна виплата'
                            ) ?>
                        </small>
                    </div>

                    <div class="admin-work-compensation-meta">
                        <span>
                            <b>Час до оплати:</b>
                            <?= $escape($formatDuration($earningSeconds)) ?>
                        </span>
                        <span>
                            <b>Ставка:</b>
                            <?= $escape(
                                $formatMoney(
                                    $hourlyRateMinor,
                                    $currency
                                )
                            ) ?>/год
                        </span>
                        <span>
                            <b>Період нарахування:</b>
                            <?= $earningFrom !== '' ? $escape($earningFrom) : '—' ?>
                            —
                            <?= $earningTo !== '' ? $escape($earningTo) : '—' ?>
                        </span>
                    </div>
                </section>

                <details class="admin-work-compensation">
                    <summary>
                        Умови оплати
                        <span>
                            <?= $hourlyRateMinor > 0
                                ? $escape($payoutLabels[$payoutType] ?? '')
                                : 'Ставку не задано' ?>
                        </span>
                    </summary>

                    <form
                        class="admin-work-compensation-form"
                        method="post"
                        action="/Anabelka/admin/work-time/compensation"
                        data-work-compensation-form
                    >
                        <input
                            type="hidden"
                            name="_csrf"
                            value="<?= $escape($csrfToken) ?>"
                        >
                        <input
                            type="hidden"
                            name="admin_user_id"
                            value="<?= $adminId ?>"
                        >
                        <input
                            type="hidden"
                            name="return_period"
                            value="<?= $escape($period) ?>"
                        >

                        <label>
                            <span>Ставка за годину</span>
                            <input
                                type="number"
                                name="hourly_rate"
                                min="0"
                                max="1000000"
                                step="0.01"
                                inputmode="decimal"
                                value="<?= $escape($hourlyRateInput) ?>"
                                required
                            >
                        </label>

                        <label>
                            <span>Валюта</span>
                            <select name="currency">
                                <?php foreach (['UAH', 'EUR', 'USD', 'PLN'] as $code): ?>
                                    <option
                                        value="<?= $escape($code) ?>"
                                        <?= $currency === $code ? 'selected' : '' ?>
                                    >
                                        <?= $escape($code) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            <span>Тип виплати</span>
                            <select
                                name="payout_type"
                                data-work-payout-type
                            >
                                <?php foreach ($payoutLabels as $key => $label): ?>
                                    <option
                                        value="<?= $escape($key) ?>"
                                        <?= $payoutType === $key ? 'selected' : '' ?>
                                    >
                                        <?= $escape($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <div
                            class="admin-work-one-time-fields"
                            data-work-one-time-fields
                            <?= $payoutType === 'one_time' ? '' : 'hidden' ?>
                        >
                            <label>
                                <span>Період від</span>
                                <input
                                    type="date"
                                    name="one_time_from"
                                    value="<?= $escape($agreement['one_time_from'] ?? '') ?>"
                                >
                            </label>
                            <label>
                                <span>Період до</span>
                                <input
                                    type="date"
                                    name="one_time_to"
                                    value="<?= $escape($agreement['one_time_to'] ?? '') ?>"
                                >
                            </label>
                        </div>

                        <button type="submit">
                            Зберегти умови
                        </button>
                    </form>
                </details>

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
                                        Android:
                                        <?= $escape($formatDuration($day['android_seconds'] ?? 0)) ?>
                                    </span>
                                    <span>
                                        iPhone:
                                        <?= $escape($formatDuration($day['ios_seconds'] ?? 0)) ?>
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
        Це оціночний активний час у браузері, а не юридичний табель або
        бухгалтерський документ. Суми є автоматичним розрахунком за
        погодинною ставкою та фактично накопиченим активним часом.
    </p>
</main>

<script
    src="/Anabelka/js/admin-work-time-compensation.js?v=1"
    defer
></script>
</body>
</html>
