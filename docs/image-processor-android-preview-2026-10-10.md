# Android KSWEB: збереження preview та вибору методу

Гілка: `feature/category-manager`.
Контрольний коміт: `c835f8f473d0d03d820385c5a909443603d89310`.
Міграції БД та перезапуск Python для цих виправлень не потрібні.

## Що виправлено

`writeManifest()` більше не передає `LOCK_EX` до `file_put_contents()`.
Маніфест записується до унікального файла, відкритого в режимі `x+b`;
права `0600` встановлюються перед записом. Цикл перевіряє кожний запис,
дописує короткі записи та відхиляє `false`/нуль байтів. Після успішних
flush/close виконується атомарний `rename` у тому самому каталозі.
Помилки прибирають лише створений цією операцією тимчасовий файл;
попередній маніфест залишається цілим. Колізія імені не видаляє чужий файл.

Чернетка вибору профілю та маски зберігається окремо від прийнятих даних
фотографії. Busy, помилка, скасування, закриття, Escape, Android Back і
повторне відкриття редактора на поточній сторінці зберігають вибір.
Preview передає саме вибрані `background_profile`/`mask_mode`.
Успішне confirm замінює чернетку прийнятими сервером налаштуваннями.
Original+Canvas вимикає ручний вибір маски, зберігаючи його для повернення
до профілю із заміною фону. Оновлено версію JS-ресурсу `v=25` → `v=26`.

Блокування операцій, транзакційне confirm, перевірки адміністратора,
сесії, токена, строку дії, SHA256, CSRF і `products.manage` не змінені.
GrabCut/MODNet, розміри, геометрія, фонові профілі та схема БД не змінені.

## Перевірка на Android

У Termux:

```sh
cd /storage/emulated/0/htdocs/Anabelka
git branch --show-current
git status --short
git pull --ff-only origin feature/category-manager
git log -1 --oneline
git diff --check
```

Гілка має бути `feature/category-manager`. Власні правки і stash залишити
без змін; якщо pull повідомить про конфлікт локальних правок, не робити
reset/stash для обходу цього повідомлення.

Якщо в Termux доступний PHP CLI:

```sh
php -l app/Services/ProductImagePreviewService.php
php -l views/admin/products/index.php
php tests/product_image_preview_manifest_runtime.php create
php tests/product_image_preview_manifest_runtime.php partial
php tests/product_image_preview_manifest_runtime.php create-failure
php tests/product_image_preview_runtime.php
php tests/product_image_preview_security_runtime.php
```

Для запуску всіх 17 сценаріїв маніфеста без Node:

```sh
for scenario in create partial zero false partial-zero flush close rename chmod collision json write-exception flush-exception close-exception cleanup-close-exception rename-exception create-failure; do
    php tests/product_image_preview_manifest_runtime.php "$scenario" || break
done
```

`php: command not found` означає відсутність CLI у Termux, а не поломку
KSWEB; CLI можна встановити командою `pkg install php`. CLI-тести не
замінюють перевірку веб-PHP саме KSWEB.

1. Оновити сторінку товарів. JS має завантажуватися з `?v=26`.
2. Відкрити фотографію з наявним ready-результатом. Вибрати Studio Light
   → MODNet → «Попередній перегляд». Обидва select мають зберегти значення
   під час обробки. Має відкритися порівняння з робочим ползунком.
3. Перевірити діагностику: запрошений метод MODNet. Якщо доступна вкладка
   Network, POST preview має містити `background_profile=studio-light`
   та `mask_mode=modnet`.
4. Скасувати, закрити хрестиком, повторити із системною кнопкою «Назад».
   Для відкритих деталей перше «Назад» закриває деталі, наступне — preview.
   Повторно відкрити редактор без перезавантаження сторінки: вибір зберігся,
   публічна фотографія досі попередня.
5. Повторити preview та «Застосувати». Інтерфейс і діагностика мають
   показати прийнятий метод; готова фотографія оновлюється після confirm.
6. Перемкнути Original+Canvas: метод недоступний. Повернути Studio Light:
   ручний вибір знову доступний.
7. У `php.log` за новим часом не повинно бути помилок
   `Exclusive locks are not supported for this stream` або
   `Не вдалося зберегти пробу фотографії` від успішного preview.

## Автоматизована перевірка

