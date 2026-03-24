<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\ImportStatus;
use App\Jobs\ProcessImportChunk;
use App\Jobs\ProcessImportFile;
use App\Models\Import;
use App\Models\ImportRow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Storage::fake('local');
    }

    // ── POST /api/imports ────────────────────────────────────

    public function test_store_creates_import_and_dispatches_job(): void
    {
        Queue::fake();

        $csv = UploadedFile::fake()->createWithContent(
            'products.csv',
            "name,sku,price,qty,category\nWidget,W001,19.99,100,Tools\n"
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', ['file' => $csv]);

        $response->assertStatus(202)
            ->assertJsonStructure([
                'message',
                'import' => ['id', 'original_filename', 'status'],
            ]);

        $this->assertDatabaseHas('imports', [
            'user_id' => $this->user->id,
            'original_filename' => 'products.csv',
            'status' => ImportStatus::Pending->value,
            'chunk_size' => 500, // дефолт
        ]);

        Queue::assertPushedOn('imports', ProcessImportFile::class);
    }

    public function test_store_saves_file_to_storage(): void
    {
        Queue::fake();

        $csv = UploadedFile::fake()->createWithContent('data.csv', "a,b\n1,2\n");

        $this->actingAs($this->user)
            ->postJson('/api/imports', ['file' => $csv]);

        $import = Import::first();
        Storage::assertExists($import->stored_path);
    }

    public function test_store_accepts_custom_chunk_size(): void
    {
        Queue::fake();

        $csv = UploadedFile::fake()->createWithContent('data.csv', "a\n1\n");

        $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $csv,
                'chunk_size' => 1000,
            ]);

        $this->assertDatabaseHas('imports', ['chunk_size' => 1000]);
    }

    public function test_store_accepts_column_mapping(): void
    {
        Queue::fake();

        $csv = UploadedFile::fake()->createWithContent('data.csv', "a\n1\n");
        $mapping = json_encode(['Название' => 'name', 'Цена' => 'price']);

        $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $csv,
                'column_mapping' => $mapping,
            ]);

        $import = Import::first();
        $this->assertEquals(['Название' => 'name', 'Цена' => 'price'], $import->column_mapping);
    }

    // ── Validation ───────────────────────────────────────────

    public function test_store_rejects_missing_file(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_store_rejects_pdf_file(): void
    {
        $file = UploadedFile::fake()->create('report.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_store_rejects_file_over_50mb(): void
    {
        // 52 MB файл
        $file = UploadedFile::fake()->create('huge.csv', 52000, 'text/csv');

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', ['file' => $file]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_store_rejects_chunk_size_below_minimum(): void
    {
        $csv = UploadedFile::fake()->createWithContent('data.csv', "a\n1\n");

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $csv,
                'chunk_size' => 50, // мин. 100
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('chunk_size');
    }

    public function test_store_rejects_chunk_size_above_maximum(): void
    {
        $csv = UploadedFile::fake()->createWithContent('data.csv', "a\n1\n");

        $response = $this->actingAs($this->user)
            ->postJson('/api/imports', [
                'file' => $csv,
                'chunk_size' => 10000, // макс. 5000
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('chunk_size');
    }

    public function test_store_requires_authentication(): void
    {
        $csv = UploadedFile::fake()->createWithContent('data.csv', "a\n1\n");

        $response = $this->postJson('/api/imports', ['file' => $csv]);

        $response->assertUnauthorized();
    }

    // ── GET /api/imports ─────────────────────────────────────

    public function test_index_returns_paginated_user_imports(): void
    {
        Import::factory(3)->create(['user_id' => $this->user->id]);
        Import::factory(2)->create(); // другого пользователя

        $response = $this->actingAs($this->user)
            ->getJson('/api/imports');

        $response->assertOk()
            ->assertJsonCount(3, 'data'); // только свои
    }

    public function test_index_ordered_by_latest(): void
    {
        $old = Import::factory()->create([
            'user_id' => $this->user->id,
            'created_at' => now()->subDay(),
        ]);
        $new = Import::factory()->create([
            'user_id' => $this->user->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/imports');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertEquals([$new->id, $old->id], $ids);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/imports');

        $response->assertUnauthorized();
    }

    // ── GET /api/imports/{id} ────────────────────────────────

    public function test_show_returns_import_with_progress(): void
    {
        $import = Import::factory()->create([
            'user_id' => $this->user->id,
            'total_rows' => 200,
            'processed_rows' => 150,
            'failed_rows' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/imports/{$import->id}");

        $response->assertOk()
            ->assertJsonPath('progress_percent', 75)
            ->assertJsonStructure([
                'import' => ['id', 'status', 'total_rows', 'processed_rows', 'failed_rows'],
                'progress_percent',
                'failed_rows',
            ]);
    }

    public function test_show_includes_paginated_failed_rows(): void
    {
        $import = Import::factory()->create(['user_id' => $this->user->id]);
        ImportRow::factory(3)->create(['import_id' => $import->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/imports/{$import->id}");

        $response->assertOk()
            ->assertJsonCount(3, 'failed_rows.data');
    }

    public function test_show_forbidden_for_other_user(): void
    {
        $otherUser = User::factory()->create();
        $import = Import::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/imports/{$import->id}");

        $response->assertForbidden();
    }

    public function test_show_404_for_nonexistent_import(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/imports/99999');

        $response->assertNotFound();
    }

    // ── POST /api/imports/{id}/retry ─────────────────────────

    public function test_retry_dispatches_chunk_for_failed_rows(): void
    {
        Queue::fake();

        $import = Import::factory()->completedWithErrors(100, 5)->create([
            'user_id' => $this->user->id,
        ]);

        for ($i = 1; $i <= 5; $i++) {
            ImportRow::factory()->create([
                'import_id' => $import->id,
                'row_number' => $i,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->postJson("/api/imports/{$import->id}/retry");

        $response->assertOk()
            ->assertJsonPath('message', 'Повторная обработка запущена');

        Queue::assertPushedOn('imports', ProcessImportChunk::class);

        // Старые ошибки удалены
        $this->assertDatabaseCount('import_rows', 0);
    }

    public function test_retry_resets_counters(): void
    {
        Queue::fake();

        $import = Import::factory()->completedWithErrors(100, 5)->create([
            'user_id' => $this->user->id,
        ]);

        ImportRow::factory(5)->create(['import_id' => $import->id]);

        $this->actingAs($this->user)
            ->postJson("/api/imports/{$import->id}/retry");

        $import->refresh();
        $this->assertEquals(0, $import->failed_rows);
        $this->assertEquals('processing', $import->status->value);
    }

    public function test_retry_returns_422_when_no_failed_rows(): void
    {
        $import = Import::factory()->completed()->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/imports/{$import->id}/retry");

        $response->assertStatus(422);
    }

    public function test_retry_forbidden_for_other_user(): void
    {
        $otherUser = User::factory()->create();
        $import = Import::factory()->completedWithErrors()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/imports/{$import->id}/retry");

        $response->assertForbidden();
    }
}
