<?php
PublicInterfaceTranslator::seed();
$currentLanguage = Translator::currentLanguage();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($currentLanguage['code'] ?? 'uk') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Анабелька</title>
</head>
<body>
    <h1>Анабелька</h1>

    <p><?= htmlspecialchars(
        Translator::t('public.home.shop', 'Інтернет-магазин')
    ) ?></p>

    <a href="/Anabelka/catalog">
        <?= htmlspecialchars(
            Translator::t('public.home.catalog', 'Перейти до каталогу')
        ) ?>
    </a>
</body>
</html>
