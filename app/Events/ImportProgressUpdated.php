<?php

namespace App\Events;

use App\Models\Import;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcasting event — отправляет прогресс через WebSocket.
 *
 * ShouldBroadcastNow — отправляем сразу, без очереди
 * (иначе прогресс придёт с задержкой).
 *
 * На фронте слушаем через Laravel Echo:
 *
 *   Echo.private(`imports.${userId}`)
 *       .listen('ImportProgressUpdated', (e) => {
 *           progressBar.value = e.progress_percent;
 *           statusText.innerText = e.status;
 *       });
 */
class ImportProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Import $import,
    ) {}

    /**
     * Приватный канал — только авторизованный пользователь видит свои импорты.
     */
    public function broadcastOn(): Channel
    {
        return new PrivateChannel("imports.{$this->import->user_id}");
    }

    /**
     * Данные, которые получит фронтенд.
     */
    public function broadcastWith(): array
    {
        return [
            'import_id' => $this->import->id,
            'status' => $this->import->status->value,
            'total_rows' => $this->import->total_rows,
            'processed_rows' => $this->import->processed_rows,
            'failed_rows' => $this->import->failed_rows,
            'progress_percent' => $this->import->progressPercent(),
            'completed_at' => $this->import->completed_at?->toISOString(),
        ];
    }
}
