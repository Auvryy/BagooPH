<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public static function accounts(): array
    {
        return ['buyer' => ['buyer', 'active', 'approved', 'http://localhost'],
            'seller' => ['seller', 'active', 'approved', 'http://seller.localhost'],
            'courier' => ['courier', 'active', 'approved', 'http://courier.localhost'],
            'handler' => ['logistics', 'active', 'approved', 'http://hub.localhost'],
            'admin' => ['admin', 'active', 'none', 'http://admin.localhost'],
            'applicant' => ['buyer', 'pending_approval', 'pending_approval', 'http://localhost'],
            'restricted' => ['courier', 'suspended', 'approved', 'http://courier.localhost']];
    }

    #[DataProvider('accounts')]
    public function test_each_role_reads_only_owned_notices_without_exposing_private_fields(string $role, string $status, string $kyc, string $host): void
    {
        $owner = User::factory()->create(['role' => $role, 'status' => $status, 'kyc_status' => $kyc]);
        $foreign = User::factory()->create();
        $service = app(NotificationDeliveryService::class);
        $notice = $service->record($owner->id, 'order-event', 'own', ['title' => 'Owned update', 'password' => 'private-password',
            'proof_path' => 'private-proof', 'code' => '12345678', 'href' => 'https://invalid.example/secret']);
        $other = $service->record($foreign->id, 'order-event', 'foreign', ['title' => 'Other account update']);
        $this->actingAs($owner)->get($host.'/notifications?user_id='.$foreign->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')->has('notices.data', 1)->where('notices.data.0.id', $notice->id)
            ->where('notices.data.0.data', ['title' => 'Owned update'])->where('notices.data.0.href', null)
            ->where('notificationSummary.unread', 1));
        $this->patchJson($host.'/notifications/'.$other->id.'/read')->assertNotFound();
        $this->patchJson($host.'/notifications/'.Str::uuid().'/read')->assertNotFound();
    }

    public function test_read_acknowledgement_is_owned_and_retains_original_time(): void
    {
        $owner = User::factory()->create();
        $notice = app(NotificationDeliveryService::class)->record($owner->id, 'order-event', 'owned:read', ['title' => 'Owned']);
        $read = $this->actingAs($owner)->patchJson('/notifications/'.$notice->id.'/read')->assertOk()->json('read_at');
        $this->travel(1)->hours();
        $this->patchJson('/notifications/'.$notice->id.'/read')->assertOk()->assertJsonPath('read_at', $read);
        $this->get('/notifications?filter=unread')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notices.data', 0)->where('notificationSummary.unread', 0));
        $this->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->has('notices.data', 1));
    }

    public function test_owned_pagination_is_stable_and_filters_are_validated(): void
    {
        $owner = User::factory()->create();
        for ($index = 0; $index < 23; $index++) {
            app(NotificationDeliveryService::class)->record($owner->id, 'order-event', 'page:'.$index, ['title' => 'Update '.$index]);
        }
        $this->actingAs($owner)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('notices.data', 20)->where('notices.total', 23)->where('notices.last_page', 2));
        $this->get('/notifications?page=2')->assertOk()->assertInertia(fn (Assert $page) => $page->has('notices.data', 3));
        $this->getJson('/notifications?filter=foreign')->assertUnprocessable();
    }

    public function test_pending_account_receives_no_transactional_access_from_notice_page(): void
    {
        $owner = User::factory()->pendingKyc()->create();
        $this->actingAs($owner)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->has('notices.data', 0));
        $this->get('/checkout')->assertRedirect(route('kyc.pending'));
        $this->getJson('/governance-history')->assertForbidden();
    }

    public function test_unavailable_storage_is_reported_as_unavailable_instead_of_empty(): void
    {
        $owner = User::factory()->create();
        Schema::drop('notifications');
        $this->actingAs($owner)->get('/notifications')->assertStatus(503)->assertInertia(fn (Assert $page) => $page
            ->where('available', false)->where('notices', null)->where('notificationSummary.available', false)
            ->where('notificationSummary.unread', null));
        $this->patchJson('/notifications/'.Str::uuid().'/read')->assertStatus(503);
    }

    public function test_guests_and_closed_accounts_cannot_use_the_center(): void
    {
        $this->getJson('/notifications')->assertUnauthorized();
        $owner = User::factory()->create(['status' => 'inactive', 'closed_at' => now()]);
        $this->actingAs($owner)->getJson('/notifications')->assertForbidden();
    }
}
