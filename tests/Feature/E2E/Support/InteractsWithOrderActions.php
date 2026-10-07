<?php

namespace Tests\Feature\E2E\Support;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Services\AccountRestrictionService;
use App\Services\Logistics\DeliveryReturnService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithCheckoutSubmission;

/** Initial eligibility is a fixture; order and custody evidence must come from real actions. */
trait InteractsWithOrderActions
{
    use InteractsWithCheckoutSubmission;

    private ?LogisticsCompany $flowCompany = null;

    private array $flowHandlers = [];

    private array $flowRiders = [];

    private bool $flowStorageFaked = false;

    private bool $flowAttemptStorageFaked = false;

    public function checkoutFlowOrder(User $buyer, Shop $shop, array $items = [], string $stage = 'placed', array $shipping = []): Order
    {
        $this->assertContains($stage, ['placed', 'confirmed', 'preparing', 'ready_for_pickup']);
        $items = $items ?: [['product' => $this->createE2EProduct($shop), 'quantity' => 2]];
        $orders = $this->checkoutFlowOrders($buyer, $items, $shipping);
        $this->assertCount(1, $orders);
        $order = $orders->first();
        $this->sellerFlowStage($order, $stage);

        return $order->fresh(['items.product', 'delivery', 'buyer']);
    }

