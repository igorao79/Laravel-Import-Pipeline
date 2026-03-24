<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Events\ImportProgressUpdated;
use App\Models\Import;
use App\Services\FileParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * Job #1: Главный оркестратор.
 * Читает файл, разбивает на чанки, диспатчит цепочку дочерних jobs.
 *
 * Архитектура:
 * ProcessImportFile → [ProcessImportChunk x N] → FinalizeImport
 *
 * Используем Bus::chain() чтобы FinalizeImport выполнился
 * только после ВСЕХ чанков. Но чанки между собой — параллельно через Bus::batch().
 */
class ProcessImportFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;       // не ретраим главный job
    public int $timeout = 300;   // 5 минут на разбиение файла

    public function __construct(
        public readonly Import $import,
    ) {}

    public function handle(FileParser $parser): void
    {
        $import = $this->import;

        // Обновляем статус
        $import->update([
            'status' => ImportStatus::Processing,
            'started_at' => now(),
        ]);

        $filePath = \Illuminate\Support\Facades\Storage::disk('local')->path($import->stored_path);

        // Считаем строки для прогресс-бара
        $totalRows = $parser->countRows($filePath);
        $import->update(['total_rows' => $totalRows]);

        // Разбиваем на чанки. Generator → array чанков.
        $chunks = [];
        $currentChunk = [];
        $chunkIndex = 0;

        foreach ($parser->parse($filePath) as $rowNumber => $row) {
            $currentChunk[$rowNumber] = $row;

            if (count($currentChunk) >= $import->chunk_size) {
                $chunks[] = $currentChunk;
                $currentChunk = [];
                $chunkIndex++;
            }
        }

        // Последний неполный чанк
        if (! empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        $import->update(['total_chunks' => count($chunks)]);

        if (empty($chunks)) {
            $import->update([
                'status' => ImportStatus::Completed,
                'completed_at' => now(),
            ]);

            return;
        }

        // Создаём Batch из чанков + FinalizeImport в конце
        $chunkJobs = array_map(
            fn (array $chunk, int $index) => new ProcessImportChunk($import, $chunk, $index),
            $chunks,
            array_keys($chunks),
        );

        Bus::batch($chunkJobs)
            ->name("import-{$import->id}")
            ->allowFailures()              // продолжаем даже если часть чанков упала
            ->finally(function () use ($import) {
                // После всех чанков — финализация
                FinalizeImport::dispatch($import);
            })
            ->onQueue('imports')
            ->dispatch();

        ImportProgressUpdated::dispatch($import->fresh());
    }

    public function failed(\Throwable $exception): void
    {
        $this->import->update([
            'status' => ImportStatus::Failed,
            'completed_at' => now(),
        ]);

        ImportProgressUpdated::dispatch($this->import->fresh());
    }
}
