<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BuyerNotificationPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function notice(User $buyer, string $key): DatabaseNotification
    {
        $delivery = app(NotificationDeliveryService::class)->record($buyer->id, 'governance-event', $key, [
            'title' => 'Buyer application approved', 'body' => 'Your identity was reviewed. Checkout is available for active approved accounts.',
            'target' => 'account-status', 'code' => 'private-code', 'id_document_path' => 'private-document.pdf',
        ]);

        return $buyer->notifications()->findOrFail($delivery->id);
    }

    public function test_preview_is_bounded_owned_private_and_does_not_change_read_state(): void
    {
        $buyer = User::factory()->pendingKyc()->create();
        foreach (range(1, 8) as $index) {
            $this->notice($buyer, 'preview-'.$index);
        }
        $this->notice(User::factory()->create(), 'foreign');
        $data = $this->actingAs($buyer)->getJson('/notifications/preview')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonCount(6, 'notices')->assertJsonPath('summary.unread', 8)->json('notices');
        foreach ($data as $notice) {
            $this->assertSame($buyer->id, $buyer->notifications()->findOrFail($notice['id'])->notifiable_id);
            $this->assertArrayNotHasKey('code', $notice['data']);
            $this->assertArrayNotHasKey('id_document_path', $notice['data']);
            $this->assertNull($notice['read_at']);
        }
        $this->assertSame(8, $buyer->unreadNotifications()->count());
    }

    public function test_only_the_displayed_ids_are_read_and_retries_keep_original_timestamps(): void
    {
        $buyer = User::factory()->pendingKyc()->create();
        $notice = $this->notice($buyer, 'shown');
        $unseen = $this->notice($buyer, 'unseen');
        $this->actingAs($buyer)->patchJson('/notifications/read-displayed', ['ids' => [$notice->id]])->assertOk()->assertJsonPath('summary.unread', 1);
        $readAt = $notice->fresh()->read_at;
        $this->travel(1)->hours();
        $this->patchJson('/notifications/read-displayed', ['ids' => [$notice->id]])->assertOk()->assertJsonPath('summary.unread', 1);
        $this->assertTrue($readAt->equalTo($notice->fresh()->read_at));
        $this->assertNull($unseen->fresh()->read_at);
    }

    public function test_foreign_or_missing_ids_cannot_partially_acknowledge_owned_updates(): void
    {
        $buyer = User::factory()->create();
        $owned = $this->notice($buyer, 'owned');
        $other = $this->notice(User::factory()->create(), 'foreign');
        foreach ([$other->id, (string) Str::uuid()] as $invalid) {
            $this->actingAs($buyer)->patchJson('/notifications/read-displayed', ['ids' => [$owned->id, $invalid]])->assertNotFound();
            $this->assertNull($owned->fresh()->read_at);
        }
        $this->patchJson('/notifications/read-displayed', ['ids' => []])->assertUnprocessable();
        $this->patchJson('/notifications/read-displayed', ['ids' => array_map(fn () => (string) Str::uuid(), range(1, 7))])->assertUnprocessable();
    }

    public function test_opening_approval_update_marks_it_read_and_provides_account_feedback(): void
    {
        $buyer = User::factory()->create();
        $notice = $this->notice($buyer, 'approval-open');
        $this->actingAs($buyer)->patch('/notifications/'.$notice->id.'/read', ['open' => true])
            ->assertRedirect('/account/status')->assertSessionHas('success');
        $this->assertNotNull($notice->fresh()->read_at);
        $this->get('/buyer/profile?tab=account&application=1')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('initialTab', 'account')->where('buyerApplication.can_use_portal', true)
            ->where('flash.success', 'Buyer application approved. Your identity was reviewed. Checkout is available for active approved accounts.'));
    }

    public function test_guests_and_closed_accounts_cannot_use_the_preview(): void
    {
        $this->getJson('/notifications/preview')->assertUnauthorized();
        $buyer = User::factory()->create(['status' => 'inactive', 'closed_at' => now()]);
        $this->actingAs($buyer)->getJson('/notifications/preview')->assertForbidden();
        $this->actingAs($buyer)->patchJson('/notifications/read-displayed', ['ids' => [Str::uuid()]])->assertForbidden();
    }

    public function test_notification_storage_failure_does_not_report_a_successful_preview_or_read(): void
    {
        $buyer = User::factory()->create();
        Schema::drop('notifications');
        $this->actingAs($buyer)->getJson('/notifications/preview')->assertStatus(503);
        $this->patchJson('/notifications/read-displayed', ['ids' => [Str::uuid()]])->assertStatus(503);
    }
}
