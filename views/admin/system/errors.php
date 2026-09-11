<?php
$items = is_array($items ?? null) ? $items : [];
$summary = is_array($summary ?? null) ? $summary : [];
$filters = is_array($filters ?? null) ? $filters : [];
$selected = is_array($selected ?? null) ? $selected : null;
$selectedReference = (string) ($selectedReference ?? '');
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$formatTime = static function ($value) {
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('d.m.Y H:i:s', $timestamp) : (string) $value;
};
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle ?? 'Адмін-панель · Системні помилки') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-system-errors.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="system-errors-page">
    <section class="system-errors-intro">
        <div>
            <h2>Системні помилки</h2>
            <p>Технічний журнал Анабельки. Деталі доступні лише Розробнику.</p>
        </div>
        <a href="/Anabelka/admin/system/error-test">Тест обробника</a>
    </section>

    <section class="system-errors-summary" aria-label="Статистика помилок">
        <div><span>Показано</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong></div>
        <div><span>Критичні</span><strong><?= (int) ($summary['critical'] ?? 0) ?></strong></div>
        <div><span>Помилки</span><strong><?= (int) ($summary['error'] ?? 0) ?></strong></div>
        <div><span>Попередження</span><strong><?= (int) ($summary['warning'] ?? 0) ?></strong></div>
    </section>

    <form class="system-errors-filter" method="get" action="/Anabelka/admin/system/errors">
        <label>
            <span>Рівень</span>
            <select name="level">
                <?php foreach ([
                    'all' => 'Усі',
                    'critical' => 'Critical',
                    'error' => 'Error',
                    'warning' => 'Warning',
                    'info' => 'Info'
                ] as $value => $label): ?>
                    <option value="<?= $escape($value) ?>" <?= ($filters['level'] ?? 'all') === $value ? 'selected' : '' ?>>
                        <?= $escape($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>
            <span>Дата</span>
            <input type="date" name="date" value="<?= $escape($filters['date'] ?? '') ?>">
        </label>

        <label class="system-errors-search">
            <span>Пошук</span>
            <input
                type="search"
                name="q"
                value="<?= $escape($filters['q'] ?? '') ?>"
                placeholder="Код, URL, повідомлення..."
            >
        </label>

        <div class="system-errors-filter-actions">
            <button type="submit">Застосувати</button>
            <a href="/Anabelka/admin/system/errors">Скинути</a>
        </div>
    </form>

    <?php if ($selectedReference !== ''): ?>
        <section class="system-error-detail">
            <?php if ($selected): ?>
                <?php
                $request = is_array($selected['request'] ?? null) ? $selected['request'] : [];
                ?>
                <div class="system-error-detail-head">
                    <div>
                        <span class="system-error-level is-<?= $escape($selected['level'] ?? 'error') ?>">
                            <?= $escape(strtoupper((string) ($selected['level'] ?? 'error'))) ?>
                        </span>
                        <h3><?= $escape($selected['reference'] ?? '') ?></h3>
                    </div>
                    <a href="/Anabelka/admin/system/errors">Закрити</a>
                </div>

                <dl class="system-error-detail-grid">
                    <div><dt>Час</dt><dd><?= $escape($formatTime($selected['time'] ?? '')) ?></dd></div>
                    <div><dt>Тип</dt><dd><?= $escape($selected['kind'] ?? '') ?></dd></div>
                    <div><dt>Метод</dt><dd><?= $escape($request['method'] ?? '') ?></dd></div>
                    <div><dt>URL</dt><dd><?= $escape($request['uri'] ?? '') ?></dd></div>
                    <div><dt>Користувач</dt><dd><?= (int) ($request['user_id'] ?? 0) ?: '—' ?></dd></div>
                    <div><dt>Адміністратор</dt><dd><?= (int) ($request['admin_user_id'] ?? 0) ?: '—' ?></dd></div>
                    <div><dt>Клас</dt><dd><?= $escape($selected['class'] ?? '') ?></dd></div>
                    <div><dt>Файл</dt><dd><?= $escape($selected['file'] ?? '') ?><?= !empty($selected['line']) ? ':' . (int) $selected['line'] : '' ?></dd></div>
                </dl>

                <div class="system-error-message-block">
                    <strong>Повідомлення</strong>
                    <p><?= $escape($selected['message'] ?? '') ?></p>
                </div>

                <?php if (!empty($selected['trace'])): ?>
                    <details class="system-error-trace">
                        <summary>Стек викликів</summary>
                        <pre><?= $escape($selected['trace']) ?></pre>
                    </details>
                <?php endif; ?>
            <?php else: ?>
                <div class="system-error-not-found">
                    Запис із кодом <strong><?= $escape($selectedReference) ?></strong> не знайдено.
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="system-errors-list" aria-label="Журнал системних помилок">
        <?php if (empty($items)): ?>
            <div class="system-errors-empty">За вибраними умовами записів немає.</div>
        <?php endif; ?>

        <?php foreach ($items as $item): ?>
            <?php $request = is_array($item['request'] ?? null) ? $item['request'] : []; ?>
            <article class="system-error-card">
                <div class="system-error-card-head">
                    <span class="system-error-level is-<?= $escape($item['level'] ?? 'error') ?>">
                        <?= $escape(strtoupper((string) ($item['level'] ?? 'error'))) ?>
                    </span>
                    <time><?= $escape($formatTime($item['time'] ?? '')) ?></time>
                </div>

                <strong class="system-error-reference"><?= $escape($item['reference'] ?? '') ?></strong>
                <p class="system-error-message"><?= $escape($item['message'] ?? '') ?></p>

                <div class="system-error-meta">
                    <span><?= $escape($request['method'] ?? '') ?></span>
                    <code><?= $escape($request['uri'] ?? '') ?></code>
                </div>

                <div class="system-error-card-foot">
                    <span><?= $escape($item['kind'] ?? '') ?></span>
                    <a href="/Anabelka/admin/system/errors?<?= $escape(http_build_query(['ref' => $item['reference'] ?? ''])) ?>">
                        Подробиці
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</main>

</body>
</html>
