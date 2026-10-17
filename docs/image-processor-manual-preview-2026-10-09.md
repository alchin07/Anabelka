# Ручний вибір методу обробки фотографій

Початковий коміт: `46823d182abb19604fb672d5bb7c363f42659e30`.
Робоча гілка: `feature/category-manager`. Міграція БД не потрібна.

## Перевірка на Android

1. У Termux відкрийте `/storage/emulated/0/htdocs/Anabelka` та перевірте
   `git branch --show-current` і `git status --short`. Гілка має бути
   `feature/category-manager`. Власні зміни та stash залиште без змін.
2. На чистій робочій копії виконайте `git pull --ff-only origin feature/category-manager`.
3. Перезапустіть наявний Python Image Processor, щоб він завантажив новий
   `tools/image-processor/server.py`. Звичний запуск із кореня проєкту:
   `python tools/image-processor/server.py`. Використовуйте лише один
   екземпляр сервера. Оновіть сторінку товарів у браузері.
4. Відкрийте наявний редактор товару та фотографій. Для `Original+Canvas`
   вибір методу має бути недоступний; preview зберігає вихідний фон.
5. Для `Studio Light` оберіть «Автоматично (рекомендовано)» та натисніть
   «Попередній перегляд». Перевірте оригінал, готове фото та ползунок.
   Повторіть із `GrabCut` та `MODNet`, потім із `Anabelka Brand`.
6. До підтвердження відкрийте товар в іншій вкладці: там має залишитися
   попередня прийнята фотографія. «Скасувати», хрестик і системна кнопка
   «Назад» також мають залишати її без змін.
7. Відкрийте «Деталі обробки». Перше натискання «Назад» закриває деталі,
   наступне — preview. Переконайтеся, що повернення до редактора товару
   працює та старе вікно «Порівняти» залишається доступним.
8. Підготуйте preview повторно та натисніть «Застосувати». Після відповіді
   сервера має змінитися готова фотографія. Підтвердження не запускає
   повторну інференцію. Перевірте запис журналу дій адміністратора:
   обраний і застосований метод, фон та версію обробника.
9. Якщо MODNet worker недоступний, його ручний preview має повернути
   помилку та зберегти попереднє ready-фото. Недоступність і тайм-аут
   worker також перевіряє автоматизована Python-серія; змінювати файли
   моделі на робочому телефоні для цієї перевірки не потрібно.

## Межі перевірки

Системну кнопку Android перевіряють також автоматизовані DOM-тести через
події history/popstate. Остаточне підтвердження поведінки саме KSWEB і
браузера телефону потребує наведеного ручного проходу.

Примусовий метод вимагає придатної маски та наявної безпечної геометрії
моделі. Коли процесор не може їх побудувати, ручна операція завершується
помилкою. Попередній прийнятий результат зберігається; правила AUTO
залишаються колишніми.

## Результат реалізації

У наявному редакторі додано Auto / GrabCut / MODNet та двоетапне
«Попередній перегляд» → «Застосувати». Порівняння й ползунок збережено.
Для Original+Canvas метод недоступний. Правила AUTO, геометрія, три
профілі, детектори та обмеження окремого worker залишаються попередніми.

Preview не змінює запис product_image_processing, статус або опубліковані
шляхи. Серверний 256-бітний токен прив'язаний до адміністратора, сесії,
image_id, оригіналу та SHA256, діагностики, попереднього прийнятого стану
і строку дії 30 хвилин. Підтвердження перевіряє ці дані під блокуваннями
та транзакцією і публікує вже готові файли без повторної інференції.
Повторне підтвердження актуального прийнятого результату є безпечним;
старий токен не перезаписує новіший результат. Скасування, помилка або
закриття preview зберігають попередню ready-фотографію.

Примусовий GrabCut не викликає MODNet. Примусовий MODNet використовує
наявний окремий worker; недоступність, помилка і тайм-аут повертають
помилку без підміни методу. AUTO зберігає дозволений раніше fallback.
Запрошений/фактичний метод, фон, версія й помилка worker зберігаються у
наявній діагностиці; прийняті операції та помилки відображаються в аудиті.

Пряме HTTP-читання storage/image-processor закрито правилом Apache.
Приватне preview видається лише через перевірений endpoint з правом
products.manage. Очищення прострочених проб запускається під час
створення наступної проби, прибирає доведено неприйняті тимчасові й
частково опубліковані файли та зберігає прийняті результати. Якщо
блокування зайняте або належність файлів непевна, очищення відкладається.

