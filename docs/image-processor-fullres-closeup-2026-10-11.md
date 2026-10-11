# MODNet close-up: проверка полного оригинала

Дата: 2026-10-11. Ветка `feature/category-manager`, исходный коммит `1467a9779bd688bd81932885e4f913a6e1985687`.

## Исправление

Воспроизведён отказ на точных байтах `20260904-111401-44eb8f1e1cbac540.jpg`, 2500×3250, 1 182 462 байта, SHA-256 `c45e4d4444b26701005fda5dc3332e2a418936ae4a251d761a3130da59ce9d7b`. Настоящий MODNet успешно вернул alpha, но базовая проверка без bbox остановилась на `source_evidence_unavailable`, `actual_foreground_ratio=0.863138`.

На BILINEAR probe 394×512 верхний правый угол имеет RGB p90 4.1231055. У прежней копии 1182×1536 показатель 3.7749171. Повторное BILINEAR уменьшение оригинала не воспроизводит байты прежней копии и также даёт 4.1231055; в регрессии проверяются оба варианта.

Изменён только дополнительный путь верхних углов в `build_closeup_validation_seed_mask()`. Исходное принятие при p90 ≤4 остаётся прежним. Для небольшого превышения теперь требуются одновременно:

1. Исходный RGB p90 ≤ `4 + sqrt(3)` — ограниченный допуск одного 8-битного значения на канал.
2. p99 локальных соседних RGB различий не превышает этот же предел.
3. После median 3×3, используемого только при оценке образца, максимальный соседний RGB перепад ≤4. Это отсекает устойчивые резкие переходы, в том числе диагональные.
4. После вычитания пространственной линейной плоскости яркости RGB p90 остатка ≤4. Из каждого канала вычитается одна и та же яркость, поэтому цветовые различия не объясняются освещением.

Порог 4.5 не внедрён как самостоятельное производственное решение. Медианы исходных цветов, chroma, пределы яркости, связность, нижние уголки и проверки сохранности материала работают как прежде. Фильтрация и плоскость используются только для оценки; исходные пиксели, seed-классификация по исходным цветам и alpha не исправляются.

`validate_undetected_subject()` и все четыре защиты — `source_component_loss`, `source_local_material_loss`, `source_thin_material_loss`, `source_background_retained` — не изменены. GrabCut, обнаруженные bbox, геометрия, профили фона, worker, PHP, UI, preview/confirm и безопасность не изменены.

SHA-256 финального `server.py`: `369295d6485c7cb54f009ce6807e28bd1539b0a998a0fde87741487720bf2847`.

## Настоящая обработка пяти оригиналов

Полные `process_image(..., 'studio-light', 'modnet')` выполнены до изменения и на финальном коде, с настоящими детекторами и MODNet subprocess. Заменялись только файловые корни и пути к моделям для изолированной проверки. Маски перехватывались до удаления временного PNG без изменения вычислений worker.

| Файл после `20260904-111401-` | Базовая обработка | Финальная обработка | Сравнение |
|---|---|---|---|
| `44eb8f1e1cbac540.jpg` | `source_evidence_unavailable` | Успех, `structural_checks_passed` | Без bbox, исходный кадр сохранён, Studio Light |
| `94806794d9042bb1.jpg` | Успех | Успех | Прежние master и thumb побайтно совпадают |
| `9dcf41ae1f6dce19.jpg` | Успех | Успех | Прежние master и thumb побайтно совпадают |
| `e0485517dd7ab463.jpg` | Успех | Успех | Прежние master и thumb побайтно совпадают |
| `e33031f29005c83e.jpg` | `source_local_material_loss` | Тот же отказ | Защита сохранена, alpha и диагностика совпадают |

Все исходники имеют 2500×3250. Настоящие workers завершились успешно 5/5 в каждом прогоне. Все пять финальных PNG alpha побайтно совпадают с базовыми, SHA-256 всех исходников сохранены. Три прежних успешных кадра идут через обнаруженный MediaPipe bbox; маршруты и выходные файлы неизменны. Отдельные лабораторные проверки без bbox для этих кадров не заменяют их реальные маршруты обработки.

Для проблемного оригинала:

- `actual_foreground_ratio`: 0.8631379692307692.
- `component_retention`: `[0.9377162629757786, 0.9986989452163003]`.
- Доступны исходные признаки `closeup_corner_samples`; все структурные проверки проходят.
- Компонентов локальных потерь площадью ≥202 пикселей на probe нет.
- Master: 1200×1800; thumb: 320×480.
- SHA-256 исходного PNG alpha: `e054515eb20064d995c8e8d5cd969521d4e1525ff77285b4437a902638a7349a`.
- SHA-256 master: `a55781f4c6489c0644f3bd18a85cff0d1f4cffef4a3fe9242b0278147043780c`.
- SHA-256 thumb: `337e4bc7e2917b06f2fc66dc1eb932013b5f5b5ac47e11613ca880f26fb0f977`.

