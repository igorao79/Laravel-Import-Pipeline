# Laravel Import Pipeline

Асинхронный pipeline обработки CSV/Excel файлов на Laravel с chunked processing, real-time прогрессом и retry механизмом.

## Что это

Загружаешь CSV с товарами → система разбивает на чанки → валидирует → трансформирует → вставляет в БД. Всё асинхронно, с прогресс-баром и обработкой ошибок.

```
CSV (50 000 строк)
  → Разбиение на чанки по 500
  → 100 параллельных Jobs
  → Валидация + Трансформация
  → Bulk Upsert в products
  → Real-time прогресс через WebSocket
```

## Стек

- **Backend:** Laravel 11, PHP 8.3
- **Queue:** Bus::batch(), exponential retry (5с → 30с → 2мин)
- **Frontend:** Blade + Tailwind CSS (CDN) + Alpine.js
- **БД:** SQLite (dev) / MySQL (prod)
- **Тесты:** PHPUnit — 133 теста, 269 assertions

## Быстрый старт

```bash
git clone https://github.com/igorao79/Laravel-Import-Pipeline.git
cd Laravel-Import-Pipeline

composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate

# Создать тестового пользователя
php artisan tinker --execute="App\Models\User::create(['name'=>'Test','email'=>'test@example.com','password'=>bcrypt('password')])"

php artisan serve
```

Открыть http://localhost:8000 → залогиниться `test@example.com` / `password` → загрузить CSV.

## API

Все эндпоинты защищены `auth:sanctum`.

| Метод | URL | Описание |
|-------|-----|----------|
| `POST` | `/api/imports` | Загрузить файл (поле `file`, опционально `chunk_size`) |
| `GET` | `/api/imports` | Список импортов (пагинация) |
| `GET` | `/api/imports/{id}` | Статус + прогресс + ошибки |
| `POST` | `/api/imports/{id}/retry` | Перезапуск упавших строк |

## Архитектура

```
app/
├── Jobs/
│   ├── ProcessImportFile.php    # Оркестратор: разбивает файл на чанки
│   ├── ProcessImportChunk.php   # Обработка одного чанка (Batchable)
│   └── FinalizeImport.php       # Финализация после всех чанков
├── Services/
│   ├── FileParser.php           # Generator-based парсинг CSV/Excel
│   ├── RowValidator.php         # Валидация строк
│   └── RowTransformer.php       # Нормализация данных
├── Events/
│   └── ImportProgressUpdated.php # WebSocket broadcasting
├── Models/
│   ├── Import.php               # Импорт (статус, счётчики)
│   └── ImportRow.php            # Ошибочные строки
└── Policies/
    └── ImportPolicy.php         # Авторизация по владельцу
```

### Ключевые решения

- **Generator** — ленивое чтение файла, не грузим всё в RAM
- **Bus::batch()** — параллельная обработка чанков
- **increment()** — атомарные счётчики без race condition
- **Upsert по SKU** — обновление при дубликате
- **Exponential backoff** — 3 попытки: 5с, 30с, 2мин

## Тесты

```bash
php artisan test
# или
vendor/bin/phpunit
```

133 теста покрывают: модели, сервисы, jobs, контроллер, events, policy.

## Формат CSV

```csv
name,sku,price,qty,category
iPhone 15 Pro,IP15-001,129990,50,Electronics
Samsung Galaxy,SG-001,89 990,30,Electronics
```

Трансформация автоматически: trim пробелов, uppercase SKU, нормализация цен (`1 500,50` → `1500.50`), пустая категория → `null`.

## Лицензия

MIT
