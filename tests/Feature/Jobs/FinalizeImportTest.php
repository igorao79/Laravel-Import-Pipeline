<?php

namespace Tests\Feature\Jobs;

use App\Enums\ImportStatus;
use App\Events\ImportProgressUpdated;
use App\Jobs\FinalizeImport;
use App\Models\Import;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinalizeImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
        Event::fake([ImportProgressUpdated::class]);
    }

    // ── Определение статуса ──────────────────────────────────

    public function test_sets_completed_when_no_failures(): void
    {
        Storage::put('imports/test.csv', 'dummy');

        $import = Import::factory()->processing()->create([
            'user_id' => $this->user->id,
            'stored_path' => 'imports/test.csv',
            'total_rows' => 500,
            'processed_rows' => 500,
            'failed_rows' => 0,
        ]);

        FinalizeImport::dispatchSync($import);

        $import->refresh();
        $this->assertEquals(ImportStatus::Completed, $import->status);
    }

    public function test_sets_completed_with_errors_when_some_failed(): void
    {
        Storage::put('imports/test.csv', 'dummy');

        $import = Import::factory()->processing()->create([
            'user_id' => $this->user->id,
            'stored_path' => 'imports/test.csv',
            'total_rows' => 100,
            'processed_rows' => 100,
            'failed_rows' => 15,
        ]);

        FinalizeImport::dispatchSync($import);

        $import->refresh();
        $this->assertEquals(ImportStatus::CompletedWithErrors, $import->status);
    }

    public function test_sets_failed_when_all_rows_failed(): void
    {
        Storage::put('imports/test.csv', 'dummy');

        $import = Import::factory()->processing()->create([
            'user_id' => $this->user->id,
            'stored_path' => 'imports/test.csv',
            'total_rows' => 50,
            'processed_rows' => 50,
            'failed_rows' => 50,
        ]);

        FinalizeImport::dispatchSync($import);

        $import->refresh();
        $this->assertEquals(ImportStatus::Failed, $import->status);
    }

    // ── Timestamp ────────────────────────────────────────────

    public function test_sets_completed_at_timestamp(): void
    {
        Storage::put('imports/test.csv', 'dummy');

        $import = Import::factory()->processing()->create([
            'user_id' => $this->user->id,
            'stored_path' => 'imports/test.csv',
            'total_rows' => 10,
            'processed_rows' => 10,
            'failed_rows' => 0,
        ]);

        $this->assertNull($import->completed_at);

        FinalizeImport::dispatchSync($import);

        $import->refresh();
        $this->assertNotNull($import->completed_at);
    }

    // ── Очистка файла ────────────────────────────────────────

    public function test_deletes_temporary_file(): void
    {
        Storage::put('imports/cleanup.csv', 'some content');
        Storage::assertExists('imports/cleanup.csv');

        $import = Import::factory()->processing()->create([
            'user_id' => $this->user->id,
            'stored_path' => 'imports/cleanup.csv',
            'total_rows' => 10,
            'processed_rows' => 10,
            'failed_rows' => 0,
        ]);

        FinalizeImport::dispatchSync($import);

        Storage::assertMissing('imports/cleanup.csv');
    }

    public function test_handles_already_deleted_file_gracefully(): void
    {
        // Файл уже не существует — не должно быть ошибки
        $import = Import::factory()->processing()->create([
            'user_id' => $this->user->id,
            'stored_path' => 'imports/ghost.csv',
            'total_rows' => 10,
            'processed_rows' => 10,
            'failed_rows' => 0,
        ]);

        // Не должно выбросить исключение
        FinalizeImport::dispatchSync($import);

        $import->refresh();
        $this->assertEquals(ImportStatus::Completed, $import->status);
    }

    // ── Broadcasting ─────────────────────────────────────────

    public function test_dispatches_final_progress_event(): void
    {
        Storage::put('imports/test.csv', 'dummy');

        $import = Import::factory()->processing()->create([
            'user_id' => $this->user->id,
            'stored_path' => 'imports/test.csv',
            'total_rows' => 10,
            'processed_rows' => 10,
            'failed_rows' => 0,
        ]);

        FinalizeImport::dispatchSync($import);

        Event::assertDispatched(ImportProgressUpdated::class, function ($event) use ($import) {
            return $event->import->id === $import->id;
        });
    }

    // ── Edge cases ───────────────────────────────────────────

    public function test_with_zero_total_rows(): void
    {
        Storage::put('imports/empty.csv', 'dummy');

        $import = Import::factory()->processing()->create([
            'user_id' => $this->user->id,
            'stored_path' => 'imports/empty.csv',
            'total_rows' => 0,
            'processed_rows' => 0,
            'failed_rows' => 0,
        ]);

        FinalizeImport::dispatchSync($import);

        $import->refresh();
        $this->assertEquals(ImportStatus::Completed, $import->status);
    }

    public function test_job_has_retry_config(): void
    {
        $import = Import::factory()->create(['user_id' => $this->user->id]);
        $job = new FinalizeImport($import);

        $this->assertEquals(3, $job->tries);
    }
}