## Автоматизована перевірка

| Перевірка | Контрольний коміт | Після змін |
|---|---:|---:|
| Python unittest discovery | 177: 176 успішних, 1 пропуск | 192: 191 успішний, 1 пропуск |
| Повна Node-серія | 141: 112 успішних, 29 помилок | 175: 148 успішних, 27 помилок |
| UI preview/compare/history | — | 27 успішних, 0 помилок |
| Цільові PHP/router/client/CSRF контракти й runtime | — | 12 успішних, 0 помилок |
| Синтаксис змінених PHP / JS / Python | — | 12 / 8 / 2 файли, без помилок |
| git diff --check | — | Без помилок |

Повна Node-серія повертає exit 1 через 27 відомих помилок. Порівняно
точні назви й місця з baseline: нових помилок немає. Усунено дві старі:
admin_audit_human_labels_contract та product_image_processing_editor_contract.

Основні команди: `python -B -m unittest discover -s tests -p 'test_*.py'`,
`node --test tests/*.test.mjs`, `php -l <file>`, `node --check <file>` і
`git diff --check`. Для Node runtime-тестів потрібні доступний PHP та
jsdom (можна задати PHP_BIN і ANABELKA_UI_TEST_DEPS); залежності
застосунку не змінено.

PHP перевірено справжнім PHP 8.2.33 WebAssembly CLI. Runtime використовує
реальну файлову систему, production-нормалізатор, модель і SQL, а БД та
інференцію підміняє керованими doubles. Є 58 місць перевірки/очікуваної
відмови в основному PHP-harness. Окрема серія запускає реальні Router,
CSRF та правила AdminAccess: відсутній/неправильний/масивний CSRF,
валідний токен і заголовок, усі POST endpoint та products.manage для
приватного GET/HEAD. Невалідний CSRF відхиляється до БД/контролера.

Перевірено всі три режими й обидва профілі заміни фону, Original+Canvas,
старі фотографії без mask_mode, preview без мутацій, підтвердження без
інференції, скасування та повторну обробку. Окремі регресії покривають
недоступний/прострочений worker, зміну шляху/вмісту оригіналу, зміну
staged/accepted hash, чужого власника/сесію/права, строк дії, повторні й
застарілі токени, помилки staging/publication/DB-save/commit, перервану
часткову публікацію та її відновлення/очищення. Помилка службового
marker після успішного DB-commit не видаляє ready-фото; повтор працює
за авторитетними даними БД. Перевірено busy-lock і збереження всіх
прийнятих поколінь при очищенні.

DOM-тести покривають ползунок, застосування/скасування, помилки,
запізнілі відповіді, подвійні кліки, Back/Escape, деталі, швидке закриття
кількох вікон, негайне повторне відкриття деталей, Forward та reload.
Два незалежні огляди PHP-безпеки й Python/UI завершено; виявлені
проблеми усунено та закрито регресійними тестами.

## Обмеження середовища

- Пропущений Python-тест вимагає зовнішнього ANABELKA_MASK_REFERENCE;
  той самий пропуск був у baseline.
- Один PHP-fixture під Emscripten пропускає перейменування всього
  каталогу з відкритими lockfiles: NODEFS аварійно завершує flock при
  розблокуванні старого шляху. Нативний fixture збережено; інші помилки
  запису, блокування та rollback виконуються й проходять.
- Справжню нейромережеву інференцію, нативні Apache/PHP/MySQL з
  паралельними процесами та фізичний Android у цьому середовищі не
  запускали. Диспетчеризацію, worker-bridge/timeouts, композицію, SQL,
  файлові операції та history перевірено автоматично. Перевірка на
  телефоні наведена вище.

## Змінені файли

