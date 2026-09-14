<?php
$errorCode = (int) ($errorCode ?? 404);
$errorTitle = trim((string) ($errorTitle ?? 'Сторінку не знайдено'));
$errorMessage = trim((string) (
    $errorMessage ?? 'Перевірте адресу або поверніться до каталогу.'
));
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($errorTitle) ?> — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <link rel="stylesheet" href="/Anabelka/css/public-error.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="public-error-page">
    <section class="public-error-card" aria-labelledby="public-error-title">
        <span class="public-error-code"><?= $escape($errorCode) ?></span>
        <h1 id="public-error-title"><?= $escape($errorTitle) ?></h1>
        <p><?= $escape($errorMessage) ?></p>
        <div class="public-error-actions">
            <a class="public-error-primary" href="/Anabelka/catalog">
                До каталогу
            </a>
            <a class="public-error-secondary" href="/Anabelka/">
                На головну
            </a>
        </div>
    </section>
</main>
</body>
</html>
