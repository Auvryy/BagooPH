<?php

namespace Tests\Feature\E2E\Tier4;

use App\Models\Delivery;
use App\Models\LogisticsHub;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\E2E\Support\AssertsCommissionLedgers;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithPortals;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\E2E\Support\SimulatesOrderLifecycle;
use Tests\TestCase;

class RealWorldExceptionsAndFleetTest extends TestCase
{
    use AssertsCommissionLedgers, AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithPortals, InteractsWithRoles, SimulatesOrderLifecycle;
    use InteractsWithOrderActions;
    use RefreshDatabase;

    public function test_t4_13_sequential_flash_sale_exhaustion_rejects_sixth_checkout(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['stock' => 5, 'price' => 200]);
        for ($i = 0; $i < 5; $i++) {
            $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $product->fresh()]]);
        }
        $this->assertSame(0, $product->fresh()->stock);
        $buyer = $this->createApprovedUser('buyer');
        $payload = $this->flowCheckoutPayload($buyer, [['product' => $product->fresh()]]);
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 5);
        $this->assertDatabaseCount('checkout_submissions', 5);
        $this->assertSame(0, $product->fresh()->stock);
    }

    public function test_t4_14_unmapped_address_rejects_without_hub_override(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['stock' => 5]);
        $payload = $this->flowCheckoutPayload($buyer, [['product' => $product]]);
        $payload['shipping_city'] = 'Unknown Valley';
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $this->assertDatabaseCount('checkout_submissions', 0);
        $this->assertSame(5, $product->fresh()->stock);
    }

    public function test_t4_15_courier_duty_cycle_and_shift(): void
    {
        $order = $this->newFlowOrder('ready_for_pickup');
        $delivery = $this->flowDelivery($order, 'assigned_to_rider');
        $rider = User::findOrFail($delivery->assigned_rider_id);
        $this->actingAs($rider)->post(route('courier.toggleDuty'), ['is_available' => false])->assertSessionHas('success');
        $this->assertFalse($rider->fresh()->courierProfile->is_available);
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])->assertSessionHas('success');
        Storage::fake('public');
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), [
            ...$this->codCollectionInput($delivery),
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->create('duty-proof.jpg', 20, 'image/jpeg'),
        ])->assertSessionHas('success');
        $this->assertSame('delivered', $delivery->fresh()->status);
        $next = $this->newFlowOrder('ready_for_pickup');
        $nextParcel = $this->flowDelivery($next, 'sorted_to_barangay_bin');
        $hub = LogisticsHub::findOrFail($nextParcel->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $nextParcel), ['rider_id' => $rider->id])->assertUnprocessable();
        $this->assertNull($nextParcel->fresh()->assigned_rider_id);
        $this->actingAs($rider)->post(route('courier.toggleDuty'), ['is_available' => true])->assertSessionHas('success');
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.assignRider', $nextParcel), ['rider_id' => $rider->id])->assertOk();
    }

    public function test_t4_16_order_cancellation_pre_vs_post_pickup(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $seller = $this->createApprovedUser('seller');
        $shop = $this->createE2EShop($seller);
        $product = $this->createE2EProduct($shop, ['stock' => 10]);
        $beforeClaim = $this->checkoutFlowOrder($buyer, $shop, [['product' => $product]]);
        $this->actingAs($seller)->post(route('seller.orders.cancel', $beforeClaim), ['reason' => 'Stock unavailable'])->assertSessionHas('success');
        $this->assertSame('cancelled', $beforeClaim->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock);
        $this->actingAs($seller)->post(route('seller.orders.cancel', $beforeClaim), ['reason' => 'Stock unavailable'])->assertSessionHas('error');
        $this->assertSame(10, $product->fresh()->stock);
        $afterClaim = $this->checkoutFlowOrder($buyer, $shop, [['product' => $product->fresh()]], 'ready_for_pickup');
        $parcel = $this->flowDelivery($afterClaim, 'assigned_pickup');
        $snapshot = [$afterClaim->fresh()->getRawOriginal(), $parcel->getRawOriginal(), $parcel->checkpoints()->pluck('id')->all()];
        $this->actingAs($seller)->post(route('seller.orders.cancel', $afterClaim), ['reason' => 'Stock unavailable'])->assertSessionHas('error');
        $this->assertSame($snapshot, [$afterClaim->fresh()->getRawOriginal(), $parcel->fresh()->getRawOriginal(), $parcel->checkpoints()->pluck('id')->all()]);
        $this->assertSame(9, $product->fresh()->stock);
        $this->actingAs($buyer)->post(route('seller.orders.cancel', $afterClaim), ['reason' => 'Changed plans'])->assertForbidden();
    }

    public function test_t4_17_hub_sorting_dock_morning_rush(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        for ($i = 0; $i < 6; $i++) {
            $order = $this->checkoutFlowOrder($buyer, $shop, [], 'ready_for_pickup');
            $delivery = $this->flowDelivery($order, 'arrived_at_destination_hub');
            $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
            $bin = $i % 2 === 0 ? 'BIN-A1' : 'BIN-B1';
            $this->actingAs($this->flowHandler($hub))->postJson(route('hub.sort'), [
                'delivery_id' => $delivery->id, 'barangay' => $order->destination_barangay, 'bin' => $bin,
            ])->assertOk();
            $this->assertSame($bin, $delivery->fresh()->destination_bin);
            $this->assertCheckpointLogged($delivery, 'arrived_at_mother_hub');
        }
        $this->assertSame(3, Delivery::where('destination_bin', 'BIN-A1')->count());
        $this->assertSame(3, Delivery::where('destination_bin', 'BIN-B1')->count());
    }

    public function test_t4_18_platform_governance_and_financial_audit(): void
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $product = $this->createE2EProduct($shop, ['price' => 500]);
        $orders = [];
        for ($i = 0; $i < 3; $i++) {
            $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $product->fresh(), 'quantity' => 2]]);
            $this->flowDelivery($order, 'delivered');
            $this->completeFlowOrder($order);
            $orders[] = $order;
        }
        $this->assertEquals(3000, collect($orders)->sum('subtotal'));
        foreach ($orders as $order) {
            $this->assertCommissionSplit($order);
        }
        $this->actingAs($this->createApprovedUser('admin'))->get(route('admin.dashboard'))->assertOk();
    }
}
