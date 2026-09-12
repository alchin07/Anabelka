<?php
$items = is_array($items ?? null) ? $items : [];
$summary = is_array($summary ?? null) ? $summary : [];
$workflowSummary = is_array($workflowSummary ?? null) ? $workflowSummary : [];
$filters = is_array($filters ?? null) ? $filters : [];
$availableDates = is_array($availableDates ?? null) ? $availableDates : [];
$selected = is_array($selected ?? null) ? $selected : null;
$selectedReference = (string) ($selectedReference ?? '');
$csrfToken = (string) ($csrfToken ?? '');
$flash = is_array($flash ?? null) ? $flash : null;
$escape = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$formatTime = static function ($value) {
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('d.m.Y H:i:s', $timestamp) : (string) $value;
};
$formatDate = static function ($value) {
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('d.m.Y', $timestamp) : (string) $value;
};
$kindLabels = [
    'uncaught_exception' => 'Необроблений виняток',
    'fatal_error' => 'Критична PHP-помилка',
    'php_error' => 'PHP-помилка',
    'handled_exception' => 'Оброблена помилка',
    'application' => 'Подія застосунку'
];
$kindLabel = static function ($kind) use ($kindLabels) {
    $kind = (string) $kind;
    return $kindLabels[$kind] ?? $kind;
};
$workflowLabels = [
    'new' => 'Нова',
    'viewed' => 'Переглянута',
    'resolved' => 'Вирішена',
    'ignored' => 'Ігнорована'
];
$workflowLabel = static function ($status) use ($workflowLabels) {
    $status = (string) $status;
    return $workflowLabels[$status] ?? $status;
};
$filterQuery = [
    'level' => (string) ($filters['level'] ?? 'all'),
    'status' => (string) ($filters['status'] ?? 'all'),
    'date' => (string) ($filters['date'] ?? ''),
    'q' => (string) ($filters['q'] ?? '')
];
$filterQuery = array_filter($filterQuery, static function ($value, $key) {
    return !(in_array($key, ['level', 'status'], true) && $value === 'all')
        && $value !== '';
}, ARRAY_FILTER_USE_BOTH);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $escape($pageTitle ?? 'Адмін-панель · Системні помилки') ?></title>
    <link rel="stylesheet" href="/Anabelka/css/admin-system-errors.css?v=4">
    <link rel="stylesheet" href="/Anabelka/css/admin-system-error-notes.css?v=1">
</head>
<body>

