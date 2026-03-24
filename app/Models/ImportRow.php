<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    use HasFactory;
    protected $fillable = [
        'import_id',
        'row_number',
        'original_data',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'original_data' => 'array',
            'errors' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
