<?php
$providers = is_array($providers ?? null) ? $providers : [];
$escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle ?? 'Адмін-панель · Авторизація та соцмережі') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-social-auth.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-social-auth-page">
    <section class="admin-social-auth-intro">
        <div>
            <h2>Авторизація та соцмережі</h2>
            <p>
                Керуйте способами входу покупців. Секретні ключі тут не показуються
                і залишаються в локальній конфігурації сервера.
            </p>
        </div>
    </section>

    <?php if (!empty($message)): ?>
        <div class="admin-social-auth-message" role="status">
            <?= $escape($message) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="admin-social-auth-message is-error" role="alert">
            <?= $escape($error) ?>
        </div>
    <?php endif; ?>

    <section class="admin-social-auth-list" aria-label="Способи входу">
        <?php foreach ($providers as $index => $provider): ?>
            <?php
            $code = (string) ($provider['code'] ?? '');
            $enabled = !empty($provider['enabled']);
            $configured = !empty($provider['configured']);
            $available = !empty($provider['available']);
            $isFirst = $index === 0;
            $isLast = $index === count($providers) - 1;
            ?>
            <article class="admin-social-auth-card">
                <div class="admin-social-auth-main">
                    <span class="admin-social-auth-mark" aria-hidden="true">
                        <?= $escape($provider['mark'] ?? '') ?>
                    </span>

                    <div class="admin-social-auth-copy">
                        <div class="admin-social-auth-title-row">
                            <h3><?= $escape($provider['label'] ?? $code) ?></h3>
                            <span class="admin-social-auth-state <?= $enabled ? 'is-on' : 'is-off' ?>">
                                <?= $enabled ? 'Увімкнено' : 'Вимкнено' ?>
                            </span>
                        </div>

                        <div class="admin-social-auth-meta">
                            <span>
                                Конфігурація:
                                <strong class="<?= $configured ? 'is-ok' : 'is-warning' ?>">
                                    <?= $configured ? 'налаштовано' : 'не налаштовано' ?>
                                </strong>
                            </span>
                            <span>
                                На сайті:
                                <strong class="<?= $available ? 'is-ok' : 'is-muted' ?>">
                                    <?= $available ? 'доступно' : 'приховано' ?>
                                </strong>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="admin-social-auth-actions">
                    <div class="admin-social-auth-order" aria-label="Порядок відображення">
                        <form method="post" action="/Anabelka/admin/social-auth/move">
                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken ?? '') ?>">
                            <input type="hidden" name="provider" value="<?= $escape($code) ?>">
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" <?= $isFirst ? 'disabled' : '' ?> aria-label="Перемістити вище">↑</button>
                        </form>
                        <form method="post" action="/Anabelka/admin/social-auth/move">
                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken ?? '') ?>">
                            <input type="hidden" name="provider" value="<?= $escape($code) ?>">
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" <?= $isLast ? 'disabled' : '' ?> aria-label="Перемістити нижче">↓</button>
                        </form>
                    </div>

                    <form method="post" action="/Anabelka/admin/social-auth/toggle">
                        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken ?? '') ?>">
                        <input type="hidden" name="provider" value="<?= $escape($code) ?>">
                        <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                        <button
                            class="admin-social-auth-toggle <?= $enabled ? 'is-disable' : 'is-enable' ?>"
                            type="submit"
                            <?= (!$enabled && !$configured) ? 'disabled' : '' ?>
                        >
                            <?= $enabled ? 'Вимкнути' : 'Увімкнути' ?>
                        </button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <section class="admin-social-auth-note">
        <strong>Важливо</strong>
        <p>
            Якщо спосіб входу вимкнено, його кнопка не показується покупцям.
            Неналаштований провайдер також автоматично приховується.
        </p>
    </section>
</main>

</body>
</html>
