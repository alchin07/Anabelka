# ERR-01 — изменённые файлы

Всего файлов относительно `69fe3bcacd64b2d74e7728d6c869d61ee48f0d37`: **31**.

## Существующие файлы

- `.htaccess`
- `app/Core/ErrorHandler.php`
- `app/Core/PublicErrorPage.php`
- `app/Core/Router.php`
- `app/Models/AdminAccess.php`
- `app/Models/AdminDashboardServiceRegistry.php`
- `app/Models/AppSetting.php`
- `css/public-error.css`
- `routes/AdminSecurity.php`
- `tests/public_error_notify_cleanup_contract.test.mjs`
- `tests/public_error_privacy_and_zero_badge_contract.test.mjs`
- `views/admin/administrators/audit.php`
- `views/admin/system/error-test.php`
- `views/errors/public.php`

## Новые файлы

- `app/Controllers/AdminErrorDisplayController.php`
- `app/Services/ErrorDisplayMode.php`
- `css/admin-error-display.css`
- `docs/ERR-01-browser-settings.jpg`
- `docs/ERR-01-existing-failures.txt`
- `docs/ERR-01-files.md`
- `docs/ERR-01-install-android.md`
- `docs/ERR-01-rollback.md`
- `docs/ERR-01-verification.md`
- `docs/superpowers/plans/2026-10-26-error-display-modes.md`
- `docs/superpowers/specs/2026-10-26-error-display-modes-design.md`
- `tests/error_display_http.test.mjs`
- `tests/error_display_mode_runtime.php`
- `tests/fixtures/error-display-router.php`
- `views/admin/system/error-display.php`
- `views/admin/system/error-examples.php`
- `views/errors/diagnostic.php`

Назначение: Core — интеграция и безопасные ответы; модели — чтение без DDL/проверка сессии/аудит/реестр; новый сервис и контроллер — режим и сохранение; views/CSS — фирменный интерфейс; tests — политика и изолированные HTTP/MariaDB fixtures; docs — инструкции и результаты.

Конфигурация рабочей БД, index.php, фотографии, заказы, Image Processor, чат и счётчик посещений не изменены.
