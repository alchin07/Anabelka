# Обработка без bbox: subject-not-detected

База: `97a1bf0efeacc0ca36c58f4212c4114380050d82`, ветка `feature/category-manager`.

## Изменение

Если MediaPipe, Haar и HOG не находят bbox, `normalized_master()` больше не
завершает обработку двух сменных фонов до запуска сегментации. Новая ветка
использует весь исходный кадр, `crop_strategy=preserve-source-frame`, без
crop, torso-normalize и zoom-out. Master остаётся 1200×1800, thumb — 320×480.
`original-canvas` сохраняет прежнее поведение. Ветка с найденным bbox,
её геометрия и правила выбора алгоритмов не изменены.

Принудительный MODNet запускает только свой worker с immutable-копией всего
исходника; мягкая alpha не проходит очистку GrabCut. Ошибка не превращается
в успешный GrabCut или Original+Canvas.

Принудительный GrabCut без bbox использует GC_INIT_WITH_MASK с проверенными
угловыми образцами и вероятным передним планом. Центральный прямоугольник
не становится гарантированным объектом. Слабые/неоднозначные исходные
образцы отклоняются до инференса. Сохраняются существующие median/close/
distance-feather улучшения края; удаление второстепенных компонентов и
анатомические проходы очистки без bbox не применяются.

Auto сохраняет существующий выбор сложного фона и проверку остаточного
фона. Для нового маршрута допускается один MODNet после недоступной или
отклонённой маски GrabCut, если worker ещё не пробовали. Успешный первичный
MODNet не запускает GrabCut; неудачный worker повторно не запускается.

## Проверки и диагностика

Перед композицией проверяются размер/mode alpha, конечная площадь маски,
непрозрачные и прозрачные области, сохранение значимых исходных компонентов,
локальных деталей и тонких волос/бретелей. Фактическая площадь вычисляется
по alpha, а не принимается из метаданных worker.

Раздельные причины:

- `detection_reason=subject-not-detected` — bbox отсутствует; это само по себе
  больше не блокирует сегментацию.
- `grabcut_mask_unavailable` — безопасную маску GrabCut не удалось получить.
- `modnet_worker_failed` — недоступный/неудачный worker.
- `mask_quality_rejected` — alpha или подтверждение сохранения материала
  не проходит проверки.
- `reason_code=background_fallback`, `fallback_reason=background_fallback`
  — Auto вернул исходный фон; подробная причина в `mask_failure_reason`.

Сохраняются запрошенные/фактические метод и фон. Python сохраняет подробные
таймеры и `mask_selection`; PHP сохраняет безопасные коды и конечное
`processing_time_ms` (время нормализации). Полные причины/worker-данные
остаются в серверном журнале. Публичные диагностические поля не содержат
внутренние пути и произвольный текст worker. Функциональные относительные
пути preview не изменены.

## Реальная проверка доступной среды

Исходная проблемная фотография пользователя **недоступна**. Применён
воспроизводимый геометрический RGB-исходник 2500×3250 с коричневым фоном,
торсом, светлым бельём и отдельной рукой. Детекторы, GrabCut и обработка
файлов настоящие; изменены только четыре корня временного файлового
окружения. Детекторы не вернули bbox.

| Метод / фон | Результат | Полное время |
|---|---|---:|
| GrabCut / Studio Light | фон применён, кадр сохранён | 4,30 с |
| Auto / Studio Light | GrabCut, фон применён, кадр сохранён | 4,52 с |
| GrabCut / Anabelka Brand | фон применён, кадр сохранён | 3,91 с |
| MODNet / Studio Light | modnet_worker_failed / worker-not-ready | 0,35 с |

Все успешные файлы декодированы: master 1200×1800, thumb 320×480;
foreground_ratio=0.3685, source и immutable-копия имеют одинаковый SHA-256.
Торс, светлая ткань и отдельная рука сохранены. После ошибки MODNet не
осталось новых original/processed файлов. Native PHP 8.3.6 принял все три
реальных успешных ответа через `ProductImageProcessingService::normalizeResult()`
с проверкой размеров, байтов, SHA и диагностических полей.

MODNet ONNX и onnxruntime в этой среде отсутствуют. Успешный маршрут MODNet
проверен с контролируемой alpha/ответом worker; это проверка маршрутизации,
валидации и композиции, **не реального качества нейросетевой сегментации**.
Физический Android/KSWEB и конкретная проблемная фотография не проверены.

## Ограничения

Без bbox цветовые/структурные признаки не дают семантической гарантии,
что область является человеком или бельём. Новый маршрут намеренно
консервативен: однородная вещь без различимых деталей, неоднозначные углы,
разные согласованные нижние углы или сложный фон без независимой опоры
могут получить отказ даже при внешне правдоподобной alpha. Такие случаи
не исправляются удалением или восстановлением пикселей по догадке.
Проверка preview пользователем остаётся необходимой для принятия результата.

Обнаруженные инверсии маски (прямоугольник, вертикальная щель, неправильный
внутренний карман), затенённый нижний фон, пропавшая отдельная рука и тонкая
бретель закреплены регрессиями. Проверки не гарантируют идеального края
каждой реальной фотографии.

## Проверки автоматизации

Полная Python серия: 214 тестов, 213 проходят, один skip — отсутствует
исходная фотография для ANABELKA_MASK_REFERENCE. Полная Node серия:
258 тестов, 231 проходят, 27 прежних ошибок; набор ошибок в точности
совпадает с контрольным 97a1bf0 (238 тестов, 211 проходят, 27 ошибок).
Новых ошибок нет.
Новые тесты без bbox: 22/22; существующие тесты ручных режимов: 15/15.
Связанные Node/PHP/HTTP/preview/security/UI проверки: 124/124.
Синтаксис изменённых Python/PHP файлов и `git diff --check` проходят.

Прежние ошибки полной Node серии (за пределами исправления):

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

## Окончательная проверка Android

1. Обновить только `feature/category-manager` обычным используемым updater
   или `git pull --ff-only`, сохранив локальные изменения. Main не merge.
2. Перезапустить Python Image Processor в Termux. Проверить `/health` и
   готовность MODNet. Установка модели должна быть завершена до проверки
   принудительного MODNet.
3. Открыть исходное фото 2500×3250 и выбрать Studio Light → MODNet → preview.
   Проверить: крупный план не обрезан/не отдалён; волосы, руки, кожа и ткань
   целы; новый фон применён. В диагностике `subject_detected=false`,
   `mask_method=modnet`, фактический `background_profile=studio-light`.
4. Нажать отмену (проверить также Android Back): исходная фотография и
   ранее принятый результат должны сохраниться. Повторить preview и
   подтвердить только визуально проверенный результат.
5. Повторить GrabCut и Auto, затем Anabelka Brand и Original+Canvas.
   Для отказа сохранить код и приватную диагностику из php.log; для Auto
   fallback фактический фон должен быть `original-canvas`.
6. Проверить полный рост с bbox и бельё без человека. Для полного роста
   убедиться, что прежняя композиция/torso-нормализация сохранились.
7. Проверить на некачественном/неоднозначном кадре и с недоступным worker:
   принудительные методы дают ошибку, не заменяют принятый результат;
   новые файлы после ошибки отсутствуют. Передать исходную проблемную
   фотографию для отдельной фактической проверки, если требуется оценка
   качества именно её маски.
