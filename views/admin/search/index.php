<?php
$pageTitle = $pageTitle ?? 'Адмін-панель · Пошук';
$rows = is_array($rows ?? null) ? $rows : [];
$filters = is_array($filters ?? null) ? $filters : [];
$summary = is_array($summary ?? null) ? $summary : [];
$periodAnalytics = is_array($periodAnalytics ?? null)
    ? $periodAnalytics
    : [];
$popularQueries = is_array($popularQueries ?? null)
    ? $popularQueries
    : [];
$popularZeroQueries = is_array($popularZeroQueries ?? null)
    ? $popularZeroQueries
    : [];
$page = max(1, (int) ($page ?? 1));
$pages = max(1, (int) ($pages ?? 1));
$total = max(0, (int) ($total ?? 0));

$escape = function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$baseQuery = $filters;
unset($baseQuery['page']);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle) ?></title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/catalog.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/admin-search.css?v=2">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-search-page">
    <section class="admin-search-card">
        <div class="admin-search-head">
            <div>
                <h2>Запити користувачів</h2>
                <p>Усі виконані пошукові запити магазину. Введення по літерах тут не зберігається.</p>
            </div>
        </div>

        <div class="admin-search-summary">
            <div><strong><?= (int) ($summary['all'] ?? 0) ?></strong><span>Усього</span></div>
            <div><strong><?= (int) ($summary['today'] ?? 0) ?></strong><span>Сьогодні</span></div>
            <div class="is-attention"><strong><?= (int) ($summary['zero'] ?? 0) ?></strong><span>Без результатів</span></div>
            <div><strong><?= (int) ($summary['users'] ?? 0) ?></strong><span>Користувачі</span></div>
            <div><strong><?= (int) ($summary['guests'] ?? 0) ?></strong><span>Гості</span></div>
        </div>

        <section class="admin-search-analytics" aria-label="Аналітика пошуку">
            <div class="admin-search-analytics-head">
                <div>
                    <h3>Аналітика пошуку</h3>
                    <p>Коротка картина того, що шукають відвідувачі магазину.</p>
                </div>
            </div>

            <div class="admin-search-periods">
                <?php
                $periodCards = [
                    'today' => ['title' => 'Сьогодні'],
                    'week' => ['title' => '7 днів'],
                    'month' => ['title' => '30 днів']
                ];
                ?>

                <?php foreach ($periodCards as $periodKey => $periodMeta): ?>
                    <?php $period = $periodAnalytics[$periodKey] ?? []; ?>
                    <div class="admin-search-period-card">
                        <span class="admin-search-period-title">
                            <?= $escape($periodMeta['title']) ?>
                        </span>
                        <strong><?= (int) ($period['count'] ?? 0) ?></strong>
                        <span>пошукових запитів</span>
                        <small>
                            Унікальних: <?= (int) ($period['unique'] ?? 0) ?>
                            · без результатів: <?= (int) ($period['zero'] ?? 0) ?>
                        </small>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="admin-search-top-grid">
                <section class="admin-search-top-card">
                    <div class="admin-search-top-head">
                        <div>
                            <h3>Популярні запити</h3>
                            <p>Топ за останні 30 днів</p>
                        </div>
                    </div>

                    <?php if (empty($popularQueries)): ?>
                        <div class="admin-search-top-empty">Поки недостатньо даних.</div>
                    <?php else: ?>
                        <div class="admin-search-top-list">
                            <?php foreach ($popularQueries as $index => $item): ?>
                                <?php
                                $queryText = (string) ($item['query_text'] ?? '');
                                $filterUrl = '/Anabelka/admin/search?'
                                    . http_build_query(['q' => $queryText]);
                                ?>
                                <a class="admin-search-top-row" href="<?= $escape($filterUrl) ?>">
                                    <span class="admin-search-top-rank"><?= $index + 1 ?></span>
                                    <span class="admin-search-top-query">
                                        <strong><?= $escape($queryText) ?></strong>
                                        <small>
                                            Востаннє: <?= $escape($item['last_searched_at'] ?? '') ?>
                                        </small>
                                    </span>
                                    <span class="admin-search-top-count">
                                        <?= (int) ($item['search_count'] ?? 0) ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="admin-search-top-card is-missed">
                    <div class="admin-search-top-head">
                        <div>
                            <h3>Шукають, але не знаходять</h3>
                            <p>Найчастіші запити з 0 результатів за 30 днів</p>
                        </div>
                    </div>

                    <?php if (empty($popularZeroQueries)): ?>
                        <div class="admin-search-top-empty">Запитів без результатів поки немає.</div>
                    <?php else: ?>
                        <div class="admin-search-top-list">
                            <?php foreach ($popularZeroQueries as $index => $item): ?>
                                <?php
                                $queryText = (string) ($item['query_text'] ?? '');
                                $filterUrl = '/Anabelka/admin/search?'
                                    . http_build_query([
                                        'q' => $queryText,
                                        'results' => 'zero'
                                    ]);
                                ?>
                                <a class="admin-search-top-row" href="<?= $escape($filterUrl) ?>">
                                    <span class="admin-search-top-rank"><?= $index + 1 ?></span>
                                    <span class="admin-search-top-query">
                                        <strong><?= $escape($queryText) ?></strong>
                                        <small>
                                            Востаннє: <?= $escape($item['last_searched_at'] ?? '') ?>
                                        </small>
                                    </span>
                                    <span class="admin-search-top-count is-zero">
                                        <?= (int) ($item['search_count'] ?? 0) ?>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </section>

        <form class="admin-search-filters" method="get" action="/Anabelka/admin/search">
            <label>
                <span>Запит</span>
                <input type="search" name="q" value="<?= $escape($filters['q'] ?? '') ?>" placeholder="Наприклад: бюстгальтер">
            </label>

            <label>
                <span>Відвідувач</span>
                <select name="visitor">
                    <option value="all" <?= ($filters['visitor'] ?? 'all') === 'all' ? 'selected' : '' ?>>Усі</option>
                    <option value="user" <?= ($filters['visitor'] ?? '') === 'user' ? 'selected' : '' ?>>Авторизовані</option>
                    <option value="guest" <?= ($filters['visitor'] ?? '') === 'guest' ? 'selected' : '' ?>>Гості</option>
                </select>
            </label>

            <label>
                <span>Результат</span>
                <select name="results">
                    <option value="all" <?= ($filters['results'] ?? 'all') === 'all' ? 'selected' : '' ?>>Усі</option>
                    <option value="with" <?= ($filters['results'] ?? '') === 'with' ? 'selected' : '' ?>>Є результати</option>
                    <option value="zero" <?= ($filters['results'] ?? '') === 'zero' ? 'selected' : '' ?>>0 результатів</option>
                </select>
            </label>

            <label>
                <span>Від дати</span>
                <input type="date" name="date_from" value="<?= $escape($filters['date_from'] ?? '') ?>">
            </label>

            <label>
                <span>До дати</span>
                <input type="date" name="date_to" value="<?= $escape($filters['date_to'] ?? '') ?>">
            </label>

            <div class="admin-search-filter-actions">
                <button type="submit">Застосувати</button>
                <a href="/Anabelka/admin/search">Скинути</a>
            </div>
        </form>

        <div class="admin-search-count">Знайдено записів: <strong><?= $total ?></strong></div>

        <?php if (empty($rows)): ?>
            <div class="admin-search-empty">Пошукових запитів за цими умовами немає.</div>
        <?php else: ?>
            <div class="admin-search-table-wrap">
                <table class="admin-search-table">
                    <thead>
                        <tr>
                            <th>Дата і час</th>
                            <th>Запит</th>
                            <th>Відвідувач</th>
                            <th>Мова</th>
                            <th>Товари</th>
                            <th>Категорії</th>
                            <th>Усього</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <?php $isZero = (int) ($row['total_results'] ?? 0) === 0; ?>
                            <tr class="<?= $isZero ? 'is-zero' : '' ?>">
                                <td data-label="Дата"><?= $escape($row['created_at'] ?? '') ?></td>
                                <td data-label="Запит"><strong><?= $escape($row['query_text'] ?? '') ?></strong></td>
                                <td data-label="Відвідувач">
                                    <?php if (!empty($row['user_id'])): ?>
                                        <span class="admin-search-user-name"><?= $escape($row['user_name'] ?: ('ID ' . $row['user_id'])) ?></span>
                                        <?php if (!empty($row['user_email'])): ?>
                                            <small><?= $escape($row['user_email']) ?></small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="admin-search-guest">Гість</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Мова"><?= $escape(strtoupper((string) ($row['language_code'] ?? ''))) ?></td>
                                <td data-label="Товари"><?= (int) ($row['product_results'] ?? 0) ?></td>
                                <td data-label="Категорії"><?= (int) ($row['category_results'] ?? 0) ?></td>
                                <td data-label="Усього">
                                    <span class="admin-search-result<?= $isZero ? ' is-zero' : '' ?>">
                                        <?= (int) ($row['total_results'] ?? 0) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($pages > 1): ?>
            <nav class="admin-search-pagination" aria-label="Сторінки">
                <?php if ($page > 1): ?>
                    <?php $prevQuery = http_build_query(array_merge($baseQuery, ['page' => $page - 1])); ?>
                    <a href="/Anabelka/admin/search?<?= $escape($prevQuery) ?>">← Попередня</a>
                <?php endif; ?>

                <span>Сторінка <?= $page ?> з <?= $pages ?></span>

                <?php if ($page < $pages): ?>
                    <?php $nextQuery = http_build_query(array_merge($baseQuery, ['page' => $page + 1])); ?>
                    <a href="/Anabelka/admin/search?<?= $escape($nextQuery) ?>">Наступна →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
