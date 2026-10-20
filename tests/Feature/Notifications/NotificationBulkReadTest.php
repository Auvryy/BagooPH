<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Services\Notifications\NotificationCenterService;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationBulkReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function notice(User $owner, string $type = 'order-event'): string
    {
        return app(NotificationDeliveryService::class)->record($owner->id, $type, 'bulk:'.Str::uuid(), ['title' => 'Saved order update'])->id;
    }

    private function snapshot(User $owner): array
    {
        return $this->actingAs($owner)->get('/notifications')->assertOk()->viewData('page')['props']['readSnapshot'];
    }

    public function test_bulk_read_uses_the_exact_owned_set_beyond_the_first_page_and_excludes_same_tick_arrivals(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $ids = collect(range(1, 27))->map(fn () => $this->notice($owner));
        $foreignId = $this->notice($foreign);
        $unknown = $this->notice($owner, 'future-notice');
        $snapshot = $this->snapshot($owner);
        $this->assertSame(27, $snapshot['count']);
        $late = $this->notice($owner);
        $this->patchJson('/notifications/'.$ids[0].'/read')->assertOk();
        $originalRead = DB::table('notifications')->where('id', $ids[0])->value('read_at');
        $this->travel(5)->minutes();
        $this->patchJson('/notifications/read-all', ['snapshot' => $snapshot['id'], 'ids' => [$foreignId, $late, $unknown], 'unread' => 99999])
            ->assertOk()->assertJsonPath('updated', 26)->assertJsonPath('summary.unread', 1);
        $times = DB::table('notifications')->whereIn('id', $ids)->pluck('read_at', 'id')->all();
        $this->assertSame($originalRead, $times[$ids[0]]);
        $this->assertSame(27, count(array_filter($times)));
        foreach ([$late, $unknown, $foreignId] as $id) {
            $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        }
        $this->travel(1)->minutes();
        $this->patchJson('/notifications/read-all', ['snapshot' => $snapshot['id']])->assertOk()->assertJsonPath('updated', 0)->assertJsonPath('summary.unread', 1);
        $this->assertSame($times, DB::table('notifications')->whereIn('id', $ids)->pluck('read_at', 'id')->all());
    }

    public function test_cross_account_forged_and_expired_snapshots_cannot_acknowledge_anything(): void
    {
        $this->freezeTime();
        $owner = User::factory()->create();
        $foreign = User::factory()->create();
        $id = $this->notice($owner);
        $snapshot = $this->snapshot($owner);
        $this->actingAs($foreign)->patchJson('/notifications/read-all', ['snapshot' => $snapshot['id']])->assertUnprocessable()->assertJsonValidationErrors('snapshot');
        $this->actingAs($owner)->patchJson('/notifications/read-all', ['snapshot' => (string) Str::uuid()])->assertUnprocessable();
        $this->patchJson('/notifications/read-all', ['snapshot' => [$snapshot['id']]])->assertUnprocessable();
        $this->travelTo($snapshot['expires_at']);
        $this->patchJson('/notifications/read-all', ['snapshot' => $snapshot['id']])->assertUnprocessable()->assertJsonValidationErrors('snapshot');
        $this->assertNull(DB::table('notifications')->where('id', $id)->value('read_at'));
        $this->get('/notifications')->assertOk();
        $this->assertDatabaseMissing('notification_read_snapshots', ['id' => $snapshot['id']]);
        $this->assertDatabaseHas('notifications', ['id' => $id, 'read_at' => null]);
    }

    public function test_a_failed_bulk_write_rolls_back_and_a_retry_can_confirm_the_real_result(): void
    {
        $owner = User::factory()->create();
        $first = $this->notice($owner);
        $second = $this->notice($owner);
        $snapshot = $this->snapshot($owner);
        DB::unprepared("CREATE TRIGGER reject_bulk_read BEFORE UPDATE ON notifications WHEN NEW.id = '{$second}' AND NEW.read_at IS NOT NULL BEGIN SELECT RAISE(ABORT, 'Injected notification failure.'); END;");
        $this->patchJson('/notifications/read-all', ['snapshot' => $snapshot['id']])->assertStatus(503);
        $this->assertSame(2, DB::table('notifications')->whereIn('id', [$first, $second])->whereNull('read_at')->count());
        DB::unprepared('DROP TRIGGER reject_bulk_read');
        $this->patchJson('/notifications/read-all', ['snapshot' => $snapshot['id']])->assertOk()->assertJsonPath('updated', 2)->assertJsonPath('summary.unread', 0);
        $this->get('/notifications?filter=unread')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notices.data', 0)->where('readSnapshot', null)->where('notificationSummary.unread', 0));
    }

    public static function roles(): array
    {
        return array_map(fn ($role) => [$role], ['buyer', 'seller', 'courier', 'logistics', 'admin']);
    }

    #[DataProvider('roles')]
    public function test_bell_preview_exposes_an_owned_bulk_action_for_all_pages_without_marking_later_arrivals(string $role): void
    {
        $this->freezeTime();
        $owner = User::factory()->create(['role' => $role]);
        foreach (range(1, 27) as $index) {
            $this->notice($owner);
        }
        $preview = $this->actingAs($owner)->getJson('/notifications/preview')->assertOk()
            ->assertJsonCount(6, 'notices')->assertJsonPath('readSnapshot.count', 27);
        $snapshot = $preview->json('readSnapshot.id');
        $later = $this->notice($owner);
        $this->patchJson('/notifications/read-all', ['snapshot' => $snapshot])->assertOk()
            ->assertJsonPath('updated', 27)->assertJsonPath('summary.unread', 1);
        $this->assertDatabaseHas('notifications', ['id' => $later, 'read_at' => null]);
    }

    #[DataProvider('roles')]
    public function test_the_explicit_action_preserves_each_roles_owned_history_and_current_filter(string $role): void
    {
        $owner = User::factory()->create(['role' => $role]);
        $id = $this->notice($owner);
        $snapshot = $this->snapshot($owner);
        $this->patch('/notifications/read-all', ['snapshot' => $snapshot['id'], 'filter' => 'unread'])->assertRedirect('/notifications?filter=unread');
        $this->get('/notifications?filter=unread')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filter', 'unread')->has('notices.data', 0)->where('notificationSummary.unread', 0));
        $this->assertDatabaseHas('notifications', ['id' => $id]);
    }

    public function test_bulk_read_does_not_expand_preview_acknowledgement_and_rechecks_closed_account_access(): void
    {
        $owner = User::factory()->create();
        $displayed = $this->notice($owner);
        $other = $this->notice($owner);
        $snapshot = $this->snapshot($owner);
        $this->patchJson('/notifications/read-displayed', ['ids' => [$displayed], 'snapshot' => $snapshot['id']])->assertOk();
        $this->assertDatabaseHas('notifications', ['id' => $other, 'read_at' => null]);
        User::whereKey($owner->id)->update(['closed_at' => now(), 'status' => 'inactive']);
        $this->patchJson('/notifications/read-all', ['snapshot' => $snapshot['id']])->assertForbidden();
        $this->assertDatabaseHas('notifications', ['id' => $other, 'read_at' => null]);
    }

    public function test_an_empty_inbox_has_no_bulk_snapshot_and_guests_cannot_submit(): void
    {
        $owner = User::factory()->create();
        $this->assertNull(app(NotificationCenterService::class)->readSnapshot($owner));
        $this->assertDatabaseCount('notification_read_snapshots', 0);
        $this->patchJson('/notifications/read-all', ['snapshot' => (string) Str::uuid()])->assertUnauthorized();
    }
}