    public function checkoutFlowOrders(User $buyer, array $items, array $shipping = []): Collection
    {
        $payload = $this->flowCheckoutPayload($buyer, $items, $shipping);
        $stocks = [];
        foreach ($items as $item) {
            $product = $item['product'];
            $stocks[$product->id] ??= [$product, $product->fresh()->stock];
            $stocks[$product->id][1] -= $item['quantity'] ?? 1;
        }
        $before = Order::pluck('id');
        $this->actingAs($buyer)->post(route('checkout.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirect(route('buyer.orders.index'));
        $orders = Order::whereNotIn('id', $before)->with('items.product', 'delivery', 'buyer')->get();
        $this->assertCount(collect($items)->pluck('product.shop_id')->unique()->count(), $orders);
        foreach ($orders as $order) {
            $this->assertSame('placed', $order->status);
            $this->assertSame('pending', $order->payment_status);
            $this->assertNotNull($order->delivery->origin_mother_hub_id);
            $this->assertNotNull($order->delivery->destination_mother_hub_id);
        }
        foreach ($stocks as [$product, $expected]) {
            $this->assertSame($expected, $product->fresh()->stock);
        }
        $this->assertSame(0, CartItem::whereIn('id', $payload['item_ids'])->count());

        return $orders;
    }

    public function newFlowOrder(string $stage = 'placed', array $shipping = []): Order
    {
        $seller = $this->createApprovedUser('seller');

        return $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $this->createE2EShop($seller), [], $stage, $shipping);
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
        $shop = $order->items->first()->product->shop;
        $seller = $shop->user;
        foreach (['confirmed' => 'accept', 'preparing' => 'pack', 'ready_for_pickup' => 'ready'] as $target => $action) {
            if ($order->fresh()->status === $stage) {
                return;
            }
            $this->actingAs($seller)->withSession(['active_seller_shop_id' => $shop->id])->post(route('seller.orders.'.$action, $order))
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
        if (in_array($action, ['DISPATCH_TO_FEEDER', 'DISPATCH_LINE_HAUL', 'RECEIVE_AT_MOTHER_HUB', 'RECEIVE_AT_DESTINATION_HUB'], true)) {
            $this->confirmFlowManifest($delivery, $hub, str_starts_with($action, 'DISPATCH'));

            return;
        }
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

    public function confirmFlowManifest(Delivery $delivery, LogisticsHub $hub, bool $departure, ?User $handler = null, string $direction = 'outbound', string $base = '/hub'): void
    {
        $delivery->refresh();
        $company = LogisticsCompany::findOrFail($delivery->logistics_company_id);
        $manager = User::findOrFail($company->user_id);
        $handler ??= $this->flowHandler($hub);
        if ($departure) {
            $this->assertSame($hub->id, $delivery->current_hub_id);
            $destinationId = $direction === 'return' ? app(DeliveryReturnService::class)->nextHub($delivery) : ($delivery->status === 'arrived_at_origin_hub' ? $delivery->origin_mother_hub_id
                : ($hub->id === $delivery->origin_mother_hub_id && $delivery->origin_mother_hub_id !== $delivery->destination_mother_hub_id
                    ? $delivery->destination_mother_hub_id : $delivery->destination_bayan_hub_id));
            $driver = $this->createApprovedUser('courier');
            $vehicle = LogisticsFleet::create(['logistics_company_id' => $company->id, 'hub_id' => $hub->id,
                'plate_number' => 'FLOW-'.str_pad((string) (LogisticsFleet::count() + 1), 4, '0', STR_PAD_LEFT),
                'vehicle_type' => $hub->isMotherHub() && LogisticsHub::findOrFail($destinationId)->isMotherHub() ? 'wing_truck' : 'l300_van',
                'model' => 'Bagoo Transport Vehicle', 'capacity_kg' => 10000,
                'assigned_driver_id' => $driver->id, 'status' => 'active']);
            $driver->courierProfile->update(['logistics_company_id' => $company->id, 'assigned_hub_id' => $hub->id,
                'vehicle_id' => $vehicle->id, 'is_available' => true]);
            $this->actingAs($manager)->get($base.'/manifests')->assertOk();
            $response = $this->postJson($base.'/manifests', ['source_hub_id' => $hub->id, 'destination_hub_id' => $destinationId,
                'direction' => $direction, 'vehicle_id' => $vehicle->id, 'creation_token' => session()->get('manifest_creation_token')])->assertCreated();
            $manifest = LogisticsManifest::findOrFail($response->json('manifest.id'));
            $this->actingAs($handler)->postJson($base.'/manifests/'.$manifest->id.'/load', $this->flowManifestPayload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
            $this->actingAs($manager)->postJson($base.'/manifests/'.$manifest->id.'/seal', $this->flowManifestPayload($manifest))->assertOk();
            $this->actingAs($handler)->postJson($base.'/manifests/'.$manifest->id.'/dispatch', $this->flowManifestPayload($manifest))->assertOk();
        } else {
            $custody = DeliveryCheckpoint::lastCustody($delivery);
            $this->assertSame('manifest', $custody['kind']);
            $manifest = LogisticsManifest::findOrFail($custody['manifest_id']);
            $this->assertSame($hub->id, $manifest->destination_hub_id);
            $this->actingAs($handler)->postJson($base.'/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest, ['barcode' => $delivery->tracking_number]))->assertOk();
            $this->actingAs($manager)->postJson($base.'/manifests/'.$manifest->id.'/close', $this->flowManifestPayload($manifest))->assertOk();
            $this->actingAs($handler)->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number,
                'hub_id' => $hub->id, 'mode' => 'inspect'])->assertOk();
        }
        $delivery->refresh();
    }

    public function flowManifestPayload(LogisticsManifest $manifest, array $fields = []): array
    {
        return ['version' => $manifest->fresh()->version, 'request_token' => (string) Str::uuid(), ...$fields];
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
        $this->actingAs($pickup)->patch(route('courier.updateStatus', $delivery), ['status' => 'picked_up', 'barcode' => $delivery->tracking_number])
            ->assertSessionHas('success');
        if ($stage === 'picked_up') {
            return $delivery->fresh();
        }
        foreach ([
            ['arrived_at_origin_hub', $origin, 'RECEIVE_FROM_PICKUP_RIDER'],
            ['in_transit_to_mother_hub', $origin, 'DISPATCH_TO_FEEDER'],
            ['arrived_at_mother_hub', $mother, 'RECEIVE_AT_MOTHER_HUB'],
            ['sorted_to_line_haul', $mother, 'SORT_TO_LINE_HAUL'],
        ] as [$target, $hub, $action]) {
            $this->confirmFlowScan($delivery, $hub, $action);
            $this->assertSame($target, $delivery->fresh()->status);
            if ($stage === $target) {
                return $delivery->fresh();
            }
        }
        if ($delivery->origin_mother_hub_id !== $delivery->destination_mother_hub_id) {
            $this->confirmFlowManifest($delivery, $mother, true);
            $mother = LogisticsHub::findOrFail($delivery->destination_mother_hub_id);
            $this->confirmFlowManifest($delivery, $mother, false);
            $this->confirmFlowScan($delivery, $mother, 'SORT_TO_LINE_HAUL');
        }
        $this->confirmFlowManifest($delivery, $mother, true);
        $this->assertSame('in_transit_to_destination_hub', $delivery->fresh()->status);
        if ($stage === 'in_transit_to_destination_hub') {
            return $delivery->fresh();
        }
        $this->confirmFlowManifest($delivery, $destination, false);
        $this->assertSame('arrived_at_destination_hub', $delivery->fresh()->status);
        if ($stage === 'arrived_at_destination_hub') {
            return $delivery->fresh();
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
        $this->actingAs($final)->patch(route('courier.updateStatus', $delivery), ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])
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

    public function restrictFlowAccount(User $subject, string $action): void
    {
        $this->assertContains($action, ['suspend', 'deactivate', 'reactivate']);
        $role = $subject->role;
        $service = app(AccountRestrictionService::class);
        $this->actingAs($this->createApprovedUser('admin'))->postJson(route('admin.users.activity.store', $subject), [
            'action' => $action, 'reason' => 'Review the current account responsibilities.', 'affected_work_confirmed' => true,
            'source_token' => $service->token($service->state($subject->fresh())),
        ])->assertOk();
        $this->assertSame(match ($action) {
            'suspend' => 'suspended', 'deactivate' => 'inactive', 'reactivate' => 'active',
        }, $subject->fresh()->status);
        $this->assertSame($role, $subject->fresh()->role);
    }

    public function reportFlowFailure(Delivery $delivery, string $reason = 'Customer unreachable'): void
    {
        $code = match ($reason) {
            'Customer unreachable' => 'customer_unreachable', 'Customer refused' => 'customer_refused',
            default => throw new \LogicException('Use a documented reason in the actual failure request.'),
        };
        $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch(route('courier.updateStatus', $delivery), $this->flowFailurePayload($delivery, $code))
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('delivery_failed', $delivery->fresh()->status);
        $this->assertSame('delivery_failed', $delivery->order->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->failure_attempts);
        $this->assertSame($code, $delivery->fresh()->failure_reason);
        $this->assertCheckpointLogged($delivery, 'delivery_failed');
    }

    public function flowFailurePayload(Delivery $delivery, string $reason = 'customer_unreachable'): array
    {
        if (! $this->flowAttemptStorageFaked) {
            Storage::fake('local');
            $this->flowAttemptStorageFaked = true;
        }

        return ['status' => 'delivery_failed', 'failure_reason' => $reason, 'barcode' => $delivery->tracking_number,
            'location_name' => 'At the saved buyer address', 'request_token' => (string) Str::uuid(),
            'proof_image_file' => UploadedFile::fake()->image('attempt.jpg'),
            'courier_notes' => 'The rider called at the address and could not hand over the parcel.'];
    }

    public function receiveFailureFlow(Delivery $delivery): void
    {
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $inspection = $this->actingAs($handler)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect',
        ])->assertOk()->assertJsonPath('prompt.requires_confirmation', true);
        // Use the server's actual action, never manufacture a future return-scan code.
        $prompt = $inspection->json('prompt');
        $this->actingAs($handler)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => $prompt['action'], 'expected_status' => $prompt['expected_status'],
            'attempt_reference' => $prompt['attempt_reference'] ?? null,
        ])->assertOk()->assertJsonPath('confirmed', true);
        $this->assertSame($hub->id, $delivery->fresh()->current_hub_id);
        $this->assertSame('delivery_failed', $delivery->order->fresh()->status);
    }