<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="system-errors-page">
    <section class="system-errors-intro">
        <div>
            <h2>Системні помилки</h2>
            <p>Технічний журнал Анабельки. Однакові помилки об’єднуються в групи.</p>
        </div>
        <a href="/Anabelka/admin/system/error-test">Тест обробника</a>
    </section>

    <section class="system-errors-summary" aria-label="Статистика помилок">
        <div>
            <span>Показано груп</span>
            <strong><?= (int) ($summary['total'] ?? 0) ?></strong>
            <small>Подій: <?= (int) ($summary['events'] ?? $summary['total'] ?? 0) ?></small>
        </div>
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
            <span>Статус</span>
            <select name="status">
                <?php foreach ([
                    'all' => 'Усі',
                    'new' => 'Нові',
                    'viewed' => 'Переглянуті',
                    'resolved' => 'Вирішені',
                    'ignored' => 'Ігноровані'
                ] as $value => $label): ?>
                    <option value="<?= $escape($value) ?>" <?= ($filters['status'] ?? 'all') === $value ? 'selected' : '' ?>>
                        <?= $escape($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="system-errors-date">
            <span>Дата</span>
            <select name="date">
                <option value="">Усі дати</option>
                <?php foreach ($availableDates as $date): ?>
                    <option value="<?= $escape($date) ?>" <?= ($filters['date'] ?? '') === $date ? 'selected' : '' ?>>
                        <?= $escape($formatDate($date)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
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

    <?php if ($flash): ?>
        <div class="system-error-flash is-<?= $escape($flash['type'] ?? 'success') ?>" role="status">
            <?= $escape($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if ($selectedReference !== ''): ?>
        <section class="system-error-detail">
            <?php if ($selected): ?>
                <?php
                $request = is_array($selected['request'] ?? null) ? $selected['request'] : [];
                $closeQuery = http_build_query($filterQuery);
                $selectedWorkflow = (string) ($selected['workflow_status'] ?? 'new');
                $selectedRepeatCount = max(1, (int) ($selected['repeat_count'] ?? 1));
                $occurrences = is_array($selected['occurrences'] ?? null)
                    ? $selected['occurrences']
                    : [];
                $developerNote = (string) ($selected['developer_note'] ?? '');
                $developerNoteUpdatedAt = (string) ($selected['developer_note_updated_at'] ?? '');
                $developerNoteUpdatedBy = (int) ($selected['developer_note_updated_by'] ?? 0);
                ?>
                <div class="system-error-detail-head">
                    <div>
                        <div class="system-error-badges">
                            <span class="system-error-level is-<?= $escape($selected['level'] ?? 'error') ?>">
                                <?= $escape(strtoupper((string) ($selected['level'] ?? 'error'))) ?>
                            </span>
                            <span class="system-error-workflow is-<?= $escape($selectedWorkflow) ?>">
                                <?= $escape($workflowLabel($selectedWorkflow)) ?>
                            </span>
                            <?php if ($selectedRepeatCount > 1): ?>
                                <span class="system-error-repeat-badge">
                                    ×<?= $selectedRepeatCount ?> повторів
                                </span>
                            <?php endif; ?>
                            <?php if ($developerNote !== ''): ?>
                                <span class="system-error-note-badge">Нотатка</span>
                            <?php endif; ?>
                        </div>
                        <h3><?= $escape($selected['reference'] ?? '') ?></h3>
                    </div>
                    <a href="/Anabelka/admin/system/errors<?= $closeQuery !== '' ? '?' . $escape($closeQuery) : '' ?>">Закрити</a>
                </div>

                <?php if ($selectedRepeatCount > 1): ?>
                    <div class="system-error-repeat-summary">
                        <strong>Повторювана помилка</strong>
                        <div>
                            <span>Перше:</span>
                            <b><?= $escape($formatTime($selected['first_time'] ?? '')) ?></b>
                        </div>
                        <div>
                            <span>Останнє:</span>
                            <b><?= $escape($formatTime($selected['last_time'] ?? $selected['time'] ?? '')) ?></b>
                        </div>
                    </div>
                <?php endif; ?>

                <dl class="system-error-detail-grid">
                    <div><dt>Час</dt><dd><?= $escape($formatTime($selected['time'] ?? '')) ?></dd></div>
                    <div><dt>Тип</dt><dd><?= $escape($kindLabel($selected['kind'] ?? '')) ?></dd></div>
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

                <?php if ($selectedRepeatCount > 1 && !empty($occurrences)): ?>
                    <details class="system-error-occurrences">
                        <summary>Останні спрацювання (<?= count($occurrences) ?>)</summary>
                        <div class="system-error-occurrence-list">
                            <?php foreach ($occurrences as $occurrence): ?>
                                <div class="system-error-occurrence-row">
                                    <time><?= $escape($formatTime($occurrence['time'] ?? '')) ?></time>
                                    <code><?= $escape($occurrence['reference'] ?? '') ?></code>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>

                <form class="system-error-note" method="post" action="/Anabelka/admin/system/errors/note">
                    <div class="system-error-note-head">
                        <strong>Нотатка розробника</strong>
                        <?php if ($developerNoteUpdatedAt !== ''): ?>
                            <span class="system-error-note-meta">
                                Оновлено <?= $escape($formatTime($developerNoteUpdatedAt)) ?>
                                <?= $developerNoteUpdatedBy > 0 ? ' · адм. ' . $developerNoteUpdatedBy : '' ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="reference" value="<?= $escape($selected['reference'] ?? '') ?>">
                    <input type="hidden" name="filter_level" value="<?= $escape($filters['level'] ?? 'all') ?>">
                    <input type="hidden" name="filter_status" value="<?= $escape($filters['status'] ?? 'all') ?>">
                    <input type="hidden" name="filter_date" value="<?= $escape($filters['date'] ?? '') ?>">
                    <input type="hidden" name="filter_q" value="<?= $escape($filters['q'] ?? '') ?>">

                    <textarea
                        name="note"
                        maxlength="4000"
                        placeholder="Наприклад: причина знайдена, виправлено в OAuth, перевірити після завантаження на хостинг..."
                    ><?= $escape($developerNote) ?></textarea>

                    <div class="system-error-note-foot">
                        <span class="system-error-note-hint">
                            Нотатка прив’язана до всієї групи повторюваної помилки. Щоб видалити її, очистіть поле та збережіть.
                        </span>
                        <button type="submit">Зберегти нотатку</button>
                    </div>
                </form>

                <div class="system-error-status-actions" aria-label="Статус помилки">
                    <?php if ($selectedWorkflow !== 'resolved'): ?>
                        <form method="post" action="/Anabelka/admin/system/errors/status">
                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="reference" value="<?= $escape($selected['reference'] ?? '') ?>">
                            <input type="hidden" name="status" value="resolved">
                            <input type="hidden" name="filter_level" value="<?= $escape($filters['level'] ?? 'all') ?>">
                            <input type="hidden" name="filter_status" value="<?= $escape($filters['status'] ?? 'all') ?>">
                            <input type="hidden" name="filter_date" value="<?= $escape($filters['date'] ?? '') ?>">
                            <input type="hidden" name="filter_q" value="<?= $escape($filters['q'] ?? '') ?>">
                            <button type="submit" class="is-resolved">Вирішено</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($selectedWorkflow !== 'ignored'): ?>
                        <form method="post" action="/Anabelka/admin/system/errors/status">
                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="reference" value="<?= $escape($selected['reference'] ?? '') ?>">
                            <input type="hidden" name="status" value="ignored">
                            <input type="hidden" name="filter_level" value="<?= $escape($filters['level'] ?? 'all') ?>">
                            <input type="hidden" name="filter_status" value="<?= $escape($filters['status'] ?? 'all') ?>">
                            <input type="hidden" name="filter_date" value="<?= $escape($filters['date'] ?? '') ?>">
                            <input type="hidden" name="filter_q" value="<?= $escape($filters['q'] ?? '') ?>">
                            <button type="submit" class="is-ignored">Ігнорувати</button>
                        </form>
                    <?php endif; ?>

                    <?php if (in_array($selectedWorkflow, ['resolved', 'ignored'], true)): ?>
                        <form method="post" action="/Anabelka/admin/system/errors/status">
                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                            <input type="hidden" name="reference" value="<?= $escape($selected['reference'] ?? '') ?>">
                            <input type="hidden" name="status" value="viewed">
                            <input type="hidden" name="filter_level" value="<?= $escape($filters['level'] ?? 'all') ?>">
                            <input type="hidden" name="filter_status" value="<?= $escape($filters['status'] ?? 'all') ?>">
                            <input type="hidden" name="filter_date" value="<?= $escape($filters['date'] ?? '') ?>">
                            <input type="hidden" name="filter_q" value="<?= $escape($filters['q'] ?? '') ?>">
                            <button type="submit" class="is-viewed">Повернути в роботу</button>
                        </form>
                    <?php endif; ?>
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
            <?php
            $request = is_array($item['request'] ?? null) ? $item['request'] : [];
            $detailQuery = $filterQuery;
            $detailQuery['ref'] = (string) ($item['reference'] ?? '');
            $itemWorkflow = (string) ($item['workflow_status'] ?? 'new');
            $repeatCount = max(1, (int) ($item['repeat_count'] ?? 1));
            $itemNote = trim((string) ($item['developer_note'] ?? ''));
            ?>
            <article class="system-error-card is-status-<?= $escape($itemWorkflow) ?>">
                <div class="system-error-card-head">
                    <div class="system-error-badges">
                        <span class="system-error-level is-<?= $escape($item['level'] ?? 'error') ?>">
                            <?= $escape(strtoupper((string) ($item['level'] ?? 'error'))) ?>
                        </span>
                        <span class="system-error-workflow is-<?= $escape($itemWorkflow) ?>">
                            <?= $escape($workflowLabel($itemWorkflow)) ?>
                        </span>
                        <?php if ($repeatCount > 1): ?>
                            <span class="system-error-repeat-badge">×<?= $repeatCount ?></span>
                        <?php endif; ?>
                        <?php if ($itemNote !== ''): ?>
                            <span class="system-error-note-badge">Нотатка</span>
                        <?php endif; ?>
                    </div>
                    <time><?= $escape($formatTime($item['last_time'] ?? $item['time'] ?? '')) ?></time>
                </div>

                <strong class="system-error-reference"><?= $escape($item['reference'] ?? '') ?></strong>
                <p class="system-error-message"><?= $escape($item['message'] ?? '') ?></p>

                <?php if ($repeatCount > 1): ?>
                    <div class="system-error-repeat-line">
                        Повторилося <?= $repeatCount ?> разів · перше <?= $escape($formatTime($item['first_time'] ?? '')) ?>
                    </div>
                <?php endif; ?>

                <div class="system-error-meta">
                    <span><?= $escape($request['method'] ?? '') ?></span>
                    <code><?= $escape($request['uri'] ?? '') ?></code>
                </div>

                <div class="system-error-card-foot">
                    <span title="<?= $escape($item['kind'] ?? '') ?>"><?= $escape($kindLabel($item['kind'] ?? '')) ?></span>
                    <a href="/Anabelka/admin/system/errors?<?= $escape(http_build_query($detailQuery)) ?>">
                        Подробиці
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    </section>
</main>

</body>
</html>
