<?php
$backups = is_array($backups ?? null) ? $backups : [];
$csrfToken = (string) ($csrfToken ?? '');
$created = (string) ($created ?? '');
$error = (string) ($error ?? '');

$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$formatBytes = static function ($bytes) {
    $bytes = max(0, (int) $bytes);
    $units = ['Б', 'КБ', 'МБ', 'ГБ'];
    $value = (float) $bytes;
    $unit = 0;

    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return number_format($value, $unit === 0 ? 0 : 1, ',', ' ')
        . ' '
        . $units[$unit];
};
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Резервна копія — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=8">
    <style>
        .admin-backup-page {
            max-width: 920px;
            margin: 0 auto;
            padding: 22px 14px 80px;
        }

        .admin-backup-card {
            margin-bottom: 18px;
            padding: 18px;
            border: 1px solid #eadcf7;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 10px 30px rgba(101, 25, 185, .06);
        }

        .admin-backup-card h2,
        .admin-backup-card h3 {
            margin: 0 0 10px;
            color: #332d37;
        }

        .admin-backup-card p {
            margin: 8px 0;
            color: #675f6c;
            line-height: 1.55;
        }

        .admin-backup-warning {
            padding: 12px 14px;
            border: 1px solid #8A2BE2;
            border-radius: 12px;
            background: #f4eaff;
            color: #6519b9;
            font-weight: 700;
        }

        .admin-backup-success,
        .admin-backup-error {
            margin-bottom: 16px;
            padding: 12px 14px;
            border-radius: 12px;
            font-weight: 750;
        }

        .admin-backup-success {
            background: #edf8f1;
            color: #26643d;
        }

        .admin-backup-error {
            background: #fff0f1;
            color: #9a3440;
        }

        .admin-backup-create {
            min-height: 46px;
            padding: 0 18px;
            border: 0;
            border-radius: 12px;
            background: #8A2BE2;
            color: #fff;
            font: inherit;
            font-weight: 850;
            cursor: pointer;
        }

        .admin-backup-list {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .admin-backup-item {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border: 1px solid #eadcf7;
            border-radius: 12px;
            background: #faf7ff;
        }

        .admin-backup-item strong,
        .admin-backup-item span {
            display: block;
            overflow-wrap: anywhere;
        }

        .admin-backup-item span {
            margin-top: 3px;
            color: #7c7184;
            font-size: 13px;
        }

        .admin-backup-download {
            min-height: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 12px;
            border: 1px solid #8A2BE2;
            border-radius: 10px;
            background: #fff;
            color: #6519b9;
            font-weight: 800;
            text-decoration: none;
        }

        @media (max-width: 620px) {
            .admin-backup-item {
                grid-template-columns: 1fr;
            }

            .admin-backup-download {
                width: 100%;
                box-sizing: border-box;
            }

            .admin-backup-create {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-backup-page">
    <?php if ($created !== ''): ?>
        <div class="admin-backup-success" role="status">
            Повну резервну копію створено.
            <a
                href="/Anabelka/admin/system/backup/download?file=<?= rawurlencode($created) ?>"
            >Завантажити архів</a>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="admin-backup-error" role="alert">
            <?= $escape($error) ?>
        </div>
    <?php endif; ?>

    <section class="admin-backup-card">
        <h2>Повна резервна копія Анабельки</h2>
        <p>
            Створюється один ZIP із поточними файлами сайту,
            завантаженими зображеннями та SQL-дампом актуальної бази даних.
        </p>

        <div class="admin-backup-warning">
            Архів містить локальні конфіги та може містити паролі,
            OAuth-секрети й API-ключі. Не завантажуйте його у відкритий доступ.
        </div>

        <p>
            Старі резервні архіви та каталог .git до нового ZIP не
            вкладаються, щоб копії не дублювали одна одну.
        </p>

        <form
            method="post"
            action="/Anabelka/admin/system/backup/create"
        >
            <input
                type="hidden"
                name="_csrf"
                value="<?= $escape($csrfToken) ?>"
            >
            <button type="submit" class="admin-backup-create">
                Створити повний ZIP + дамп БД
            </button>
        </form>
    </section>

    <section class="admin-backup-card">
        <h3>Останні резервні копії</h3>

        <?php if (empty($backups)): ?>
            <p>Повних резервних копій ще немає.</p>
        <?php else: ?>
            <div class="admin-backup-list">
                <?php foreach ($backups as $backup): ?>
                    <div class="admin-backup-item">
                        <div>
                            <strong><?= $escape($backup['name'] ?? '') ?></strong>
                            <span>
                                <?= $escape(
                                    date(
                                        'd.m.Y H:i:s',
                                        (int) ($backup['created_at'] ?? 0)
                                    )
                                ) ?>
                                ·
                                <?= $escape(
                                    $formatBytes($backup['size'] ?? 0)
                                ) ?>
                            </span>
                        </div>

                        <a
                            class="admin-backup-download"
                            href="/Anabelka/admin/system/backup/download?file=<?= rawurlencode((string) ($backup['name'] ?? '')) ?>"
                        >Завантажити</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
