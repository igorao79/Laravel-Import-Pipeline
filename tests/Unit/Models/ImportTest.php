<?php

namespace Tests\Unit\Models;

use App\Enums\ImportStatus;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    // ── progressPercent() ────────────────────────────────────

    public function test_progress_percent_returns_zero_when_no_rows(): void
    {
        $import = Import::factory()->create(['total_rows' => 0, 'processed_rows' => 0]);

        $this->assertEquals(0, $import->progressPercent());
    }

    public function test_progress_percent_at_50(): void
    {
        $import = Import::factory()->create([
            'total_rows' => 200,
            'processed_rows' => 100,
        ]);

        $this->assertEquals(50, $import->progressPercent());
    }

    public function test_progress_percent_at_100(): void
    {
        $import = Import::factory()->create([
            'total_rows' => 500,
            'processed_rows' => 500,
        ]);

        $this->assertEquals(100, $import->progressPercent());
    }

    public function test_progress_percent_rounds_correctly(): void
    {
        $import = Import::factory()->create([
            'total_rows' => 3,
            'processed_rows' => 1,
        ]);

        // 1/3 = 33.333... → 33
        $this->assertEquals(33, $import->progressPercent());
    }

    public function test_progress_percent_returns_integer(): void
    {
        $import = Import::factory()->create([
            'total_rows' => 7,
            'processed_rows' => 3,
        ]);

        $this->assertIsInt($import->progressPercent());
    }

    // ── recordChunkResult() ──────────────────────────────────

    public function test_record_chunk_result_increments_counters(): void
    {
        $import = Import::factory()->create([
            'processed_rows' => 0,
            'failed_rows' => 0,
            'completed_chunks' => 0,
        ]);

        $import->recordChunkResult(processed: 100, failed: 5);

        $import->refresh();
        $this->assertEquals(100, $import->processed_rows);
        $this->assertEquals(5, $import->failed_rows);
        $this->assertEquals(1, $import->completed_chunks);
    }

    public function test_record_chunk_result_accumulates(): void
    {
        $import = Import::factory()->create([
            'processed_rows' => 100,
            'failed_rows' => 3,
            'completed_chunks' => 1,
        ]);

        $import->recordChunkResult(processed: 50, failed: 2);

        $import->refresh();
        $this->assertEquals(150, $import->processed_rows);
        $this->assertEquals(5, $import->failed_rows);
        $this->assertEquals(2, $import->completed_chunks);
    }

    public function test_record_chunk_result_with_zero_failures(): void
    {
        $import = Import::factory()->create([
            'processed_rows' => 0,
            'failed_rows' => 0,
            'completed_chunks' => 0,
        ]);

        $import->recordChunkResult(processed: 500, failed: 0);

        $import->refresh();
        $this->assertEquals(500, $import->processed_rows);
        $this->assertEquals(0, $import->failed_rows);
    }

    // ── Casts ────────────────────────────────────────────────

    public function test_status_cast_to_enum(): void
    {
        $import = Import::factory()->create(['status' => ImportStatus::Processing]);

        $import->refresh();
        $this->assertInstanceOf(ImportStatus::class, $import->status);
        $this->assertEquals(ImportStatus::Processing, $import->status);
    }

    public function test_column_mapping_cast_to_array(): void
    {
        $mapping = ['Название' => 'name', 'Цена' => 'price'];

        $import = Import::factory()->withMapping($mapping)->create();

        $import->refresh();
        $this->assertIsArray($import->column_mapping);
        $this->assertEquals($mapping, $import->column_mapping);
    }

    public function test_column_mapping_null_by_default(): void
    {
        $import = Import::factory()->create();

        $this->assertNull($import->column_mapping);
    }

    public function test_dates_cast_correctly(): void
    {
        $import = Import::factory()->create([
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $import->refresh();
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $import->started_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $import->completed_at);
    }

    // ── Relationships ────────────────────────────────────────

    public function test_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $import = Import::factory()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(User::class, $import->user);
        $this->assertEquals($user->id, $import->user->id);
    }

    public function test_has_many_failed_rows(): void
    {
        $import = Import::factory()->create();

        ImportRow::factory(3)->create(['import_id' => $import->id]);

        $this->assertCount(3, $import->failedRows);
        $this->assertInstanceOf(ImportRow::class, $import->failedRows->first());
    }

    public function test_cascade_deletes_failed_rows(): void
    {
        $import = Import::factory()->create();
        ImportRow::factory(5)->create(['import_id' => $import->id]);

        $this->assertDatabaseCount('import_rows', 5);

        $import->delete();

        $this->assertDatabaseCount('import_rows', 0);
    }

    // ── Factory states ───────────────────────────────────────

    public function test_factory_completed_state(): void
    {
        $import = Import::factory()->completed()->create();

        $this->assertEquals(ImportStatus::Completed, $import->status);
        $this->assertEquals(0, $import->failed_rows);
        $this->assertNotNull($import->completed_at);
    }

    public function test_factory_failed_state(): void
    {
        $import = Import::factory()->failed()->create();

        $this->assertEquals(ImportStatus::Failed, $import->status);
        $this->assertNotNull($import->completed_at);
    }

    public function test_factory_completed_with_errors_state(): void
    {
        $import = Import::factory()->completedWithErrors(500, 25)->create();

        $this->assertEquals(ImportStatus::CompletedWithErrors, $import->status);
        $this->assertEquals(500, $import->total_rows);
        $this->assertEquals(25, $import->failed_rows);
    }
}
