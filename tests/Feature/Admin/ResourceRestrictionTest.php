<?php

namespace Tests\Feature\Admin;

use App\Models\CommissionLedger;
use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RestrictionAffectedWork;
use App\Models\RestrictionDecision;
use App\Models\Shop;
use App\Models\User;
use App\Services\AccountRestrictionService;
use App\Services\Courier\CourierOperationsService;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsRoutingEngine;
use App\Services\Logistics\OrderStateMachineService;
use App\Services\Orders\OrderLifecycleService;
use App\Services\ResourceRestrictionService;
use App\Services\ShopEligibilityService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ResourceRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $handlerUser;

    private User $rider;

    private User $seller;

    private Shop $shop;

    private LogisticsCompany $company;

    private LogisticsHub $hub;

    private HubHandler $handler;

    private LogisticsFleet $fleet;

    private CourierProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->admin()->create();
        $this->owner = User::factory()->logistics()->create();
        $this->handlerUser = User::factory()->logistics()->create();
        $this->rider = User::factory()->courier()->create();
        $this->seller = User::factory()->seller()->create();
        $this->shop = Shop::factory()->approved()->create(['user_id' => $this->seller->id, 'city' => 'Santa Cruz']);
        $this->company = LogisticsCompany::create(['user_id' => $this->owner->id, 'name' => 'Bagoo Test Network', 'slug' => 'b07-network', 'code' => 'B07-NET', 'status' => 'active', 'is_active' => true]);
        $this->hub = LogisticsHub::create(['logistics_company_id' => $this->company->id, 'name' => 'Bagoo Test Hub', 'code' => 'B07-HUB', 'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'address' => 'Test facility', 'is_active' => true]);
        $this->handler = HubHandler::create(['user_id' => $this->handlerUser->id, 'hub_id' => $this->hub->id, 'is_active' => true]);
        $this->fleet = LogisticsFleet::create(['logistics_company_id' => $this->company->id, 'hub_id' => $this->hub->id, 'plate_number' => 'B07-TEST', 'vehicle_type' => 'motorcycle', 'status' => 'active', 'assigned_driver_id' => $this->rider->id]);
        $this->profile = CourierProfile::factory()->create(['user_id' => $this->rider->id, 'logistics_company_id' => $this->company->id, 'assigned_hub_id' => $this->hub->id, 'vehicle_id' => $this->fleet->id, 'is_available' => true]);
    }

    private function resource(string $type): Model
    {
        return $this->{$type === 'handler' ? 'handler' : $type}->fresh();
    }

    private function input(string $type, string $action = 'suspend', ?User $actor = null): array
    {
        return ['action' => $action, 'reason' => 'Review the current resource responsibilities.', 'affected_work_confirmed' => true,
            'source_token' => app(ResourceRestrictionService::class)->presentation($actor ?? $this->admin, $type, $this->resource($type)->id)['source_token']];
    }

    private function decide(string $type, string $action = 'suspend', ?User $actor = null): RestrictionDecision
    {
        return app(ResourceRestrictionService::class)->decide($actor ?? $this->admin, $type, $this->resource($type)->id, $this->input($type, $action, $actor));
    }

    private function work(): array
    {
        $order = Order::factory()->create(['status' => 'picked_up', 'payment_status' => 'paid']);
        $product = Product::factory()->create(['shop_id' => $this->shop->id, 'category_id' => $this->shop->root_category_id, 'status' => 'active']);
        OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $this->shop->id, 'product_id' => $product->id]);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'status' => 'picked_up', 'courier_id' => $this->rider->id, 'logistics_company_id' => $this->company->id,
            'origin_bayan_hub_id' => $this->hub->id, 'current_hub_id' => $this->hub->id, 'shuttle_manifest_number' => 'B07-FEEDER', 'truck_manifest_number' => 'B07-LINEHAUL', 'proof_image' => 'private-proof.jpg']);
        $checkpoint = DeliveryCheckpoint::record($parcel, 'arrived_at_origin_hub', 'Test facility', actor: $this->handlerUser, hub: $this->hub, manifestNumber: 'B07-FEEDER');
        $ledger = CommissionLedger::factory()->create(['order_id' => $order->id, 'seller_id' => $this->seller->id, 'status' => 'settled']);

        return [$order, $parcel, $checkpoint, $ledger, $product];
    }

    public static function resourcePortals(): array
    {
        $cases = [];
        foreach (['http://localhost/admin', 'http://admin.localhost'] as $host) {
            foreach (array_keys(ResourceRestrictionService::TYPES) as $type) {
                foreach (['suspend', 'deactivate'] as $action) {
                    $cases[$host.' '.$type.' '.$action] = [$host, $type, $action];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('resourcePortals')]
    public function test_every_resource_has_a_reasoned_independent_decision_on_both_admin_portals(string $host, string $type, string $action): void
    {
        $resource = $this->resource($type);
        $this->actingAs($this->admin)->postJson($host.'/resources/'.$type.'/'.$resource->id, $this->input($type, $action))->assertOk();
        $this->assertSame($action === 'suspend' ? 'suspended' : 'inactive', app(ResourceRestrictionService::class)->status($type, $resource->fresh()));
        $this->assertFalse(app(ResourceRestrictionService::class)->summary($type, $resource->fresh())['eligible']);
        $this->assertSame(1, $resource->fresh()->restriction_version);
        $decision = RestrictionDecision::sole();
        $this->assertSame($type, $decision->subject_type);
        $this->assertSame($this->admin->id, $decision->actor_id);
        $this->assertSame('active', $decision->before_state['subject']['status']);
        $this->assertSame($action === 'suspend' ? 'suspended' : 'inactive', $decision->after_state['subject']['status']);
        $this->assertSame('active', $this->owner->fresh()->status);
        $this->assertSame('approved', $this->seller->fresh()->kyc_status);
        $this->assertTrue($this->profile->fresh()->is_available);
    }

    public static function resourceTypes(): array
    {
        return array_map(fn ($type) => [$type], array_keys(ResourceRestrictionService::TYPES));
    }

    #[DataProvider('resourceTypes')]
    public function test_each_resource_preserves_ongoing_work_manifest_cash_stock_and_records_responsibility(string $type): void
    {
        $records = $this->work();
        $before = array_map(fn ($record) => $record->fresh()->getAttributes(), $records);
        $foreign = Order::factory()->create();
        $decision = $this->decide($type);
        $responsibility = $decision->affectedWork()->sole();
        $this->assertSame($records[0]->id, $responsibility->order_id);
        $this->assertSame($records[1]->id, $responsibility->delivery_id);
        $this->assertSame($this->admin->id, $responsibility->responsible_user_id);
        $this->assertSame('B07-LINEHAUL', $responsibility->snapshot['parcel']['truck_manifest_number']);
        $this->assertSame($this->handlerUser->id, $responsibility->snapshot['last_checkpoint']['scanned_by_id']);
        $this->assertSame('settled', $responsibility->snapshot['cash']['ledger_state']);
        $this->assertSame('unverified', $responsibility->snapshot['cash']['evidence']);
        $this->assertNull($responsibility->snapshot['cash']['holder']);
        $this->assertFalse($decision->affectedWork()->where('order_id', $foreign->id)->exists());
        foreach ($records as $index => $record) {
            $this->assertSame($before[$index], $record->fresh()->getAttributes());
        }
    }

    #[DataProvider('resourceTypes')]
    public function test_valid_reactivation_is_separate_and_an_old_retry_never_reapplies_the_restriction(string $type): void
    {
        $input = $this->input($type);
        $first = app(ResourceRestrictionService::class)->decide($this->admin, $type, $this->resource($type)->id, $input);
        $this->decide($type, 'reactivate');
        $retried = app(ResourceRestrictionService::class)->decide($this->admin, $type, $this->resource($type)->id, $input);
        $this->assertSame($first->id, $retried->id);
        $this->assertSame('active', app(ResourceRestrictionService::class)->status($type, $this->resource($type)));
        $this->assertSame(2, $this->resource($type)->restriction_version);
        $this->assertDatabaseCount('restriction_decisions', 2);
    }

    public static function companyPortals(): array
    {
        $cases = [];
        foreach (['http://localhost/hub', 'http://hub.localhost'] as $host) {
            foreach (['hub', 'handler', 'fleet'] as $type) {
                $cases[] = [$host, $type];
            }
        }

        return $cases;
    }

    #[DataProvider('companyPortals')]
    public function test_company_admin_can_decide_only_its_own_documented_resources(string $host, string $type): void
    {
        $this->actingAs($this->owner)->postJson($host.'/resources/'.$type.'/'.$this->resource($type)->id, $this->input($type, actor: $this->owner))->assertOk();
        $this->assertSame($this->owner->id, RestrictionDecision::sole()->actor_id);
        foreach (['shop', 'company'] as $forbidden) {
            $this->postJson($host.'/resources/'.$forbidden.'/'.$this->resource($forbidden)->id, $this->input($forbidden))->assertForbidden();
        }
        $foreign = $this->foreignHub();
        $this->getJson($host.'/resources/hub/'.$foreign->id)->assertNotFound();
        $this->postJson($host.'/resources/hub/'.$foreign->id, $this->input($type))->assertNotFound();
        $this->assertTrue($foreign->fresh()->is_active);
        $this->assertSame('active', $this->company->fresh()->status);
        $this->assertSame('approved', $this->owner->fresh()->kyc_status);
        $this->assertDatabaseCount('restriction_decisions', 1);
    }

    private function foreignHub(): LogisticsHub
    {
        $company = LogisticsCompany::create(['user_id' => User::factory()->logistics()->create()->id, 'name' => 'Foreign network', 'slug' => 'foreign-network', 'code' => 'B07-FOREIGN', 'status' => 'active', 'is_active' => true]);

        return LogisticsHub::create(['logistics_company_id' => $company->id, 'name' => 'Foreign facility', 'code' => 'B07-FOREIGN-HUB', 'tier' => 'local_bayan_hub', 'province' => 'Laguna', 'city_municipality' => 'Santa Cruz', 'address' => 'Other facility', 'is_active' => true]);
    }

    public function test_handler_and_unplaced_accounts_cannot_manage_resources_or_read_governance_history(): void
    {
        foreach ([$this->handlerUser, $this->rider, $this->seller, User::factory()->logistics()->create()] as $actor) {
            foreach (['http://localhost/hub', 'http://hub.localhost'] as $host) {
                $this->actingAs($actor)->getJson($host.'/resources')->assertForbidden();
                $this->getJson($host.'/resources/hub/'.$this->hub->id)->assertForbidden();
                $this->postJson($host.'/resources/hub/'.$this->hub->id, $this->input('hub'))->assertForbidden();
            }
        }
        $this->assertDatabaseCount('restriction_decisions', 0);
        $this->expectException(HttpException::class);
        app(ResourceRestrictionService::class)->decide($this->handlerUser, 'hub', $this->hub->id, $this->input('hub'));
    }

    public function test_company_review_hides_seller_finance_and_foreign_linkage_but_keeps_own_cash_context(): void
    {
        [$order] = $this->work();
        $foreign = $this->foreignHub();
        $foreignOrder = Order::factory()->create();
        Delivery::factory()->create(['order_id' => $foreignOrder->id, 'logistics_company_id' => $foreign->logistics_company_id, 'current_hub_id' => $this->hub->id, 'proof_image' => 'private-foreign-proof.jpg']);
        $service = app(ResourceRestrictionService::class);
        $review = $service->presentation($this->owner, 'hub', $this->hub->id);
        $this->assertSame([$order->id], array_column($review['affected_work'], 'order_id'));
        $this->assertArrayNotHasKey('ledger_id', $review['affected_work'][0]['cash']);
        $this->assertArrayNotHasKey('ledger_state', $review['state']['work'][0]['cash']);
        $this->assertSame('unverified', $review['affected_work'][0]['cash']['evidence']);
        $this->decide('hub', actor: $this->owner);
        $this->assertSame([$order->id], RestrictionAffectedWork::pluck('order_id')->all());
        $this->actingAs($this->owner)->get('/hub/resources/hub/'.$this->hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('subject.affected_work', 1)->missing('subject.affected_work.0.cash.ledger_state')->where('subject.history.0.actor', $this->owner->name));
        $this->assertStringNotContainsString('private-foreign-proof.jpg', $this->get('/hub/resources/hub/'.$this->hub->id)->getContent());
    }

    public function test_company_list_and_detail_do_not_disclose_foreign_parent_of_a_malformed_owned_assignment(): void
    {
        $foreign = $this->foreignHub();
        DB::table('hub_handlers')->where('id', $this->handler->id)->update(['hub_id' => $foreign->id]);
        $this->actingAs($this->owner)->get('/hub/resources?type=handler')->assertOk()->assertInertia(fn (Assert $page) => $page->has('resources.data', 0));
        $this->getJson('/hub/resources/handler/'.$this->handler->id)->assertNotFound();
        $this->postJson('/hub/resources/handler/'.$this->handler->id, $this->input('handler'))->assertNotFound();
        $this->assertDatabaseCount('restriction_decisions', 0);
    }

    public function test_parent_reactivation_preserves_all_child_restrictions_assignments_and_off_duty_state(): void
    {
        $this->profile->update(['is_available' => false]);
        $profile = $this->profile->fresh()->getAttributes();
        foreach (['handler', 'fleet', 'hub', 'company'] as $type) {
            $this->decide($type);
        }
        $this->decide('company', 'reactivate');
        foreach (['hub', 'handler', 'fleet'] as $type) {
            $this->assertSame('suspended', app(ResourceRestrictionService::class)->status($type, $this->resource($type)));
        }
        $this->decide('hub', 'reactivate');
        $this->assertFalse($this->handler->fresh()->is_active);
        $this->assertSame('suspended', $this->fleet->fresh()->status);
        $this->decide('handler', 'reactivate');
        $this->decide('fleet', 'reactivate');
        $this->assertSame($profile, $this->profile->fresh()->getAttributes());
        $this->assertSame('active', $this->company->fresh()->status);
    }

    public static function parentBlocks(): array
    {
        return [['shop', 'seller', 'status', 'suspended'], ['shop', 'seller', 'kyc_status', 'rejected'], ['shop', 'shop', 'review_status', 'rejected'],
            ['company', 'owner', 'status', 'inactive'], ['company', 'owner', 'kyc_status', 'rejected'],
            ['hub', 'company', 'status', 'suspended'], ['hub', 'hub', 'tier', 'unknown'],
            ['handler', 'hub', 'is_active', false], ['handler', 'handlerUser', 'kyc_status', 'pending_approval'],
            ['fleet', 'hub', 'is_active', false], ['fleet', 'rider', 'status', 'suspended'], ['fleet', 'fleet', 'vehicle_type', 'unknown']];
    }

    #[DataProvider('parentBlocks')]
    public function test_reactivation_cannot_bypass_invalid_review_or_parent_scope(string $type, string $parent, string $field, mixed $value): void
    {
        $this->decide($type);
        $this->{$parent}->update([$field => $value]);
        $input = $this->input($type, 'reactivate');
        $this->actingAs($this->admin)->postJson('/admin/resources/'.$type.'/'.$this->resource($type)->id, $input)->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->assertSame('suspended', app(ResourceRestrictionService::class)->status($type, $this->resource($type)));
        $this->assertDatabaseCount('restriction_decisions', 1);
    }

    public function test_parent_account_and_company_version_cycles_make_old_decisions_stale(): void
    {
        $service = app(ResourceRestrictionService::class);
        $input = $this->input('hub');
        $this->decide('company');
        $this->decide('company', 'reactivate');
        $this->actingAs($this->admin)->postJson('/admin/resources/hub/'.$this->hub->id, $input)->assertConflict();
        $input = $this->input('hub');
        $accounts = app(AccountRestrictionService::class);
        foreach (['suspend', 'reactivate'] as $action) {
            $review = $accounts->presentation($this->admin, $this->owner);
            $accounts->decide($this->admin, $this->owner, ['action' => $action, 'reason' => 'Review this network owner.', 'affected_work_confirmed' => true, 'source_token' => $review['source_token']]);
        }
        $this->postJson('/admin/resources/hub/'.$this->hub->id, $input)->assertConflict();
        $this->assertTrue($this->hub->fresh()->is_active);
        $this->assertDatabaseCount('restriction_decisions', 4);
        $this->assertSame('active', $service->presentation($this->admin, 'hub', $this->hub->id)['status']);
    }

    public static function invalidInputs(): array
    {
        return [['reason', null], ['reason', ''], ['reason', '    '], ['reason', 'a'], ['reason', str_repeat('a', 1001)], ['reason', '<script>change</script>'], ['reason', "Review\u{200B} this"], ['reason', ['bad']],
            ['source_token', 'bad'], ['source_token', null], ['action', 'active'], ['action', 'delete'], ['action', null], ['affected_work_confirmed', false],
            ['is_active', true], ['user_id', 999], ['logistics_company_id', 999], ['hub_id', 999], ['assigned_driver_id', 999], ['review_status', 'approved'], ['root_category_id', 999], ['status', 'active'], ['role', 'admin']];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_decision_fields_never_mutate_resources(string $field, mixed $value): void
    {
        $input = $this->input('hub');
        $input[$field] = $value;
        $this->actingAs($this->admin)->postJson('/admin/resources/hub/'.$this->hub->id, $input)->assertUnprocessable();
        $this->assertTrue($this->hub->fresh()->is_active);
        $this->assertSame(0, $this->hub->fresh()->restriction_version);
        $this->assertDatabaseCount('restriction_decisions', 0);
    }

    public static function unknownStates(): array
    {
        return [['shop', 'status', 'unknown'], ['company', 'status', 'unknown'], ['company', 'is_active', 2], ['hub', 'is_active', 2], ['handler', 'is_active', 2], ['fleet', 'status', 'unknown'], ['fleet', 'status', 'pending']];
    }

    #[DataProvider('unknownStates')]
    public function test_unknown_source_never_defaults_to_an_active_resource(string $type, string $field, mixed $value): void
    {
        DB::table($this->resource($type)->getTable())->where('id', $this->resource($type)->id)->update([$field => $value]);
        $input = $this->input($type, 'reactivate');
        $this->actingAs($this->admin)->postJson('/admin/resources/'.$type.'/'.$this->resource($type)->id, $input)->assertConflict()->assertJsonPath('permitted_actions', []);
        $this->assertDatabaseCount('restriction_decisions', 0);
        $this->assertFalse(app(ResourceRestrictionService::class)->summary($type, $this->resource($type))['eligible']);
    }

    public function test_reason_retry_identity_conflicts_and_new_work_are_checked_against_fresh_state(): void
    {
        $input = $this->input('hub');
        $this->actingAs($this->admin)->postJson('/admin/resources/hub/'.$this->hub->id, $input)->assertOk();
        $this->postJson('/admin/resources/hub/'.$this->hub->id, $input)->assertOk();
        foreach (['reason' => 'A different reason for this decision.', 'action' => 'deactivate'] as $field => $value) {
            $this->postJson('/admin/resources/hub/'.$this->hub->id, [...$input, $field => $value])->assertConflict();
        }
        $otherAdmin = User::factory()->admin()->create();
        $this->actingAs($otherAdmin)->postJson('/admin/resources/hub/'.$this->hub->id, $input)->assertConflict();
        $this->assertDatabaseCount('restriction_decisions', 1);
        $reactivate = $this->input('hub', 'reactivate');
        $this->work();
        $this->postJson('/admin/resources/hub/'.$this->hub->id, $reactivate)->assertConflict();
        $this->assertFalse($this->hub->fresh()->is_active);
    }

    public static function writeFailures(): array
    {
        return [[RestrictionDecision::class], [RestrictionAffectedWork::class]];
    }

    #[DataProvider('writeFailures')]
    public function test_audit_and_recovery_write_failure_roll_back_resource_and_history(string $model): void
    {
        $this->work();
        $input = $this->input('hub');
        Event::listen('eloquent.creating: '.$model, fn () => throw new RuntimeException('Simulated decision failure'));
        try {
            app(ResourceRestrictionService::class)->decide($this->admin, 'hub', $this->hub->id, $input);
            $this->fail('This write must fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated decision failure', $exception->getMessage());
        }
        $this->assertTrue($this->hub->fresh()->is_active);
        $this->assertSame(0, $this->hub->fresh()->restriction_version);
        $this->assertDatabaseCount('restriction_decisions', 0);
        $this->assertDatabaseCount('restriction_affected_work', 0);
    }

    public static function fleetModes(): array
    {
        return [['maintenance'], ['idle'], ['active']];
    }

    #[DataProvider('fleetModes')]
    public function test_fleet_reactivation_restores_prior_operational_mode_and_keeps_driver_duty(string $mode): void
    {
        $this->fleet->update(['status' => $mode]);
        $this->profile->update(['is_available' => false]);
        $this->decide('fleet');
        $this->decide('fleet', 'deactivate');
        $this->assertSame($mode, app(ResourceRestrictionService::class)->presentation($this->admin, 'fleet', $this->fleet->id)['reactivation_status']);
        $this->decide('fleet', 'reactivate');
        $this->assertSame($mode, $this->fleet->fresh()->status);
        $this->assertFalse($this->profile->fresh()->is_available);
        $this->assertSame($this->rider->id, $this->fleet->fresh()->assigned_driver_id);
        $this->assertSame($mode === 'active', LogisticsFleet::ready()->whereKey($this->fleet->id)->exists());
    }

    public function test_restrictions_agree_with_shop_routing_handler_and_linked_fleet_action_gates(): void
    {
        $this->decide('shop');
        $this->assertFalse(app(ShopEligibilityService::class)->isEligible($this->shop));
        $order = Order::factory()->create(['status' => 'placed']);
        OrderItem::factory()->create(['order_id' => $order->id, 'shop_id' => $this->shop->id]);
        try {
            app(OrderLifecycleService::class)->sellerTransition($order, $this->shop, $this->seller, 'confirmed');
            $this->fail('A cached shop cannot fulfill work while restricted.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame('placed', $order->fresh()->status);
        $this->decide('handler');
        $this->assertFalse(app(LogisticsEligibilityService::class)->canScan($this->handlerUser, $this->hub));
        $this->decide('fleet');
        $this->assertFalse(CourierProfile::operational()->whereKey($this->profile->id)->exists());
        $parcel = Delivery::factory()->create(['order_id' => Order::factory()->create(['status' => 'ready_for_pickup'])->id, 'status' => 'unassigned', 'courier_id' => null,
            'logistics_company_id' => $this->company->id, 'origin_bayan_hub_id' => $this->hub->id, 'proof_image' => 'private-proof.jpg']);
        try {
            app(CourierOperationsService::class)->claimPickup($this->rider, $parcel);
            $this->fail('A restricted linked fleet cannot receive new pickup work.');
        } catch (DomainException) {
        }
        $this->assertNull($parcel->fresh()->courier_id);
        $this->decide('hub');
        $this->assertNull(app(LogisticsRoutingEngine::class)->resolveOriginBayanHub($this->shop, $this->company));
        $this->decide('company');
        $this->assertFalse(LogisticsHub::eligible()->whereKey($this->hub->id)->exists());
        $this->assertFalse(LogisticsFleet::ready()->whereKey($this->fleet->id)->exists());
    }

    public function test_restricted_handler_cannot_dispatch_cached_parcel_and_restricted_hub_cannot_be_replaced_by_a_scan(): void
    {
        [$order, $parcel] = $this->work();
        $parcel->update(['status' => 'arrived_at_origin_hub']);
        $this->decide('handler');
        try {
            app(OrderStateMachineService::class)->transition($parcel, 'in_transit_to_mother_hub', $this->handlerUser, ['hub_id' => $this->hub->id, 'barcode' => $parcel->tracking_number]);
            $this->fail('A restricted handler cannot dispatch work.');
        } catch (DomainException) {
        }
        $this->assertSame('arrived_at_origin_hub', $parcel->fresh()->status);
        $this->assertSame('picked_up', $order->fresh()->status);
        $this->assertDatabaseCount('delivery_checkpoints', 1);
    }

    #[DataProvider('resourceTypes')]
    public function test_referenced_resources_cannot_be_deleted_and_version_is_not_exposed_or_mass_assignable(string $type): void
    {
        $decision = $this->decide($type);
        $resource = $this->resource($type);
        $this->assertArrayNotHasKey('restriction_version', $resource->toArray());
        $this->assertFalse($resource->isFillable('restriction_version'));
        try {
            $resource->delete();
            $this->fail('A referenced resource must be retained.');
        } catch (LogicException) {
        }
        $this->assertNotNull($resource->fresh());
        $this->assertSame($decision->id, RestrictionDecision::sole()->id);
        if (in_array($type, ['shop', 'company', 'hub', 'handler', 'fleet'], true)) {
            $this->assertFalse(($type === 'shop' ? $this->seller : $this->owner)->canDeleteOwnAccount());
        }
    }

    public function test_parent_cascade_and_migration_rollback_cannot_remove_child_restriction_evidence(): void
    {
        $this->decide('handler');
        foreach ([$this->hub, $this->company] as $parent) {
            try {
                $parent->delete();
                $this->fail('A parent cannot erase child history.');
            } catch (LogicException) {
            }
        }
        $migration = require database_path('migrations/2026_10_05_120000_add_resource_restriction_versions.php');
        try {
            $migration->down();
            $this->fail('Recorded resource versions cannot be erased.');
        } catch (LogicException) {
        }
        $this->assertDatabaseCount('restriction_decisions', 1);
        $this->assertFalse($this->handler->fresh()->is_active);
        $this->assertFalse($this->handlerUser->canDeleteOwnAccount());
    }

    public function test_resource_screens_show_local_and_parent_state_and_keep_company_navigation_when_all_hubs_are_paused(): void
    {
        $this->decide('hub');
        foreach (['http://localhost/admin', 'http://admin.localhost'] as $host) {
            $this->actingAs($this->admin)->get($host.'/resources?type=hub')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Governance/Resources')->where('resources.data.0.status', 'suspended')->where('resources.data.0.parents.1.eligible', true));
            $this->get($host.'/resources/hub/'.$this->hub->id)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Governance/ResourceRestriction')->where('subject.status', 'suspended')->where('subject.history.0.after_status', 'suspended'));
        }
        foreach (['http://localhost/hub', 'http://hub.localhost'] as $host) {
            $this->actingAs($this->owner)->get($host.'/resources?type=hub')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.canManageResources', true)->where('auth.user.activeHub', null)->where('resources.data.0.status', 'suspended'));
        }
        $this->actingAs($this->handlerUser)->get('/hub/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page->where('auth.user.canManageResources', false));
    }

    public function test_company_owner_losing_eligibility_cannot_use_cached_governance_service_or_platform_tokens(): void
    {
        $input = $this->input('hub', actor: $this->owner);
        $this->owner->update(['status' => 'suspended']);
        foreach (['http://localhost/hub', 'http://hub.localhost'] as $host) {
            $this->actingAs($this->owner)->postJson($host.'/resources/hub/'.$this->hub->id, $input)->assertForbidden();
        }
        $this->assertTrue($this->hub->fresh()->is_active);
        $this->expectException(HttpException::class);
        app(ResourceRestrictionService::class)->decide($this->owner, 'hub', $this->hub->id, $input);
    }

    public function test_anonymous_and_arbitrary_resource_type_are_rejected_without_a_decision(): void
    {
        $this->postJson('/admin/resources/hub/'.$this->hub->id, $this->input('hub'))->assertUnauthorized();
        $this->actingAs($this->admin)->postJson('/admin/resources/user/'.$this->owner->id, $this->input('hub'))->assertNotFound();
        $this->assertDatabaseCount('restriction_decisions', 0);
    }

    #[DataProvider('companyPortals')]
    public function test_foreign_resources_are_denied_for_every_company_resource_type(string $host, string $type): void
    {
        $hub = $this->foreignHub();
        $resource = match ($type) {
            'hub' => $hub,
            'handler' => HubHandler::create(['user_id' => User::factory()->logistics()->create()->id, 'hub_id' => $hub->id, 'is_active' => true]),
            'fleet' => LogisticsFleet::create(['logistics_company_id' => $hub->logistics_company_id, 'hub_id' => $hub->id, 'plate_number' => 'B07-FOREIGN-FLEET', 'vehicle_type' => 'motorcycle', 'status' => 'active']),
        };
        $this->actingAs($this->owner)->getJson($host.'/resources/'.$type.'/'.$resource->id)->assertNotFound();
        $this->postJson($host.'/resources/'.$type.'/'.$resource->id, $this->input($type, actor: $this->owner))->assertNotFound();
        $this->get($host.'/resources?type='.$type)->assertOk()->assertInertia(fn (Assert $page) => $page->has('resources.data', 1)->where('resources.data.0.id', $this->resource($type)->id));
        $this->assertDatabaseCount('restriction_decisions', 0);
    }

    public function test_fleet_manifest_context_is_retained_without_inventing_a_vehicle_or_cash_custodian(): void
    {
        [$order, $parcel] = $this->work();
        $parcel->update(['courier_id' => null, 'assigned_rider_id' => null]);
        $this->fleet->update(['vehicle_type' => 'l300_van', 'assigned_driver_id' => null]);
        $this->decide('fleet', actor: $this->owner);
        $snapshot = RestrictionAffectedWork::sole()->snapshot;
        $this->assertSame($order->id, $snapshot['order_id']);
        $this->assertSame('B07-FEEDER', $snapshot['parcel']['shuttle_manifest_number']);
        $this->assertNull($snapshot['cash']['holder']);
        $this->assertNull($parcel->fresh()->courier_id);
        $this->assertSame('picked_up', $parcel->fresh()->status);
    }

    public function test_legacy_fleet_reactivation_is_explicit_and_never_claims_an_invented_prior_mode(): void
    {
        $this->fleet->update(['status' => 'inactive']);
        $review = app(ResourceRestrictionService::class)->presentation($this->admin, 'fleet', $this->fleet->id);
        $this->assertFalse($review['reactivation_mode_recorded']);
        $this->assertTrue($review['legacy_activity']);
        $this->decide('fleet', 'reactivate');
        $this->assertSame('active', $this->fleet->fresh()->status);
        $this->assertSame('inactive', RestrictionDecision::sole()->before_state['subject']['status']);
    }

    public function test_raw_updates_and_deletes_cannot_bypass_immutable_audit_guards(): void
    {
        $this->work();
        $this->decide('hub');
        foreach (['restriction_decisions', 'restriction_affected_work'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    $query = DB::table($table);
                    $operation === 'update' ? $query->update([$table === 'restriction_decisions' ? 'reason' : 'snapshot' => 'Changed']) : $query->delete();
                    $this->fail('Raw writes cannot alter recorded restriction evidence.');
                } catch (QueryException) {
                }
                $this->assertDatabaseCount($table, 1);
            }
        }
    }

    #[DataProvider('resourceTypes')]
    public function test_raw_deletion_and_parent_cascade_preserve_resource_decision_references(string $type): void
    {
        $this->decide($type);
        $resource = $this->resource($type);
        try {
            DB::table($resource->getTable())->where('id', $resource->id)->delete();
            $this->fail('Raw deletion must preserve restriction subjects.');
        } catch (QueryException) {
        }
        $this->assertNotNull($resource->fresh());
        $this->assertDatabaseCount('restriction_decisions', 1);
    }

    public function test_raw_company_cascade_and_guard_rollback_cannot_erase_child_history(): void
    {
        $this->decide('handler');
        try {
            DB::table('logistics_companies')->where('id', $this->company->id)->delete();
            $this->fail('Cascading deletion must preserve child history.');
        } catch (QueryException) {
        }
        $migration = require database_path('migrations/2026_10_05_120100_preserve_restriction_history.php');
        try {
            $migration->down();
            $this->fail('History guards cannot be removed after a decision.');
        } catch (LogicException) {
        }
        $this->assertNotNull($this->company->fresh());
        $this->assertNotNull($this->handler->fresh());
        $this->assertDatabaseCount('restriction_decisions', 1);
    }
}
