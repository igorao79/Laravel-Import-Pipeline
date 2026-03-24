<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Events\ImportProgressUpdated;
use App\Models\Import;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Job #3: Финализация после обработки всех чанков.
 *
 * - Определяет итоговый статус
 * - Удаляет временный файл
 * - Отправляет уведомление пользователю
 */
class FinalizeImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly Import $import,
    ) {}

    public function handle(): void
    {
        $import = $this->import->fresh();

        // Определяем итоговый статус
        $status = match (true) {
            $import->failed_rows === 0 => ImportStatus::Completed,
            $import->failed_rows === $import->total_rows => ImportStatus::Failed,
            default => ImportStatus::CompletedWithErrors,
        };

        $import->update([
            'status' => $status,
            'completed_at' => now(),
        ]);

        // Удаляем временный файл
        Storage::delete($import->stored_path);

        // Отправляем финальное событие
        ImportProgressUpdated::dispatch($import);

        // Можно добавить уведомление пользователю
        // $import->user->notify(new ImportCompletedNotification($import));
    }
}
