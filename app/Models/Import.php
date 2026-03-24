<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Import extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'original_filename',
        'stored_path',
        'status',
        'total_rows',
        'processed_rows',
        'failed_rows',
        'chunk_size',
        'total_chunks',
        'completed_chunks',
        'column_mapping',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'column_mapping' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function failedRows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function progressPercent(): int
    {
        if ($this->total_rows === 0) {
            return 0;
        }

        return (int) round(($this->processed_rows / $this->total_rows) * 100);
    }

    /**
     * Атомарно увеличивает счётчики обработанных строк.
     * Используем increment() чтобы избежать race condition между чанками.
     */
    public function recordChunkResult(int $processed, int $failed): void
    {
        $this->increment('processed_rows', $processed);
        $this->increment('failed_rows', $failed);
        $this->increment('completed_chunks');
    }
}
