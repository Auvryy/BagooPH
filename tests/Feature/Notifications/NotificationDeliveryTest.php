<?php

namespace Tests\Feature\Notifications;

use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_intent_is_transactional_and_notice_waits_for_commit(): void
    {
        $user = User::factory()->create();
        $service = app(NotificationDeliveryService::class);
        DB::transaction(function () use ($user, $service) {
            $service->record($user->id, 'order-event', 'event:one', ['title' => 'Order confirmed']);
            $this->assertDatabaseCount('notification_deliveries', 1);
            $this->assertDatabaseCount('notifications', 0);
        });
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull(NotificationDelivery::sole()->delivered_at);
    }

    public function test_rollback_removes_intent_and_does_not_deliver_success(): void
    {
        $user = User::factory()->create();
        try {
            DB::transaction(function () use ($user) {
                $user->update(['name' => 'A rolled back change']);
                app(NotificationDeliveryService::class)->record($user->id, 'order-event', 'event:rollback', ['title' => 'Updated']);
                throw new RuntimeException('Roll back');
            });
        } catch (RuntimeException) {
        }
        $this->assertNotSame('A rolled back change', $user->fresh()->name);
        $this->assertDatabaseCount('notification_deliveries', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notice_outage_keeps_committed_source_and_retries_once_without_private_error_text(): void
    {
        $user = User::factory()->create();
        DB::unprepared("CREATE TRIGGER notice_outage BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'private proof and token details'); END;");
        Log::spy();
        DB::transaction(function () use ($user) {
            $user->update(['name' => 'Saved source change']);
            app(NotificationDeliveryService::class)->record($user->id, 'order-event', 'event:outage', ['title' => 'Order confirmed']);
        });
        $this->assertSame('Saved source change', $user->fresh()->name);
        $this->assertDatabaseCount('notifications', 0);
        $intent = NotificationDelivery::sole();
        $this->assertNull($intent->delivered_at);
        $this->assertSame(1, $intent->attempts);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $message === 'Notification delivery will retry.' && array_keys($context) === ['delivery_id', 'exception_type']);
        DB::unprepared('DROP TRIGGER notice_outage');
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 0); // Backoff is respected.
        $this->travel(2)->minutes();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->artisan('notifications:deliver-pending')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull($intent->fresh()->delivered_at);
    }

    public function test_event_and_recipient_uniqueness_preserves_original_payload_and_read_state(): void
    {
        $users = User::factory()->count(2)->create();
        $service = app(NotificationDeliveryService::class);
        $first = $service->record($users[0]->id, 'order-event', 'event:duplicate', ['title' => 'Original']);
        DB::table('notifications')->where('id', $first->id)->update(['read_at' => now()]);
        $read = DB::table('notifications')->where('id', $first->id)->value('read_at');
        $this->travel(5)->minutes();
        $retry = $service->record($users[0]->id, 'order-event', 'event:duplicate', ['title' => 'Changed']);
        $service->record($users[1]->id, 'order-event', 'event:duplicate', ['title' => 'Other recipient']);
        $this->assertSame($first->id, $retry->id);
        $this->assertDatabaseCount('notification_deliveries', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame('Original', $first->fresh()->data['title']);
        $this->assertSame($read, DB::table('notifications')->where('id', $first->id)->value('read_at'));
    }

    public function test_delivery_and_logging_outages_do_not_escape_the_committed_action(): void
    {
        $user = User::factory()->create();
        DB::unprepared("CREATE TRIGGER combined_notice_outage BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT, 'Notice unavailable'); END;");
        Log::shouldReceive('warning')->once()->andThrow(new RuntimeException('Log unavailable'));
        DB::transaction(function () use ($user) {
            $user->update(['name' => 'Still committed']);
            app(NotificationDeliveryService::class)->record($user->id, 'order-event', 'event:log-outage', ['title' => 'Saved update']);
        });
        $this->assertSame('Still committed', $user->fresh()->name);
        $this->assertNull(NotificationDelivery::sole()->delivered_at);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_raw_sql_cannot_change_or_delete_event_identity(): void
    {
        $user = User::factory()->create();
        app(NotificationDeliveryService::class)->record($user->id, 'order-event', 'event:retained', ['title' => 'Original']);
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(fn () => $operation === 'update'
                    ? DB::table('notification_deliveries')->update(['source_key' => 'replacement'])
                    : DB::table('notification_deliveries')->delete());
                $this->fail('Notification delivery history changed.');
            } catch (Throwable $error) {
                $this->assertStringContainsString('retained', $error->getMessage());
            }
        }
        $this->assertSame('event:retained', NotificationDelivery::sole()->source_key);
    }

    public function test_retry_command_rejects_unbounded_or_invalid_limits(): void
    {
        $this->artisan('notifications:deliver-pending', ['--limit' => '0'])->assertExitCode(2);
        $this->artisan('notifications:deliver-pending', ['--limit' => '1001'])->assertExitCode(2);
        $this->artisan('notifications:deliver-pending', ['--limit' => 'abc'])->assertExitCode(2);
    }
}
