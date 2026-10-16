<?php

namespace Tests\Feature\Notifications;

use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PortalNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public static function portals(): array
    {
        $cases = [];
        foreach (['buyer', 'seller', 'courier', 'company', 'handler', 'admin'] as $kind) {
            $domain = match ($kind) {
                'buyer' => 'localhost',
                'company', 'handler' => 'hub.localhost',
                default => $kind.'.localhost',
            };
            $cases[$kind.' root'] = [$kind, 'http://localhost'];
            $cases[$kind.' portal host'] = [$kind, 'http://'.$domain];
        }

        return $cases;
    }

    private function account(string $kind): User
    {
        $user = User::factory()->create(['role' => in_array($kind, ['company', 'handler'], true) ? 'logistics' : $kind]);
        if (in_array($kind, ['company', 'handler'], true)) {
            $company = $this->company($kind === 'company' ? $user : User::factory()->create(['role' => 'logistics']));
            $hub = $this->hub($company);
            if ($kind === 'handler') {
                HubHandler::create(['user_id' => $user->id, 'hub_id' => $hub->id, 'is_active' => true]);
            }
        }

        return $user;
    }

    private function company(User $owner): LogisticsCompany
    {
        return LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Bagoo Notification Fleet',
            'slug' => 'notification-fleet-'.$owner->id, 'code' => 'NTF-'.$owner->id, 'status' => 'active', 'is_active' => true]);
    }

    private function hub(LogisticsCompany $company): LogisticsHub
    {
        return LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Bagoo Notification Bayan Hub',
            'code' => 'NTF-BH-'.$company->id, 'tier' => 'local_bayan_hub', 'province' => 'Laguna',
            'city_municipality' => 'Santa Cruz', 'address' => 'Notification hub street', 'is_active' => true]);
    }

    private function notice(User $user, string $key): DatabaseNotification
    {
        $saved = app(NotificationDeliveryService::class)->record($user->id, 'governance-event', $key, [
            'title' => 'Your account update', 'body' => 'Review the saved decision for your account.', 'target' => 'account-status',
            'code' => 'private-claim-code', 'proof_path' => 'private-proof.pdf', 'href' => 'https://invalid.example/private',
        ]);

        return $user->notifications()->findOrFail($saved->id);
    }

    #[DataProvider('portals')]
    public function test_every_role_has_an_owned_bounded_preview_and_its_own_full_page_context(string $kind, string $host): void
    {
        $owner = $this->account($kind);
        $other = $this->notice(User::factory()->create(), 'foreign');
        foreach (range(1, 8) as $number) {
            $this->notice($owner, 'owned-'.$number);
        }
        $unknown = $owner->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'unsupported-notice', 'source_key' => 'unsupported:'.$owner->id, 'data' => ['title' => 'Unknown type']]);
        $preview = $this->actingAs($owner)->getJson($host.'/notifications/preview?user_id='.$other->notifiable_id)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(6, 'notices')
            ->assertJsonPath('summary.unread', 8)->json('notices');
        $expectedIds = $owner->notifications()->where('type', 'governance-event')->orderByDesc('created_at')->orderByDesc('id')->limit(6)->pluck('id')->all();
        $this->assertSame($expectedIds, array_column($preview, 'id'));
        foreach ($preview as $notice) {
            $this->assertNotSame($other->id, $notice['id']);
            $this->assertSame(['title', 'body'], array_keys($notice['data']));
            $this->assertNull($notice['read_at']);
            $this->assertSame($kind === 'buyer' ? '/buyer/profile?tab=account&application=1' : '/pending-approval', $notice['href']);
        }
        $this->assertSame(9, $owner->unreadNotifications()->count());
        $unseen = $this->notice($owner, 'arrived-after-preview');
        $this->patchJson($host.'/notifications/read-displayed', ['ids' => $expectedIds])->assertOk()->assertJsonPath('summary.unread', 3);
        $readAt = $owner->notifications()->findOrFail($expectedIds[0])->read_at;
        $this->travel(1)->hours();
        $this->patchJson($host.'/notifications/read-displayed', ['ids' => $expectedIds])->assertOk()->assertJsonPath('summary.unread', 3);
        $this->assertTrue($readAt->equalTo($owner->notifications()->findOrFail($expectedIds[0])->read_at));
        $this->assertNull($unseen->fresh()->read_at);
        $this->assertNull($unknown->fresh()->read_at);
        $portal = match ($kind) {
            'buyer' => 'buyer', 'courier' => 'courier', default => 'dashboard'
        };
        $this->get($host.'/notifications?filter=unread')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')->where('portal', $portal)->where('filter', 'unread')
            ->has('notices.data', 3)->where('notificationSummary.unread', 3)->where('isOnline', false)->where('scope', null));
        $owned = $this->notice($owner, 'rollback-owned');
        foreach ([$other->id, (string) Str::uuid()] as $foreignId) {
            $this->patchJson($host.'/notifications/read-displayed', ['ids' => [$owned->id, $foreignId]])->assertNotFound();
            $this->assertNull($owned->fresh()->read_at);
        }
        foreach ([[], array_fill(0, 7, (string) Str::uuid()), [$owned->id, $owned->id], ['invalid-id']] as $invalid) {
            $this->patchJson($host.'/notifications/read-displayed', ['ids' => $invalid])->assertUnprocessable();
        }
        $this->patch($host.'/notifications/'.$owned->id.'/read', ['open' => true])
            ->assertRedirect($kind === 'buyer' ? '/buyer/profile?tab=account&application=1' : '/pending-approval')->assertSessionHas('success');
        $this->assertNotNull($owned->fresh()->read_at);
    }

    public static function heldAccounts(): array
    {
        $cases = [];
        foreach (['seller', 'courier', 'logistics', 'admin'] as $role) {
            foreach ([['pending_approval', 'pending_approval'], ['active', 'rejected'], ['suspended', 'approved'], ['inactive', 'approved']] as [$status, $kyc]) {
                if ($role === 'admin' && $status === 'active') {
                    continue;
                }
                $cases[$role.' '.$status.' '.$kyc] = [$role, $status, $kyc];
            }
        }

        return $cases;
    }

    #[DataProvider('heldAccounts')]
    public function test_held_accounts_keep_owned_updates_without_operational_shell_or_access(string $role, string $status, string $kyc): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => $status, 'kyc_status' => $kyc]);
        if ($role === 'courier') {
            CourierProfile::factory()->create(['user_id' => $user->id, 'is_available' => true]);
        }
        $notice = $this->notice($user, 'held');
        $this->actingAs($user)->getJson('/notifications/preview')->assertOk()->assertJsonCount(1, 'notices');
        $this->patchJson('/notifications/read-displayed', ['ids' => [$notice->id]])->assertOk()->assertJsonPath('summary.unread', 0);
        $this->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('portal', 'account')->where('scope', null)->where('isOnline', false)->where('accountUrl', '/pending-approval'));
        $path = match ($role) {
            'seller' => '/seller/dashboard', 'courier' => '/courier/deliveries', 'logistics' => '/hub/dashboard', default => '/admin/dashboard'
        };
        $this->get($path)->assertRedirect();
    }

    public function test_rider_notifications_retain_real_duty_assignment_and_operational_scope(): void
    {
        $company = $this->company(User::factory()->create(['role' => 'logistics']));
        $hub = $this->hub($company);
        $rider = $this->account('courier');
        $profile = CourierProfile::factory()->create(['user_id' => $rider->id, 'logistics_company_id' => $company->id,
            'assigned_hub_id' => $hub->id, 'vehicle_id' => null, 'is_available' => true]);
        $this->actingAs($rider)->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('portal', 'courier')->where('isOnline', true)->where('scope.company', $company->name)
            ->where('scope.hub', $hub->name)->where('scope.hubCode', $hub->code)->where('scope.isAssigned', true)->where('scope.isOperational', true));
        $profile->update(['is_available' => false]);
        $this->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->where('isOnline', false));
        $foreignHub = $this->hub($this->company(User::factory()->create(['role' => 'logistics'])));
        $profile->update(['assigned_hub_id' => $foreignHub->id]);
        $this->get('/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page->where('scope.hub', null)->where('scope.hubCode', null)->where('scope.isOperational', false));
    }

    public function test_reading_the_last_notice_on_a_later_unread_page_returns_to_existing_history(): void
    {
        $owner = $this->account('seller');
        foreach (range(1, 21) as $number) {
            $this->notice($owner, 'later-page-'.$number);
        }
        $last = $owner->notifications()->orderByDesc('created_at')->orderByDesc('id')->skip(20)->firstOrFail();
        $this->actingAs($owner)->get('/notifications?filter=unread&page=2')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('notices.data', 1)->where('notices.data.0.id', $last->id));
        $this->from('/notifications?filter=unread&page=2')->patch('/notifications/'.$last->id.'/read')->assertRedirect('/notifications?filter=unread&page=2');
        $this->get('/notifications?filter=unread&page=2')->assertRedirect('/notifications?filter=unread&page=1');
        $this->get('/notifications?filter=unread&page=1')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('portal', 'dashboard')->has('notices.data', 20)->where('notificationSummary.unread', 20));
    }

    #[DataProvider('portals')]
    public function test_storage_outages_keep_the_role_context_and_never_claim_a_successful_preview_or_read(string $kind, string $host): void
    {
        $owner = $this->account($kind);
        Schema::drop('notifications');
        $this->actingAs($owner)->getJson($host.'/notifications/preview')->assertStatus(503);
        $this->patchJson($host.'/notifications/read-displayed', ['ids' => [(string) Str::uuid()]])->assertStatus(503);
        $portal = match ($kind) {
            'buyer' => 'buyer', 'courier' => 'courier', default => 'dashboard'
        };
        $this->get($host.'/notifications')->assertStatus(503)->assertInertia(fn (Assert $page) => $page
            ->where('portal', $portal)->where('available', false)->where('notices', null)->where('notificationSummary.unread', null));
    }

    public function test_unsupported_and_closed_accounts_cannot_preview_or_acknowledge_notifications(): void
    {
        foreach ([['role' => 'unknown'], ['closed_at' => now(), 'status' => 'inactive']] as $attributes) {
            $user = User::factory()->create($attributes);
            $this->actingAs($user)->getJson('/notifications/preview')->assertForbidden();
            $this->actingAs($user)->patchJson('/notifications/read-displayed', ['ids' => [(string) Str::uuid()]])->assertForbidden();
        }
    }
}
