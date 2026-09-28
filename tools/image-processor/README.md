# Anabelka Image Processor

Локальний сервіс обробки фотографій товарів без ШІ.

## Призначення

Перший етап модуля:

1. вихідний файл товару читається тільки з `uploads/products`;
2. створюється окрема копія оригіналу, яку сервіс більше не змінює;
3. EXIF-орієнтація застосовується до робочої копії;
4. OpenCV перевіряє, що файл дійсно декодується як зображення;
5. Pillow створює `master.webp` і `thumb.webp`;
6. результати зберігаються в `storage/image-processor`.

Поточний редактор товару поки не змінюється.

## Запуск у Termux

З кореня проєкту:

```bash
python tools/image-processor/server.py
```

За замовчуванням сервіс слухає тільки:

```text
http://127.0.0.1:8765
```

Перевірка:

```text
http://127.0.0.1:8765/health
```

PHP-перевірка після входу в адмін-панель:

```text
/Anabelka/admin/image-processor/health
```

## API

### GET /health

Повертає стан сервісу та версії Pillow/OpenCV.

### POST /process

JSON:

```json
{
  "source": "uploads/products/example.jpg"
}
```

Дозволені тільки файли безпосередньо з `uploads/products`.
Абсолютні шляхи, `..` та інші каталоги відхиляються.

Результат містить окремі шляхи:

- immutable original copy;
- `master.webp`;
- `thumb.webp`.

Сервіс не перезаписує вихідну фотографію товару.
