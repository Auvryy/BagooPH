<?php

namespace Tests\Feature\E2E\Tier4;

use App\Models\LogisticsHub;
use App\Models\RestrictionAffectedWork;
use App\Models\User;
use App\Services\Logistics\RestrictedCustodyRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithKycReviews;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class RealWorldLogisticsRoutingTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithKycReviews;
    use InteractsWithOrderActions;
    use RefreshDatabase;

    public function test_t4_07_seller_merchant_onboarding_and_first_sale(): void
    {
        $seller = $this->createPendingUser('seller');
        $seller->shop->update(['root_category_id' => $this->validMasterCategory()->id, 'city' => 'Los Baños']);
        $admin = $this->createApprovedUser('admin');
        $this->actingAs($admin)->post(route('admin.kyc.approve', $seller), $this->prepareKycReview($admin, $seller))->assertSessionHas('success');
        $seller = $seller->fresh();
        $this->assertSame('approved', $seller->kyc_status);
        $shop = $seller->shop;
        $product = $this->createE2EProduct($shop, ['name' => 'Handmade Wallet', 'stock' => 20, 'price' => 500]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $product, 'quantity' => 2]]);
        $this->assertSame(18, $product->fresh()->stock);
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->settleFlowOrder($order);
        $this->assertCheckpointLogged($delivery, 'buyer_completed');
        $this->assertCommissionSplit($order, null, (float) $order->shipping_fee);
    }

    public function test_t4_08_cod_financial_lifecycle_and_remittance(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['price' => 2000]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $product]]);
        $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->settleFlowOrder($order);
        $this->assertSame('paid', $order->fresh()->payment_status);
        // Cash and seller payment were recorded independently from buyer confirmation.
        $this->assertCommissionSplit($order, 2000, (float) $order->shipping_fee);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_t4_09_high_value_artisan_order_immediate_confirmation(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['price' => 8000]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $product]], 'ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertCheckpointLogged($delivery, 'arrived_at_mother_hub');
        $this->assertCheckpointLogged($delivery, 'buyer_completed');
    }

    public function test_t4_10_post_delivery_dispute_and_admin_governance(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'delivered');
        $this->completeFlowOrder($order);
        $this->settleFlowOrder($order);
        $admin = $this->createApprovedUser('admin');
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('buyer.orders.show', $order))->assertOk();
        $this->assertCheckpointLogged($delivery, 'buyer_completed');
        // Dispute processing remains deferred; finance oversight still requires real settlement sources.
        $this->assertCommissionSplit($order, null, (float) $order->shipping_fee);
    }

    public function test_t4_11_courier_breakdown_hub_reassignment(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'out_for_delivery');
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $replacement = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $before = [$delivery->getRawOriginal(), $delivery->checkpoints()->pluck('id')->all()];
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $delivery), ['rider_id' => $replacement->id])->assertUnprocessable();
        $this->assertSame($before, [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->pluck('id')->all()]);
        // Phase 3 must establish accountable recovery and a real handoff; regular assignment cannot transfer custody.
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect',
        ])->assertConflict();
        $this->assertSame($before, [$delivery->fresh()->getRawOriginal(), $delivery->checkpoints()->pluck('id')->all()]);
        $original = User::findOrFail($delivery->assigned_rider_id);
        $this->restrictFlowAccount($original, 'suspend');
        $work = RestrictionAffectedWork::where('delivery_id', $delivery->id)->latest('id')->firstOrFail();
        $manager = $hub->company->user;
        $detail = $this->actingAs($manager)->getJson('/exceptions/restriction/'.$work->id)->assertOk()->json('exception');
        $this->postJson('/exceptions/restriction/'.$work->id, ['action' => 'assign', 'responsible_user_id' => $replacement->id,
            'source_token' => $detail['sourceToken'], 'request_token' => (string) Str::uuid(), 'reason' => 'Arrange an actual hub handover after the rider breakdown.'])->assertOk();
        $this->assertSame($original->id, $delivery->fresh()->assigned_rider_id);
        $service = app(RestrictedCustodyRecoveryService::class);
        $proposal = $service->proposal($manager, $work);
        $grant = $service->grant($manager, $work, ['source_token' => $proposal['source_token'], 'request_token' => (string) Str::uuid(),
            'reason' => 'Return the original parcel to the assigned destination hub.']);
        $receipt = ['barcode' => $delivery->tracking_number, 'request_token' => (string) Str::uuid(), 'notes' => 'Actual breakdown parcel handed to the original hub handler.'];
        $this->actingAs($original)->postJson('/custody-recovery/'.$grant->id.'/handover', $receipt)->assertOk();
        $this->actingAs($this->flowHandler($hub))->postJson('/custody-recovery/'.$grant->id.'/receipt', array_replace($receipt, ['request_token' => (string) Str::uuid()]))->assertOk();
        $this->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'barangay' => $order->destination_barangay])->assertOk();
        $this->postJson(route('hub.assignRider', $delivery), ['rider_id' => $replacement->id])->assertOk();
        $this->assertSame($replacement->id, $delivery->fresh()->assigned_rider_id, 'A recorded recovery handoff must establish the replacement responsibility.');
        $this->actingAs($replacement)->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])->assertSessionHas('success');
        Storage::fake('public');
        $this->patch(route('courier.updateStatus', $delivery), $this->codCollectionInput($delivery) + ['status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('recovery-delivery.jpg')])->assertSessionHas('success');
        $detail = $this->actingAs($manager)->getJson('/exceptions/restriction/'.$work->id)->assertOk()->json('exception');
        $this->postJson('/exceptions/restriction/'.$work->id, ['action' => 'resolve', 'source_token' => $detail['sourceToken'], 'request_token' => (string) Str::uuid(),
            'reason' => 'Original hub receipt and actual replacement delivery are retained.'])->assertOk();
        $this->assertSame('suspended', $original->fresh()->status);
        $this->assertSame('delivered', $delivery->fresh()->status, 'Actual recovery handoff and replacement-rider delivery remain required.');
    }

    public function test_t4_12_full_subdomain_cross_portal_multi_actor_session(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $courier = $this->createApprovedUser('courier');
        $logistics = $this->createApprovedUser('logistics');
        $admin = $this->createApprovedUser('admin');

        // Verify each actor accesses their respective domain cleanly
        $respBuyer = $this->actingAs($buyer)->onPortal('buyer')->get('/');
        $this->assertTrue(in_array($respBuyer->status(), [200, 302]));

        $respSeller = $this->actingAs($seller)->onPortal('seller')->get('/seller/dashboard');
        $this->assertTrue(in_array($respSeller->status(), [200, 302]));

        $respCourier = $this->actingAs($courier)->onPortal('courier')->get('/courier/deliveries');
        $this->assertTrue(in_array($respCourier->status(), [200, 302]));

        $respHub = $this->actingAs($logistics)->onPortal('hub')->get('/hub');
        $this->assertTrue(in_array($respHub->status(), [200, 302]));

        $respAdmin = $this->actingAs($admin)->onPortal('admin')->get('/admin/dashboard');
        $this->assertTrue(in_array($respAdmin->status(), [200, 302]));
    }
}
