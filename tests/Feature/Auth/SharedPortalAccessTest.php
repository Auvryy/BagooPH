<?php

namespace Tests\Feature\Auth;

use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SharedPortalAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function restrictedWorkerAccounts(): array
    {
        $cases = [];
        foreach (['seller', 'logistics'] as $role) {
            foreach (self::portalPrefixes($role) as $prefix) {
                foreach ([
                    ['active', 'none'], ['active', 'pending_approval'], ['active', 'rejected'],
                    ['active', 'unknown'], ['pending_approval', 'approved'],
                    ['inactive', 'approved'], ['inactive', 'verified'], ['unknown', 'approved'],
                    ['suspended', 'approved'],
                ] as [$status, $kyc]) {
                    $cases[$role.' '.$prefix.' '.$status.' '.$kyc] = [$role, $prefix, $status, $kyc];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('restrictedWorkerAccounts')]
    public function test_restricted_workers_cannot_read_or_write_either_portal(string $role, string $prefix, string $status, string $kyc): void
    {
        $actor = User::factory()->create(['role' => $role, 'status' => $status, 'kyc_status' => $kyc]);
        $product = $role === 'seller' ? $this->sellerProduct($actor) : null;
        $hub = $role === 'logistics' ? $this->companyHub($actor) : null;
        $pages = $role === 'seller' ? ['dashboard', 'products', 'orders'] : ['dashboard', 'network', 'deliveries'];

        foreach ($pages as $page) {
            $response = $this->actingAs($actor)->get($prefix.'/'.$page)->assertRedirect();
            $this->assertStringEndsWith($status === 'suspended' ? '/login' : '/pending-approval', $response->headers->get('Location'));
        }

        $this->actingAs($actor);
        $response = $product
            ? $this->patch($prefix.'/products/'.$product->id.'/stock', ['mode' => 'set', 'quantity' => 99])
            : $this->post($prefix.'/switch-hub', ['hub_id' => $hub->id]);
        $response->assertRedirect();
        $this->assertStringEndsWith($status === 'suspended' ? '/login' : '/pending-approval', $response->headers->get('Location'));
        $this->assertSame($status, $actor->fresh()->status);
        $this->assertSame($kyc, $actor->fresh()->kyc_status);
        if ($product) {
            $this->assertSame(7, $product->fresh()->stock);
        } else {
            $response->assertSessionMissing('active_hub_id');
        }
        if ($status === 'suspended') {
            $this->assertGuest();
            $response->assertSessionHasErrors('email');
        }

        if ($role === 'logistics' && $prefix === 'http://localhost/hub') {
            $response = $this->actingAs($actor)->get($prefix)->assertRedirect();
            $this->assertStringEndsWith($status === 'suspended' ? '/login' : '/pending-approval', $response->headers->get('Location'));
        }

        $response = $this->actingAs($actor)->get('http://localhost/dashboard')->assertRedirect();
        $this->assertStringEndsWith($status === 'suspended' ? '/login' : '/pending-approval', $response->headers->get('Location'));
    }

    #[DataProvider('restrictedWorkerAccounts')]
    public function test_password_login_cannot_send_a_restricted_worker_into_a_portal(string $role, string $prefix, string $status, string $kyc): void
    {
        $actor = User::factory()->create(['role' => $role, 'status' => $status, 'kyc_status' => $kyc]);
        $loginBase = str_starts_with($prefix, 'http://localhost/') ? 'http://localhost' : $prefix;
        $response = $this->post($loginBase.'/login', ['email' => $actor->email, 'password' => 'password'])->assertRedirect();
        $this->assertStringEndsWith($status === 'suspended' ? '/login' : '/pending-approval', $response->headers->get('Location'));
        if ($status === 'suspended') {
            $this->assertGuest();
        } else {
            $this->assertAuthenticatedAs($actor);
        }
    }

    public static function restrictedAdmins(): array
    {
        $cases = [];
        foreach (self::portalPrefixes('admin') as $prefix) {
            foreach (['inactive', 'suspended', 'pending_approval', 'unknown'] as $status) {
                $cases[$prefix.' '.$status] = [$prefix, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('restrictedAdmins')]
    public function test_non_active_admins_cannot_read_or_change_privileged_records(string $prefix, string $status): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => $status, 'kyc_status' => 'none']);
        $applicant = User::factory()->create(['role' => 'seller', 'status' => 'pending_approval', 'kyc_status' => 'pending_approval']);
        $product = $this->sellerProduct($applicant);
        $before = $applicant->fresh()->getAttributes();

        $loginBase = str_starts_with($prefix, 'http://localhost/') ? 'http://localhost' : $prefix;
        $this->post($loginBase.'/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect();
        $this->assertGuest();

        foreach (['dashboard', 'users', 'products', 'kyc', 'logistics'] as $page) {
            $response = $this->actingAs($admin)->get($prefix.'/'.$page)->assertRedirect();
            $this->assertStringEndsWith('/login', $response->headers->get('Location'));
            $this->assertGuest();
        }

        foreach ([
            ['PATCH', '/users/'.$applicant->id.'/role', ['role' => 'courier', 'status' => 'active']],
            ['PATCH', '/products/'.$product->id.'/toggle', []],
            ['POST', '/kyc/'.$applicant->id.'/approve', []],
            ['POST', '/kyc/'.$applicant->id.'/reject', ['reason' => 'Document is unreadable.']],
        ] as [$method, $path, $payload]) {
            $response = $this->actingAs($admin)->call($method, $prefix.$path, $payload)->assertRedirect();
            $this->assertStringEndsWith('/login', $response->headers->get('Location'));
            $this->assertGuest();
        }

        $this->assertSame($before, $applicant->fresh()->getAttributes());
        $this->assertSame('active', $product->fresh()->status);
        $this->assertSame(7, $product->fresh()->stock);
        $response = $this->actingAs($admin)->get('http://localhost/dashboard')->assertRedirect();
        $this->assertStringEndsWith('/login', $response->headers->get('Location'));
        $this->assertGuest();
    }

    public static function approvedWorkers(): array
    {
        $cases = [];
        foreach (['seller', 'logistics'] as $role) {
            foreach (self::portalPrefixes($role) as $prefix) {
                foreach (['approved', 'verified'] as $kyc) {
                    $cases[$role.' '.$prefix.' '.$kyc] = [$role, $prefix, $kyc];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('approvedWorkers')]
    public function test_active_reviewed_workers_keep_legitimate_read_and_write_access(string $role, string $prefix, string $kyc): void
    {
        $actor = User::factory()->create(['role' => $role, 'status' => 'active', 'kyc_status' => $kyc]);
        if ($role === 'seller') {
            $product = $this->sellerProduct($actor);
            $this->actingAs($actor)->get($prefix.'/dashboard')->assertOk();
            $this->patch($prefix.'/products/'.$product->id.'/stock', ['mode' => 'set', 'quantity' => 9])->assertSessionHas('success');
            $this->assertSame(9, $product->fresh()->stock);
        } else {
            $hub = $this->companyHub($actor);
            $this->actingAs($actor)->get($prefix.'/dashboard')->assertOk();
            $this->post($prefix.'/switch-hub', ['hub_id' => $hub->id])->assertSessionHas('success')->assertSessionHas('active_hub_id', $hub->id);
            if ($prefix === 'http://localhost/hub') {
                $this->get($prefix)->assertOk();
            }
        }
    }

    public static function foreignRoles(): array
    {
        $cases = [];
        foreach (['seller', 'logistics', 'admin'] as $portalRole) {
            foreach (self::portalPrefixes($portalRole) as $prefix) {
                foreach (['buyer', 'seller', 'courier', 'logistics', 'admin', 'unknown'] as $actorRole) {
                    if ($actorRole === $portalRole || ($portalRole === 'logistics' && $actorRole === 'admin')) {
                        continue;
                    }
                    $cases[$portalRole.' '.$prefix.' '.$actorRole] = [$portalRole, $prefix, $actorRole];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('foreignRoles')]
    public function test_other_roles_cannot_impersonate_workers_or_admins(string $portalRole, string $prefix, string $actorRole): void
    {
        $actor = User::factory()->create(['role' => $actorRole, 'status' => 'active', 'kyc_status' => 'approved']);
        $seller = User::factory()->create(['role' => 'seller']);
        $product = $this->sellerProduct($seller);
        $this->actingAs($actor)->get($prefix.'/dashboard')->assertForbidden();
        if ($portalRole === 'seller') {
            $this->patch($prefix.'/products/'.$product->id.'/stock', ['mode' => 'set', 'quantity' => 99])->assertForbidden();
        } elseif ($portalRole === 'admin') {
            $this->patch($prefix.'/users/'.$seller->id.'/role', ['role' => 'courier', 'status' => 'active'])->assertForbidden();
        } else {
            $hub = $this->companyHub(User::factory()->create(['role' => 'logistics']));
            $this->post($prefix.'/switch-hub', ['hub_id' => $hub->id])->assertForbidden();
        }
        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame('seller', $seller->fresh()->role);
    }

    public function test_active_admin_kyc_exemption_keeps_oversight_without_worker_actions(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'kyc_status' => 'none']);
        $applicant = User::factory()->create(['role' => 'seller', 'status' => 'pending_approval', 'kyc_status' => 'pending_approval']);
        foreach (self::portalPrefixes('admin') as $prefix) {
            $this->actingAs($admin)->get($prefix.'/dashboard')->assertOk();
            $this->get($prefix.'/kyc')->assertOk();
            $this->patch($prefix.'/users/'.$applicant->id.'/role', ['role' => 'seller', 'status' => 'active'])->assertSessionHas('success');
        }
        foreach (self::portalPrefixes('logistics') as $prefix) {
            $this->get($prefix.'/dashboard')->assertOk();
            $this->get($prefix.'/scan')->assertForbidden();
            $this->postJson($prefix.'/scan', ['barcode' => 'BGO-TEST'])->assertForbidden();
        }
        foreach (self::portalPrefixes('seller') as $prefix) {
            $this->get($prefix.'/dashboard')->assertForbidden();
        }
    }

    #[DataProvider('restrictedAdmins')]
    public function test_existing_admin_session_rechecks_the_current_database_restriction(string $prefix, string $status): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'kyc_status' => 'none']);
        $target = User::factory()->create(['role' => 'seller']);
        $this->post('http://localhost/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect();
        $this->get($prefix.'/dashboard')->assertOk();

        User::whereKey($admin->id)->update(['status' => $status]);
        Auth::forgetGuards();

        $response = $this->patch($prefix.'/users/'.$target->id.'/role', ['role' => 'courier', 'status' => 'active'])->assertRedirect();
        $this->assertStringEndsWith('/login', $response->headers->get('Location'));
        $this->assertGuest();
        $this->assertSame('seller', $target->fresh()->role);
    }

    public function test_review_holding_and_guest_hub_login_remain_available(): void
    {
        $this->get('http://localhost/hub')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/HubLogin'));
        foreach (['seller', 'courier', 'logistics'] as $role) {
            $applicant = User::factory()->create(['role' => $role, 'status' => 'pending_approval', 'kyc_status' => 'rejected']);
            $prefix = self::portalPrefixes($role)[1];
            $this->actingAs($applicant)->get($prefix.'/pending-approval')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Auth/PendingApproval')->where('user.id', $applicant->id));
        }
    }

    public function test_reviewed_legacy_account_leaves_holding_without_a_redirect_cycle(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active', 'kyc_status' => 'verified']);
        $this->actingAs($seller)->get('http://localhost/pending-approval')->assertRedirect(route('dashboard'));
    }

    private static function portalPrefixes(string $role): array
    {
        $portal = $role === 'logistics' ? 'hub' : $role;

        return ['http://localhost/'.$portal, 'http://'.$portal.'.localhost'];
    }

    private function sellerProduct(User $seller): Product
    {
        $shop = Shop::factory()->create(['user_id' => $seller->id, 'status' => 'active', 'is_default' => true]);

        return Product::factory()->create(['shop_id' => $shop->id, 'stock' => 7, 'status' => 'active']);
    }

    private function companyHub(User $actor): LogisticsHub
    {
        $company = LogisticsCompany::create(['user_id' => $actor->id, 'name' => 'Bagoo Access Test', 'slug' => 'bagoo-access-test-'.$actor->id, 'code' => 'BAT'.$actor->id, 'status' => 'active', 'is_active' => true]);

        return LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Access Test Bayan Hub', 'code' => 'BH-TEST-'.$actor->id, 'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'address' => 'Access Test Road', 'is_active' => true]);
    }
}
