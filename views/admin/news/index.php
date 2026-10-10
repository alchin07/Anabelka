<?php
$pageTitle = 'Новини';
$items = is_array($items ?? null) ? $items : [];
$languages = is_array($languages ?? null) ? $languages : [];
$csrfToken = (string) ($csrfToken ?? '');
$flash = is_array($flash ?? null) ? $flash : null;
$loadError = trim((string) ($loadError ?? ''));
$escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Адмін-панель · Новини — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/admin-news.css?v=1">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-news-page">
    <?php if ($flash): ?>
        <div class="admin-news-flash <?= ($flash['type'] ?? '') === 'success' ? 'is-success' : 'is-error' ?>">
            <?= $escape($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>

    <?php if ($loadError !== ''): ?>
        <div class="admin-news-warning"><?= $escape($loadError) ?></div>
    <?php endif; ?>

    <section class="admin-news-create">
        <h2>Створити новину</h2>
        <form method="post" action="/Anabelka/admin/news/create">
            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
            <div class="admin-news-grid">
                <label class="admin-news-field">
                    <span>Заголовок українською</span>
                    <input type="text" name="title" maxlength="255" required>
                </label>
                <label class="admin-news-field">
                    <span>Дата публікації</span>
                    <input type="datetime-local" name="published_at">
                </label>
                <label class="admin-news-field is-wide">
                    <span>Короткий опис</span>
                    <textarea name="summary" maxlength="2000"></textarea>
                </label>
                <label class="admin-news-field is-wide">
                    <span>Текст новини</span>
                    <textarea name="body" maxlength="50000" required></textarea>
                </label>
                <label class="admin-news-field is-wide">
                    <span>Шлях до зображення</span>
                    <input type="text" name="image_path" maxlength="500" placeholder="uploads/... або https://...">
                </label>
            </div>
            <div class="admin-news-actions">
                <button class="is-primary" type="submit">Створити чернетку</button>
            </div>
        </form>
    </section>

    <?php if (empty($items)): ?>
        <section class="admin-news-card">Новин поки немає.</section>
    <?php endif; ?>

    <?php foreach ($items as $item): ?>
        <?php
        $id = (int) ($item['id'] ?? 0);
        $translations = is_array($item['translations'] ?? null)
            ? $item['translations']
            : [];
        $publishedAt = trim((string) ($item['published_at'] ?? ''));
        $publishedInput = $publishedAt !== ''
            ? date('Y-m-d\TH:i', strtotime($publishedAt))
            : '';
        $isPublished = ($item['status'] ?? '') === 'published';
        ?>
        <article class="admin-news-card">
            <div class="admin-news-meta">
                <strong>#<?= $id ?> · <?= $escape($item['slug'] ?? '') ?></strong>
                <span><?= $isPublished ? 'Опубліковано' : 'Чернетка' ?></span>
                <span>Створено: <?= $escape($item['created_at'] ?? '') ?></span>
            </div>

            <form method="post" action="/Anabelka/admin/news/update">
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="news_id" value="<?= $id ?>">
                <div class="admin-news-grid">
                    <label class="admin-news-field">
                        <span>Заголовок українською</span>
                        <input type="text" name="title" maxlength="255" value="<?= $escape($item['title'] ?? '') ?>" required>
                    </label>
                    <label class="admin-news-field">
                        <span>Дата публікації</span>
                        <input type="datetime-local" name="published_at" value="<?= $escape($publishedInput) ?>">
                    </label>
                    <label class="admin-news-field is-wide">
                        <span>Короткий опис</span>
                        <textarea name="summary" maxlength="2000"><?= $escape($item['summary'] ?? '') ?></textarea>
                    </label>
                    <label class="admin-news-field is-wide">
                        <span>Текст новини</span>
                        <textarea name="body" maxlength="50000" required><?= $escape($item['body'] ?? '') ?></textarea>
                    </label>
                    <label class="admin-news-field is-wide">
                        <span>Шлях до зображення</span>
                        <input type="text" name="image_path" maxlength="500" value="<?= $escape($item['image_path'] ?? '') ?>">
                    </label>
                </div>

                <div class="admin-news-translations">
                    <?php foreach ($languages as $language): ?>
                        <?php
                        $code = strtolower(trim((string) ($language['code'] ?? '')));
                        if ($code === '' || $code === Language::SOURCE_CODE) {
                            continue;
                        }
                        $translation = is_array($translations[$code] ?? null)
                            ? $translations[$code]
                            : [];
                        ?>
                        <details>
                            <summary><?= $escape(($language['name'] ?? $code) . ' (' . strtoupper($code) . ')') ?></summary>
                            <div class="admin-news-grid" style="margin-top:10px">
                                <label class="admin-news-field is-wide">
                                    <span>Заголовок</span>
                                    <input type="text" name="translations[<?= $escape($code) ?>][title]" maxlength="255" value="<?= $escape($translation['title'] ?? '') ?>">
                                </label>
                                <label class="admin-news-field is-wide">
                                    <span>Короткий опис</span>
                                    <textarea name="translations[<?= $escape($code) ?>][summary]" maxlength="2000"><?= $escape($translation['summary'] ?? '') ?></textarea>
                                </label>
                                <label class="admin-news-field is-wide">
                                    <span>Текст</span>
                                    <textarea name="translations[<?= $escape($code) ?>][body]" maxlength="50000"><?= $escape($translation['body'] ?? '') ?></textarea>
                                </label>
                                <input type="hidden" name="translations[<?= $escape($code) ?>][source]" value="manual">
                                <input type="hidden" name="translations[<?= $escape($code) ?>][status]" value="approved">
                            </div>
                        </details>
                    <?php endforeach; ?>
                </div>

                <div class="admin-news-actions">
                    <button class="is-primary" type="submit">Зберегти</button>
                </div>
            </form>

            <div class="admin-news-actions">
                <form class="admin-news-inline-form" method="post" action="/Anabelka/admin/news/publish">
                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="news_id" value="<?= $id ?>">
                    <input type="hidden" name="published" value="<?= $isPublished ? '0' : '1' ?>">
                    <button type="submit"><?= $isPublished ? 'Зняти з публікації' : 'Опублікувати' ?></button>
                </form>
                <form class="admin-news-inline-form" method="post" action="/Anabelka/admin/news/delete" onsubmit="return confirm('Видалити цю новину?');">
                    <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                    <input type="hidden" name="news_id" value="<?= $id ?>">
                    <button class="is-danger" type="submit">Видалити</button>
                </form>
            </div>
        </article>
    <?php endforeach; ?>
</main>
</body>
</html>
