<?php

namespace App\Jobs;

use App\Events\ImportProgressUpdated;
use App\Models\Import;
use App\Models\ImportRow;
use App\Services\RowTransformer;
use App\Services\RowValidator;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Job #2: Обрабатывает один чанк строк.
 *
 * Этапы: Валидация → Трансформация → Bulk Insert
 *
 * Важные моменты для собеса:
 * - Batchable trait — позволяет работать внутри Bus::batch()
 * - tries/backoff — экспоненциальный retry при ошибках БД
 * - DB::transaction — атомарность вставки чанка
 * - increment() на модели — атомарное обновление счётчиков (без race condition)
 */
class ProcessImportChunk implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [5, 30, 120]; // экспоненциальный retry: 5с, 30с, 2мин

    public function __construct(
        public readonly Import $import,
        public readonly array $rows,       // [rowNumber => rowData]
        public readonly int $chunkIndex,
    ) {}

    public function handle(RowValidator $validator, RowTransformer $transformer): void
    {
        // Если Batch был отменён — не обрабатываем
        if ($this->batch()?->cancelled()) {
            return;
        }

        $import = $this->import;

        // 1. Трансформация сырых данных (нормализация цен, trim, и т.д.)
        $transformedRows = $transformer->transformBatch(
            $this->rows,
            $import->column_mapping,
        );

        // 2. Валидация уже трансформированных данных
        $validation = $validator->validateBatch($transformedRows, transformed: true);

        // Трансформированные и прошедшие валидацию
        $transformed = $validation['passed'];

        // 3. Bulk insert в транзакции
        DB::transaction(function () use ($import, $transformed, $validation) {
            // Вставляем валидные строки пачкой (быстрее чем по одной)
            if (! empty($transformed)) {
                $insertData = array_map(
                    fn (array $row) => array_merge($row, [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]),
                    array_values($transformed),
                );

                // Используем upsert чтобы обновлять по SKU если дубликат
                DB::table('products')->upsert(
                    $insertData,
                    uniqueBy: ['sku'],
                    update: ['name', 'price', 'quantity', 'category', 'updated_at'],
                );
            }

            // Сохраняем строки с ошибками для дебага
            $failedInserts = [];
            foreach ($validation['failed'] as $rowNumber => $failedRow) {
                $failedInserts[] = [
                    'import_id' => $import->id,
                    'row_number' => $rowNumber,
                    'original_data' => json_encode($failedRow['data']),
                    'errors' => json_encode($failedRow['errors']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (! empty($failedInserts)) {
                ImportRow::insert($failedInserts);
            }
        });

        // 4. Атомарно обновляем счётчики (без race condition между чанками)
        $import->recordChunkResult(
            processed: count($this->rows),
            failed: count($validation['failed']),
        );

        // 5. Шлём прогресс через WebSocket
        ImportProgressUpdated::dispatch($import->fresh());
    }

    /**
     * Если все retry исчерпаны — логируем ошибку.
     */
    public function failed(\Throwable $exception): void
    {
        $this->import->recordChunkResult(
            processed: count($this->rows),
            failed: count($this->rows),
        );

        ImportProgressUpdated::dispatch($this->import->fresh());
    }
}
