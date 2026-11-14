<?php

namespace Tests\Feature\Monitoring;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnEvent;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\MissingParcelInvestigation;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\PickupClaim;
use App\Models\Product;
use App\Models\SlaClock;
use App\Models\SlaClockEvent;
use App\Models\User;
use App\Services\Monitoring\EtaCalculationService;
use App\Services\Monitoring\SlaClockService;
use App\Services\Monitoring\SlaPauseService;
use App\Services\Returns\BuyerReturnService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Support\RoutingCheckoutSeeder;
use Tests\TestCase;

class SlaMonitoringAndEtaTest extends TestCase
{
    use AssertsDeliveryCheckpoints;
    use CreatesE2EOrders;
    use InteractsWithCheckoutSubmission;
    use InteractsWithOrderActions;
    use InteractsWithRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_checkout_quote_preview_is_read_only_and_checkout_store_persists_dynamic_eta_and_clock(): void
    {
        $this->seed(RoutingCheckoutSeeder::class);
        $this->travelTo(CarbonImmutable::create(2026, 11, 10, 17, 0, 0, 'Asia/Manila'));

        $buyer = User::where('email', 'buyer@bagoo.test')->sole();
        $product = Product::whereHas('shop', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('email', 'seller@bagoo.test')))->firstOrFail();
        $product->update(['stock' => 20, 'weight_kg' => '1.00', 'length_cm' => '20.00', 'width_cm' => '20.00', 'height_cm' => '10.00']);
        $company = LogisticsHub::where('code', 'BH-LBN-01')->sole()->company;

