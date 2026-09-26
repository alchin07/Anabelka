<?php
$errorTitle = trim((string) ($errorTitle ?? 'Щось пішло не так'));
$errorMessage = trim((string) (
    $errorMessage ?? 'Спробуйте ще раз.'
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
    <link rel="stylesheet" href="/Anabelka/css/public-error.css?v=3">
</head>
<body>
<main class="public-error-page">
    <section class="public-error-card" aria-labelledby="public-error-title">
        <h1 id="public-error-title"><?= $escape($errorTitle) ?></h1>
        <p><?= $escape($errorMessage) ?></p>
        <div class="public-error-actions">
            <a class="public-error-primary" href="/Anabelka/">
                На головну
            </a>
        </div>
    </section>
</main>
</body>
</html>
