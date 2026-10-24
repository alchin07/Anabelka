<?php
$pageTitle = $pageTitle ?? 'Адмін-панель · Статистика відвідуваності';
$reportUnavailable = !empty($reportUnavailable) || !is_array($report ?? null);
$report = is_array($report ?? null) ? $report : [];
$period = (int) ($report['period'] ?? $period ?? 30);
$period = in_array($period, [7, 30, 90], true) ? $period : 30;
$summary = $report['summary'] ?? [];
$chart = $report['chart'] ?? [];
$categories = $report['top_categories'] ?? [];
$products = $report['top_products'] ?? [];
$escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$number = static fn ($value) => number_format(max(0, (int) $value), 0, ',', ' ');
$dateLabel = static fn ($date) => substr((string) $date, 8, 2) . '.' . substr((string) $date, 5, 2);
$imageUrl = static function ($value): string {
    $path = rawurldecode(trim((string) $value));
    if (preg_match('/[\x00-\x1F\x7F\\\\?#]/', $path)) {
        return '';
    }
    if (str_starts_with($path, '/Anabelka/')) {
        $path = substr($path, strlen('/Anabelka/'));
    }
    if (!str_starts_with($path, 'uploads/') || !preg_match('/\.(?:jpe?g|png|webp|gif|avif)$/i', $path)) {
        return '';
    }
    $parts = explode('/', $path);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return '';
        }
    }
    return '/Anabelka/' . implode('/', array_map('rawurlencode', $parts));
};
$maximum = $chart ? max(array_column($chart, 'views')) : 0;
$scale = max(1, (int) $maximum);
$labels = ['today' => 'Сьогодні', 'yesterday' => 'Вчора',
    'week' => 'Останні 7 днів', 'month' => 'Останні 30 днів'];
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle) ?></title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/admin-visitor-stats.css?v=2">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="visitor-stats-page">
    <section class="visitor-stats-intro">
        <p class="visitor-stats-eyebrow">Огляд активності</p>
        <h2>Статистика відвідуваності</h2>
        <p>Перегляди сторінок сайту та найпопулярніші категорії й товари.</p>
        <?php if (!$reportUnavailable): ?>
            <p class="visitor-stats-note">Рахуємо перегляди сторінок. Часовий пояс: <?= $escape($report['timezone'] ?? 'Europe/Kyiv') ?>.</p>
        <?php endif; ?>
    </section>

    <?php if ($reportUnavailable): ?>
        <section class="visitor-stats-message visitor-stats-error" role="alert">
            <h3>Статистика тимчасово недоступна</h3>
            <p>Не вдалося завантажити дані. Спробуйте оновити сторінку пізніше.</p>
            <a href="/Anabelka/admin/visitor-stats?period=<?= $period ?>">Спробувати ще раз</a>
        </section>
    <?php else: ?>
        <section class="visitor-stats-summary" aria-label="Кількість переглядів сторінок">
            <?php foreach ($labels as $key => $label): ?>
                <article class="visitor-summary-card">
                    <h3><?= $escape($label) ?></h3>
                    <strong><?= $number($summary[$key] ?? 0) ?></strong>
                    <span>переглядів</span>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if (empty($report['has_data'])): ?>
            <p class="visitor-stats-message" role="status">Даних поки немає. Вони з’являться після перших переглядів сторінок.</p>
        <?php endif; ?>

        <section class="visitor-stats-panel visitor-chart-panel" aria-labelledby="visitor-daily-heading">
            <div class="visitor-panel-heading">
                <div>
                    <h3 id="visitor-daily-heading">Перегляди за останні 30 днів</h3>
                    <p>Щоденна активність, включно із сьогоднішнім днем.</p>
                </div>
                <span class="visitor-chart-key"><i aria-hidden="true"></i> Перегляди</span>
            </div>
            <figure class="visitor-chart">
                <p id="visitor-chart-scroll-hint" class="visitor-chart-scroll-hint">Прокрутіть графік горизонтально, щоб побачити всі дні.</p>
                <div class="visitor-chart-scroll" role="region" aria-label="Графік щоденних переглядів — горизонтальне прокручування" aria-describedby="visitor-chart-scroll-hint" tabindex="0">
                <svg viewBox="0 0 900 230" role="img" aria-labelledby="visitor-chart-title visitor-chart-description">
                    <title id="visitor-chart-title">Щоденні перегляди сторінок за останні 30 днів</title>
                    <desc id="visitor-chart-description">Кожен стовпчик відповідає одному дню. Точні значення доступні нижче в таблиці щоденних переглядів.</desc>
                    <?php foreach ([20, 105, 190] as $gridY): ?>
                        <line x1="52" y1="<?= $gridY ?>" x2="884" y2="<?= $gridY ?>" class="visitor-chart-grid" />
                    <?php endforeach; ?>
                    <?php if ($maximum > 0): ?>
                        <text x="42" y="25" text-anchor="end"><?= $number($maximum) ?></text>
                        <text x="42" y="110" text-anchor="end"><?= $number((int) floor($maximum / 2)) ?></text>
                    <?php endif; ?>
                    <text x="42" y="195" text-anchor="end">0</text>
                    <?php foreach ($chart as $index => $day): ?>
                        <?php
                        $height = round(max(0, (int) $day['views']) / $scale * 170, 2);
                        $barX = round(57 + $index * 27.6, 2);
                        $barY = 190 - $height;
                        ?>
                        <rect x="<?= $barX ?>" y="<?= $barY ?>" width="18" height="<?= $height ?>" rx="3" class="visitor-chart-bar">
                            <title><?= $escape($dateLabel($day['date'])) ?>: <?= $number($day['views']) ?> переглядів</title>
                        </rect>
                        <?php if (in_array($index, [0, 14, 29], true)): ?>
                            <text x="<?= $barX + 9 ?>" y="218" text-anchor="middle"><?= $escape($dateLabel($day['date'])) ?></text>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </svg>
                </div>
                <figcaption>Останні 7 і 30 днів у картках також включають сьогодні.</figcaption>
            </figure>

            <details class="visitor-daily-details">
                <summary>Таблиця щоденних переглядів (30 днів)</summary>
                <div class="visitor-daily-table-wrap" tabindex="0" role="region" aria-label="Щоденні перегляди — прокручувана таблиця">
                    <table class="visitor-stats-table">
                        <caption class="visitor-visually-hidden">Дані графіка за останні 30 днів</caption>
                        <thead><tr><th scope="col">Дата</th><th scope="col">Перегляди</th></tr></thead>
                        <tbody>
                            <?php foreach (array_reverse($chart) as $day): ?>
                                <tr data-visitor-date="<?= $escape($day['date']) ?>">
                                    <th scope="row"><time datetime="<?= $escape($day['date']) ?>"><?= $escape($dateLabel($day['date']) . '.' . substr($day['date'], 0, 4)) ?></time></th>
                                    <td><?= $number($day['views']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        </section>

        <section id="visitor-popularity" class="visitor-popularity" aria-labelledby="visitor-popularity-heading">
            <div class="visitor-popularity-heading">
                <div>
                    <h3 id="visitor-popularity-heading">Що переглядають найчастіше</h3>
                    <p>До 10 позицій за вибраний період.</p>
                </div>
                <form method="get" action="/Anabelka/admin/visitor-stats#visitor-popularity" class="visitor-period-form">
                    <label for="visitor-period">Період популярності</label>
                    <div>
                        <select id="visitor-period" name="period">
                            <?php foreach ([7, 30, 90] as $days): ?>
                                <option value="<?= $days ?>" <?= $period === $days ? 'selected' : '' ?>>Останні <?= $days ?> днів</option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit">Показати</button>
                    </div>
                </form>
            </div>
            <div class="visitor-popularity-grid">
                <section class="visitor-stats-panel" aria-labelledby="visitor-categories-heading">
                    <div class="visitor-panel-heading"><h4 id="visitor-categories-heading">Популярні категорії</h4></div>
                    <?php if (!$categories): ?>
                        <p class="visitor-top-empty">Даних поки немає</p>
                    <?php else: ?>
                        <table class="visitor-stats-table visitor-popularity-table">
                            <thead><tr><th scope="col">№</th><th scope="col">Категорія</th><th scope="col">Перегляди</th></tr></thead>
                            <tbody>
                            <?php foreach ($categories as $index => $category): ?>
                                <tr>
                                    <td class="visitor-rank"><?= $index + 1 ?></td>
                                    <th scope="row"><a href="/Anabelka/catalog/<?= $escape(rawurlencode($category['department_slug'])) ?>/<?= $escape(rawurlencode($category['slug'])) ?>"><?= $escape($category['name']) ?></a></th>
                                    <td><?= $number($category['view_count']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>
                <section class="visitor-stats-panel" aria-labelledby="visitor-products-heading">
                    <div class="visitor-panel-heading"><h4 id="visitor-products-heading">Популярні товари</h4></div>
                    <?php if (!$products): ?>
                        <p class="visitor-top-empty">Даних поки немає</p>
                    <?php else: ?>
                        <table class="visitor-stats-table visitor-popularity-table">
                            <thead><tr><th scope="col">№</th><th scope="col">Товар</th><th scope="col">Перегляди</th></tr></thead>
                            <tbody>
                            <?php foreach ($products as $index => $product): ?>
                                <?php $image = $imageUrl($product['main_image'] ?? ''); ?>
                                <tr>
                                    <td class="visitor-rank"><?= $index + 1 ?></td>
                                    <th scope="row">
                                        <a class="visitor-product" href="/Anabelka/product/<?= $escape(rawurlencode($product['slug'])) ?>">
                                            <?php if ($image !== ''): ?>
                                                <img class="visitor-product-image" src="<?= $escape($image) ?>" alt="" width="44" height="52" loading="lazy">
                                            <?php else: ?>
                                                <span class="visitor-product-placeholder" aria-hidden="true">Фото</span>
                                            <?php endif; ?>
                                            <span><?= $escape($product['name']) ?></span>
                                        </a>
                                    </th>
                                    <td><?= $number($product['view_count']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>
            </div>
        </section>
    <?php endif; ?>
</main>
<script defer src="/Anabelka/js/admin-visitor-stats.js?v=1"></script>
</body>
</html>
