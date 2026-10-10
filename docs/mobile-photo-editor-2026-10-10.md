# Мобильный редактор фотографий — 10 октября 2026

База: `eae53ff1b99ae42a74e75c61a4b171b66db02923`.
Ветка: `feature/category-manager`.

## Изменение

Кнопки предпросмотра и сравнения используют встроенные SVG. При ширине
до 650 CSS-пикселей видны иконки, на широком экране — иконки и текст.
Названия доступны через aria-label, title и визуально скрытый текст.
Области нажатия не меньше 44×44; сетка карточки не растягивается из-за подписи цвета.

Кнопка «Деталі обробки» находится в верхней панели рядом с закрытием
как до применения, так и в сравнении принятого результата. Диалог деталей
получил доступное название, управление фокусом и прокрутку с видимой кнопкой
закрытия. Back и Escape закрывают сначала детали, затем сравнение.

Нижний range и подпись удалены. Единственный разделитель на фото хранит
своё числовое положение, сообщает значение скринридеру и поддерживает
перетаскивание, ArrowLeft/ArrowRight, Home/End. SVG-стрелки центрированы
в сохранённом круге 38×38; область захвата разделителя — 44 пикселя.
Высота фотографии учитывает высоту окна и место для верхних и нижних действий.
На коротких экранах подписи оригинала и результата находятся в разных углах
и выше разделительной линии.

PHP, Python, маршруты, безопасность, обработка фотографий и БД не изменены.

## Браузерная проверка

Настоящий headless Chromium 153.0.8010.0 с сенсорной эмуляцией.
Сервер теста загружает реальные JS/CSS; тестовый HTML повторяет структуру редактора
из PHP-представления;
preview/confirm/cancel отвечают тестовыми данными, фотографии заменены различающимися SVG.
Физический Android/KSWEB здесь не проверялся.

| Экран, CSS px | Размер фото | Размер окна | Кнопки применения/отмены |
|---|---|---|---|
| 320×740 | 284×426 | 304×558 | 138×44 каждая |
| 360×740 | 324×486 | 344×618 | 158×44 каждая |
| 390×740 | 354×531 | 374×663 | 173×44 каждая |
| 412×740 | 373,33×559,98 | 396×691,98 | 184×44 каждая |
| 320/360/390/412×360 | 120×180 | ширина экрана − 16, высота 312 | высота 44, ширина как выше |

Информация и закрытие: 44×44 на всех размерах. Карточка при 320 px:
clientWidth = scrollWidth = 135, кнопки действий 58,75×44, скрытый текст 1×1.
На desktop 1100×900 кнопки 177,75×44 с видимыми подписями.

21 браузерный тест проверяет реальные размеры и пересечения, отсутствие
горизонтальной прокрутки, видимость панелей, загрузку картинок, центр SVG,
mouse и CDP touch, крайние положения и вычисленный clip-path, монотонность сенсорного перемещения,
отсутствие прокрутки во время жеста, детали до/после confirm, реальные
history/popstate и четыре способа отмены без запроса confirm.
Скриншоты 320×360, 390×640 и карточек mobile/desktop визуально проверены.

## Автоматические результаты

| Проверка | Результат |
|---|---|
| Chromium | 21/21, без пропусков |
| UI actions/history/accessibility | 40/40 |
| Целевая Node-серия image/preview/CSRF/permissions | 104/104 |
| Полная Node-серия на базе | 212: 185 проходят, 27 ошибок |
| Полная Node-серия после изменения | 238: 211 проходят, те же 27 ошибок |
| Новые/изменённые ошибки полной Node-серии | 0/0 |
| Полная Python-серия | 192: 191 проходит, 1 пропуск |
| JS и тесты: node --check; git diff --check | проходят |

Новые UI-тесты сначала воспроизвели 7 дефектов на базе; браузерные проверки
также воспроизвели ошибки размеров/иконок/доступности деталей. Последующие
проверки выявили и исправили переполнение при 320 px и пересечение подписей.

Тестовые зависимости ставятся вне приложения: jsdom, Playwright и Chromium.
Для PHP runtime использован native PHP 8.3.6 с PDO; для Python — внешние
OpenCV/NumPy/Pillow. Зависимости приложения не добавлялись.

```sh
# Если зависимости находятся в ../test-deps:
npm install --prefix ../test-deps jsdom playwright
../test-deps/node_modules/.bin/playwright install chromium
# Либо укажите путь к уже установленному браузеру через ANABELKA_UI_TEST_BROWSER.
node --test tests/product_image_processing_mobile_browser.test.mjs
node --test tests/product_image_processing_preview_ui.test.mjs
node --test tests/image_processor*.test.mjs tests/product_image*.test.mjs tests/csrf_core_contract.test.mjs tests/admin_product_central_csrf_contract.test.mjs tests/admin_permission_guard_contract.test.mjs
node --test tests/*.test.mjs
python -B -m unittest discover -s tests -p 'test_*.py'
node --check js/admin-products.js
git diff --check
```

## Существующие ошибки полной Node-серии

Имена совпадают с неизменённым контрольным коммитом; эти модули не исправлялись
в рамках задачи:

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
