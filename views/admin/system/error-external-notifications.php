<?php
$settings = is_array($settings ?? null) ? $settings : [];
$flash = is_array($flash ?? null) ? $flash : null;
$transportStatus = is_array($transportStatus ?? null) ? $transportStatus : [];
$csrfToken = (string) ($csrfToken ?? '');
$levels = is_array($settings['levels'] ?? null) ? $settings['levels'] : [];
$channels = is_array($settings['channels'] ?? null) ? $settings['channels'] : [];
$emailTransport = is_array($transportStatus['email'] ?? null) ? $transportStatus['email'] : [];
$telegramTransport = is_array($transportStatus['telegram'] ?? null) ? $transportStatus['telegram'] : [];
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$checked = static function ($condition) {
    return $condition ? 'checked' : '';
};
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle ?? 'Адмін-панель · Зовнішні сповіщення') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-system-error-external-notifications.css?v=2">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="error-alert-settings-page">
    <section class="error-alert-settings-head">
        <div>
            <h2>Зовнішні сповіщення про помилки</h2>
            <p>
                Налаштуйте, які серйозні помилки мають виходити за межі адмін-панелі,
                та окремо перевіряйте канали без створення реальної помилки.
            </p>
        </div>
        <a href="/Anabelka/admin/system/errors">До журналу</a>
    </section>

    <?php if ($flash): ?>
        <div class="error-alert-flash is-<?= $escape($flash['type'] ?? 'success') ?>" role="status">
            <?= $escape($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <form
        class="error-alert-settings-form"
        method="post"
        action="/Anabelka/admin/system/error-external-notifications"
    >
        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">

        <section class="error-alert-card is-master">
            <div class="error-alert-card-copy">
                <h3>Зовнішні сповіщення</h3>
                <p>Головний перемикач. Якщо вимкнено — автоматичні зовнішні повідомлення не відправляються.</p>
            </div>
            <label class="error-alert-switch">
                <input type="checkbox" name="enabled" value="1" <?= $checked(!empty($settings['enabled'])) ?>>
                <span>Увімкнути</span>
            </label>
        </section>

        <section class="error-alert-card">
            <div class="error-alert-section-title">
                <h3>Рівні помилок</h3>
                <p>Оберіть, які рівні дозволено відправляти назовні.</p>
            </div>

            <div class="error-alert-choice-grid">
                <?php foreach ([
                    'critical' => ['Critical', 'Найсерйозніші збої системи.'],
                    'error' => ['Error', 'Звичайні системні помилки.'],
                    'warning' => ['Warning', 'Попередження без повної зупинки роботи.']
                ] as $value => $copy): ?>
                    <label class="error-alert-choice">
                        <input
                            type="checkbox"
                            name="levels[]"
                            value="<?= $escape($value) ?>"
                            <?= $checked(in_array($value, $levels, true)) ?>
                        >
                        <span>
                            <strong><?= $escape($copy[0]) ?></strong>
                            <small><?= $escape($copy[1]) ?></small>
                        </span>
                    </label>
                <?php endforeach; ?>
            </div>

            <label class="error-alert-inline-check">
                <input
                    type="checkbox"
                    name="critical_immediate"
                    value="1"
                    <?= $checked(!empty($settings['critical_immediate'])) ?>
                >
                <span>
                    <strong>Critical надсилати одразу</strong>
                    <small>Для критичної помилки не чекати накопичення повторів.</small>
                </span>
            </label>
        </section>

        <section class="error-alert-card">
            <div class="error-alert-section-title">
                <h3>Захист від дублювання</h3>
                <p>Однакова помилка не повинна засипати повідомленнями.</p>
            </div>

            <div class="error-alert-fields two-columns">
                <label>
                    <span>Сповістити після повторів</span>
                    <input
                        type="number"
                        name="repeat_threshold"
                        min="1"
                        max="50"
                        inputmode="numeric"
                        value="<?= (int) ($settings['repeat_threshold'] ?? 3) ?>"
                    >
                    <small>Наприклад: 3 — звичайний Error піде назовні після третього однакового випадку.</small>
                </label>

                <label>
                    <span>Пауза між повторними повідомленнями</span>
                    <select name="cooldown_minutes">
                        <?php foreach ([
                            0 => 'Без паузи',
                            5 => '5 хвилин',
                            15 => '15 хвилин',
                            30 => '30 хвилин',
                            60 => '1 година',
                            180 => '3 години',
                            360 => '6 годин',
                            720 => '12 годин',
                            1440 => '24 години'
                        ] as $minutes => $label): ?>
                            <option
                                value="<?= (int) $minutes ?>"
                                <?= (int) ($settings['cooldown_minutes'] ?? 30) === (int) $minutes ? 'selected' : '' ?>
                            ><?= $escape($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Після відправки одна група помилок не буде повторно турбувати протягом цієї паузи.</small>
                </label>
            </div>
        </section>

        <section class="error-alert-card">
            <div class="error-alert-section-title">
                <h3>Канали</h3>
                <p>Адресати зберігаються в налаштуваннях, а паролі й токени — тільки в локальному конфігураційному файлі.</p>
            </div>

            <div class="error-alert-channel-grid">
                <div class="error-alert-channel">
                    <div class="error-alert-channel-head">
                        <label class="error-alert-inline-check">
                            <input
                                type="checkbox"
                                name="channels[]"
                                value="email"
                                <?= $checked(in_array('email', $channels, true)) ?>
                            >
                            <span>
                                <strong>Email</strong>
                                <small>Надсилати службовий лист розробнику.</small>
                            </span>
                        </label>
                        <span class="error-alert-transport-state <?= !empty($emailTransport['configured']) ? 'is-ready' : 'is-missing' ?>">
                            <?= !empty($emailTransport['configured']) ? 'Готово до тесту' : 'Не налаштовано' ?>
                        </span>
                    </div>
                    <label class="error-alert-field">
                        <span>Email одержувача</span>
                        <input
                            type="email"
                            name="email_to"
                            autocomplete="email"
                            value="<?= $escape($settings['email_to'] ?? '') ?>"
                            placeholder="developer@example.com"
                        >
                    </label>
                </div>

                <div class="error-alert-channel">
                    <div class="error-alert-channel-head">
                        <label class="error-alert-inline-check">
                            <input
                                type="checkbox"
                                name="channels[]"
                                value="telegram"
                                <?= $checked(in_array('telegram', $channels, true)) ?>
                            >
                            <span>
                                <strong>Telegram</strong>
                                <small>Надсилати повідомлення через службового бота.</small>
                            </span>
                        </label>
                        <span class="error-alert-transport-state <?= !empty($telegramTransport['configured']) ? 'is-ready' : 'is-missing' ?>">
                            <?= !empty($telegramTransport['configured']) ? 'Готово до тесту' : 'Не налаштовано' ?>
                        </span>
                    </div>
                    <label class="error-alert-field">
                        <span>Telegram Chat ID</span>
                        <input
                            type="text"
                            name="telegram_chat_id"
                            inputmode="numeric"
                            value="<?= $escape($settings['telegram_chat_id'] ?? '') ?>"
                            placeholder="Наприклад: 123456789"
                        >
                    </label>
                </div>
            </div>

            <div class="error-alert-security-note">
                Секрети читаються з <code>config/error-notifications.local.php</code>.
                Цей файл додано до <code>.gitignore</code> і він не повинен потрапляти в GitHub.
            </div>
        </section>

        <div class="error-alert-submit-row">
            <button type="submit">Зберегти налаштування</button>
        </div>
    </form>

    <section class="error-alert-card error-alert-test-card">
        <div class="error-alert-section-title">
            <h3>Безпечний тест каналів</h3>
            <p>
                Тест використовує вже збережені канали й адресатів. Він не створює системну помилку
                і не вмикає автоматичне надсилання, навіть якщо головний перемикач вимкнений.
            </p>
        </div>
        <form method="post" action="/Anabelka/admin/system/error-external-notifications/test">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <button type="submit">Надіслати тестове сповіщення</button>
        </form>
    </section>
</main>

</body>
</html>
