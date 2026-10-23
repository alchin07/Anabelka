# VC-01 · Звіт перевірки

Дата: 2026-10-23. Гілка результату: `feature/visitor-counter`. Перевірена віддалена база перед початком точно збігалася з контрольним `69fe3bcacd64b2d74e7728d6c869d61ee48f0d37` (`feature/category-manager`, дерево `b0d9492b5c6f88c6ff1e3f227c356b714450fe11`). Локальна окрема копія була чистою, stash не використовувався. Перевірено відкриті PR №4, №5, №6, №7; їх не змінювали.

## Реалізовано

- Один агрегований page view на успішний GET дозволеної сторінки; actual ID, атомарний upsert, єдиний пояс Europe/Kyiv, без ідентифікаторів відвідувача.
- Вимкнений за замовчуванням збір, точні allowlisted hosts/base path, службові/тестові/admin/bot/prefetch/error/redirect виключення; власні та успадковані 18+ не деталізуються.
- Read-only адмін-звіт: сьогодні/вчора/останні7/30 календарних днів, графік і таблиця30 днів, рейтинги7/30/90; різні empty/unavailable стани.
- Окреме право visitor_stats.view, server-side403, меню та сервіс наявного конструктора без дублікатів.
- Окрема ідемпотентна міграція й офлайн-очищення з dry-run, cutoff та обмеженими batch.

## Автоматичні перевірки

| Перевірка | Результат |
| --- | --- |
| `php tests/visitor_counter_runtime.php` | 73/73 |
| `php tests/visitor_counter_hooks_runtime.php` | 64/64 |
| `php tests/visitor_stats_runtime.php` | Пройдено: дати, DST, періоди, privacy graph, метадані, порожній/помилковий стан, 403, escaping |
| `node --test tests/visitor_counter_contract.test.mjs` | 3/3 |
| `bash tests/run-visitor-counter-db.sh` | 88/88 реальних MariaDB/HTTP assertions |
| PHP syntax | 315 файлів, 0 помилок |
| JavaScript syntax | 59 файлів js/ + 2 нові test mjs, 0 помилок |
| `git diff --check` | Пройдено |

MariaDB-тест створював **новий випадковий datadir**, власний TCP-сервер та порожню `anabelka_vc_test`; після завершення runner видалив тільки цю тимчасову інстанцію. Прямий запуск PHP вимагає явно заданий DSN із суфіксом `_vc_test` та порожню БД, не читає config/database.php. Непорожня/звичайна БД й дубльований dbname відхиляються без змін. Жодна робоча БД або фотографія не використовувалася.

Підтверджено реальним SQL: повторне застосування міграції, 4 дозволені поля, 8 процесів ×50 інкрементів =400 без втрат, локальна північ, відсутність змін session settings; fail-open при row lock близько0.12с і при відсутній таблиці без DDL. Реальні HTTP-запити через production facade перевіряють reload/HEAD/POST/AJAX/JSON/bot/prefetch/admin/local/status/fail-open. Production admin controller з реальним AdminAccess повертає200 для дозволених ролей і403 для заборонених; явна видача й відкликання права перевірені також для звичайного Адміністратора. Admin-запити не збільшують показники.

Окремий runtime запускає фактичні 8 публічних controller actions з real counter та ізольованим domain/render I/O: ID category42/product71/news9, завершення view перед SQL, жодного запису при render exception, 18+ після підтвердження, preview403/news404/collection503. Чотири контрольні мутації в тимчасових копіях (видалення hook, перенесення перед view, неправильний ID, видалення adult-прапорця) усі були виявлені тестом.

## Браузер

`node --test tests/visitor_stats_browser.test.mjs` з установленим Playwright/Chromium: **17/17, 0 failures**. Фактичні PHP view, CSS і спільний header, явно синтетичні дані, жодної робочої сесії/БД. Перевірено populated/empty/unavailable на320,360,390,430,1280px, довгі й небезпечні назви, відсутність viewport overflow, відсутність зовнішніх запитів, завантаження локальної мініатюри, 7/30/90 GET-перемикання, доступну SVG-назву й осі, 30 дат у таблиці та Enter/Space.

У браузері виявили й виправили читабельність осей: до зміни SVG шрифт на320px зменшувався приблизно до3.7px; regression-тест спочатку впав, після виправлення пройшов. Тепер графік розміщений у підписаній keyboard-focusable scroll region, з видимою підказкою; мінімальний фактичний шрифт осей10.67px. Перевірено реальне горизонтальне прокручування ArrowRight, а не лише CSS overflow. Візуально переглянуті screenshot populated320/1280, empty/unavailable320 та відкрита таблиця. Додаткова нестабільність лише helper-а знімків (очікування точного scrollY=0 під час native smooth focus scrolling) усунена; фінальні17 перевірок виконані після виправлення.