- `.htaccess`
- `app/Controllers/AdminImageProcessorController.php`
- `app/Core/App.php`
- `app/Models/AdminAccess.php`
- `app/Models/ProductImageProcessing.php`
- `app/Services/ImageProcessorClient.php`
- `app/Services/ProductImagePreviewService.php`
- `app/Services/ProductImageProcessingService.php`
- `css/admin-products.css`
- `docs/image-processor-manual-preview-2026-10-09.md`
- `docs/superpowers/plans/2026-10-09-image-mask-preview.md`
- `js/admin-products.js`
- `routes/Web.php`
- `tests/image_processor_mask_mode_contract.test.mjs`
- `tests/product_image_preview_routes_contract.test.mjs`
- `tests/product_image_preview_runtime.php`
- `tests/product_image_preview_runtime.test.mjs`
- `tests/product_image_preview_security_runtime.php`
- `tests/product_image_preview_security_runtime.test.mjs`
- `tests/product_image_processing_editor_contract.test.mjs`
- `tests/product_image_processing_integration_contract.test.mjs`
- `tests/product_image_processing_preview_ui.test.mjs`
- `tests/test_image_processor_mask_modes.py`
- `tools/image-processor/README.md`
- `tools/image-processor/server.py`
- `views/admin/administrators/audit.php`
- `views/admin/products/index.php`

## Відомі помилки повної Node-серії

- `tests/admin_android_back_contract.test.mjs:1:1` — tests/admin_android_back_contract.test.mjs
- `tests/admin_audit_compact_groups_contract.test.mjs:1:1` — tests/admin_audit_compact_groups_contract.test.mjs
- `tests/admin_audit_notification_badges_contract.test.mjs:1:1` — tests/admin_audit_notification_badges_contract.test.mjs
- `tests/admin_dashboard_builder_contract.test.mjs:1:1` — tests/admin_dashboard_builder_contract.test.mjs
- `tests/admin_dashboard_service_registry_contract.test.mjs:1:1` — tests/admin_dashboard_service_registry_contract.test.mjs
- `tests/admin_floating_tool_contract.test.mjs:92:1` — editor scripts explicitly signal AI context changes
- `tests/admin_mobile_navigation_contract.test.mjs:1:1` — tests/admin_mobile_navigation_contract.test.mjs
- `tests/admin_view_manage_ui_contract.test.mjs:1:1` — tests/admin_view_manage_ui_contract.test.mjs
- `tests/admin_work_time_contract.test.mjs:1:1` — tests/admin_work_time_contract.test.mjs
- `tests/admin_work_time_regression_contract.test.mjs:18:1` — legacy activity migration qualifies duplicate-key target columns
- `tests/anabelka_notify_contract.test.mjs:450:1` — header system badge uses AnabelkaNotify.formatCount when available
- `tests/anabelka_notify_contract.test.mjs:468:1` — header system badge keeps a safe 99+ fallback without the shared module
- `tests/catalog_pagination_contract.test.mjs:1:1` — tests/catalog_pagination_contract.test.mjs
- `tests/category_manager_contract.test.mjs:1:1` — tests/category_manager_contract.test.mjs
- `tests/category_thumbnail_select_contract.test.mjs:1:1` — tests/category_thumbnail_select_contract.test.mjs
- `tests/category_translation_status_select_contract.test.mjs:1:1` — tests/category_translation_status_select_contract.test.mjs
- `tests/final_review_category_depth_palette_contract.test.mjs:1:1` — tests/final_review_category_depth_palette_contract.test.mjs
- `tests/final_review_home_mobile_polish_contract.test.mjs:1:1` — tests/final_review_home_mobile_polish_contract.test.mjs
- `tests/home_page_builder_contract.test.mjs:1:1` — tests/home_page_builder_contract.test.mjs
- `tests/home_right_rail_contract.test.mjs:1:1` — tests/home_right_rail_contract.test.mjs
- `tests/mobile_navigation_runtime.test.mjs:1:1` — tests/mobile_navigation_runtime.test.mjs
- `tests/product_cart_available_stock_contract.test.mjs:40:1` — legacy product page refreshes visible stock from the AJAX response
- `tests/product_stock_total_consistency_contract.test.mjs:15:1` — duplicate product sizes are rejected instead of silently merged
- `tests/product_stock_total_consistency_contract.test.mjs:69:1` — stock consistency scripts are cache-busted
- `tests/product_variant_color_contract.test.mjs:113:1` — removed product colors do not return from photos or matrix
- `tests/public_admin_badge_permissions_contract.test.mjs:1:1` — tests/public_admin_badge_permissions_contract.test.mjs
- `tests/public_catalog_sidebar_contract.test.mjs:1:1` — tests/public_catalog_sidebar_contract.test.mjs
