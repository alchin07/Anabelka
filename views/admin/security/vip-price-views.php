<?php
$pageTitle = $pageTitle ?? 'Адмін-панель · VIP-ціни';
$rows = is_array($rows ?? null) ? $rows : [];
$filters = is_array($filters ?? null) ? $filters : [];
$summary = is_array($summary ?? null) ? $summary : [];
$rankOptions = is_array($rankOptions ?? null) ? $rankOptions : [];
$surfaceOptions = is_array($surfaceOptions ?? null) ? $surfaceOptions : [];
$page = max(1, (int) ($page ?? 1));
$pages = max(1, (int) ($pages ?? 1));
$total = max(0, (int) ($total ?? 0));
$perPage = max(1, (int) ($perPage ?? 50));

$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$baseQuery = array_filter(
    $filters,
    static function ($value) {
        return $value !== ''
            && $value !== 0
            && $value !== '0';
    }
);

$surfaceLabels = [
    'product' => 'Картка товару',
    'catalog' => 'Каталог',
    'search' => 'Пошук',
    'favorites' => 'Обране',
    'home' => 'Головна',
    'collection' => 'Підбірка'
];

$surfaceLabel = static function ($surface) use ($surfaceLabels) {
    $surface = (string) $surface;

    return $surfaceLabels[$surface] ?? $surface;
};
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle) ?></title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/admin-vip-price-views.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-vip-price-page">
    <section class="admin-vip-price-card">
        <div class="admin-vip-price-head">
            <div>
                <h2>Журнал переглядів VIP-цін</h2>
                <p>
                    Персональні watermark-коди та фактичні перегляди
                    захищених цін. Нові записи показуються першими.
                </p>
            </div>
        </div>

        <div class="admin-vip-price-summary" aria-label="Статистика журналу">
            <div>
                <strong><?= (int) ($summary['views'] ?? 0) ?></strong>
                <span>Переглядів</span>
            </div>
            <div>
                <strong><?= (int) ($summary['users'] ?? 0) ?></strong>
                <span>Користувачів</span>
            </div>
            <div>
                <strong><?= (int) ($summary['products'] ?? 0) ?></strong>
                <span>Товарів</span>
            </div>
            <div>
                <strong><?= (int) ($summary['ranks'] ?? 0) ?></strong>
                <span>VIP-рівнів</span>
            </div>
        </div>

        <form
            class="admin-vip-price-filters"
            method="get"
            action="/Anabelka/admin/vip-price-views"
        >
            <label>
                <span>Від дати</span>
                <input
                    type="date"
                    name="date_from"
                    value="<?= $escape($filters['date_from'] ?? '') ?>"
                >
            </label>

            <label>
                <span>До дати</span>
                <input
                    type="date"
                    name="date_to"
                    value="<?= $escape($filters['date_to'] ?? '') ?>"
                >
            </label>

            <label>
                <span>Користувач</span>
                <input
                    type="search"
                    name="user"
                    value="<?= $escape($filters['user'] ?? '') ?>"
                    placeholder="ID, ім’я або email"
                >
            </label>

            <label>
                <span>Товар</span>
                <input
                    type="search"
                    name="product"
                    value="<?= $escape($filters['product'] ?? '') ?>"
                    placeholder="ID, назва, SKU або slug"
                >
            </label>

            <label>
                <span>Ранг</span>
                <select name="rank_id">
                    <option value="0">Усі VIP-рівні</option>
                    <?php foreach ($rankOptions as $rank): ?>
                        <?php $rankId = (int) ($rank['id'] ?? 0); ?>
                        <option
                            value="<?= $rankId ?>"
                            <?= (int) ($filters['rank_id'] ?? 0) === $rankId ? 'selected' : '' ?>
                        >
                            <?= $escape($rank['name'] ?? ('ID ' . $rankId)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Місце показу</span>
                <select name="surface">
                    <option value="">Усі місця</option>
                    <?php foreach ($surfaceOptions as $surface): ?>
                        <option
                            value="<?= $escape($surface) ?>"
                            <?= ($filters['surface'] ?? '') === $surface ? 'selected' : '' ?>
                        >
                            <?= $escape($surfaceLabel($surface)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span>Код watermark</span>
                <input
                    class="admin-vip-price-code-input"
                    type="search"
                    name="view_code"
                    maxlength="6"
                    value="<?= $escape($filters['view_code'] ?? '') ?>"
                    placeholder="Напр. D5107B"
                    autocomplete="off"
                >
            </label>

            <div class="admin-vip-price-filter-actions">
                <button type="submit">Застосувати</button>
                <a href="/Anabelka/admin/vip-price-views">Скинути</a>
            </div>
        </form>

        <div class="admin-vip-price-result-head">
            <span>
                Знайдено:
                <strong><?= $total ?></strong>
            </span>
            <small>
                По <?= $perPage ?> записів на сторінку
            </small>
        </div>

        <?php if (empty($rows)): ?>
            <div class="admin-vip-price-empty">
                За вибраними фільтрами переглядів VIP-цін немає.
            </div>
        <?php else: ?>
            <div class="admin-vip-price-table-wrap">
                <table class="admin-vip-price-table">
                    <thead>
                        <tr>
                            <th>Дата і час</th>
                            <th>Користувач</th>
                            <th>Товар</th>
                            <th>VIP-рівень</th>
                            <th>Ціна</th>
                            <th>Місце</th>
                            <th>Watermark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php
                            $productSlug = trim((string) ($row['product_slug'] ?? ''));
                            $productUrl = $productSlug !== ''
                                ? '/Anabelka/product/' . rawurlencode($productSlug)
                                : '';
                            ?>
                            <tr>
                                <td data-label="Дата">
                                    <time>
                                        <?= $escape($row['viewed_at'] ?? '') ?>
                                    </time>
                                </td>
                                <td data-label="Користувач">
                                    <strong>
                                        <?= $escape(
                                            $row['user_name']
                                            ?: ('ID ' . (int) ($row['user_id'] ?? 0))
                                        ) ?>
                                    </strong>
                                    <small>
                                        ID <?= (int) ($row['user_id'] ?? 0) ?>
                                        <?php if (!empty($row['user_email'])): ?>
                                            · <?= $escape($row['user_email']) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td data-label="Товар">
                                    <?php if ($productUrl !== ''): ?>
                                        <a
                                            href="<?= $escape($productUrl) ?>"
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            <?= $escape(
                                                $row['product_name']
                                                ?: ('ID ' . (int) ($row['product_id'] ?? 0))
                                            ) ?>
                                        </a>
                                    <?php else: ?>
                                        <strong>
                                            ID <?= (int) ($row['product_id'] ?? 0) ?>
                                        </strong>
                                    <?php endif; ?>
                                    <small>
                                        ID <?= (int) ($row['product_id'] ?? 0) ?>
                                        <?php if (!empty($row['product_sku'])): ?>
                                            · <?= $escape($row['product_sku']) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td data-label="VIP-рівень">
                                    <strong>
                                        <?= $escape(
                                            $row['rank_name']
                                            ?: ('ID ' . (int) ($row['rank_id'] ?? 0))
                                        ) ?>
                                    </strong>
                                </td>
                                <td data-label="Ціна">
                                    <strong class="admin-vip-price-amount">
                                        <?= number_format(
                                            (float) ($row['price_amount'] ?? 0),
                                            2,
                                            ',',
                                            ' '
                                        ) ?> €
                                    </strong>
                                </td>
                                <td data-label="Місце">
                                    <?= $escape($surfaceLabel($row['surface'] ?? '')) ?>
                                </td>
                                <td data-label="Watermark">
                                    <code class="admin-vip-price-code">
                                        <?= $escape($row['view_code'] ?? '') ?>
                                    </code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($pages > 1): ?>
            <nav
                class="admin-vip-price-pagination"
                aria-label="Сторінки журналу VIP-цін"
            >
                <?php if ($page > 1): ?>
                    <?php
                    $previousQuery = http_build_query(
                        array_merge($baseQuery, ['page' => $page - 1])
                    );
                    ?>
                    <a
                        href="/Anabelka/admin/vip-price-views?<?= $escape($previousQuery) ?>"
                        rel="prev"
                    >← Попередня</a>
                <?php endif; ?>

                <span>Сторінка <?= $page ?> з <?= $pages ?></span>

                <?php if ($page < $pages): ?>
                    <?php
                    $nextQuery = http_build_query(
                        array_merge($baseQuery, ['page' => $page + 1])
                    );
                    ?>
                    <a
                        href="/Anabelka/admin/vip-price-views?<?= $escape($nextQuery) ?>"
                        rel="next"
                    >Наступна →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