    public function returnToSellerFlow(Delivery $delivery): void
    {
        $returns = app(DeliveryReturnService::class);
        $route = $returns->route($delivery->fresh());
        foreach (array_slice($route->hub_ids, 1) as $hubId) {
            $this->confirmFlowManifest($delivery, LogisticsHub::findOrFail($delivery->fresh()->current_hub_id), true, direction: 'return');
            $this->assertSame('delivery_failed', $delivery->order->fresh()->status);
            $this->confirmFlowManifest($delivery, LogisticsHub::findOrFail($hubId), false, direction: 'return');
        }
        $origin = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($origin));
        $prompt = $this->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number, 'hub_id' => $origin->id, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('prompt.action', 'STAGE_SELLER_RETURN')->json('prompt');
        $this->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number, 'hub_id' => $origin->id, 'mode' => 'confirm',
            'action' => $prompt['action'], 'expected_status' => $prompt['expected_status'], 'route_reference' => $prompt['route_reference']])->assertOk();
        $seller = $delivery->order->shop->user;
        $receipt = ['barcode' => $delivery->tracking_number, 'route_reference' => $route->reference,
            'notes' => 'I received the original parcel from the origin hub.', 'request_token' => (string) Str::uuid()];
        $this->actingAs($seller)->withSession(['active_seller_shop_id' => $delivery->order->shop->id])
            ->postJson(route('seller.orders.return-receipt', $delivery->order), $receipt)->assertOk()->assertJsonPath('status', 'returned');
        $count = $delivery->checkpoints()->count();
        $this->postJson(route('seller.orders.return-receipt', $delivery->order), $receipt)->assertOk();
        $this->assertSame($count, $delivery->checkpoints()->count());
        $delivery->refresh();
    }

    public function retryFailureFlow(Delivery $delivery): void
    {
        $delivery->refresh();
        $attempt = DeliveryAttempt::where('delivery_id', $delivery->id)->latest('attempt_number')->firstOrFail();
        $rider = User::findOrFail($attempt->rider_id);
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $this->actingAs($hub->company->user)->post(route('hub.recovery.retry', $delivery), [
            'retry_at' => now('Asia/Manila')->addMinutes(10)->format('Y-m-d\TH:i'),
            'notes' => 'The buyer confirmed availability at the same delivery address.',
            'request_token' => (string) Str::uuid(), 'attempt_reference' => $attempt->reference,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->travel(11)->minutes();
        $inspection = $this->actingAs($handler)->postJson(route('hub.scan'), [
            'barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect',
        ])->assertOk()->assertJsonPath('prompt.action', 'RELEASE_APPROVED_RETRY');
        $prompt = $inspection->json('prompt');
        $this->postJson(route('hub.scan'), ['barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => $prompt['action'], 'expected_status' => $prompt['expected_status'], 'attempt_reference' => $prompt['attempt_reference']])
            ->assertOk()->assertJsonPath('confirmed', true);
        $this->postJson(route('hub.sort'), ['delivery_id' => $delivery->id, 'barangay' => $delivery->order->destination_barangay])->assertOk();
        $this->postJson(route('hub.assignRider', $delivery), ['rider_id' => $rider->id])->assertOk();
        $this->actingAs($rider)->patch(route('courier.updateStatus', $delivery), [
            'status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('out_for_delivery', $delivery->fresh()->status);
    }
}