        $cart = Cart::create(['user_id' => $buyer->id]);
        $line = CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => $product->price]);

        $input = [
            'checkout_token' => $this->checkoutToken($buyer, $cart),
            'item_ids' => [$line->id],
            'recipient_name' => $buyer->name,
            'recipient_phone' => $buyer->phone,
            'shipping_address' => '100 Test Recipient Road',
            'shipping_province' => 'Laguna',
            'shipping_city' => 'Santa Cruz',
            'shipping_postal_code' => '4009',
            'destination_barangay' => 'Poblacion III',
            'delivery_type' => 'doorstep',
            'payment_method' => 'cod',
            'logistics_company_id' => $company->id,
        ];

        // Read-only quote preview returns dynamic PHT ETA + coverage with zero DB writes
        $quoteResponse = $this->actingAs($buyer)->postJson(route('buyer.vouchers.apply'), $input)->assertOk();
        $quoteResponse->assertJsonPath('quote.shops.0.shipping_quote.eta.cutoff_applied', true);
        $quoteResponse->assertJsonPath('quote.shops.0.shipping_quote.coverage.coverage_status', 'available');
        $this->assertNotEmpty($quoteResponse->json('quote.shops.0.shipping_quote.eta.label'));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Delivery::count());
        $this->assertSame(0, SlaClock::count());

        // Checkout persists ETA bounds, basis, and starts seller_acceptance_pack clock
        $this->actingAs($buyer)->post(route('checkout.store'), $input)->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));

        $order = Order::sole();
        $delivery = $order->delivery;
        $this->assertNotNull($delivery->estimated_delivery_min_at);
        $this->assertNotNull($delivery->estimated_delivery_max_at);
        $this->assertNotNull($delivery->estimated_delivery_at);
        $this->assertTrue($delivery->eta_basis['cutoff_applied']);
        $this->assertSame('on_track', $delivery->eta_basis['eta_status']);

        $clock = SlaClock::where('subject_type', 'order')
            ->where('subject_id', $order->id)
            ->where('clock_kind', 'seller_acceptance_pack')
            ->sole();
        $this->assertSame('running', $clock->status);
    }

    public function test_public_tracking_exposes_eta_without_pii_leakage(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery->fresh();
        app(EtaCalculationService::class)->applyToDelivery($delivery, $order);

        $admin = $this->createApprovedUser('admin');
        app(SlaPauseService::class)->activate($admin, [
            'scope_type' => 'platform',
            'reason_code' => 'typhoon',
            'title' => 'Internal Typhoon Operations Hold #77',
            'notes' => 'Confidential internal escalation guidance',
        ]);
        app(EtaCalculationService::class)->applyToDelivery($delivery->fresh(), $order->fresh());

        $this->get('/track/'.$delivery->tracking_number)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Public/Tracking')
                ->where('parcel.eta.eta_status', 'paused')
                ->where('parcel.eta.pause_notice', fn ($notice) => is_string($notice) && str_contains($notice, 'Force Majeure') && ! str_contains($notice, 'Confidential internal'))
                ->where('parcel.delivery_recipient_name', fn ($name) => $name !== $order->recipient_name && str_contains($name, '*')));
    }

    public function test_manila_timezone_dashboard_counts_late_utc_as_next_pht_day(): void
    {
        // 2026-11-10 16:30:00 UTC is 2026-11-11 00:30:00 Asia/Manila (already Nov 11 in PHT!)
        $utcLateNov10 = CarbonImmutable::create(2026, 11, 10, 16, 30, 0, 'UTC');
        $this->travelTo($utcLateNov10);

        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery->fresh();
        $originHub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $handler = $this->flowHandler($originHub);

        // Checkpoint recorded at 00:30 PHT on Nov 11 (2026-11-10 16:30 UTC)
        $cpBefore = DeliveryCheckpoint::create([
            'delivery_id' => $delivery->id,
            'checkpoint_type' => 'in_transit_to_mother_hub',
            'hub_id' => $originHub->id,
            'location_name' => $originHub->name,
            'notes' => 'Dispatched at 00:30 PHT.',
            'created_at' => $utcLateNov10,
            'updated_at' => $utcLateNov10,
        ]);
        $cpBefore->forceFill(['created_at' => $utcLateNov10])->saveQuietly();

        // Advance to 01:00 PHT Nov 11 (still Nov 11 PHT) -> counted in dispatched_today
        $this->travelTo(CarbonImmutable::create(2026, 11, 11, 1, 0, 0, 'Asia/Manila'));
        $this->actingAs($handler)->get('/hub/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.dispatched_today', 1));
    }

    public function test_state_aware_sla_clock_pauses_at_staged_final_mile_and_force_majeure_without_mutating_immutable_pickup_claim(): void
    {
        $t0 = CarbonImmutable::create(2026, 11, 10, 10, 0, 0, 'Asia/Manila');
        $this->travelTo($t0);

        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $destHub = LogisticsHub::where('tier', 'local_bayan_hub')->where('city_municipality', 'Santa Cruz')->sole();
        $destHub->update(['allows_self_pickup' => true]);
        $this->actingAs($destHub->company->user)->postJson('/hub/counter/hours', [
            'hub_id' => $destHub->id,
            'operating_hours' => 'Monday to Saturday, 08:00 to 17:00',
        ])->assertOk();

        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, shipping: [
            'delivery_type' => 'hub_self_pickup',
            'pickup_hub_id' => $destHub->id,
        ]);
        $this->sellerFlowStage($order, 'ready_for_pickup');
        $delivery = $this->flowDelivery($order->fresh(), 'arrived_at_destination_hub');
        $destHandler = $this->flowHandler($destHub);
        $this->actingAs($destHandler)->postJson('/hub/scan', [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $destHub->id,
            'mode' => 'confirm',
            'action' => 'STAGE_FOR_PICKUP',
            'expected_status' => 'arrived_at_destination_hub',
        ])->assertOk();
        $claim = PickupClaim::where('delivery_id', $delivery->id)->sole();
        $originalExpiresAt = $claim->expires_at->toIso8601String();

        // Simulate a staged_for_final_mile delivery pausing hub_idle_sorting
        $doorOrder = $this->newFlowOrder('ready_for_pickup', ['delivery_type' => 'doorstep']);
        $doorDelivery = $this->flowDelivery($doorOrder, 'sorted_to_barangay_bin');
        $idleClock = SlaClock::where('subject_type', 'delivery')
            ->where('subject_id', $doorDelivery->id)
            ->where('clock_kind', 'hub_idle_sorting')
            ->sole();
        $finalClock = SlaClock::where('subject_type', 'delivery')
            ->where('subject_id', $doorDelivery->id)
            ->where('clock_kind', 'final_mile_staging')
            ->sole();
        $this->assertSame('paused', $idleClock->status);
        $this->assertSame('staged_for_final_mile', $idleClock->pause_reason);
        $this->assertSame('running', $finalClock->status);

        // Now test Force-Majeure pause on immutable PickupClaim holding window
        $admin = $this->createApprovedUser('admin');
        $pauseService = app(SlaPauseService::class);
        $clockService = app(SlaClockService::class);

        $pause = $pauseService->activate($admin, [
            'scope_type' => 'hub',
            'logistics_hub_id' => $claim->hub_id,
            'reason_code' => 'typhoon',
            'title' => 'Hub Flooding Temporary Pause',
        ]);

        $holdingClock = SlaClock::where('subject_type', 'pickup_claim')
            ->where('subject_id', $claim->id)
            ->where('clock_kind', 'counter_pickup_holding')
            ->sole();
        $this->assertSame('paused', $holdingClock->status);
        $this->assertSame('force_majeure', $holdingClock->pause_reason);

        // Travel 6 hours forward and resume pause
        $this->travelTo($t0->addHours(6));
        $pauseService->resume($pause, $admin, 'Floodwaters subsided; counter reopened.');

        $resumedClock = $holdingClock->fresh();
        $this->assertSame('running', $resumedClock->status);
        $this->assertSame(21600, $resumedClock->paused_seconds);

        // Immutable DB column pickup_claims.expires_at MUST NOT be altered, while effectiveExpiresAt extends +6h!
        $this->assertSame($originalExpiresAt, $claim->fresh()->expires_at->toIso8601String());
        $this->assertSame(
            $claim->expires_at->addSeconds(21600)->toIso8601String(),
            $clockService->effectiveExpiresAt($claim->fresh())->toIso8601String()
        );
    }

    public function test_dry_run_scan_is_read_only_and_live_scan_is_idempotent_across_ticks(): void
    {
        $t0 = CarbonImmutable::create(2026, 11, 10, 8, 0, 0, 'Asia/Manila')->utc();
        $this->travelTo($t0);

        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'in_transit_to_mother_hub');

        // Make forward_interhub_transit clock overdue past critical_at
        $clock = SlaClock::where('subject_type', 'delivery')
            ->where('subject_id', $delivery->id)
            ->where('clock_kind', 'forward_interhub_transit')
            ->sole();
        $clock->update([
            'warning_at' => $t0->subHours(25),
            'breach_at' => $t0->subHours(2),
            'critical_at' => $t0->subHour(),
            'last_warned_at' => null,
            'last_breached_at' => null,
            'last_critical_at' => null,
        ]);

        // Dry run must NOT write events, notifications, or investigations
        $this->artisan('monitoring:scan-sla', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, SlaClockEvent::where('sla_clock_id', $clock->id)->whereIn('event_type', ['warned', 'breached', 'critical'])->count());
        $this->assertSame(0, MissingParcelInvestigation::count());
        $this->assertNull($clock->fresh()->last_breached_at);

        // Live scan run #1 records warned/breached/critical + opens MissingParcelInvestigation (proves_loss=false)
        $this->artisan('monitoring:scan-sla')->assertSuccessful();
        $this->assertSame(1, SlaClockEvent::where('sla_clock_id', $clock->id)->where('event_type', 'warned')->count());
        $this->assertSame(1, SlaClockEvent::where('sla_clock_id', $clock->id)->where('event_type', 'breached')->count());
        $this->assertSame(1, SlaClockEvent::where('sla_clock_id', $clock->id)->where('event_type', 'critical')->count());

        $investigation = MissingParcelInvestigation::where('delivery_id', $delivery->id)->sole();
        $this->assertSame('scan_timeout', $investigation->source_kind);
        $this->assertFalse($investigation->proves_loss);
        $this->assertFalse($investigation->liability_determined);

        $notifCountAfterFirst = NotificationDelivery::count();
        $eventCountAfterFirst = SlaClockEvent::count();

        // Live scan run #2 is 100% idempotent
        $this->artisan('monitoring:scan-sla')->assertSuccessful();
        $this->assertSame($notifCountAfterFirst, NotificationDelivery::count());
        $this->assertSame($eventCountAfterFirst, SlaClockEvent::count());
        $this->assertSame(1, MissingParcelInvestigation::where('delivery_id', $delivery->id)->count());
    }

    public function test_overdue_buyer_return_escalates_and_expires_without_auto_approving_refunds(): void
    {
        $t0 = CarbonImmutable::create(2026, 11, 10, 9, 0, 0, 'Asia/Manila');
        $this->travelTo($t0);
        $this->createApprovedUser('admin');

        $order = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'delivered')->order->fresh(['items', 'buyer', 'delivery']);
        $token = app(BuyerReturnService::class)->form($order->buyer, $order)['requestToken'];

        $this->actingAs($order->buyer)->post('/buyer-returns/orders/'.$order->id, [
            'request_token' => $token,
            'reason' => 'damaged',
            'description' => 'Damaged buckle on receipt.',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]],
            'photos' => [UploadedFile::fake()->image('dmg.png')],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $case = BuyerReturnCase::sole();
        $this->assertSame('requested', $case->status);
        $this->assertSame(1, DB::table('buyer_return_unit_claims')->where('buyer_return_case_id', $case->id)->count());

        // Jump past response_until (72h) -> monitoring:scan-sla escalates to admin WITHOUT approving or issuing money
        $this->travelTo($case->response_until->addHours(2));
        $this->artisan('monitoring:scan-sla')->assertSuccessful();

        $case->refresh();
        $this->assertSame('escalated', $case->status);
        $this->assertSame(1, BuyerReturnEvent::where('buyer_return_case_id', $case->id)->where('event_type', 'escalate')->count());

        // Test rejected -> expired appeal window -> denied
        $case->update(['status' => 'rejected', 'appeal_until' => now()->subHour()]);
        app(SlaClockService::class)->syncForBuyerReturnCase($case->fresh());
        $this->artisan('monitoring:scan-sla')->assertSuccessful();
        $this->assertSame('denied', $case->fresh()->status);

        // Test approved -> expired dropoff_until -> expired + releases unit claims
        $case->update(['status' => 'approved', 'dropoff_until' => now()->subHour()]);
        app(SlaClockService::class)->syncForBuyerReturnCase($case->fresh());
        $this->artisan('monitoring:scan-sla')->assertSuccessful();
        $this->assertSame('expired', $case->fresh()->status);
        $this->assertSame(0, DB::table('buyer_return_unit_claims')->where('buyer_return_case_id', $case->id)->count());
    }

    public function test_monitoring_portal_rbac_and_company_pause_scope(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $company = LogisticsCompany::findOrFail($order->delivery->logistics_company_id);
        $logisticsManager = $company->user;
        $buyer = $order->buyer;
        $admin = $this->createApprovedUser('admin');

        // Buyer cannot access governance monitoring
        $this->actingAs($buyer)->get('/governance/monitoring')->assertForbidden();

        // Logistics manager CAN open /governance/monitoring and create a company pause, but NOT platform pause or dry-run
        $this->actingAs($logisticsManager)->get('/governance/monitoring')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Governance/Monitoring'));

        $this->actingAs($logisticsManager)->postJson('/governance/monitoring/dry-run')->assertForbidden();
        $this->actingAs($logisticsManager)->postJson('/governance/monitoring/pauses', [
            'scope_type' => 'platform',
            'reason_code' => 'typhoon',
            'title' => 'Unauthorized Platform Pause Attempt',
        ])->assertForbidden();

        $pauseRes = $this->actingAs($logisticsManager)->postJson('/governance/monitoring/pauses', [
            'scope_type' => 'company',
            'logistics_company_id' => $company->id,
            'reason_code' => 'emergency',
            'title' => 'Carrier Highway Detour Pause',
        ])->assertCreated();

        $pauseId = $pauseRes->json('pause.id');
        $this->assertDatabaseHas('sla_pauses', ['id' => $pauseId, 'status' => 'active', 'logistics_company_id' => $company->id]);

        // Admin can dry-run and resume pause
        $this->actingAs($admin)->postJson('/governance/monitoring/dry-run')->assertOk()->assertJsonPath('dry_run', true);
        $this->actingAs($admin)->postJson("/governance/monitoring/pauses/{$pauseId}/resume", ['notes' => 'Road cleared.'])
            ->assertOk()
            ->assertJsonPath('pause.status', 'ended');
    }
}
