<?php
$mode = ($mode ?? '') === 'development' ? 'development' : 'production';
$escapeMode = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
};
?>
<!doctype html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title><?= $escapeMode($pageTitle ?? 'Режими відображення помилок') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/public-error.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/admin-error-display.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>
<main class="error-mode-page">
    <header class="error-mode-head">
        <h1>Режими відображення помилок</h1>
        <p>Поточний режим: <strong><?= $mode === 'development' ? 'Розробка' : 'Робочий сайт' ?></strong></p>
    </header>
    <aside class="error-mode-note">
        Режим розробки призначений для діагностики. Деталі бачить лише Розробник із чинною сесією.
        Відвідувачі та адміністратори інших ролей завжди отримують безпечні повідомлення.
    </aside>
    <form class="error-mode-form" method="post" action="/Anabelka/admin/system/error-display"
          data-anabelka-confirm="Змінити режим відображення помилок? Діагностика доступна лише Розробнику."
          data-anabelka-confirm-title="Зміна режиму помилок"
          data-anabelka-confirm-confirm-text="Змінити режим">
        <input type="hidden" name="_csrf" value="<?= $escapeMode($csrfToken ?? '') ?>">
        <input type="hidden" name="confirmed" value="1">
        <fieldset>
            <legend>Оберіть режим</legend>
            <?php foreach ([
                'development' => ['Розробка', 'Детальна діагностика для Розробника'],
                'production' => ['Робочий сайт', 'Безпечні повідомлення для відвідувачів']
            ] as $value => $copy): ?>
                <label class="error-mode-choice">
                    <input type="radio" name="mode" value="<?= $value ?>" <?= $mode === $value ? 'checked' : '' ?> required>
                    <span><strong><?= $copy[0] ?></strong><small><?= $copy[1] ?></small></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <button type="submit">Зберегти режим</button>
        <noscript><p>Для підтвердження зміни режиму увімкніть JavaScript.</p></noscript>
    </form>
    <nav class="error-mode-links" aria-label="Системні інструменти">
        <a href="/Anabelka/admin/system/errors">Журнал системних помилок</a>
        <a href="/Anabelka/admin/system/error-test">Безпечні приклади помилок</a>
        <a href="/Anabelka/admin">До адмін-панелі</a>
    </nav>
    <?php require __DIR__ . '/error-examples.php'; ?>
</main>
</body>
</html>
