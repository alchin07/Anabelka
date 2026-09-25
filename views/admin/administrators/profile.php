<?php
$admin = is_array($admin ?? null) ? $admin : [];
$csrfToken = (string) ($csrfToken ?? '');
$message = trim((string) ($message ?? ''));
$error = trim((string) ($error ?? ''));
$canCustomizeNotificationBadge = !empty($canCustomizeNotificationBadge);
$notificationBadgeOptions = is_array($notificationBadgeOptions ?? null)
    ? $notificationBadgeOptions
    : [];
$workContract = is_array($workContract ?? null)
    ? $workContract
    : ['has_contract' => false];
$workAgreement = is_array($workContract['agreement'] ?? null)
    ? $workContract['agreement']
    : [];
$workEarnings = is_array($workContract['earnings'] ?? null)
    ? $workContract['earnings']
    : [];
$workSummary = is_array($workContract['work'] ?? null)
    ? $workContract['work']
    : [];
$workDaily = is_array($workContract['daily'] ?? null)
    ? $workContract['daily']
    : [];

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

    return number_format($minor / 100, 2, ',', ' ') . ' ' . $symbol;
};

$formatDateTime = static function ($value) {
    $value = trim((string) $value);

    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('d.m.Y H:i', $timestamp)
        : $value;
};

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
    <title><?= htmlspecialchars($pageTitle ?? 'Адмін-панель · Профіль') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-profile.css?v=3">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-profile-page">
    <section class="admin-profile-hero">
        <div>
            <span>Безпека акаунта</span>
            <h2>Мій профіль</h2>
            <p>Особисті дані, робочий контракт, сповіщення та пароль поточного адміністратора.</p>
        </div>
        <div class="admin-profile-role">
            <small>Роль</small>
            <strong><?= htmlspecialchars((string) ($admin['role_name'] ?? '')) ?></strong>
        </div>
    </section>

    <?php if ($message !== ''): ?>
        <div class="admin-profile-message is-success">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="admin-profile-message is-error">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($workContract['has_contract'])): ?>
        <?php
        $contractCurrency = strtoupper((string) (
            $workAgreement['currency'] ?? 'UAH'
        ));
        $contractPayoutType = (string) (
            $workAgreement['payout_type'] ?? 'monthly'
        );
        $contractFrom = trim((string) (
            $workEarnings['date_from'] ?? ''
        ));
        $contractTo = trim((string) (
            $workEarnings['date_to'] ?? ''
        ));
        ?>
        <section class="admin-profile-card admin-profile-work-contract">
            <div class="admin-profile-card-head">
                <div>
                    <span class="admin-profile-contract-kicker">Робота</span>
                    <h3>Мій робочий контракт</h3>
                    <p>
                        Умови оплати та фактичний активний час за поточний
                        розрахунковий період.
                    </p>
                </div>
                <div class="admin-profile-contract-amount">
                    <small>Нараховано</small>
                    <strong>
                        <?= htmlspecialchars(
                            $formatMoney(
                                $workEarnings['amount_minor'] ?? 0,
                                $contractCurrency
                            )
                        ) ?>
                    </strong>
                </div>
            </div>

            <div class="admin-profile-contract-terms">
                <div>
                    <span>Ставка</span>
                    <strong>
                        <?= htmlspecialchars(
                            $formatMoney(
                                $workAgreement['hourly_rate_minor'] ?? 0,
                                $contractCurrency
                            )
                        ) ?>/год
                    </strong>
                </div>
                <div>
                    <span>Тип виплати</span>
                    <strong>
                        <?= htmlspecialchars(
                            $payoutLabels[$contractPayoutType]
                                ?? 'Щомісячна виплата'
                        ) ?>
                    </strong>
                </div>
                <div>
                    <span>Період розрахунку</span>
                    <strong>
                        <?= $contractFrom !== ''
                            ? htmlspecialchars($contractFrom)
                            : '—' ?>
                        —
                        <?= $contractTo !== ''
                            ? htmlspecialchars($contractTo)
                            : '—' ?>
                    </strong>
                </div>
                <div>
                    <span>Валюта</span>
                    <strong><?= htmlspecialchars($contractCurrency) ?></strong>
                </div>
            </div>

            <div class="admin-profile-contract-hours">
                <div>
                    <span>Разом</span>
                    <strong><?= htmlspecialchars(
                        $formatDuration($workSummary['total_seconds'] ?? 0)
                    ) ?></strong>
                </div>
                <div>
                    <span>Адмін-панель</span>
                    <strong><?= htmlspecialchars(
                        $formatDuration($workSummary['admin_seconds'] ?? 0)
                    ) ?></strong>
                </div>
                <div>
                    <span>Сайт</span>
                    <strong><?= htmlspecialchars(
                        $formatDuration($workSummary['public_seconds'] ?? 0)
                    ) ?></strong>
                </div>
                <div>
                    <span>Сесій</span>
                    <strong><?= (int) ($workSummary['session_count'] ?? 0) ?></strong>
                </div>
                <div>
                    <span>Активних днів</span>
                    <strong><?= (int) ($workSummary['active_days'] ?? 0) ?></strong>
                </div>
            </div>

            <div class="admin-profile-contract-boundaries">
                <span>
                    Перша активність:
                    <b><?= htmlspecialchars(
                        $formatDateTime(
                            $workSummary['first_activity_at'] ?? ''
                        )
                    ) ?></b>
                </span>
                <span>
                    Остання активність:
                    <b><?= htmlspecialchars(
                        $formatDateTime(
                            $workSummary['last_activity_at'] ?? ''
                        )
                    ) ?></b>
                </span>
                <span>
                    Умови оновлено:
                    <b><?= htmlspecialchars(
                        $formatDateTime(
                            $workAgreement['updated_at'] ?? ''
                        )
                    ) ?></b>
                </span>
            </div>

            <?php if (!empty($workDaily)): ?>
                <details class="admin-profile-contract-days">
                    <summary>
                        Робочий час по днях
                        <span><?= count($workDaily) ?></span>
                    </summary>

                    <div class="admin-profile-contract-day-list">
                        <?php foreach ($workDaily as $day): ?>
                            <div class="admin-profile-contract-day">
                                <strong>
                                    <?= htmlspecialchars(
                                        (string) ($day['work_date'] ?? '')
                                    ) ?>
                                </strong>
                                <span>
                                    Разом:
                                    <?= htmlspecialchars(
                                        $formatDuration(
                                            $day['total_seconds'] ?? 0
                                        )
                                    ) ?>
                                </span>
                                <span>
                                    Адмін:
                                    <?= htmlspecialchars(
                                        $formatDuration(
                                            $day['admin_seconds'] ?? 0
                                        )
                                    ) ?>
                                </span>
                                <span>
                                    Сайт:
                                    <?= htmlspecialchars(
                                        $formatDuration(
                                            $day['public_seconds'] ?? 0
                                        )
                                    ) ?>
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

            <p class="admin-profile-contract-note">
                Умови контракту змінюються лише Розробником або Власником.
                У цьому профілі вони доступні тільки для перегляду.
            </p>
        </section>
    <?php endif; ?>

    <section class="admin-profile-card">
        <div class="admin-profile-card-head">
            <h3>Дані профілю</h3>
            <p>Для зміни імені або email підтвердьте дію поточним паролем.</p>
        </div>

        <form method="post" action="/Anabelka/admin/profile" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <label>
                <span>Ім’я</span>
                <input
                    type="text"
                    name="name"
                    maxlength="120"
                    value="<?= htmlspecialchars((string) ($admin['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="name"
                    required
                >
            </label>

            <label>
                <span>Email для входу</span>
                <input
                    type="email"
                    name="email"
                    maxlength="190"
                    value="<?= htmlspecialchars((string) ($admin['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="username"
                    inputmode="email"
                    required
                >
            </label>

            <label>
                <span>Поточний пароль</span>
                <input
                    type="password"
                    name="current_password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <button type="submit">Зберегти профіль</button>
        </form>
    </section>

    <?php if ($canCustomizeNotificationBadge): ?>
        <section class="admin-profile-card admin-profile-badge-settings">
            <div class="admin-profile-card-head">
                <h3>Мій бейдж сповіщень</h3>
                <p>
                    Оберіть, які події входять у загальне число над іконкою
                    адмін-панелі. Внутрішні лічильники розділів не змінюються.
                </p>
            </div>

            <form
                method="post"
                action="/Anabelka/admin/profile/notification-badge"
                class="admin-profile-badge-form"
            >
                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
                >

                <div class="admin-profile-badge-options">
                    <?php foreach ($notificationBadgeOptions as $option): ?>
                        <label class="admin-profile-badge-option">
                            <input
                                type="checkbox"
                                name="badge_channels[]"
                                value="<?= htmlspecialchars((string) ($option['key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                <?= !empty($option['enabled']) ? 'checked' : '' ?>
                            >
                            <span>
                                <?= htmlspecialchars((string) ($option['label'] ?? '')) ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <small class="admin-profile-badge-note">
                    Якщо нічого не вибрати, загальний бейдж не показуватиметься.
                </small>

                <button type="submit">Зберегти бейдж</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="admin-profile-card">
        <div class="admin-profile-card-head">
            <h3>Змінити пароль</h3>
            <p>Новий пароль має містити щонайменше 10 символів.</p>
        </div>

        <form method="post" action="/Anabelka/admin/profile/password" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <label>
                <span>Поточний пароль</span>
                <input
                    type="password"
                    name="current_password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <label>
                <span>Новий пароль</span>
                <input
                    type="password"
                    name="new_password"
                    minlength="10"
                    autocomplete="new-password"
                    required
                >
            </label>

            <label>
                <span>Повторіть новий пароль</span>
                <input
                    type="password"
                    name="new_password_confirmation"
                    minlength="10"
                    autocomplete="new-password"
                    required
                >
            </label>

            <button type="submit">Змінити пароль</button>
        </form>
    </section>

    <section class="admin-profile-meta">
        <div>
            <span>Останній вхід</span>
            <strong><?= htmlspecialchars((string) ($admin['last_login_at'] ?? '—')) ?></strong>
        </div>
        <div>
            <span>Акаунт створено</span>
            <strong><?= htmlspecialchars((string) ($admin['created_at'] ?? '—')) ?></strong>
        </div>
    </section>
</main>

</body>
</html>
