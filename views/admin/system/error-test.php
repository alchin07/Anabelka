<?php
$csrfToken = (string) ($csrfToken ?? '');
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тест обробника помилок — Анабелька</title>
    <style>
        body{margin:0;background:#faf7ff;color:#302735;font-family:Arial,sans-serif}
        .wrap{width:min(620px,calc(100% - 28px));margin:36px auto;padding:22px;box-sizing:border-box;background:#fff;border:1px solid #eadcf7;border-radius:18px}
        h1{margin:0 0 10px;font-size:24px}
        p{color:#6b6170;line-height:1.55}
        .note{padding:12px 14px;border-radius:12px;background:#f4eaff;color:#5b327d}
        form{margin-top:18px}
        button,a{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:9px 14px;border-radius:11px;font:inherit;font-weight:800;text-decoration:none;box-sizing:border-box}
        button{border:0;background:#8a2be2;color:#fff;cursor:pointer}
        a{margin-left:8px;border:1px solid #eadcf7;background:#fff;color:#5c5162}
        code{word-break:break-word}
        @media(max-width:560px){.wrap{margin:18px auto;padding:16px}button,a{width:100%}a{margin:9px 0 0}}
    </style>
</head>
<body>
<main class="wrap">
    <h1>Тест обробника помилок</h1>
    <p>
        Кнопка нижче навмисно створює одну тестову помилку. Системний обробник має
        записати її у журнал і показати безпечну сторінку без технічних деталей.
    </p>
    <div class="note">
        Очікуваний файл журналу: <code>storage/logs/app-<?= htmlspecialchars(date('Y-m-d')) ?>.log</code>
    </div>

    <form method="post" action="/Anabelka/admin/system/error-test">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <button type="submit">Створити тестову помилку</button>
        <a href="/Anabelka/admin">Скасувати</a>
    </form>
</main>
</body>
</html>
