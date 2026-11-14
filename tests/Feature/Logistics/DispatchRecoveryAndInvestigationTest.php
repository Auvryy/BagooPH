<?php

namespace Tests\Feature\Logistics;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CourierProfile;
use App\Models\DeliveryCheckpoint;
use App\Models\DispatchEscalation;
use App\Models\HubHandler;
use App\Models\HubPickupPause;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\MissingParcelInvestigation;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Logistics\DispatchRecoveryService;
use App\Services\Logistics\LogisticsManifestService;
use App\Services\Logistics\OrderStateMachineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Support\RoutingCheckoutSeeder;
use Tests\TestCase;

class DispatchRecoveryAndInvestigationTest extends TestCase
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

    public function test_4_step_pickup_escalation_ladder_and_72h_penalty_exempt_auto_cancel(): void
    {
        $t0 = CarbonImmutable::create(2026, 11, 10, 8, 0, 0, 'Asia/Manila');
        $this->travelTo($t0);

        // Order A: unassigned with an available rider -> 12h triggers direct_assignment
        $orderA = $this->newFlowOrder('ready_for_pickup');
        $deliveryA = $orderA->delivery->fresh();
        $originHub = LogisticsHub::findOrFail($deliveryA->origin_bayan_hub_id);
        $rider = $this->flowRider($originHub);

        $this->travelTo($t0->addHours(13));
        $this->artisan('dispatch:process-escalations', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame('unassigned', $deliveryA->fresh()->status);

        $this->artisan('dispatch:process-escalations')->assertSuccessful();
        $deliveryA->refresh();
        $this->assertSame('assigned_pickup', $deliveryA->status);
        $this->assertSame($rider->id, $deliveryA->courier_id);
        $escalationA = DispatchEscalation::where('delivery_id', $deliveryA->id)->sole();
        $this->assertSame('direct_assignment', $escalationA->current_tier);

        // Make rider unavailable & create Order B (0 capacity -> climbs 24h -> 36h -> 72h auto_cancelled)
        $rider->courierProfile->update(['is_available' => false]);
        $t1 = $t0->addHours(14);
        $this->travelTo($t1);
        $orderB = $this->newFlowOrder('ready_for_pickup');
        $deliveryB = $orderB->delivery->fresh();

        // 24h tier -> priority_bounty_deferred with surge_deferred_note & delay label
        $this->travelTo($t1->addHours(25));
        $this->artisan('dispatch:process-escalations')->assertSuccessful();
        $escalationB = DispatchEscalation::where('delivery_id', $deliveryB->id)->sole();
        $this->assertSame('priority_bounty_deferred', $escalationB->current_tier);
        $this->assertTrue($escalationB->is_urgent);
        $this->assertSame('Pickup Delayed — Reassigning Rider', $escalationB->dispatch_delay_label);
        $this->assertNotEmpty($escalationB->surge_deferred_note);

        // 36h tier -> hub_emergency_sweep
        $this->travelTo($t1->addHours(37));
        $this->artisan('dispatch:process-escalations')->assertSuccessful();
        $escalationB->refresh();
        $this->assertSame('hub_emergency_sweep', $escalationB->current_tier);
        $this->assertSame('escalated', $escalationB->status);

        // 72h on claimed Order A -> manual_review (never auto-cancelled because rider already assigned)
        $this->travelTo($t0->addHours(73));
        $this->artisan('dispatch:process-escalations')->assertSuccessful();
        $this->assertSame('manual_review', $escalationA->fresh()->status);
        $this->assertSame('ready_for_pickup', $orderA->fresh()->status);

        // 72h on unclaimed Order B -> penalty-exempt auto-cancel
        $this->travelTo($t1->addHours(73));
        $this->artisan('dispatch:process-escalations')->assertSuccessful();
        $escalationB->refresh();
        $this->assertSame('auto_cancelled', $escalationB->status);
        $this->assertTrue($escalationB->penalty_exempt);
        $this->assertSame('cancelled', $orderB->fresh()->status);
    }

    public function test_controlled_rider_reassignment_works_before_handover_and_rejects_after_physical_handover(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $order->delivery->fresh();
        $originHub = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $handler = $this->flowHandler($originHub);
        $rider1 = $this->flowRider($originHub);
        $rider2 = $this->createApprovedUser('courier');
        $rider2->courierProfile->update([
            'logistics_company_id' => $originHub->logistics_company_id,
            'assigned_hub_id' => $originHub->id,
            'assigned_barangay' => 'Poblacion III',
            'is_available' => true,
        ]);

        // Buyer is forbidden from reassigning riders
        $this->actingAs($order->buyer)->postJson("/hub/deliveries/{$delivery->id}/reassign-rider", [
            'new_rider_id' => $rider2->id,
            'reason' => 'Unauthorized buyer attempt.',
        ])->assertForbidden();

        // Handler assigns rider1, then reassigns pre-handover to rider2, then returns to open pool (null)
        $this->actingAs($handler)->postJson("/hub/deliveries/{$delivery->id}/reassign-rider", [
            'new_rider_id' => $rider1->id,
            'reason' => 'Initial pre-handover rider dispatch assignment.',
        ])->assertOk()->assertJsonPath('phase', 'first_mile');
        $this->assertSame($rider1->id, $delivery->fresh()->courier_id);

        $this->actingAs($handler)->postJson("/hub/deliveries/{$delivery->id}/reassign-rider", [
            'new_rider_id' => $rider2->id,
            'reason' => 'Rider 1 route congestion near shop street.',
        ])->assertOk();
        $this->assertSame($rider2->id, $delivery->fresh()->courier_id);
        $this->assertCheckpointLogged($delivery, 'pickup_reassigned');

        $this->actingAs($handler)->postJson("/hub/deliveries/{$delivery->id}/reassign-rider", [
            'new_rider_id' => null,
            'reason' => 'Return parcel to open pool while reassigning shift.',
        ])->assertOk();
        $this->assertNull($delivery->fresh()->courier_id);
        $this->assertSame('unassigned', $delivery->fresh()->status);

        // Final-mile pre-departure reassignment on sorted_to_barangay_bin -> assigned_to_rider
        $fmOrder = $this->newFlowOrder('ready_for_pickup');
        $fmDelivery = $this->flowDelivery($fmOrder, 'sorted_to_barangay_bin');
        $destHub = LogisticsHub::findOrFail($fmDelivery->destination_bayan_hub_id);
        $destHandler = $this->flowHandler($destHub);
        $destRider = $this->createApprovedUser('courier');
        $destRider->courierProfile->update([
            'logistics_company_id' => $destHub->logistics_company_id,
            'assigned_hub_id' => $destHub->id,
            'assigned_barangay' => 'Poblacion III',
            'is_available' => true,
        ]);

        $this->actingAs($destHandler)->postJson("/hub/deliveries/{$fmDelivery->id}/reassign-rider", [
            'new_rider_id' => $destRider->id,
            'reason' => 'Assigning available destination rider for final mile.',
        ])->assertOk()->assertJsonPath('phase', 'final_mile');
        $this->assertSame($destRider->id, $fmDelivery->fresh()->assigned_rider_id);
        $this->assertSame('assigned_to_rider', $fmDelivery->fresh()->status);

        // After out_for_delivery (physical departure), reassignment MUST be rejected (422)
        app(OrderStateMachineService::class)->transition(
            delivery: $fmDelivery->fresh(),
            targetStatus: OrderStateMachineService::STATUS_OUT_FOR_DELIVERY,
            actor: $destRider,
            scanMetadata: ['barcode' => $fmDelivery->tracking_number]
        );
        $this->actingAs($destHandler)->postJson("/hub/deliveries/{$fmDelivery->id}/reassign-rider", [
            'new_rider_id' => $destRider->id,
            'reason' => 'Attempted illegal mid-transit rider swap.',
        ])->assertStatus(422);
    }

    public function test_explicit_hub_pickup_pause_blocks_checkout_while_zero_available_riders_allows_checkout(): void
    {
        $this->seed(RoutingCheckoutSeeder::class);

        $buyer = User::where('email', 'buyer@bagoo.test')->sole();
        $product = Product::whereHas('shop', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('email', 'seller@bagoo.test')))->firstOrFail();
        $product->update(['stock' => 20]);
        $originHub = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $company = $originHub->company;

        // Zero available riders at origin hub -> coverage reports no_available_rider, STILL ALLOWS checkout!
        CourierProfile::where('assigned_hub_id', $originHub->id)->update(['is_available' => false]);
        $coverage = app(DispatchRecoveryService::class)->coverageAssessment($company, $originHub, 'Los Banos');
        $this->assertSame('no_available_rider', $coverage['coverage_status']);

        $cart = Cart::create(['user_id' => $buyer->id]);
        $line = CartItem::create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => $product->price]);
        $baseInput = [
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

        $this->actingAs($buyer)->post(route('checkout.store'), [
            ...$baseInput,
            'checkout_token' => $this->checkoutToken($buyer, $cart),
        ])->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));
        $this->assertSame(1, Order::count());

        // Now add an explicit HubPickupPause on BH-LBN-01 -> coverage returns pickup_paused & BLOCKS checkout!
        $handler = $this->createApprovedUser('logistics');
        HubHandler::create([
            'user_id' => $handler->id,
            'logistics_company_id' => $company->id,
            'hub_id' => $originHub->id,
            'role_title' => 'Hub Supervisor',
            'is_active' => true,
        ]);

        $pauseRes = $this->actingAs($handler)->postJson('/hub/pickup-pauses', [
            'logistics_hub_id' => $originHub->id,
            'barangay' => null,
            'reason' => 'Severe road flood blocking pickup lane access.',
        ])->assertCreated();
        $pauseId = $pauseRes->json('pickup_pause.id');

        $pausedCoverage = app(DispatchRecoveryService::class)->coverageAssessment($company, $originHub, 'Los Banos');
        $this->assertSame('pickup_paused', $pausedCoverage['coverage_status']);

        $cart2 = Cart::create(['user_id' => $buyer->id]);
        $line2 = CartItem::create(['cart_id' => $cart2->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => $product->price]);
        $this->actingAs($buyer)->post(route('checkout.store'), [
            ...$baseInput,
            'item_ids' => [$line2->id],
            'checkout_token' => $this->checkoutToken($buyer, $cart2),
        ])->assertSessionHasErrors();
        $this->assertSame(1, Order::count());

        // Resume hub pickup pause -> checkout works again!
        $this->actingAs($handler)->postJson("/hub/pickup-pauses/{$pauseId}/resume")
            ->assertOk()
            ->assertJsonPath('pickup_pause.status', 'resumed');
        $this->assertSame('resumed', HubPickupPause::find($pauseId)?->status);
    }

    public function test_manifest_missing_opens_non_loss_investigation_and_physical_scan_recovers_while_only_admin_confirms_loss(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'in_transit_to_mother_hub');

        $custody = DeliveryCheckpoint::lastCustody($delivery->fresh());
        $manifest = LogisticsManifest::findOrFail($custody['manifest_id']);
        $destHub = LogisticsHub::findOrFail($manifest->destination_hub_id);
        $destHandler = $this->flowHandler($destHub);
        $companyManager = LogisticsCompany::findOrFail($delivery->logistics_company_id)->user;
        $admin = $this->createApprovedUser('admin');

        $manifestParcel = $manifest->parcels()->where('delivery_id', $delivery->id)->sole();
        $this->openFlowMotherBags($manifest, $destHandler);

        // Report manifest discrepancy kind=missing -> opens non-loss investigation
        app(LogisticsManifestService::class)->command($manifest, $destHandler, 'report-discrepancy', [
            'version' => $manifest->fresh()->version,
            'request_token' => (string) Str::uuid(),
            'kind' => 'missing',
            'parcel_id' => $manifestParcel->id,
            'notes' => 'Parcel absent from sealed cage when unloading at Mother Hub.',
        ]);

        $investigation = MissingParcelInvestigation::where('delivery_id', $delivery->id)->sole();
        $this->assertSame('investigating', $investigation->status);
        $this->assertFalse($investigation->proves_loss);
        $this->assertFalse($investigation->liability_determined);
        $this->assertSame('deferred_no_insurer_pool', $investigation->insurer_funding_status);

        // Assign investigator + add finding through HTTP portal endpoints
        $this->actingAs($companyManager)->postJson("/governance/monitoring/investigations/{$investigation->id}/assign", [
            'investigator_id' => $destHandler->id,
            'notes' => 'Dispatching dock supervisor to check staging cage.',
        ])->assertOk()->assertJsonPath('investigation.investigator_id', $destHandler->id);

        $this->actingAs($destHandler)->postJson("/governance/monitoring/investigations/{$investigation->id}/finding", [
            'notes' => 'Checked adjacent bay; parcel found on secondary pallet.',
        ])->assertOk();

        // Logistics manager CANNOT confirm permanent loss (403)
        $this->actingAs($companyManager)->postJson("/governance/monitoring/investigations/{$investigation->id}/confirm-loss", [
            'notes' => 'Attempted non-admin permanent loss confirmation.',
        ])->assertForbidden();

        // Physical arrival scan via manifest receipt auto-resolves investigation to recovered (proves_loss=false)
        app(LogisticsManifestService::class)->command($manifest->fresh(), $destHandler, 'receive', [
            'version' => $manifest->fresh()->version,
            'request_token' => (string) Str::uuid(),
            'barcode' => $delivery->tracking_number,
            'notes' => 'Found on secondary pallet.',
        ]);

        $investigation->refresh();
        $this->assertSame('recovered', $investigation->status);
        $this->assertFalse($investigation->proves_loss);

        // Separate manual investigation confirmed lost ONLY by Platform Admin -> proves_loss=true
        $order2 = $this->newFlowOrder('ready_for_pickup');
        $delivery2 = $this->flowDelivery($order2, 'in_transit_to_mother_hub');
        $inv2Res = $this->actingAs($companyManager)->postJson('/governance/monitoring/investigations', [
            'delivery_id' => $delivery2->id,
            'summary' => 'No dock trace after 48h escalation check; opening formal trace.',
        ])->assertCreated();
        $inv2Id = $inv2Res->json('investigation.id');

        $this->actingAs($admin)->postJson("/governance/monitoring/investigations/{$inv2Id}/confirm-loss", [
            'notes' => 'Verified total transport loss after CCTV and truck sweep.',
        ])->assertOk()
            ->assertJsonPath('investigation.status', 'confirmed_lost')
            ->assertJsonPath('investigation.proves_loss', true)
            ->assertJsonPath('investigation.liability_determined', true);
    }
}
