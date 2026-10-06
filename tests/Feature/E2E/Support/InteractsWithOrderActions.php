<?php

namespace Tests\Feature\E2E\Support;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Delivery;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithCheckoutSubmission;

/** Initial eligibility is a fixture; order and custody evidence must come from real actions. */
trait InteractsWithOrderActions
{
    use InteractsWithCheckoutSubmission;

    private ?LogisticsCompany $flowCompany = null;

    private array $flowHandlers = [];

    private array $flowRiders = [];

    private bool $flowStorageFaked = false;

    public function checkoutFlowOrder(User $buyer, Shop $shop, array $items = [], string $stage = 'placed', array $shipping = []): Order
    {
        $this->assertContains($stage, ['placed', 'confirmed', 'preparing', 'ready_for_pickup']);
        $items = $items ?: [['product' => $this->createE2EProduct($shop), 'quantity' => 2]];
        $payload = $this->flowCheckoutPayload($buyer, $items, $shipping);
        $stocks = [];
        foreach ($items as $item) {
            $product = $item['product'];
            $stocks[$product->id] = [$product, $product->stock - ($item['quantity'] ?? 1)];
        }
        $before = Order::pluck('id');
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));
        $orders = Order::whereNotIn('id', $before)->get();
        $this->assertCount(1, $orders);
        $order = $orders->first()->load('items.product', 'delivery', 'buyer');
        $this->assertSame('placed', $order->status);
        $this->assertSame('pending', $order->payment_status);
        foreach ($stocks as [$product, $expected]) {
            $this->assertSame($expected, $product->fresh()->stock);
        }
        $this->assertSame(0, CartItem::whereIn('id', $payload['item_ids'])->count());
        $this->assertNotNull($order->delivery->origin_mother_hub_id);
        $this->assertNotNull($order->delivery->destination_mother_hub_id);
        $this->sellerFlowStage($order, $stage);

        return $order->fresh(['items.product', 'delivery', 'buyer']);
    }

    public function flowCheckoutPayload(User $buyer, array $items, array $shipping = []): array
    {
        $cart = Cart::firstOrCreate(['user_id' => $buyer->id]);
        $ids = [];
        foreach ($items as $item) {
            $product = $item['product'];
            $this->flowNetwork($product->shop, $shipping['shipping_city'] ?? 'Santa Cruz');
            $ids[] = CartItem::create([
                'cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => $item['quantity'] ?? 1,
                'unit_price' => $product->price, 'color' => $item['color'] ?? null, 'size' => $item['size'] ?? null,
            ])->id;
        }

        return array_replace([
            'checkout_token' => $this->checkoutToken($buyer, $cart), 'item_ids' => $ids,
            'recipient_name' => $buyer->name, 'recipient_phone' => '09171234567',
            'shipping_address' => '100 Market Road', 'shipping_city' => 'Santa Cruz',
            'shipping_province' => 'Laguna', 'shipping_postal_code' => '4009',
            'destination_barangay' => 'Poblacion III', 'delivery_type' => 'doorstep', 'payment_method' => 'cod',
        ], $shipping);
    }

    public function sellerFlowStage(Order $order, string $stage): void
    {
        $seller = $order->items->first()->product->shop->user;
        foreach (['confirmed' => 'accept', 'preparing' => 'pack', 'ready_for_pickup' => 'ready'] as $target => $action) {
            if ($order->fresh()->status === $stage) {
                return;
            }
            $this->actingAs($seller)->post(route('seller.orders.'.$action, $order))
                ->assertSessionHasNoErrors()->assertSessionHas('success');
            $this->assertSame($target, $order->fresh()->status);
        }
        $this->assertSame($stage, $order->fresh()->status);
    }

    public function flowNetwork(Shop $shop, string $destination = 'Santa Cruz'): void
    {
        if (! $this->flowCompany) {
            $owner = $this->createApprovedUser('logistics');
            $this->flowCompany = LogisticsCompany::create([
                'user_id' => $owner->id, 'name' => 'Bagoo Acceptance Network',
                'slug' => 'bagoo-acceptance', 'code' => 'ACCEPTANCE', 'status' => 'active', 'is_active' => true,
            ]);
        }
        $company = $this->flowCompany;
        LogisticsHub::firstOrCreate(['logistics_company_id' => $company->id, 'code' => 'FLOW-MH'], [
            'name' => 'Laguna Mother Hub', 'tier' => 'regional_mother_hub', 'province' => 'Laguna',
            'city_municipality' => 'Santa Cruz', 'address' => '100 Mother Hub Road', 'is_active' => true,
        ]);
        foreach (array_unique([$shop->city, $destination]) as $city) {
            LogisticsHub::firstOrCreate([
                'logistics_company_id' => $company->id, 'tier' => 'local_bayan_hub', 'city_municipality' => $city,
            ], ['name' => $city.' Bayan Hub', 'code' => 'FLOW-BH-'.LogisticsHub::count(),
                'province' => 'Laguna', 'address' => '100 Bayan Hub Road', 'is_active' => true]);
        }
    }

    public function flowHandler(LogisticsHub $hub): User
    {
        if (! isset($this->flowHandlers[$hub->id])) {
            $handler = $this->createApprovedUser('logistics');
            HubHandler::create(['user_id' => $handler->id, 'logistics_company_id' => $hub->logistics_company_id,
                'hub_id' => $hub->id, 'role_title' => 'Hub Handler', 'is_active' => true]);
            $this->flowHandlers[$hub->id] = $handler;
        }

        return $this->flowHandlers[$hub->id];
    }

    public function flowRider(LogisticsHub $hub, ?User $rider = null): User
    {
        if (! $rider && isset($this->flowRiders[$hub->id])) {
            return $this->flowRiders[$hub->id];
        }
        $rider ??= $this->createApprovedUser('courier');
        $profile = $rider->courierProfile;
        if ($profile->assigned_hub_id !== null) {
            $this->assertSame($hub->id, $profile->assigned_hub_id, 'Never relocate an existing rider to manufacture custody.');
        } else {
            $profile->update(['logistics_company_id' => $hub->logistics_company_id, 'assigned_hub_id' => $hub->id,
                'assigned_barangay' => 'Poblacion III', 'vehicle_id' => null]);
        }
        $this->assertTrue($rider->fresh()->courierProfile()->operational()->exists());
        $this->flowRiders[$hub->id] = $rider;

        return $rider->fresh();
    }

    public function confirmFlowScan(Delivery $delivery, LogisticsHub $hub, string $action): void
    {
        $handler = $this->flowHandler($hub);
        $source = $delivery->fresh()->status;
        $this->actingAs($handler)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect',
        ])->assertOk()->assertJsonPath('prompt.action', $action)
            ->assertJsonPath('prompt.expected_status', $source)->assertJsonPath('prompt.requires_confirmation', true);
        $this->actingAs($handler)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => $action, 'expected_status' => $source,
        ])->assertOk()->assertJsonPath('confirmed', true);
        $delivery->refresh();
    }

    public function flowDelivery(Order $order, string $stage = 'unassigned', ?User $rider = null): Delivery
    {
        $delivery = $order->fresh()->delivery;
        if ($stage === 'unassigned') {
            $this->assertSame('unassigned', $delivery->status);

            return $delivery;
        }
        $this->sellerFlowStage($order, 'ready_for_pickup');
        $origin = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $mother = LogisticsHub::findOrFail($delivery->origin_mother_hub_id);
        $destination = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $pickup = $this->flowRider($origin, in_array($stage, ['assigned_pickup', 'picked_up'], true) ? $rider : null);
        $this->actingAs($pickup)->post(route('courier.claim', $delivery))->assertSessionHas('success');
        $this->assertSame('assigned_pickup', $delivery->fresh()->status);
        if ($stage === 'assigned_pickup') {
            return $delivery->fresh();
        }
        $this->actingAs($pickup)->patch(route('courier.updateStatus', $delivery), ['status' => 'picked_up'])
            ->assertSessionHas('success');
        if ($stage === 'picked_up') {
            return $delivery->fresh();
        }
        foreach ([
            ['arrived_at_origin_hub', $origin, 'RECEIVE_FROM_PICKUP_RIDER'],
            ['in_transit_to_mother_hub', $origin, 'DISPATCH_TO_FEEDER'],
            ['arrived_at_mother_hub', $mother, 'RECEIVE_AT_MOTHER_HUB'],
            ['sorted_to_line_haul', $mother, 'SORT_TO_LINE_HAUL'],
            ['in_transit_to_destination_hub', $mother, 'DISPATCH_LINE_HAUL'],
            ['arrived_at_destination_hub', $destination, 'RECEIVE_AT_DESTINATION_HUB'],
        ] as [$target, $hub, $action]) {
            $this->confirmFlowScan($delivery, $hub, $action);
            $this->assertSame($target, $delivery->fresh()->status);
            if ($stage === $target) {
                return $delivery->fresh();
            }
        }
        $handler = $this->flowHandler($destination);
        $this->actingAs($handler)->postJson(route('hub.sort'), ['delivery_id' => $delivery->id,
            'barangay' => $order->destination_barangay])->assertOk();
        $this->assertSame('sorted_to_barangay_bin', $delivery->fresh()->status);
        if ($stage === 'sorted_to_barangay_bin') {
            return $delivery->fresh();
        }
        $final = $this->flowRider($destination, $rider);
        $this->actingAs($handler)->postJson(route('hub.assignRider', $delivery), ['rider_id' => $final->id])->assertOk();
        $this->assertSame('assigned_to_rider', $delivery->fresh()->status);
        if ($stage === 'assigned_to_rider') {
            return $delivery->fresh();
        }
        $this->actingAs($final)->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery'])
            ->assertSessionHas('success');
        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
        if ($stage === 'out_for_delivery') {
            return $delivery->fresh();
        }
        $this->assertSame('delivered', $stage, 'Unknown flow stage must never fabricate evidence.');
        if (! $this->flowStorageFaked) {
            Storage::fake('public');
            $this->flowStorageFaked = true;
        }
        $this->actingAs($final)->patch(route('courier.updateStatus', $delivery), [
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->create('proof.jpg', 20, 'image/jpeg'),
        ])->assertSessionHas('success');
        $delivery->refresh();
        $this->assertSame('delivered', $delivery->status);
        $this->assertTrue(Storage::disk('public')->exists(substr($delivery->proof_image, strlen('/storage/'))));
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);

        return $delivery;
    }

    public function completeFlowOrder(Order $order): void
    {
        $this->actingAs($order->buyer)->post(route('buyer.orders.confirm', $order))
            ->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->delivery->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }
}
