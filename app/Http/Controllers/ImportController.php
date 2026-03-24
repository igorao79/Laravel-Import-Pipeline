<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImportRequest;
use App\Jobs\ProcessImportFile;
use App\Models\Import;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    /**
     * GET /api/imports — список импортов текущего пользователя.
     */
    public function index(Request $request): JsonResponse
    {
        $imports = $request->user()
            ->imports()
            ->latest()
            ->paginate(15);

        return response()->json($imports);
    }

    /**
     * POST /api/imports — загрузка файла и запуск обработки.
     *
     * Поток:
     * 1. Валидация файла (FormRequest)
     * 2. Сохранение в storage
     * 3. Создание записи Import
     * 4. Dispatch job в очередь
     * 5. Возвращаем import_id (фронт подписывается на WebSocket)
     */
    public function store(StoreImportRequest $request): JsonResponse
    {
        $file = $request->file('file');

        // Сохраняем файл во временное хранилище
        $path = $file->store('imports', 'local');

        // Создаём запись импорта
        $import = Import::create([
            'user_id' => $request->user()->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'chunk_size' => $request->input('chunk_size', 500),
            'column_mapping' => $request->input('column_mapping')
                ? json_decode($request->input('column_mapping'), true)
                : null,
        ]);

        // Отправляем в очередь
        ProcessImportFile::dispatch($import)->onQueue('imports');

        return response()->json([
            'message' => 'Импорт поставлен в очередь',
            'import' => $import->fresh(),
        ], 202); // 202 Accepted — обработка начнётся асинхронно
    }

    /**
     * GET /api/imports/{import} — статус конкретного импорта.
     */
    public function show(Import $import): JsonResponse
    {
        // Авторизация: только владелец видит свои импорты
        $this->authorize('view', $import);

        return response()->json([
            'import' => $import,
            'progress_percent' => $import->progressPercent(),
            'failed_rows' => $import->failedRows()
                ->select(['row_number', 'original_data', 'errors'])
                ->paginate(50),
        ]);
    }

    /**
     * POST /api/imports/{import}/retry — перезапуск только упавших строк.
     */
    public function retry(Import $import): JsonResponse
    {
        $this->authorize('update', $import);

        if ($import->failed_rows === 0) {
            return response()->json(['message' => 'Нет строк для повторной обработки'], 422);
        }

        // Собираем данные упавших строк и перезапускаем
        $failedData = $import->failedRows()
            ->pluck('original_data', 'row_number')
            ->toArray();

        // Очищаем старые ошибки
        $import->failedRows()->delete();
        $import->update([
            'status' => 'processing',
            'failed_rows' => 0,
            'processed_rows' => $import->processed_rows - count($failedData),
        ]);

        // Диспатчим один чанк с упавшими строками
        \App\Jobs\ProcessImportChunk::dispatch($import, $failedData, 0)
            ->onQueue('imports');

        return response()->json(['message' => 'Повторная обработка запущена']);
    }
}
