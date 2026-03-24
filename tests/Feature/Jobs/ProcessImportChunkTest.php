<?php

namespace Tests\Feature\Jobs;

use App\Events\ImportProgressUpdated;
use App\Jobs\ProcessImportChunk;
use App\Models\Import;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ProcessImportChunkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Import $import;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Event::fake([ImportProgressUpdated::class]);

        // Создаём таблицу products для тестов
        if (! \Schema::hasTable('products')) {
            \Schema::create('products', function ($table) {
                $table->id();
                $table->string('name');
                $table->string('sku')->unique();
                $table->decimal('price', 10, 2);
                $table->integer('quantity');
                $table->string('category')->nullable();
                $table->timestamps();
            });
        }

        $this->import = Import::factory()->create([
            'user_id' => $this->user->id,
            'total_rows' => 10,
        ]);
    }

    // ── Успешная обработка ───────────────────────────────────

    public function test_inserts_valid_rows_into_products(): void
    {
        $rows = [
            2 => ['name' => 'Widget', 'sku' => 'W001', 'price' => '29.99', 'qty' => '10', 'category' => 'Tools'],
            3 => ['name' => 'Gadget', 'sku' => 'G001', 'price' => '49.99', 'qty' => '5', 'category' => 'Electronics'],
        ];

        ProcessImportChunk::dispatchSync($this->import, $rows, 0);

        $this->assertDatabaseHas('products', ['sku' => 'W001', 'name' => 'Widget']);
        $this->assertDatabaseHas('products', ['sku' => 'G001', 'name' => 'Gadget']);
    }

    public function test_transforms_data_before_insert(): void
    {
        $rows = [
            2 => ['name' => '  Widget  ', 'sku' => 'w001', 'price' => '1 500,50', 'qty' => '10', 'category' => ''],
        ];

        ProcessImportChunk::dispatchSync($this->import, $rows, 0);

        $product = DB::table('products')->where('sku', 'W001')->first();
        $this->assertNotNull($product);
        $this->assertEquals('Widget', $product->name);       // trimmed
        $this->assertEquals('W001', $product->sku);           // uppercased
        $this->assertEquals(1500.50, (float) $product->price); // normalized
        $this->assertNull($product->category);                 // empty → null
    }

    public function test_upserts_by_sku(): void
    {
        // Вставляем первый раз
        DB::table('products')->insert([
            'name' => 'Old Name',
            'sku' => 'W001',
            'price' => 10.00,
            'quantity' => 1,
            'category' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [
            2 => ['name' => 'New Name', 'sku' => 'W001', 'price' => '25.00', 'qty' => '50', 'category' => 'Updated'],
        ];

        ProcessImportChunk::dispatchSync($this->import, $rows, 0);

        // Должен обновить, а не создать дубликат
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseHas('products', [
            'sku' => 'W001',
            'name' => 'New Name',
            'quantity' => 50,
        ]);
    }

    // ── Обработка ошибок ─────────────────────────────────────

    public function test_saves_failed_rows_to_import_rows(): void
    {
        $rows = [
            2 => ['name' => '', 'sku' => '', 'price' => 'not-a-number', 'qty' => '-5', 'category' => ''],
        ];

        ProcessImportChunk::dispatchSync($this->import, $rows, 0);

        $this->assertDatabaseCount('import_rows', 1);
        $this->assertDatabaseHas('import_rows', [
            'import_id' => $this->import->id,
            'row_number' => 2,
        ]);
    }

    public function test_mixed_valid_and_invalid_rows(): void
    {
        $rows = [
            2 => ['name' => 'Good', 'sku' => 'G001', 'price' => '10', 'qty' => '5', 'category' => 'A'],
            3 => ['name' => '', 'sku' => '', 'price' => 'bad', 'qty' => '-1', 'category' => ''],
            4 => ['name' => 'Also Good', 'sku' => 'G002', 'price' => '20', 'qty' => '10', 'category' => 'B'],
            5 => ['name' => '', 'sku' => 'X', 'price' => 'nope', 'qty' => '1', 'category' => ''],
        ];

        ProcessImportChunk::dispatchSync($this->import, $rows, 0);

        // 2 вставлены, 2 ошибочные
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseCount('import_rows', 2);

        $this->assertDatabaseHas('products', ['sku' => 'G001']);
        $this->assertDatabaseHas('products', ['sku' => 'G002']);
        $this->assertDatabaseHas('import_rows', ['row_number' => 3]);
        $this->assertDatabaseHas('import_rows', ['row_number' => 5]);
    }

    public function test_all_rows_invalid(): void
    {
        $rows = [
            2 => ['name' => '', 'sku' => '', 'price' => 'x', 'qty' => '-1', 'category' => ''],
            3 => ['name' => '', 'sku' => '', 'price' => 'y', 'qty' => '-2', 'category' => ''],
        ];

        ProcessImportChunk::dispatchSync($this->import, $rows, 0);

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('import_rows', 2);
    }

    // ── Счётчики ─────────────────────────────────────────────

    public function test_increments_processed_rows_counter(): void
    {
        $rows = [
            2 => ['name' => 'A', 'sku' => 'A1', 'price' => '10', 'qty' => '1', 'category' => ''],
            3 => ['name' => 'B', 'sku' => 'B1', 'price' => '20', 'qty' => '2', 'category' => ''],
            4 => ['name' => 'C', 'sku' => 'C1', 'price' => '30', 'qty' => '3', 'category' => ''],
        ];

        ProcessImportChunk::dispatchSync($this->import, $rows, 0);

        $this->import->refresh();
        $this->assertEquals(3, $this->import->processed_rows);
    }

    public function test_increments_failed_rows_counter(): void
    {
        $rows = [
            2 => ['name' => 'Good', 'sku' => 'G1', 'price' => '10', 'qty' => '1', 'category' => ''],
            3 => ['name' => '', 'sku' => '', 'price' => 'bad', 'qty' => '-1', 'category' => ''],
        ];

        ProcessImportChunk::dispatchSync($this->import, $rows, 0);

        $this->import->refresh();
        $this->assertEquals(2, $this->import->processed_rows);
        $this->assertEquals(1, $this->import->failed_rows);
    }

    public function test_increments_completed_chunks(): void
    {
        $rows = [
            2 => ['name' => 'A', 'sku' => 'A1', 'price' => '10', 'qty' => '1', 'category' => ''],
        ];

        ProcessImportChunk::dispatchSync($this->import, $rows, 0);

        $this->import->refresh();
        $this->assertEquals(1, $this->import->completed_chunks);
    }

    public function test_counters_accumulate_across_chunks(): void
    {
        $chunk1 = [
            2 => ['name' => 'A', 'sku' => 'A1', 'price' => '10', 'qty' => '1', 'category' => ''],
            3 => ['name' => 'B', 'sku' => 'B1', 'price' => '20', 'qty' => '2', 'category' => ''],
        ];

        $chunk2 = [
            4 => ['name' => 'C', 'sku' => 'C1', 'price' => '30', 'qty' => '3', 'category' => ''],
            5 => ['name' => '', 'sku' => '', 'price' => 'x', 'qty' => '-1', 'category' => ''], // fail
        ];

        ProcessImportChunk::dispatchSync($this->import, $chunk1, 0);
        ProcessImportChunk::dispatchSync($this->import, $chunk2, 1);

        $this->import->refresh();
        $this->assertEquals(4, $this->import->processed_rows);
        $this->assertEquals(1, $this->import->failed_rows);
        $this->assertEquals(2, $this->import->completed_chunks);
    }

    // ── Broadcasting ─────────────────────────────────────────

    public function test_dispatches_progress_event(): void
    {
        $rows = [
            2 => ['name' => 'A', 'sku' => 'A1', 'price' => '10', 'qty' => '1', 'category' => ''],
        ];

        ProcessImportChunk::dispatchSync($this->import, $rows, 0);

        Event::assertDispatched(ImportProgressUpdated::class, function ($event) {
            return $event->import->id === $this->import->id;
        });
    }

    // ── Failed job handler ───────────────────────────────────

    public function test_failed_handler_counts_all_rows_as_failed(): void
    {
        $rows = [
            2 => ['name' => 'A', 'sku' => 'A1', 'price' => '10', 'qty' => '1', 'category' => ''],
            3 => ['name' => 'B', 'sku' => 'B1', 'price' => '20', 'qty' => '2', 'category' => ''],
            4 => ['name' => 'C', 'sku' => 'C1', 'price' => '30', 'qty' => '3', 'category' => ''],
        ];

        $job = new ProcessImportChunk($this->import, $rows, 0);
        $job->failed(new \RuntimeException('Database connection lost'));

        $this->import->refresh();
        $this->assertEquals(3, $this->import->processed_rows);
        $this->assertEquals(3, $this->import->failed_rows);
    }

    // ── Job configuration ────────────────────────────────────

    public function test_job_has_correct_retry_config(): void
    {
        $rows = [2 => ['name' => 'A', 'sku' => 'A1', 'price' => '10', 'qty' => '1', 'category' => '']];
        $job = new ProcessImportChunk($this->import, $rows, 0);

        $this->assertEquals(3, $job->tries);
        $this->assertEquals([5, 30, 120], $job->backoff);
    }
}
