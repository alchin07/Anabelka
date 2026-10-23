<?php
$pageTitle = 'Чат адміністраторів';
$adminId = (int) ($adminId ?? 0);
$csrfToken = (string) ($csrfToken ?? '');
$loadError = (string) ($loadError ?? '');
$escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Чат адміністраторів — Анабелька</title>
    <link rel="stylesheet" href="/Anabelka/css/style.css?v=9">
    <link rel="stylesheet" href="/Anabelka/css/admin-chat.css?v=5">
</head>
<body>
<?php require __DIR__ . '/../partials/header.php'; ?>

<main class="admin-chat-page" id="admin-chat" data-admin-id="<?= $adminId ?>"
      data-csrf="<?= $escape($csrfToken) ?>"
      data-role="<?= $escape(AdminAccess::current()['role_slug'] ?? '') ?>"
      data-emoji-graphics="/Anabelka/assets/chat-emoji/graphics">
    <div class="admin-chat-heading">
        <div>
            <h2>Чат команди <span aria-hidden="true">💜</span></h2>
            <p>Особисті розмови та групи адміністраторів Анабельки</p>
        </div>
        <span class="chat-privacy"><span aria-hidden="true">🔒</span> Лише для адміністраторів</span>
    </div>

    <?php if ($loadError !== ''): ?>
        <p class="chat-setup-error" role="alert"><?= $escape($loadError) ?></p>
    <?php endif; ?>

    <div class="chat-layout" id="chat-layout">
        <aside class="chat-sidebar" aria-label="Список розмов">
            <div class="chat-sidebar-heading">
                <h3>Повідомлення <span class="chat-all-count" id="chat-all-count" hidden></span></h3>
                <div class="chat-quick-actions">
                    <button type="button" class="chat-icon-button" id="chat-new-direct"
                            aria-label="Нова особиста розмова" title="Написати адміністратору">✎</button>
                    <button type="button" class="chat-new-group" id="chat-new-group">+ Група</button>
                </div>
            </div>
            <label class="chat-search-label" for="chat-search">Знайти бесіду або повідомлення</label>
            <div class="chat-search-box">
                <span aria-hidden="true">⌕</span>
                <input type="search" id="chat-search" placeholder="Пошук..." autocomplete="off">
            </div>
            <div id="chat-search-results" class="chat-search-results" hidden></div>
            <div id="chat-conversations" class="chat-conversations" role="list">
                <p class="chat-help">Завантаження розмов…</p>
            </div>
        </aside>

        <section class="chat-thread" aria-label="Повідомлення розмови">
            <header class="chat-thread-header">
                <button type="button" id="chat-mobile-back" class="chat-icon-button chat-back"
                        aria-label="Повернутися до списку">←</button>
                <span class="chat-thread-avatar" id="chat-thread-avatar"></span>
                <div class="chat-thread-heading">
                    <h3 id="chat-thread-title">Оберіть розмову</h3>
                    <p id="chat-thread-subtitle">Спілкуйтеся з адміністраторами</p>
                </div>
                <button type="button" id="chat-manage-group" class="chat-icon-button"
                        aria-label="Керування групою" title="Керування групою" hidden>⚙</button>
            </header>

            <div id="chat-messages" class="chat-messages" role="log"
                 aria-label="Історія повідомлень" aria-live="off">
                <div class="chat-empty">
                    <span class="chat-empty-icon" aria-hidden="true">💜</span>
                    <strong>Ваш простір для спілкування</strong>
                    <span>Оберіть існуючу бесіду або створіть нову.</span>
                </div>
            </div>

            <div class="chat-reply-banner" id="chat-reply-banner" hidden>
                <div><strong>Відповідь</strong><span id="chat-reply-text"></span></div>
                <button type="button" class="chat-icon-button" id="chat-reply-cancel"
                        aria-label="Скасувати відповідь">×</button>
            </div>

            <div class="chat-selected-files" id="chat-selected-files" hidden></div>

            <div class="chat-composer-wrap">
                <div class="chat-emoji-panel" id="chat-emoji-panel" hidden>
                    <div class="chat-emoji-search">
                        <input type="search" id="chat-emoji-search" placeholder="Знайти смайлик…" aria-label="Пошук емодзі">
                        <button type="button" id="chat-emoji-close" class="chat-icon-button" aria-label="Закрити емодзі">×</button>
                    </div>
                    <div class="chat-emoji-grid" id="chat-emoji-grid" aria-label="Вибір емодзі"></div>
                    <nav class="chat-emoji-tabs" id="chat-emoji-tabs" aria-label="Категорії емодзі"></nav>
                </div>
                <form id="chat-compose" class="chat-compose" enctype="multipart/form-data">
                    <input type="file" id="chat-files" name="files[]" multiple hidden
                           accept="image/jpeg,image/png,image/webp,image/gif,.pdf,.txt,.csv,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods">
                    <button type="button" class="chat-icon-button chat-attachment-button" id="chat-attach"
                            aria-label="Додати фото чи файл" title="Додати файл">＋</button>
                    <button type="button" class="chat-icon-button chat-emoji-button" id="chat-emoji-toggle"
                            aria-label="Вибрати смайлик" title="Емодзі">☺</button>
                    <label for="chat-message" class="chat-visually-hidden">Текст повідомлення</label>
                    <textarea id="chat-message" rows="1" maxlength="4000" name="body"
                              placeholder="Напишіть повідомлення…" enterkeyhint="send"></textarea>
                    <button class="chat-send" type="submit" id="chat-send"
                            aria-label="Надіслати повідомлення" title="Надіслати">➤</button>
                </form>
                <p class="chat-file-note">Файли до 8 МБ, максимум 4 за повідомлення. Enter — надіслати, Shift+Enter — новий рядок.</p>
            </div>
        </section>
    </div>