| Перевірка | Результат |
|---|---|
| Цільова Node-серія image/preview/CSRF/permissions, native PHP 8.3.6 | 78 / 78 успішних |
| DOM UI preview/history/slider (входить до цільової серії) | 35 / 35 успішних |
| Маніфест: середовище без stream-lock, короткі записи та помилки | 17 / 17 успішних на native PHP 8.3.6 та WASM PHP 8.2.33 |
| PHP 8.2.33: існуючі preview та security runtime | 6 / 6 успішних |
| Native конкурентне confirm з двома процесами | 2 / 2 успішних |
| Повна Node-серія на контрольному коміті | 184: 157 успішних, 27 помилок |
| Повна Node-серія після виправлень | 212: 185 успішних, ті самі 27 помилок |
| Нові помилки / змінені відомі помилки | 0 / 0 |
| PHP 8.2/8.3 синтаксис, JS синтаксис, `git diff --check` | Успішно |

Регресійні тести спочатку відтворили дефекти: 8 UI-кейсів втрачали
Studio Light/MODNet; початкова серія маніфеста мала 15 помилок із 16,
зокрема відмову stream-lock під час повного create. Додатковий тест
перевірив cleanup при вторинному винятку закриття потоку.

Команди Node (PHP CLI, jsdom, OpenCV/NumPy/Pillow мають бути доступні
у тестовому середовищі; вони не додані до залежностей застосунку):

```sh
node --check js/admin-products.js
node --test tests/image_processor*.test.mjs tests/product_image*.test.mjs tests/csrf_core_contract.test.mjs tests/admin_product_central_csrf_contract.test.mjs tests/admin_permission_guard_contract.test.mjs
node --test tests/*.test.mjs
```

Для порівняння використано незмінений архів точного контрольного коміту
з назвою кореневого каталогу `Anabelka` та ті самі зовнішні залежності.
Повна Node-серія повертає exit 1 через наведені нижче існуючі помилки.

## Межі перевірки

Реальний Android/KSWEB PRO з PHP 8.2 тут недоступний: потрібна ручна
приймання на телефоні за кроками вище. Відмову stream-lock відтворено
маніфестним PHP stream-wrapper на реальних файлах; блокування операцій
залишаються справжніми `flock`. PHP 8.2 перевірено через WASM runtime.
DOM-тести відтворюють history/popstate, клавіатуру й події ползунка,
але не замінюють браузер телефону.

Конкурентні тести потребують native Linux PHP та доступного `/proc`
status/wchan: вони спостерігають фактичне очікування kernel flock.
На non-Linux/Emscripten або без доступного `/proc` їх явно пропускають.
Під час наведених запусків обидва виконалися без пропусків.
Використано справжні процеси,
файлові блокування та готові файли; БД/worker замінено спільною файловою
тестовою БД і підготовленими результатами. Вони перевіряють серіалізацію,
відмову застарілого токена, rollback та збереження попередніх ready-файлів,
але не перевіряють реальну MariaDB/InnoDB. Чернетка UI живе до
перезавантаження сторінки; після reload відображаються прийняті налаштування.

## Незмінені помилки baseline

- `tests/admin_android_back_contract.test.mjs`
- `tests/admin_audit_compact_groups_contract.test.mjs`
- `tests/admin_audit_notification_badges_contract.test.mjs`
- `tests/admin_dashboard_builder_contract.test.mjs`
- `tests/admin_dashboard_service_registry_contract.test.mjs`
- `editor scripts explicitly signal AI context changes`
- `tests/admin_mobile_navigation_contract.test.mjs`
- `tests/admin_view_manage_ui_contract.test.mjs`
- `tests/admin_work_time_contract.test.mjs`
- `legacy activity migration qualifies duplicate-key target columns`
- `header system badge uses AnabelkaNotify.formatCount when available`
- `header system badge keeps a safe 99+ fallback without the shared module`
- `tests/catalog_pagination_contract.test.mjs`
- `tests/category_manager_contract.test.mjs`
- `tests/category_thumbnail_select_contract.test.mjs`
- `tests/category_translation_status_select_contract.test.mjs`
- `tests/final_review_category_depth_palette_contract.test.mjs`
- `tests/final_review_home_mobile_polish_contract.test.mjs`
- `tests/home_page_builder_contract.test.mjs`
- `tests/home_right_rail_contract.test.mjs`
- `tests/mobile_navigation_runtime.test.mjs`
- `legacy product page refreshes visible stock from the AJAX response`
- `duplicate product sizes are rejected instead of silently merged`
- `stock consistency scripts are cache-busted`
- `removed product colors do not return from photos or matrix`
- `tests/public_admin_badge_permissions_contract.test.mjs`
- `tests/public_catalog_sidebar_contract.test.mjs`
