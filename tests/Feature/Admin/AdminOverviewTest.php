<?php

namespace Tests\Feature\Admin;

use App\Models\CommissionLedger;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\IdentityCorrectionRequest;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\AccountRestrictionService;
use App\Services\AdminOverviewService;
use App\Services\ProductModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['birthday' => '1990-01-01']);
    }

    private function network(string $code): array
    {
        $owner = User::factory()->logistics()->create();
        $company = LogisticsCompany::create(['user_id' => $owner->id, 'name' => 'Bagoo '.$code.' Network', 'slug' => strtolower($code),
            'code' => $code, 'status' => 'active', 'is_active' => true]);
        $hub = LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Bagoo '.$code.' Hub', 'code' => $code.'-HUB',
            'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'address' => '123 Test Street', 'is_active' => true]);

        return [$owner, $company, $hub];
    }

    public function test_empty_records_have_real_zero_counts_and_unavailable_finance(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Dashboard')->where('stats.totalUsers', 1)->where('stats.usersByRole.admin', 1)
            ->where('stats.usersByRole.logistics', 0)->where('stats.paidOrderGross', '0.00')->where('stats.paidOrderCount', 0)
            ->where('stats.totalOrders', 0)->where('stats.openParcels', 0)->where('orderStates', [])->where('recentOrders', [])
            ->where('workReferences', [])->has('queues', 5)->where('queues.0.count', 0)->where('finance.0.amount', '0.00')
            ->missing('stats.totalRevenue')->missing('stats.platformCommission'));
        $this->get('/admin/logistics')->assertInertia(fn (Assert $page) => $page->component('Admin/Logistics')
            ->where('deliveries.data', [])->where('couriers', [])->where('stats.total', 0)->where('stats.onDutyEligibleRiders', 0)
            ->where('finance.3.amount', null)->where('finance.4.amount', null)->missing('stats.totalShippingRevenue')->missing('stats.courierPayouts')->missing('stats.hubFee'));
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_all_five_registered_roles_and_portal_specific_links_are_distinct(): void
    {
        $admin = $this->admin();
        foreach (['buyer', 'seller', 'courier', 'logistics'] as $role) {
            User::factory()->create(['role' => $role]);
        }
        foreach (['http://localhost/admin/dashboard', 'http://admin.localhost/dashboard'] as $url) {
            $base = str_contains($url, '/admin/dashboard') ? '/admin' : '';
            $this->actingAs($admin)->get($url)->assertInertia(fn (Assert $page) => $page->where('stats.totalUsers', 5)
                ->where('stats.usersByRole.admin', 1)->where('stats.usersByRole.buyer', 1)->where('stats.usersByRole.seller', 1)
                ->where('stats.usersByRole.courier', 1)->where('stats.usersByRole.logistics', 1)->where('stats.unknownRoles', 0)
                ->where('queues.0.url', $base.'/kyc')->where('queues.1.url', $base.'/shops'));
        }
    }

    public function test_review_queues_use_existing_pending_and_restricted_records_only(): void
    {
        $admin = $this->admin();
        foreach (['buyer', 'seller', 'courier', 'logistics'] as $role) {
            User::factory()->create(['role' => $role, 'status' => 'pending_approval', 'kyc_status' => 'pending_approval']);
        }
        User::factory()->buyer()->create(['status' => 'suspended', 'kyc_status' => 'approved']);
        $shop = Shop::factory()->approved()->create();
        Shop::factory()->create(['review_status' => 'pending_approval', 'status' => 'pending']);
        Shop::factory()->create(['review_status' => 'rejected']);
        $product = Product::factory()->create(['shop_id' => $shop->id, 'category_id' => $shop->root_category_id]);
        $moderation = app(ProductModerationService::class);
        $moderation->decide($admin, $product, ['action' => 'remove', 'reason' => 'Review the description before allowing new purchases.',
            'source_token' => app(AccountRestrictionService::class)->token($moderation->state($product))]);
        $buyer = User::factory()->buyer()->create();
        // Explicit pending-history fixture; this test reads queues and does not create a synthetic production review.
        IdentityCorrectionRequest::create(['user_id' => $buyer->id, 'requester_id' => $buyer->id, 'version' => 1,
            'source_token' => hash('sha256', 'overview-source'), 'request_token' => hash('sha256', 'overview-request'),
            'reason' => 'Review the corrected identity.', 'source' => [], 'proposed' => ['name' => 'Maria Santos'], 'evidence' => [], 'provenance' => [], 'requested_at' => now()]);
        $this->actingAs($admin)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('queues.0.count', 4)->where('queues.1.count', 1)->where('queues.2.count', 1)->where('queues.3.count', 1));
        $this->assertDatabaseCount('kyc_decisions', 0);
    }

    public function test_paid_order_gross_is_exact_decimal_and_keeps_payment_and_commission_separate(): void
    {
        $admin = $this->admin();
        foreach ([['100.05', 'paid', 'completed'], ['0.10', 'paid', 'completed'], ['0.20', 'paid', 'cancelled'],
            ['99.99', 'pending', 'delivered'], ['12.34', 'refunded', 'returned']] as [$amount, $payment, $status]) {
            Order::factory()->create(['total_amount' => $amount, 'payment_status' => $payment, 'status' => $status]);
        }
        CommissionLedger::factory()->create(['order_id' => Order::first()->id, 'gross_amount' => '100.00', 'seller_amount' => '90.00',
            'platform_commission' => '10.00', 'delivery_fee' => '7.25', 'status' => 'settled']);
        $this->actingAs($admin)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('stats.paidOrderGross', '100.35')->where('stats.paidOrderCount', 3)->where('stats.deliveredAwaitingBuyer', 1)
            ->where('finance.0.amount', null)->where('finance.1.amount', null)->where('finance.2.amount', null));
        $this->get('/admin/logistics')->assertInertia(fn (Assert $page) => $page->where('finance.3.amount', null)->where('finance.4.amount', null));
    }

    public function test_open_parcels_and_exact_states_include_hub_and_recovery_phases_without_alias_expansion(): void
    {
        $admin = $this->admin();
        $open = ['unassigned', 'assigned_pickup', 'picked_up', 'arrived_at_origin_hub', 'arrived_at_mother_hub',
            'ready_for_hub_pickup', 'assigned_to_rider', 'out_for_delivery', 'delivery_failed', 'return_to_sender'];
        foreach ([...$open, 'delivered', 'customer_collected', 'completed', 'returned', 'cancelled', 'unknown_legacy_state'] as $status) {
            Delivery::factory()->create(['status' => $status]);
        }
        Order::factory()->create(['status' => 'placed']);
        Order::factory()->create(['status' => 'picked_up']);
        Order::factory()->create(['status' => 'shipped']);
        $this->actingAs($admin)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page->where('stats.openParcels', 10));
        $states = collect(app(AdminOverviewService::class)->overview($admin, '/admin')['orderStates'])->keyBy('status');
        $this->assertSame(1, $states['picked_up']['count']);
        $this->assertSame(1, $states['shipped']['count']);
        $this->get('/admin/logistics')->assertInertia(fn (Assert $page) => $page->where('stats.total', 16)->where('stats.open', 10)
            ->where('stats.awaitingPickupAssignment', 1)->where('stats.handoverStatus', 3)->where('stats.exceptions', 2));
        Delivery::factory()->create(['status' => 'assigned']);
        $this->get('/admin/logistics?status=assigned')->assertInertia(fn (Assert $page) => $page->has('deliveries.data', 1)->where('deliveries.data.0.status', 'assigned'));
        $this->get('/admin/logistics?status=unknown_legacy_state')->assertInertia(fn (Assert $page) => $page->has('deliveries.data', 1)->where('deliveries.data.0.status', 'unknown_legacy_state'));
    }

    public function test_roster_separates_stored_duty_network_eligibility_and_unavailable_presence(): void
    {
        $admin = $this->admin();
        [, $company, $hub] = $this->network('DUTY');
        $riders = [];
        foreach ([[true, 'active'], [false, 'active'], [true, 'inactive']] as [$duty, $activity]) {
            $user = User::factory()->courier()->create(['status' => $activity, 'birthday' => '1990-01-01']);
            CourierProfile::factory()->create(['user_id' => $user->id, 'logistics_company_id' => $company->id, 'assigned_hub_id' => $hub->id, 'vehicle_id' => null, 'is_available' => $duty]);
            $riders[] = $user;
        }
        $missing = User::factory()->courier()->create();
        Delivery::factory()->create(['courier_id' => $riders[0]->id, 'assigned_rider_id' => $riders[0]->id, 'status' => 'out_for_delivery']);
        Delivery::factory()->create(['courier_id' => $riders[0]->id, 'status' => 'delivered']);
        $result = app(AdminOverviewService::class)->logistics($admin, []);
        $roster = collect($result['couriers'])->keyBy('id');
        $this->assertTrue($roster[$riders[0]->id]['on_duty']);
        $this->assertFalse($roster[$riders[1]->id]['on_duty']);
        $this->assertTrue($roster[$riders[2]->id]['on_duty']);
        $this->assertFalse($roster[$riders[2]->id]['network_eligible']);
        $this->assertNull($roster[$missing->id]['on_duty']);
        $this->assertNull($roster[$riders[0]->id]['live_presence']);
        $this->assertSame(1, $roster[$riders[0]->id]['linked_open_parcels']);
        $this->assertSame(1, $result['stats']['onDutyEligibleRiders']);
        $company->update(['is_active' => false]);
        $this->assertSame(0, app(AdminOverviewService::class)->logistics($admin, [])['stats']['onDutyEligibleRiders']);
        $this->assertTrue($riders[0]->courierProfile->fresh()->is_available);
    }

    public function test_restriction_work_links_are_distinct_current_open_orders_and_keep_real_record_urls(): void
    {
        $admin = $this->admin();
        $buyer = User::factory()->buyer()->create();
        $open = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'placed']);
        $completed = Order::factory()->create(['buyer_id' => $buyer->id, 'status' => 'placed']);
        $restrictions = app(AccountRestrictionService::class);
        $decision = $restrictions->decide($admin, $buyer, ['action' => 'suspend', 'reason' => 'Review current responsibilities before accepting new work.',
            'affected_work_confirmed' => true, 'source_token' => $restrictions->token($restrictions->state($buyer->fresh()))]);
        // Explicit final-state fixture: this overview must exclude historical references to completed work.
        DB::table('orders')->where('id', $completed->id)->update(['status' => 'completed']);
        $this->actingAs($admin)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page->where('queues.4.count', 1)
            ->has('workReferences', 1)->where('workReferences.0.order_id', $open->id)->where('workReferences.0.order_url', '/my-orders/'.$open->id)
            ->where('workReferences.0.decision_url', '/governance-history/restriction/'.$decision->id));
        $this->get('/my-orders/'.$open->id)->assertOk();
        $this->get('/governance-history/restriction/'.$decision->id)->assertOk();
    }

    public function test_company_dashboard_stays_in_its_own_network_and_cannot_open_platform_counts(): void
    {
        [$owner, $company, $hub] = $this->network('LOCAL');
        [, $foreignCompany, $foreignHub] = $this->network('FOREIGN');
        Delivery::factory()->create(['logistics_company_id' => $company->id, 'current_hub_id' => $hub->id, 'status' => 'sorted_to_barangay_bin']);
        Delivery::factory()->create(['logistics_company_id' => $foreignCompany->id, 'current_hub_id' => $foreignHub->id, 'status' => 'sorted_to_barangay_bin']);
        $this->actingAs($owner)->get('/hub/dashboard')->assertInertia(fn (Assert $page) => $page->component('Hub/Dashboard')
            ->where('stats.parcels_in_custody', 1)->has('facilities', 1)->where('scope.company_name', $company->name));
        $this->get('/hub/dashboard?hub_id='.$foreignHub->id)->assertForbidden();
        $this->get('/admin/dashboard')->assertForbidden();
        $this->get('/admin/logistics')->assertForbidden();
        $company->update(['is_active' => false]);
        $this->actingAs($owner)->get('/hub/dashboard')->assertForbidden();
    }

    public static function restrictedAdmins(): array
    {
        return [['inactive'], ['suspended'], ['pending_approval'], ['unknown']];
    }

    #[DataProvider('restrictedAdmins')]
    public function test_stale_restricted_admin_cannot_read_overview_on_either_portal(string $status): void
    {
        $admin = $this->admin();
        $stale = $admin->fresh();
        $admin->update(['status' => $status]);
        foreach (['http://localhost/admin/dashboard', 'http://localhost/admin/logistics', 'http://admin.localhost/dashboard', 'http://admin.localhost/logistics'] as $url) {
            $this->actingAs($stale)->getJson($url)->assertForbidden();
        }
    }

    public static function invalidFilters(): array
    {
        return [['status', 'invented'], ['status', ['assigned']], ['search', ['parcel']], ['search', str_repeat('x', 101)], ['page', 0], ['page', 1000001]];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_registry_filters_reject_before_returning_records(string $field, mixed $value): void
    {
        $this->actingAs($this->admin())->getJson('/admin/logistics?'.http_build_query([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_registry_search_and_pagination_are_exact_and_stable(): void
    {
        $admin = $this->admin();
        Delivery::factory()->count(18)->create(['pickup_store_name' => 'Bagoo Parcel Shop']);
        $this->actingAs($admin)->get('/admin/logistics?search=bagoo+parcel')->assertInertia(fn (Assert $page) => $page->where('deliveries.total', 18)->has('deliveries.data', 15)->where('deliveries.data.0.id', 18));
        $this->get('/admin/logistics?search=bagoo+parcel&page=2')->assertInertia(fn (Assert $page) => $page->where('deliveries.total', 18)->has('deliveries.data', 3)->where('deliveries.data.0.id', 3));
        $this->get('/admin/logistics?search='.urlencode("' OR 1=1 --"))->assertInertia(fn (Assert $page) => $page->where('deliveries.total', 0)->where('deliveries.data', []));
    }

    public function test_overview_preserves_orders_payments_duty_and_ledger_and_has_no_mutating_success_action(): void
    {
        $admin = $this->admin();
        $order = Order::factory()->create(['status' => 'delivered', 'payment_status' => 'paid']);
        Delivery::factory()->create(['order_id' => $order->id, 'status' => 'delivered']);
        CommissionLedger::factory()->create(['order_id' => $order->id]);
        $before = [];
        foreach (['orders', 'deliveries', 'commission_ledgers', 'courier_profiles', 'users'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }
        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
        $this->get('/admin/logistics')->assertOk();
        $this->post('/admin/logistics', ['status' => 'completed', 'payout' => true])->assertStatus(405);
        $this->patch('/admin/dashboard', ['status' => 'completed', 'payout' => true])->assertStatus(405);
        foreach ($before as $table => $rows) {
            $this->assertEquals($rows, DB::table($table)->orderBy('id')->get()->toArray(), $table.' changed during read-only oversight.');
        }
    }
}