Runtime: Linux, Python 3.12.14, OpenCV 4.14.0, Pillow 12.3.0, ONNX Runtime 1.31.0 CPU provider. Модель MODNet проверена по размеру 25 969 398 байт и SHA-256 `5069a5e306b9f5e9f4f2b0360264c9f8ea13b257c7c39943c7cf6a2ec3a102ae`; модель MediaPipe также проверена по штатному SHA-256. Маски не дорисовывались и не восстанавливались для прохождения проверки.

## Сохранённый отказ второго close-up

У `e33031f29005c83e.jpg` сохраняется прежний `source_local_material_loss`, несмотря на общую сохранность компонента 0.9576841800550008. Проверку не обходили. Сохранены полный raw alpha, source seed/core, loss-map, overlay и координаты компонентов:

| Probe bbox `(x,y,w,h)` | Площадь | Границы на оригинале `(x1,y1,x2,y2)` |
|---|---:|---|
| `(59,28,103,48)` | 2022 | `(374,177,1028,483)` |
| `(279,50,50,62)` | 1427 | `(1770,317,2088,711)` |
| `(49,315,33,170)` | 3098 | `(310,1999,521,3079)` |
| `(0,461,23,51)` | 603 | `(0,2926,146,3250)` |

Это координаты расхождения между исходными цветовыми признаками и alpha, а не доказательство семантической принадлежности всех участков к коже или белью. На overlay значительная часть областей находится в коричневых промежутках у волос/шеи и между рукой и торсом. Для этого кадра нужна отдельная работа с самой сегментацией/alpha и исходной принадлежностью этих областей; данное исправление не разрешает ослаблять защиту.

## Регрессии и проверки

Добавлены точный оригинал, прежняя уменьшенная копия и настоящий PNG alpha с SHA-256-проверками. Тесты покрывают оригинал/оба уменьшенных варианта, плавный перепад освещения, шум, полосы, резкие и диагональные переходы разных амплитуд, чужой цвет, чрезмерный градиент, отсутствие изменений пикселей и принятие настоящей alpha без ремонта. Существующие тесты дополнительно проверяют все причины потерь, ошибочное принятие нижнего фона и неизменность принудительного GrabCut.

Наблюдались ожидаемые RED→GREEN: оригинал без seed; настоящий alpha без исходных признаков; два небольших резких перехода, найденных независимым ревью. В финальном ревью все четыре защиты также проверены на дефектных масках через вновь допустимый плавный фон.

| Итоговая проверка | Результат |
|---|---|
| Полный Python `unittest discover -s tests -p 'test_*.py' -v` | 229 выполнено: 228 проходят, 1 skip; exit 0 |
| Close-up регрессии, включая настоящую alpha | 15/15 проходят |
| PHP/image/preview `node --test tests/image_processor*.test.mjs tests/product_image*.test.mjs` | 121/121 проходят, без skip |
| Полный Node `node --test tests/*.test.mjs` | 258: 231 проходят, 27 прежних ошибок; имена совпадают с baseline, новых нет |
| PHP синтаксис | 306/306 файлов проходят |
| Python синтаксис | 30/30 файлов проходят |
| `git diff --check` | Проходит |
| Реальный MODNet на пяти оригиналах | Worker 5/5; 4 результата, 1 прежний защищённый отказ |

Единственный Python skip — `test_supplied_photo_preserves_hair_shoulder_and_clear_background`: не установлен `ANABELKA_MASK_REFERENCE` для отдельного старого фото. Новые регрессии оригинала и настоящая обработка пяти приложенных кадров выполнены. Независимое ревью кода и сверка отчёта с финальной диагностикой завершены без оставшихся существенных замечаний.

Полный Node baseline и итоговый набор имеют одинаковые 27 ранее существовавших ошибок, без новых ошибок; их имена и логи сохранены в диагностическом архиве. Android preview/confirm на физическом устройстве не проверялся.

## Прежние ошибки полного Node-набора

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


## Ограничения

Признаки фонового цвета и гладкости консервативны и не являются семантическим детектором. Настоящее выполнение и визуальный осмотр сделаны в Linux; результат Android зависит от установленного runtime и должен быть проверен на устройстве после обновления и перезапуска сервиса. Кадр `e33031f29005c83e.jpg` остаётся отклонённым. Main, merge и stash не затронуты.
