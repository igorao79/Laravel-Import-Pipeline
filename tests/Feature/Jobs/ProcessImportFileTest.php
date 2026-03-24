<?php

namespace Tests\Feature\Jobs;

use App\Enums\ImportStatus;
use App\Events\ImportProgressUpdated;
use App\Jobs\ProcessImportFile;
use App\Models\Import;
use App\Models\User;
use App\Services\FileParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProcessImportFileTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    /**
     * Helper: directly invokes the job's handle() method,
     * bypassing Bus::fake() which would prevent execution.
     */
    private function runJob(Import $import): void
    {
        $job = new ProcessImportFile($import);
        $job->handle(app(FileParser::class));
    }

    public function test_sets_status_to_processing(): void
    {
        Bus::fake();
        Event::fake();

        $path = 'imports/test.csv';
        Storage::put($path, "name,sku,price,qty,category\nA,A1,10,1,Cat\n");

        $import = Import::factory()->create([
            'user_id' => $this->user->id,
            'stored_path' => $path,
        ]);

        $this->runJob($import);

        $import->refresh();
        $this->assertEquals(ImportStatus::Processing, $import->status);
        $this->assertNotNull($import->started_at);
    }

    public function test_counts_total_rows(): void
    {
        Bus::fake();
        Event::fake();

        $csv = "name,sku,price,qty,category\n";
        for ($i = 1; $i <= 25; $i++) {
            $csv .= "Product{$i},SKU{$i},{$i},1,Cat\n";
        }

        $path = 'imports/test.csv';
        Storage::put($path, $csv);

        $import = Import::factory()->create([
            'user_id' => $this->user->id,
            'stored_path' => $path,
        ]);

        $this->runJob($import);

        $import->refresh();
        $this->assertEquals(25, $import->total_rows);
    }

    public function test_calculates_total_chunks(): void
    {
        Bus::fake();
        Event::fake();

        $csv = "name,sku,price,qty,category\n";
        for ($i = 1; $i <= 10; $i++) {
            $csv .= "P{$i},S{$i},{$i},1,C\n";
        }

        $path = 'imports/test.csv';
        Storage::put($path, $csv);

        $import = Import::factory()->create([
            'user_id' => $this->user->id,
            'stored_path' => $path,
            'chunk_size' => 3, // 10 / 3 = 4 чанка (3+3+3+1)
        ]);

        $this->runJob($import);

        $import->refresh();
        $this->assertEquals(4, $import->total_chunks);
    }

    public function test_empty_file_completes_immediately(): void
    {
        Bus::fake();
        Event::fake();

        $path = 'imports/empty.csv';
        Storage::put($path, "name,sku,price,qty,category\n");

        $import = Import::factory()->create([
            'user_id' => $this->user->id,
            'stored_path' => $path,
        ]);

        $this->runJob($import);

        $import->refresh();
        $this->assertEquals(ImportStatus::Completed, $import->status);
        $this->assertNotNull($import->completed_at);
        $this->assertEquals(0, $import->total_chunks);
    }

    public function test_dispatches_progress_event(): void
    {
        Bus::fake();
        Event::fake([ImportProgressUpdated::class]);

        $path = 'imports/test.csv';
        Storage::put($path, "name,sku,price,qty,category\nA,A1,10,1,Cat\n");

        $import = Import::factory()->create([
            'user_id' => $this->user->id,
            'stored_path' => $path,
        ]);

        $this->runJob($import);

        Event::assertDispatched(ImportProgressUpdated::class, function ($event) use ($import) {
            return $event->import->id === $import->id;
        });
    }

    public function test_failed_job_sets_failed_status(): void
    {
        Event::fake();

        // Файл не существует — вызовет ошибку
        $import = Import::factory()->create([
            'user_id' => $this->user->id,
            'stored_path' => 'imports/nonexistent.csv',
        ]);

        $job = new ProcessImportFile($import);

        // Симулируем failure
        $job->failed(new \RuntimeException('File not found'));

        $import->refresh();
        $this->assertEquals(ImportStatus::Failed, $import->status);
        $this->assertNotNull($import->completed_at);
    }
}
