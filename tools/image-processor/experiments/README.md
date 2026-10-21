# BiRefNet Lite: независимый CPU-эксперимент

Этот каталог не подключён к магазину, HTTP API, БД, preview/confirm или
автоматическому выбору алгоритма. Запуск только вручную, одно фото за команду.
MODNet, GrabCut и проверки основной системы не меняются.

## Модель и источник

Точный файл: `BiRefNet-general-bb_swin_v1_tiny-epoch_232.onnx`.

- [Официальный ONNX автора, release v1](https://github.com/ZhengPeng7/BiRefNet/releases/tag/v1), asset ID `186739942`.
- [Официальная карточка Lite](https://huggingface.co/ZhengPeng7/BiRefNet_lite) содержит веса safetensors, а не этот ONNX.
- Размер ONNX: **224005088 байт**, примерно 213.63 МиБ.
- SHA-256 проверенной загрузки: `5600024376f572a557870a5eb0afb1e5961636bef4e1e22132025467d0f03333`.
- [rembg](https://github.com/danielgatis/rembg/blob/main/rembg/sessions/birefnet_general_lite.py) публикует MD5 `4fab47adc4ff364be1713e97b7e66334`; официальная загрузка совпала.
- SHA-256 вычислен нами после загрузки и закреплён для воспроизводимости.
  Автор не опубликовал SHA-256 этого asset (`digest: null`); release-файлы
  могут изменяться, поэтому URL сам по себе не гарантирует те же байты.
- [MIT-лицензия автора](https://github.com/ZhengPeng7/BiRefNet/blob/main/LICENSE)
  и MIT в карточке Lite допускают коммерческое использование. При распространении
  копий/существенных частей сохранять copyright и текст разрешения.

Фактически прочитанный граф: IR 8, opset 17, 16400 узлов, стандартный домен,
без внешних tensor-файлов. Вход `input_image`: float32 `[1,3,1024,1024]`;
выход `output_image`: float32 `[1,1,1024,1024]`. Для IR8/opset17 таблица
[ONNX Runtime](https://onnxruntime.ai/docs/reference/compatibility.html)
указывает поддержку начиная с 1.12. Это необходимое условие, а не доказательство
работы конкретной сборки Termux: её надо проверять локальным запуском.

## Как трактовать маску

Предобработка и выход соответствуют ONNX-пути rembg
([base](https://github.com/danielgatis/rembg/blob/main/rembg/sessions/base.py),
[BiRefNet](https://github.com/danielgatis/rembg/blob/main/rembg/sessions/birefnet_general.py),
проверенная ревизия rembg `cd8f3665dda6c246b5baecb85fda6a2acd644c2a`):
EXIF-ориентация, RGB, Lanczos 1024×1024, деление на максимум яркости всех
каналов изображения (минимум 1e-6), mean `(0.485,0.456,0.406)`,
std `(0.229,0.224,0.225)`, float32 NCHW. Выход: sigmoid, min/max нормализация,
uint8 alpha, Lanczos к исходному ориентированному размеру.

Входной домен модели квадратный; это не letterbox. Alpha возвращается в
координаты исходного кадра, итоговое RGB не растягивается и не обрезается.
Невалидный или постоянный выход отвергается. Min/max-маска не является
калиброванной вероятностью материала.

В сравнении MODNet вызывается существующий `modnet_worker.py` без изменений:
он использует свою нормализацию, пропорциональный размер и 2 CPU-потока.
BiRefNet использует 1 поток, inter-op 1, последовательное выполнение,
отключённые arena/memory pattern. Сравнение измеряет эти конкретные профили,
а не одинаковую вычислительную нагрузку.

## Приватность и ограничения ресурсов

Веса и результаты хранить в HOME Termux вне проекта, например
`$HOME/.anabelka-birefnet`. Каталоги 0700, веса 0600. Артефакты в Git-папках,
Android shared storage, через symlink или hardlink отвергаются. Исходники
могут находиться в `uploads/products`, но читаются без изменения; SHA-256
проверяется до и после. Выход каждой команды — новая отдельная папка.

Supervisor допускает один эксперимент, использует отдельный процесс,
тайм-аут и мониторинг RSS/доступной памяти через `/proc`, с учётом доступных
конечных квот cgroup v1/v2. Это наблюдаемый
лимит с периодическим опросом, а не жёсткая контейнерная квота. ОС всё ещё
может остановить процесс при нехватке памяти; нельзя обещать отсутствие
любого влияния на телефон. Не повышать лимиты на телефоне ради завершения.
При недоступном мониторинге запуск прекращается. На завершённых запусках
также сохраняется максимальная RSS ядра через `getrusage`; при принудительной
остановке доступна только измеренная supervisor величина VmHWM. Опрос — 25 мс.
Windows не имеет этого Linux/Termux-адаптера: запуск там намеренно запрещён.
Общая блокировка использует обычный неизменный HOME Termux и доверенный
системный UID. Не запускать параллельные копии с подменой HOME, не заменять
файл блокировки во время работы. Worker требует приватный наследуемый канал
supervisor и завершает свою группу при закрытии этого канала, включая
принудительное завершение родителя.

Не устанавливать PyTorch, Transformers, rembg или новый ONNX Runtime для
этого эксперимента. Использовать уже установленный Runtime. Текущий rembg
декларирует Python <3.14; это не ограничение нашего прямого ONNX-инструмента.
Обычные Linux manylinux-wheels не доказывают совместимость с Android bionic.

## Проверка на физическом Android

В Termux, после обычного обновления разрешённой ветки:

```sh
cd /storage/emulated/0/htdocs/Anabelka
python -c 'import sys, onnxruntime as o; print(sys.version); print(o.__version__); print(o.get_available_providers())'
umask 077
mkdir -p "$HOME/.anabelka-birefnet/models" "$HOME/.anabelka-birefnet/results"
chmod 700 "$HOME/.anabelka-birefnet" "$HOME/.anabelka-birefnet/models" "$HOME/.anabelka-birefnet/results"
python tools/image-processor/experiments/birefnet_experiment.py download --model "$HOME/.anabelka-birefnet/models/BiRefNet-general-bb_swin_v1_tiny-epoch_232.onnx"
python tools/image-processor/experiments/birefnet_experiment.py check \
  --model "$HOME/.anabelka-birefnet/models/BiRefNet-general-bb_swin_v1_tiny-epoch_232.onnx" \
  --timeout 120 --rss-limit-mib 1536
```

`check` сверяет веса, загружает CPU-сессию в отдельном процессе и показывает
версию Runtime, provider и точную схему входа/выхода. Это ещё не проверка
inference или качества. Продолжать только при `ok: true`.

Сначала один проблемный кадр, а затем остальные строго по одному:

```sh
python tools/image-processor/experiments/birefnet_experiment.py run \
  --method birefnet \
  --source "$PWD/uploads/products/20260904-111401-e33031f29005c83e.jpg" \
  --model "$HOME/.anabelka-birefnet/models/BiRefNet-general-bb_swin_v1_tiny-epoch_232.onnx" \
  --output-root "$HOME/.anabelka-birefnet/results" \
  --timeout 120 --rss-limit-mib 1536 --previews
```

Имя/место исходника проверить на своём телефоне. Если файл не найден,
не подставлять другое фото под именем этого примера. При timeout, нехватке
памяти или ошибке Runtime сохранить сообщение и завершить эксперимент.
Сервер 8765 перезапускать или останавливать для этой команды не требуется.

Для MODNet сделать **копию** уже проверенных рабочих весов в приватный
каталог (не перемещать рабочие веса), выставить 0600, затем повторить `run`
с `--method modnet --model <приватная-копия-MODNet.onnx>` и тем же `--source`.
Сверить `source_sha256`, ориентированный размер, alpha, white/checker и
метрики двух запусков. Пиковую RSS сравнивать вместе с загрузкой модели,
а `inference_ms` отдельно от общего времени и записи previews.

## Независимые тесты

```sh
PYTHONDONTWRITEBYTECODE=1 python -m unittest discover -s tests -p 'test_birefnet_experiment.py' -v
git diff --check
```

Необязательные реальные веса/фото задаются `ANABELKA_EXPERIMENT_MODEL` и
`ANABELKA_EXPERIMENT_SOURCE`; при отсутствии ресурсов
эти тесты пропускаются. Синтетические тесты не заменяют визуальную оценку
реальных фотографий или измерения на физическом Android.

Ни успех inference, ни согласие существующего coarse seed с новой маской
не дают разрешения на confirm. Четыре source-gate основной системы и
защита неизменяемой alpha редактора сохраняются. Сравнивать новую сеть с
MODNet через `immutable_material_loss` некорректно: этот guard защищает
ручные изменения конкретной исходной alpha, а не определяет истинный фон.
Интеграция в магазин допускается только отдельным последующим заданием.
