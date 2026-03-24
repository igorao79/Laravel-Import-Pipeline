<?php

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    protected $model = Import::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'original_filename' => fake()->word() . '.csv',
            'stored_path' => 'imports/' . fake()->uuid() . '.csv',
            'status' => ImportStatus::Pending,
            'total_rows' => 0,
            'processed_rows' => 0,
            'failed_rows' => 0,
            'chunk_size' => 500,
            'total_chunks' => 0,
            'completed_chunks' => 0,
            'column_mapping' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => ImportStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ImportStatus::Completed,
            'total_rows' => 1000,
            'processed_rows' => 1000,
            'failed_rows' => 0,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }

    public function completedWithErrors(int $totalRows = 1000, int $failedRows = 50): static
    {
        return $this->state(fn () => [
            'status' => ImportStatus::CompletedWithErrors,
            'total_rows' => $totalRows,
            'processed_rows' => $totalRows,
            'failed_rows' => $failedRows,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => ImportStatus::Failed,
            'started_at' => now()->subMinutes(1),
            'completed_at' => now(),
        ]);
    }

    public function withMapping(array $mapping): static
    {
        return $this->state(fn () => [
            'column_mapping' => $mapping,
        ]);
    }
}