Відтворення browser-тесту: встановіть Playwright окремо від production-коду, задайте `ANABELKA_UI_TEST_DEPS` (каталог з node_modules) або runtime node modules, `ANABELKA_UI_TEST_BROWSER` (шлях до Chromium, якщо потрібно), потім `node --test tests/visitor_stats_browser.test.mjs`. `ANABELKA_UI_TEST_SCREENSHOTS` необов'язково вказує каталог знімків. Браузерний fixture доступний тільки CLI.

## Регресії: старі помилки відокремлено

Однаковий набір35 контрактних файлів був запущений на незмінному snapshot базового69fe3bc та на результаті: **101 тест,87 пройдено,14 старих падінь у13 файлах** в обох випадках. Порівняно точний список14 failing test names — збігається, нових падінь у цьому наборі немає.

| Наявний failing файл | Наявне очікування, яке вже не відповідає базі |
| --- | --- |
| admin_dashboard_builder_contract | dragging pseudo-element title attribute |
| admin_dashboard_service_registry_contract | catalog() source-call expectation |
| admin_product_notifications_migration_contract | admin-products.js?v=35 |
| admin_product_shared_notice_contract | admin-products.js?v=35 |
| catalog_pagination_contract | colorVariantsForProducts source call |
| final_review_home_mobile_polish_contract | variant hex source pattern |
| home_page_builder_contract | Hero label |
| home_right_rail_contract | latestPublished(3,…) source call |
| product_cart_available_stock_contract | product-color-variants.js?v=6 |
| product_page_notifications_contract | product-color-variants.js?v=6 |
| product_stock_total_consistency_contract | duplicate-size wording + admin-products.js?v=10 (2 failures) |
| product_variant_color_contract | roots/imageList/uploadPreview source pattern |
| public_catalog_sidebar_contract | scrollbar thumb gradient/72px pattern |

Контрольний набір12 інших контрактних файлів по каталогу/товару/18+/пошуку/VIP/замовленнях/конструкторах проходить25/25. Це source/runtime regressions із тестовими межами, не повний ручний E2E магазину з робочими даними. Старі помилки не виправлялися в межах VC-01.

## Незалежне рев'ю та межі перевірки

Незалежний агент перевірив повний diff і нові файли: суттєвих функціональних/безпекових помилок не виявлено. Виявлені прогалини тестів (реальні публічні controller hooks та явна видача права ordinary administrator) закриті окремими перевірками. Мобільна читабельність осей перевіряється браузерним regression-тестом.

| Середовище | Статус |
| --- | --- |
| Linux, PHP8.3.6, MariaDB10.11.14, Chromium | Автоматичні перевірки виконані |
| PHP8.2 / MariaDB10.4 | Використано сумісні конструкції; нативний запуск цих точних версій ще потрібний |
| Android16 / KSWEB PRO4.07 | Фізичний пристрій недоступний; окрема інструкція й ізольовані тести підготовлені |
| Windows11 / XAMPP | Нативна перевірка ще потрібна; інструкція надана |

Збір залишається вимкненим. Робочий магазин не оновлювався, міграція в робочій БД не виконувалася. Main, feature/category-manager, feature/admin-chat, Python Image Processor та робочі фото не змінювалися цим завданням. Merge не виконувався. Перед публічним увімкненням — окрема target-device перевірка та перевірка актуальних privacy/information requirements.

Інструкція встановлення й очищення: [visitor-counter.md](visitor-counter.md). Міграція: [2026-10-23_visitor_counter.sql](../database/migrations/2026-10-23_visitor_counter.sql).

## Повний список файлів результату

- `.gitignore`
- `app/Controllers/AdminVisitorStatsController.php`
- `app/Controllers/CatalogController.php`
- `app/Controllers/HomeController.php`
- `app/Controllers/NewsController.php`
- `app/Controllers/ProductController.php`
- `app/Controllers/StorefrontPageController.php`
- `app/Core/App.php`
- `app/Core/Controller.php`
- `app/Models/AdminAccess.php`
- `app/Models/AdminDashboardServiceRegistry.php`
- `app/Models/VisitorStats.php`
- `app/Services/VisitorPageViewCounter.php`
- `config/visitor-stats.local.example.php`
- `config/visitor-stats.php`
- `css/admin-visitor-stats.css`
- `database/migrations/2026-10-23_visitor_counter.sql`
- `docs/superpowers/plans/2026-10-23-visitor-counter.md`
- `docs/visitor-counter-test-report.md`
- `docs/visitor-counter.md`
- `routes/AdminSecurity.php`
- `tests/fixtures/visitor_stats_page.php`
- `tests/run-visitor-counter-db.sh`
- `tests/visitor_counter_concurrency_worker.php`
- `tests/visitor_counter_contract.test.mjs`
- `tests/visitor_counter_hooks_runtime.php`
- `tests/visitor_counter_mariadb.php`
- `tests/visitor_counter_runtime.php`
- `tests/visitor_counter_test_server.php`
- `tests/visitor_stats_browser.test.mjs`
- `tests/visitor_stats_runtime.php`
- `tools/visitor-stats-prune.php`
- `views/admin/administrators/index.php`
- `views/admin/partials/header.php`
- `views/admin/visitor-stats/index.php`
