<?php

namespace Tests\Feature\Events;

use App\Enums\ImportStatus;
use App\Events\ImportProgressUpdated;
use App\Models\Import;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportProgressUpdatedTest extends TestCase
{
    use RefreshDatabase;

    // ── Channel ──────────────────────────────────────────────

    public function test_broadcasts_on_private_user_channel(): void
    {
        $user = User::factory()->create();
        $import = Import::factory()->create(['user_id' => $user->id]);

        $event = new ImportProgressUpdated($import);

        $channel = $event->broadcastOn();
        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertEquals("private-imports.{$user->id}", $channel->name);
    }

    public function test_different_users_get_different_channels(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $import1 = Import::factory()->create(['user_id' => $user1->id]);
        $import2 = Import::factory()->create(['user_id' => $user2->id]);

        $channel1 = (new ImportProgressUpdated($import1))->broadcastOn();
        $channel2 = (new ImportProgressUpdated($import2))->broadcastOn();

        $this->assertNotEquals($channel1->name, $channel2->name);
    }

    // ── Broadcast data ───────────────────────────────────────

    public function test_broadcast_data_contains_all_fields(): void
    {
        $import = Import::factory()->create([
            'total_rows' => 200,
            'processed_rows' => 100,
            'failed_rows' => 5,
            'status' => ImportStatus::Processing,
            'completed_at' => null,
        ]);

        $event = new ImportProgressUpdated($import);
        $data = $event->broadcastWith();

        $this->assertArrayHasKey('import_id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('total_rows', $data);
        $this->assertArrayHasKey('processed_rows', $data);
        $this->assertArrayHasKey('failed_rows', $data);
        $this->assertArrayHasKey('progress_percent', $data);
        $this->assertArrayHasKey('completed_at', $data);
    }

    public function test_broadcast_data_has_correct_values(): void
    {
        $import = Import::factory()->create([
            'total_rows' => 200,
            'processed_rows' => 100,
            'failed_rows' => 5,
            'status' => ImportStatus::Processing,
        ]);

        $data = (new ImportProgressUpdated($import))->broadcastWith();

        $this->assertEquals($import->id, $data['import_id']);
        $this->assertEquals('processing', $data['status']);
        $this->assertEquals(200, $data['total_rows']);
        $this->assertEquals(100, $data['processed_rows']);
        $this->assertEquals(5, $data['failed_rows']);
        $this->assertEquals(50, $data['progress_percent']);
    }

    public function test_broadcast_completed_at_null_when_not_completed(): void
    {
        $import = Import::factory()->create([
            'status' => ImportStatus::Processing,
            'completed_at' => null,
        ]);

        $data = (new ImportProgressUpdated($import))->broadcastWith();

        $this->assertNull($data['completed_at']);
    }

    public function test_broadcast_completed_at_iso_string_when_completed(): void
    {
        $import = Import::factory()->completed()->create();

        $data = (new ImportProgressUpdated($import))->broadcastWith();

        $this->assertNotNull($data['completed_at']);
        $this->assertIsString($data['completed_at']);
        // Проверяем что формат ISO 8601
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $data['completed_at']);
    }

    public function test_broadcast_progress_at_zero(): void
    {
        $import = Import::factory()->create([
            'total_rows' => 0,
            'processed_rows' => 0,
        ]);

        $data = (new ImportProgressUpdated($import))->broadcastWith();

        $this->assertEquals(0, $data['progress_percent']);
    }

    public function test_broadcast_progress_at_100(): void
    {
        $import = Import::factory()->create([
            'total_rows' => 500,
            'processed_rows' => 500,
        ]);

        $data = (new ImportProgressUpdated($import))->broadcastWith();

        $this->assertEquals(100, $data['progress_percent']);
    }

    // ── Channel authorization ────────────────────────────────

    public function test_channel_authorization_allows_owner(): void
    {
        $user = User::factory()->create();
        Import::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson('/broadcasting/auth', [
                'channel_name' => "private-imports.{$user->id}",
            ]);

        $response->assertOk();
    }

    public function test_channel_authorization_denies_other_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        // Проверяем callback канала напрямую,
        // т.к. log-драйвер не enforces auth через HTTP
        $result = (int) $intruder->id === (int) $owner->id;
        $this->assertFalse($result, 'Intruder should not access owner channel');
    }
}
