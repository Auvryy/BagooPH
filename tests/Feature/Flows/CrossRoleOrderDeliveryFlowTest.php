<?php

namespace Tests\Feature\Flows;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CommissionLedger;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Logistics\OrderStateMachineService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithCheckoutSubmission;
use Tests\TestCase;

class CrossRoleOrderDeliveryFlowTest extends TestCase
{
    use InteractsWithCheckoutSubmission, \Tests\Feature\E2E\Support\InteractsWithOrderActions, \Tests\Feature\E2E\Support\InteractsWithRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

    }

    public function test_seeded_roles_complete_the_real_los_banos_to_santa_cruz_delivery_flow(): void
    {
        $buyer = User::where('email', 'buyer@bagoo.test')->firstOrFail();
        $seller = User::where('email', 'seller@bagoo.test')->firstOrFail();
        $pickupRider = User::where('email', 'pickup.rider@bagoo.test')->firstOrFail();
        $finalRider = User::where('email', 'rider@bagoo.test')->firstOrFail();
        $originHandler = User::where('email', 'losbanos.hub@bagoo.test')->firstOrFail();
        $motherHandler = User::where('email', 'motherhub@bagoo.test')->firstOrFail();
        $destinationHandler = User::where('email', 'logistics@bagoo.test')->firstOrFail();
        $originHub = LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail();
        $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();
        $destinationHub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
        $product = Product::whereHas('shop', fn ($query) => $query->where('user_id', $seller->id))->firstOrFail();
        $startingStock = $product->stock;

        $cart = Cart::create(['user_id' => $buyer->id]);
        $item = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => $product->price,
        ]);

        $checkoutPayload = [
            'checkout_token' => $this->checkoutToken($buyer, $cart),
            'recipient_name' => $buyer->name,
            'recipient_phone' => $buyer->phone,
            'shipping_address' => 'Pedro Guevara Avenue, Poblacion III',
            'shipping_city' => 'Santa Cruz',
            'shipping_province' => 'Laguna',
            'shipping_postal_code' => '4009',
            'destination_barangay' => 'Poblacion III',
            'delivery_type' => 'doorstep',
            'item_ids' => [$item->id],
            'payment_method' => 'cod',
        ];
        $this->actingAs($buyer)->post(route('checkout.store'), $checkoutPayload)->assertRedirect(route('buyer.orders.index'));

        $order = Order::with('delivery')->firstOrFail();
        $delivery = $order->delivery;

        $this->assertSame('placed', $order->status);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame($startingStock - 1, $product->fresh()->stock);
        $this->assertSame($originHub->id, $delivery->origin_bayan_hub_id);
        $this->assertSame($motherHub->id, $delivery->origin_mother_hub_id);
        $this->assertSame($motherHub->id, $delivery->destination_mother_hub_id);
        $this->assertSame($destinationHub->id, $delivery->destination_bayan_hub_id);
        $this->assertNotNull($delivery->tracking_number);

        $this->actingAs($seller)->post(route('seller.orders.accept', $order))->assertSessionHas('success');
        $this->assertSame('confirmed', $order->fresh()->status);
        $this->actingAs($seller)->post(route('seller.orders.pack', $order))->assertSessionHas('success');
        $this->assertSame('preparing', $order->fresh()->status);
        $this->actingAs($seller)->post(route('seller.orders.ready', $order))->assertSessionHas('success');
        $this->assertSame('ready_for_pickup', $order->fresh()->status);

        $this->actingAs($finalRider)->post(route('courier.claim', $delivery))->assertSessionHas('error');
        $this->assertNull($delivery->fresh()->courier_id);

        $this->actingAs($pickupRider)->post(route('courier.claim', $delivery))->assertSessionHas('success');
        $this->assertSame($pickupRider->id, $delivery->fresh()->courier_id);
        $this->actingAs($pickupRider)->patch(route('courier.updateStatus', $delivery), [
            'status' => 'picked_up',
            'barcode' => $delivery->tracking_number,
            'courier_notes' => 'Seller waybill matched the parcel at pickup.',
        ])->assertSessionHas('success');
        $this->assertSame(OrderStateMachineService::STATUS_PICKED_UP, $delivery->fresh()->status);

        $this->scanAndConfirm($originHandler, $delivery, $originHub, 'RECEIVE_FROM_PICKUP_RIDER', OrderStateMachineService::STATUS_PICKED_UP);
        $this->assertSame($originHub->id, $delivery->fresh()->current_hub_id);
        $checkpointCount = DeliveryCheckpoint::where('delivery_id', $delivery->id)
            ->where('checkpoint_type', OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB)
            ->count();
        $this->confirmScan($originHandler, $delivery, $originHub, 'RECEIVE_FROM_PICKUP_RIDER', OrderStateMachineService::STATUS_PICKED_UP);
        $this->assertSame($checkpointCount, DeliveryCheckpoint::where('delivery_id', $delivery->id)
            ->where('checkpoint_type', OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB)
            ->count());

        $this->scanAndConfirm($originHandler, $delivery, $originHub, 'DISPATCH_TO_FEEDER', OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB);
        $this->assertNull($delivery->fresh()->current_hub_id);
        $this->scanAndConfirm($motherHandler, $delivery, $motherHub, 'RECEIVE_AT_MOTHER_HUB', OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB);
        $this->assertSame($motherHub->id, $delivery->fresh()->current_hub_id);
        $this->scanAndConfirm($motherHandler, $delivery, $motherHub, 'SORT_TO_LINE_HAUL', OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB);
        $this->assertSame($motherHub->id, $delivery->fresh()->current_hub_id);
        $this->scanAndConfirm($motherHandler, $delivery, $motherHub, 'DISPATCH_LINE_HAUL', OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL);
        $this->assertNull($delivery->fresh()->current_hub_id);
        $this->scanAndConfirm($destinationHandler, $delivery, $destinationHub, 'RECEIVE_AT_DESTINATION_HUB', OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB);
        $this->assertSame($destinationHub->id, $delivery->fresh()->current_hub_id);

        $this->actingAs($destinationHandler)->postJson(route('hub.sort'), [
            'delivery_id' => $delivery->id,
            'barangay' => 'Poblacion III',
            'bin' => 'BIN: BRGY-POBLACION-III',
        ])->assertOk();

        $this->actingAs($destinationHandler)->postJson(route('hub.assignRider', $delivery), [
            'rider_id' => $finalRider->id,
        ])->assertOk();
        $this->assertSame($finalRider->id, $delivery->fresh()->assigned_rider_id);

        $this->actingAs($pickupRider)->patch(route('courier.updateStatus', $delivery), [
            'status' => 'out_for_delivery',
            'barcode' => $delivery->tracking_number,
        ])->assertSessionHas('error');

        $this->actingAs($finalRider)->patch(route('courier.updateStatus', $delivery), [
            'status' => 'out_for_delivery',
            'barcode' => $delivery->tracking_number,
        ])->assertSessionHas('success');
        $this->assertNull($delivery->fresh()->current_hub_id);
        $this->actingAs($finalRider)->patch(route('courier.updateStatus', $delivery), [
            ...$this->codCollectionInput($delivery),
            'status' => 'delivered',
            'courier_notes' => 'Parcel handed directly to the buyer.',
            'proof_image_file' => UploadedFile::fake()->create('delivery-proof.jpg', 20, 'image/jpeg'),
        ])->assertSessionHas('success');

        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertStringStartsWith('/storage/delivery-proofs/', $delivery->fresh()->proof_image);
        $proofPath = str_replace('/storage/', '', $delivery->fresh()->proof_image);
        $this->assertTrue(Storage::disk('public')->exists($proofPath));
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertSame(0, CommissionLedger::where('order_id', $order->id)->count());

        $this->actingAs($buyer)->post(route('buyer.orders.confirm', $order))->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);

        $this->assertSame([
            'seller_pack',
            'ready_for_pickup',
            'assigned_pickup',
            'picked_up',
            'courier_pickup',
            'arrived_at_origin_hub',
            'in_transit_to_mother_hub',
            'arrived_at_mother_hub',
            'sorted_to_line_haul',
            'in_transit_to_destination_hub',
            'arrived_at_destination_hub',
            'sorted_to_barangay_bin',
            'assigned_to_rider',
            'out_for_delivery',
            'delivered',
            'buyer_completed',
        ], DeliveryCheckpoint::where('delivery_id', $delivery->id)->orderBy('id')->pluck('checkpoint_type')->all());

        $completed = [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(),
            DeliveryCheckpoint::where('delivery_id', $delivery->id)->count()];
        $this->actingAs($buyer)->post(route('checkout.store'), $checkoutPayload)->assertRedirect(route('buyer.orders.index'));
        $this->assertSame($completed, [$order->fresh()->getRawOriginal(), $delivery->fresh()->getRawOriginal(),
            DeliveryCheckpoint::where('delivery_id', $delivery->id)->count()]);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame($startingStock - 1, $product->fresh()->stock);
        $this->assertDatabaseCount('checkout_submissions', 1);

        Storage::disk('public')->delete($proofPath);
    }

    private function scanAndConfirm(
        User $operator,
        $delivery,
        LogisticsHub $hub,
        string $action,
        string $expectedStatus
    ): void {
        if (in_array($action, ['DISPATCH_TO_FEEDER', 'DISPATCH_LINE_HAUL', 'RECEIVE_AT_MOTHER_HUB', 'RECEIVE_AT_DESTINATION_HUB'], true)) {
            $this->assertSame($expectedStatus, $delivery->fresh()->status);
            $this->actingAs($operator)->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number,
                'hub_id' => $hub->id, 'mode' => 'inspect'])->assertOk()->assertJsonPath('prompt.requires_confirmation', false)
                ->assertJsonPath('prompt.manifest_url', '/hub/manifests');
            $this->confirmFlowManifest($delivery, $hub, str_starts_with($action, 'DISPATCH'), $operator);

            return;
        }
        $this->actingAs($operator)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $hub->id,
            'mode' => 'inspect',
        ])->assertOk()
            ->assertJsonPath('prompt.action', $action)
            ->assertJsonPath('prompt.expected_status', $expectedStatus)
            ->assertJsonPath('prompt.requires_confirmation', true);

        $this->confirmScan($operator, $delivery, $hub, $action, $expectedStatus);
    }

    private function confirmScan(
        User $operator,
        $delivery,
        LogisticsHub $hub,
        string $action,
        string $expectedStatus
    ): void {
        $this->actingAs($operator)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number,
            'hub_id' => $hub->id,
            'mode' => 'confirm',
            'action' => $action,
            'expected_status' => $expectedStatus,
        ])->assertOk()->assertJsonPath('confirmed', true);
    }
}