</main>

<dialog id="chat-direct-dialog" class="chat-dialog" aria-labelledby="chat-direct-title">
    <form method="dialog" class="chat-dialog-content" id="chat-direct-form">
        <div class="chat-dialog-title">
            <h3 id="chat-direct-title">Нова особиста розмова</h3>
            <button type="button" class="chat-icon-button" data-dialog-close aria-label="Закрити">×</button>
        </div>
        <label for="chat-direct-user">Оберіть адміністратора</label>
        <select id="chat-direct-user" required></select>
        <div class="chat-dialog-buttons">
            <button type="button" class="chat-secondary" data-dialog-close>Скасувати</button>
            <button type="submit" class="chat-primary">Почати чат</button>
        </div>
    </form>
</dialog>

<dialog id="chat-group-dialog" class="chat-dialog" aria-labelledby="chat-group-title">
    <form class="chat-dialog-content" id="chat-group-form">
        <div class="chat-dialog-title">
            <h3 id="chat-group-title">Створити групу</h3>
            <button type="button" class="chat-icon-button" data-dialog-close aria-label="Закрити">×</button>
        </div>
        <label for="chat-group-name">Назва групи</label>
        <input type="text" id="chat-group-name" required maxlength="120"
               placeholder="Наприклад, Замовлення">
        <label>Учасники</label>
        <div id="chat-group-users" class="chat-member-choices"></div>
        <div class="chat-dialog-buttons">
            <button type="button" class="chat-secondary" data-dialog-close>Скасувати</button>
            <button type="submit" class="chat-primary">Створити</button>
        </div>
    </form>
</dialog>

<dialog id="chat-manage-dialog" class="chat-dialog" aria-labelledby="chat-manage-title">
    <div class="chat-dialog-content">
        <div class="chat-dialog-title">
            <h3 id="chat-manage-title">Учасники групи</h3>
            <button type="button" class="chat-icon-button" data-dialog-close aria-label="Закрити">×</button>
        </div>
        <div class="chat-group-settings" id="chat-group-settings">
            <label for="chat-rename-input">Назва групи</label>
            <div class="chat-inline-form">
                <input type="text" id="chat-rename-input" maxlength="120">
                <button type="button" class="chat-primary" id="chat-rename">Зберегти</button>
            </div>
        </div>
        <h4>Учасники</h4>
        <div id="chat-current-members" class="chat-members-list"></div>
        <div class="chat-group-settings" id="chat-add-settings">
            <label for="chat-add-user">Додати адміністратора</label>
            <div class="chat-inline-form">
                <select id="chat-add-user"></select>
                <button type="button" class="chat-primary" id="chat-add-member">Додати</button>
            </div>
        </div>
    </div>
</dialog>

<dialog id="chat-delete-dialog" class="chat-dialog" aria-labelledby="chat-delete-title">
    <div class="chat-dialog-content">
        <div class="chat-dialog-title"><h3 id="chat-delete-title">Видалити повідомлення?</h3></div>
        <p>Текст та вкладення більше не будуть доступні учасникам чату.</p>
        <div class="chat-dialog-buttons">
            <button type="button" class="chat-secondary" data-dialog-close>Скасувати</button>
            <button type="button" class="chat-primary" id="chat-delete-confirm">Видалити</button>
        </div>
    </div>
</dialog>

<!-- Приватна галерея: посилання використовують існуючий серверний маршрут перевірки доступу. -->
<div id="chat-photo-viewer" class="chat-photo-viewer" role="dialog" aria-modal="true"
     aria-labelledby="chat-photo-title" aria-label="Перегляд фотографії" tabindex="-1" hidden>
    <header class="chat-photo-toolbar">
        <span id="chat-photo-counter" class="chat-photo-counter" aria-live="polite">1 / 1</span>
        <div class="chat-photo-controls">
            <button type="button" id="chat-photo-zoom-out" aria-label="Зменшити масштаб" title="Зменшити">−</button>
            <span id="chat-photo-zoom-label" class="chat-photo-zoom-label">100%</span>
            <button type="button" id="chat-photo-zoom-in" aria-label="Збільшити масштаб" title="Збільшити">+</button>
            <a id="chat-photo-save" class="chat-photo-save" href="#" download title="Зберегти фотографію">⬇ Зберегти</a>
            <button type="button" id="chat-photo-close" aria-label="Закрити фотографію" title="Закрити">×</button>
        </div>
    </header>
    <div class="chat-photo-canvas">
        <button type="button" id="chat-photo-prev" class="chat-photo-arrow is-prev"
                aria-label="Попереднє фото" title="Попереднє фото">‹</button>
        <div id="chat-photo-stage" class="chat-photo-stage" aria-label="Фото: два пальці для масштабу">
            <img id="chat-photo-image" alt="" draggable="false">
            <span id="chat-photo-status" class="chat-photo-status" role="status" hidden></span>
        </div>
        <button type="button" id="chat-photo-next" class="chat-photo-arrow is-next"
                aria-label="Наступне фото" title="Наступне фото">›</button>
    </div>
    <footer class="chat-photo-footer">
        <span id="chat-photo-title" class="chat-photo-name">Фото</span>
        <span class="chat-photo-hint">Гортайте свайпом · Два пальці для масштабу</span>
    </footer>
</div>
<p class="chat-toast" id="chat-toast" role="status" aria-live="polite" hidden></p>

<script defer src="/Anabelka/js/admin-chat-emoji.js?v=2"></script>
<script defer src="/Anabelka/js/admin-chat.js?v=5"></script>
<script defer src="/Anabelka/js/admin-chat-photo-viewer.js?v=1"></script>
</body>
</html>
