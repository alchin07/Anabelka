<?php
PublicInterfaceTranslator::seed();
CustomerAccountInterfaceTranslator::seed();
CustomerRankRequestInterfaceTranslator::seed();
CustomerNotificationInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
$pageTitle = Translator::t('public.account.title', 'Мій акаунт');
$user = is_array($user ?? null) ? $user : [];
$addresses = is_array($addresses ?? null) ? $addresses : [];
$rankRequestState = is_array($rankRequestState ?? null)
    ? $rankRequestState
    : [];
$latestRankRequest = is_array($rankRequestState['latest'] ?? null)
    ? $rankRequestState['latest']
    : null;
$notifications = is_array($notifications ?? null) ? $notifications : [];
$notificationUnreadCount = max(0, (int) ($notificationUnreadCount ?? 0));
$csrfToken = (string) ($csrfToken ?? '');
$message = trim((string) ($message ?? ''));
$error = trim((string) ($error ?? ''));
$adultEnabled = !empty($user['adult_section_access']);
$adultConfirmed = !empty($user['adult_confirmed']);
$automaticAdultAccess = !empty($user['automatic_adult_access']);
$knownUnderage = !empty($user['is_known_underage']);
$rankName = UserRankTranslator::localizeName(
    (int) ($user['rank_id'] ?? 0),
    (string) ($user['rank_name'] ?? ''),
    (string) ($currentLanguage['code'] ?? Language::SOURCE_CODE)
);
$rankRequestStatus = (string) ($latestRankRequest['status'] ?? '');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/account.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/account-adult.css?v=1">
    <link rel="stylesheet" href="/Anabelka/css/account-rank-request.css?v=1">
    <link rel="stylesheet" href="/Anabelka/css/account-notifications.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="account-page">
    <section class="account-hero">
        <div>
            <span class="account-kicker">Анабелька</span>
            <h2><?= htmlspecialchars(
                Translator::t('public.account.heading', 'Мій акаунт')
            ) ?></h2>
            <p><?= htmlspecialchars((string) ($user['name'] ?? '')) ?></p>
        </div>

        <div class="account-hero-actions">
            <span class="account-rank">
                <?= htmlspecialchars(
                    Translator::t('public.account.rank', 'Мій ранг')
                ) ?>: <strong><?= htmlspecialchars($rankName) ?></strong>
            </span>

            <form method="post" action="/Anabelka/logout">
                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
                >
                <button class="account-logout" type="submit">
                    <?= htmlspecialchars(
                        Translator::t('header.logout', 'Вийти')
                    ) ?>
                </button>
            </form>
        </div>
    </section>

    <?php if ($message !== ''): ?>
        <div class="account-message is-success" role="status">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="account-message is-error" role="alert">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <section class="account-notifications" aria-label="Notifications">
        <div class="account-notifications-head">
            <h3><?= htmlspecialchars(
                Translator::t('public.notifications.title', 'Сповіщення')
            ) ?></h3>
            <?php if ($notificationUnreadCount > 0): ?>
                <span class="account-notifications-count">
                    <?= htmlspecialchars(
                        Translator::t('public.notifications.unread', 'Нових')
                    ) ?>: <?= $notificationUnreadCount ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="account-notifications-empty">
                <?= htmlspecialchars(
                    Translator::t(
                        'public.notifications.empty',
                        'Нових сповіщень поки немає.'
                    )
                ) ?>
            </div>
        <?php else: ?>
            <div class="account-notifications-list">
                <?php foreach ($notifications as $notification): ?>
                    <?php
                    $notificationType = (string) ($notification['type'] ?? '');
                    $notificationData = is_array($notification['data'] ?? null)
                        ? $notification['data']
                        : [];
                    $notificationTitle = $notificationType;
                    $notificationText = '';

                    if ($notificationType === 'rank_request_approved') {
                        $notificationTitle = Translator::t(
                            'public.notifications.rank_approved_title',
                            'Ранг підвищено'
                        );
                        $notificationText = str_replace(
                            '{rank}',
                            (string) ($notificationData['rank_name'] ?? ''),
                            Translator::t(
                                'public.notifications.rank_approved_text',
                                'Ваш запит схвалено. Новий ранг: {rank}.'
                            )
                        );
                    } elseif ($notificationType === 'rank_request_rejected') {
                        $notificationTitle = Translator::t(
                            'public.notifications.rank_rejected_title',
                            'Запит на підвищення відхилено'
                        );
                        $notificationText = Translator::t(
                            'public.notifications.rank_rejected_text',
                            'Адміністратор відхилив запит на підвищення рангу.'
                        );
                    }
                    ?>
                    <article class="account-notification <?= empty($notification['is_read']) ? 'is-unread' : '' ?>">
                        <div class="account-notification-head">
                            <strong><?= htmlspecialchars($notificationTitle) ?></strong>
                            <time><?= htmlspecialchars((string) ($notification['created_at'] ?? '')) ?></time>
                        </div>

                        <?php if ($notificationText !== ''): ?>
                            <p><?= htmlspecialchars($notificationText) ?></p>
                        <?php endif; ?>

                        <?php if (!empty($notificationData['admin_note'])): ?>
                            <div class="account-notification-note">
                                <strong><?= htmlspecialchars(
                                    Translator::t(
                                        'public.notifications.admin_note',
                                        'Примітка адміністратора'
                                    )
                                ) ?>:</strong>
                                <?= htmlspecialchars((string) $notificationData['admin_note']) ?>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="account-links" aria-label="Account shortcuts">
        <a href="/Anabelka/orders">
            <strong><?= htmlspecialchars(
                Translator::t('public.account.orders', 'Мої замовлення')
            ) ?></strong>
            <span>→</span>
        </a>
        <a href="/Anabelka/favorites">
            <strong><?= htmlspecialchars(
                Translator::t('public.account.favorites', 'Обране')
            ) ?></strong>
            <span>→</span>
        </a>
    </section>

    <section class="account-rank-request">
        <div class="account-rank-request-head">
            <div>
                <h3><?= htmlspecialchars(
                    Translator::t('public.rank_request.title', 'Підвищення рангу')
                ) ?></h3>
                <p><?= htmlspecialchars(
                    Translator::t(
                        'public.rank_request.hint',
                        'Надішліть запит адміністратору. Для розгляду мають бути заповнені телефон і хоча б одна адреса доставки.'
                    )
                ) ?></p>
            </div>

            <?php if ($rankRequestStatus === 'pending'): ?>
                <span class="account-rank-request-status is-pending">
                    <?= htmlspecialchars(Translator::t('public.rank_request.pending', 'Запит очікує на розгляд')) ?>
                </span>
            <?php elseif ($rankRequestStatus === 'approved'): ?>
                <span class="account-rank-request-status is-approved">
                    <?= htmlspecialchars(Translator::t('public.rank_request.approved', 'Запит схвалено')) ?>
                </span>
            <?php elseif ($rankRequestStatus === 'rejected'): ?>
                <span class="account-rank-request-status is-rejected">
                    <?= htmlspecialchars(Translator::t('public.rank_request.rejected', 'Запит відхилено')) ?>
                </span>
            <?php endif; ?>
        </div>

        <div class="account-rank-request-meta">
            <span>
                <?= htmlspecialchars(Translator::t('public.rank_request.current', 'Поточний ранг')) ?>:
                <strong><?= htmlspecialchars($rankName) ?></strong>
            </span>
            <?php if ($rankRequestStatus === 'approved' && !empty($latestRankRequest['approved_rank_name'])): ?>
                <span>
                    <?= htmlspecialchars(Translator::t('public.rank_request.new_rank', 'Новий ранг')) ?>:
                    <strong><?= htmlspecialchars((string) $latestRankRequest['approved_rank_name']) ?></strong>
                </span>
            <?php endif; ?>
        </div>

        <div class="account-rank-request-requirements">
            <span class="<?= !empty($rankRequestState['has_phone']) ? 'is-ok' : 'is-missing' ?>">
                <?= !empty($rankRequestState['has_phone']) ? '✓ ' : '• ' ?>
                <?= htmlspecialchars(
                    !empty($rankRequestState['has_phone'])
                        ? Translator::t('public.account.phone', 'Телефон')
                        : Translator::t('public.rank_request.phone_missing', 'Додайте номер телефону')
                ) ?>
            </span>
            <span class="<?= !empty($rankRequestState['has_address']) ? 'is-ok' : 'is-missing' ?>">
                <?= !empty($rankRequestState['has_address']) ? '✓ ' : '• ' ?>
                <?= htmlspecialchars(
                    !empty($rankRequestState['has_address'])
                        ? Translator::t('public.account.addresses', 'Адреси доставки')
                        : Translator::t('public.rank_request.address_missing', 'Додайте адресу доставки')
                ) ?>
            </span>
        </div>

        <?php if (empty($rankRequestState['has_higher_rank'])): ?>
            <p class="account-rank-request-note">
                <?= htmlspecialchars(
                    Translator::t(
                        'public.rank_request.highest',
                        'Ви вже маєте найвищий доступний ранг'
                    )
                ) ?>
            </p>
        <?php elseif ($rankRequestStatus === 'rejected' && !empty($latestRankRequest['admin_note'])): ?>
            <p class="account-rank-request-note">
                <?= htmlspecialchars((string) $latestRankRequest['admin_note']) ?>
            </p>
        <?php endif; ?>

        <?php if (empty($rankRequestState['has_pending']) && !empty($rankRequestState['has_higher_rank'])): ?>
            <form method="post" action="/Anabelka/account/rank-request">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <button
                    type="submit"
                    <?= !empty($rankRequestState['can_request']) ? '' : 'disabled' ?>
                >
                    <?= htmlspecialchars(
                        Translator::t('public.rank_request.send', 'Надіслати запит на підвищення')
                    ) ?>
                </button>
            </form>
        <?php endif; ?>
    </section>

    <section class="account-grid">
        <article class="account-card">
            <div class="account-card-head">
                <h3><?= htmlspecialchars(
                    Translator::t('public.account.profile', 'Профіль')
                ) ?></h3>
                <p><?= htmlspecialchars(
                    Translator::t(
                        'public.account.edit_profile',
                        'Змінити ім’я, email або телефон'
                    )
                ) ?></p>
            </div>

            <form method="post" action="/Anabelka/account/profile" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <label>
                    <span><?= htmlspecialchars(
                        Translator::t('public.auth.name', 'Ім’я')
                    ) ?></span>
                    <input
                        type="text"
                        name="name"
                        maxlength="120"
                        value="<?= htmlspecialchars((string) ($user['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </label>

                <label>
                    <span>Email</span>
                    <input
                        type="email"
                        name="email"
                        maxlength="190"
                        value="<?= htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        required
                    >
                </label>

                <label>
                    <span><?= htmlspecialchars(
                        Translator::t('public.account.phone', 'Телефон')
                    ) ?></span>
                    <input
                        type="tel"
                        name="phone"
                        maxlength="40"
                        autocomplete="tel"
                        value="<?= htmlspecialchars((string) ($user['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    >
                </label>

                <label>
                    <span><?= htmlspecialchars(
                        Translator::t(
                            'public.account.current_password',
                            'Поточний пароль'
                        )
                    ) ?></span>
                    <input
                        type="password"
                        name="current_password"
                        autocomplete="current-password"
                        required
                    >
                </label>

                <button type="submit">
                    <?= htmlspecialchars(
                        Translator::t(
                            'public.account.save_profile',
                            'Зберегти дані'
                        )
                    ) ?>
                </button>
            </form>
        </article>

        <article class="account-card">
            <div class="account-card-head">
                <h3><?= htmlspecialchars(
                    Translator::t(
                        'public.account.change_password',
                        'Змінити пароль'
                    )
                ) ?></h3>
                <p><?= htmlspecialchars(
                    Translator::t(
                        'public.account.password_hint',
                        'Щонайменше 8 символів'
                    )
                ) ?></p>
            </div>

            <form method="post" action="/Anabelka/account/password" autocomplete="off">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <label>
                    <span><?= htmlspecialchars(
                        Translator::t(
                            'public.account.current_password',
                            'Поточний пароль'
                        )
                    ) ?></span>
                    <input
                        type="password"
                        name="current_password"
                        autocomplete="current-password"
                        required
                    >
                </label>

                <label>
                    <span><?= htmlspecialchars(
                        Translator::t(
                            'public.account.new_password',
                            'Новий пароль'
                        )
                    ) ?></span>
                    <input
                        type="password"
                        name="new_password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </label>

                <label>
                    <span><?= htmlspecialchars(
                        Translator::t(
                            'public.account.confirm_password',
                            'Повторіть новий пароль'
                        )
                    ) ?></span>
                    <input
                        type="password"
                        name="new_password_confirmation"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </label>

                <button type="submit">
                    <?= htmlspecialchars(
                        Translator::t(
                            'public.account.change_password',
                            'Змінити пароль'
                        )
                    ) ?>
                </button>
            </form>
        </article>
    </section>

    <section class="account-adult-section">
        <div class="account-adult-head">
            <div>
                <h3><?= htmlspecialchars(
                    Translator::t('public.account.adult_settings', 'Вік і товари 18+')
                ) ?></h3>
                <p><?= htmlspecialchars(
                    Translator::t(
                        'public.account.adult_privacy_hint',
                        'Дата народження не є обов’язковою. Для доступу до розділу 18+ достатньо підтвердити повноліття.'
                    )
                ) ?></p>
            </div>

            <span class="account-adult-status <?= $adultEnabled ? 'is-enabled' : '' ?>">
                <?= htmlspecialchars(
                    $knownUnderage
                        ? Translator::t('public.account.adult_status_blocked', '18+ недоступно')
                        : ($automaticAdultAccess
                            ? Translator::t('public.account.adult_status_rank', '18+ доступ за рангом')
                            : ($adultConfirmed
                                ? Translator::t('public.account.adult_status_confirmed', '18+ підтверджено')
                                : Translator::t('public.account.adult_status_off', '18+ не підтверджено')))
                ) ?>
            </span>
        </div>

        <?php if ($automaticAdultAccess && !$knownUnderage): ?>
            <p class="account-adult-note">
                <?= htmlspecialchars(
                    Translator::t(
                        'public.account.adult_vip_hint',
                        'Ваш ранг дає автоматичний доступ до розділу 18+. Дата народження та окреме підтвердження не потрібні.'
                    )
                ) ?>
            </p>
        <?php endif; ?>

        <form
            class="account-adult-form"
            method="post"
            action="/Anabelka/account/adult-preferences"
            autocomplete="off"
        >
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <label>
                <span><?= htmlspecialchars(
                    Translator::t('public.account.birth_date', 'Дата народження')
                ) ?></span>
                <input
                    type="date"
                    name="birth_date"
                    max="<?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8') ?>"
                    value="<?= htmlspecialchars((string) ($user['birth_date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                >
                <small><?= htmlspecialchars(
                    Translator::t(
                        'public.account.birth_optional',
                        'Необов’язково. Ви можете залишити це поле порожнім.'
                    )
                ) ?></small>
            </label>

            <label class="account-adult-toggle">
                <input
                    type="checkbox"
                    name="adult_confirmed"
                    value="1"
                    <?= $adultConfirmed ? 'checked' : '' ?>
                >
                <span class="account-adult-toggle-text">
                    <strong><?= htmlspecialchars(
                        Translator::t('public.account.adult_confirm', 'Мені вже виповнилося 18 років')
                    ) ?></strong>
                    <small><?= htmlspecialchars(
                        Translator::t(
                            'public.account.adult_confirm_hint',
                            'Підтвердження відкриває доступ до окремого розділу 18+ без обов’язкового збереження дати народження.'
                        )
                    ) ?></small>
                </span>
            </label>

            <label class="account-adult-toggle">
                <input
                    type="checkbox"
                    name="show_adult"
                    value="1"
                    <?= !empty($user['show_adult']) ? 'checked' : '' ?>
                >
                <span class="account-adult-toggle-text">
                    <strong><?= htmlspecialchars(
                        Translator::t('public.account.adult_search', 'Показувати товари 18+ у звичайному пошуку')
                    ) ?></strong>
                    <small><?= htmlspecialchars(
                        Translator::t(
                            'public.account.adult_search_hint',
                            'Увімкніть лише якщо хочете бачити 18+ товари разом зі звичайними результатами пошуку.'
                        )
                    ) ?></small>
                </span>
            </label>

            <label>
                <span><?= htmlspecialchars(
                    Translator::t('public.account.current_password', 'Поточний пароль')
                ) ?></span>
                <input
                    type="password"
                    name="current_password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <button type="submit">
                <?= htmlspecialchars(
                    Translator::t('public.account.adult_save', 'Зберегти налаштування 18+')
                ) ?>
            </button>
        </form>
    </section>

    <section class="account-address-section">
        <div class="account-address-head">
            <div>
                <h3><?= htmlspecialchars(
                    Translator::t('public.account.addresses', 'Адреси доставки')
                ) ?></h3>
                <p><?= htmlspecialchars(
                    Translator::t(
                        'public.account.addresses_hint',
                        'Основна адреса автоматично підставляється під час оформлення замовлення.'
                    )
                ) ?></p>
            </div>

            <details class="account-address-add">
                <summary><?= htmlspecialchars(
                    Translator::t('public.account.address_add', 'Додати адресу')
                ) ?></summary>

                <form method="post" action="/Anabelka/account/address/create" autocomplete="off">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="account-address-form-grid">
                        <label>
                            <span><?= htmlspecialchars(Translator::t('public.account.address_label', 'Назва адреси')) ?></span>
                            <input type="text" name="label" maxlength="80" placeholder="<?= htmlspecialchars(Translator::t('public.account.address_label_placeholder', 'Наприклад: Дім')) ?>">
                        </label>
                        <label>
                            <span><?= htmlspecialchars(Translator::t('public.account.address_country', 'Країна')) ?></span>
                            <input type="text" name="country" maxlength="120" required>
                        </label>
                        <label>
                            <span><?= htmlspecialchars(Translator::t('public.account.address_city', 'Місто')) ?></span>
                            <input type="text" name="city" maxlength="120" required>
                        </label>
                        <label>
                            <span><?= htmlspecialchars(Translator::t('public.account.address_postcode', 'Поштовий індекс')) ?></span>
                            <input type="text" name="postcode" maxlength="30" inputmode="text">
                        </label>
                        <label class="account-address-wide">
                            <span><?= htmlspecialchars(Translator::t('public.account.address_address', 'Адреса')) ?></span>
                            <input type="text" name="address" maxlength="255" required>
                        </label>
                    </div>
                    <label class="account-check-row">
                        <input type="checkbox" name="is_default" value="1" <?= empty($addresses) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars(Translator::t('public.account.address_make_default', 'Зробити основною')) ?></span>
                    </label>
                    <button type="submit"><?= htmlspecialchars(Translator::t('public.account.address_add', 'Додати адресу')) ?></button>
                </form>
            </details>
        </div>

        <?php if (empty($addresses)): ?>
            <div class="account-address-empty">
                <?= htmlspecialchars(Translator::t('public.account.address_empty', 'Збережених адрес поки немає.')) ?>
            </div>
        <?php else: ?>
            <div class="account-address-list">
                <?php foreach ($addresses as $address): ?>
                    <?php $isDefault = !empty($address['is_default']); ?>
                    <article class="account-address-card <?= $isDefault ? 'is-default' : '' ?>">
                        <div class="account-address-card-top">
                            <div>
                                <strong><?= htmlspecialchars((string) ($address['label'] ?? '')) ?></strong>
                                <?php if ($isDefault): ?>
                                    <span class="account-address-badge"><?= htmlspecialchars(Translator::t('public.account.address_default', 'Основна')) ?></span>
                                <?php endif; ?>
                            </div>
                            <small>#<?= (int) ($address['id'] ?? 0) ?></small>
                        </div>

                        <p>
                            <?= htmlspecialchars((string) ($address['country'] ?? '')) ?>,
                            <?= htmlspecialchars((string) ($address['city'] ?? '')) ?><br>
                            <?= htmlspecialchars((string) ($address['address'] ?? '')) ?>
                            <?php if (!empty($address['postcode'])): ?>
                                · <?= htmlspecialchars((string) $address['postcode']) ?>
                            <?php endif; ?>
                        </p>

                        <div class="account-address-actions">
                            <?php if (!$isDefault): ?>
                                <form method="post" action="/Anabelka/account/address/default">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="address_id" value="<?= (int) ($address['id'] ?? 0) ?>">
                                    <button type="submit" class="is-secondary"><?= htmlspecialchars(Translator::t('public.account.address_make_default', 'Зробити основною')) ?></button>
                                </form>
                            <?php endif; ?>

                            <details class="account-address-edit">
                                <summary><?= htmlspecialchars(Translator::t('public.account.address_edit', 'Редагувати')) ?></summary>
                                <form method="post" action="/Anabelka/account/address/update" autocomplete="off">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="address_id" value="<?= (int) ($address['id'] ?? 0) ?>">
                                    <div class="account-address-form-grid">
                                        <label>
                                            <span><?= htmlspecialchars(Translator::t('public.account.address_label', 'Назва адреси')) ?></span>
                                            <input type="text" name="label" maxlength="80" value="<?= htmlspecialchars((string) ($address['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        </label>
                                        <label>
                                            <span><?= htmlspecialchars(Translator::t('public.account.address_country', 'Країна')) ?></span>
                                            <input type="text" name="country" maxlength="120" value="<?= htmlspecialchars((string) ($address['country'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                        </label>
                                        <label>
                                            <span><?= htmlspecialchars(Translator::t('public.account.address_city', 'Місто')) ?></span>
                                            <input type="text" name="city" maxlength="120" value="<?= htmlspecialchars((string) ($address['city'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                        </label>
                                        <label>
                                            <span><?= htmlspecialchars(Translator::t('public.account.address_postcode', 'Поштовий індекс')) ?></span>
                                            <input type="text" name="postcode" maxlength="30" value="<?= htmlspecialchars((string) ($address['postcode'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        </label>
                                        <label class="account-address-wide">
                                            <span><?= htmlspecialchars(Translator::t('public.account.address_address', 'Адреса')) ?></span>
                                            <input type="text" name="address" maxlength="255" value="<?= htmlspecialchars((string) ($address['address'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                                        </label>
                                    </div>
                                    <?php if (!$isDefault): ?>
                                        <label class="account-check-row">
                                            <input type="checkbox" name="is_default" value="1">
                                            <span><?= htmlspecialchars(Translator::t('public.account.address_make_default', 'Зробити основною')) ?></span>
                                        </label>
                                    <?php endif; ?>
                                    <button type="submit"><?= htmlspecialchars(Translator::t('public.account.address_save', 'Зберегти адресу')) ?></button>
                                </form>
                            </details>

                            <form method="post" action="/Anabelka/account/address/delete" onsubmit="return confirm('Видалити цю адресу?');">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="address_id" value="<?= (int) ($address['id'] ?? 0) ?>">
                                <button type="submit" class="is-danger"><?= htmlspecialchars(Translator::t('public.account.address_delete', 'Видалити')) ?></button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<script src="/Anabelka/js/public-ui-focus-policy.js?v=1"></script>

</body>
</html>
