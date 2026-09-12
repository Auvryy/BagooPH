<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected User $buyer;
    protected User $seller;
    protected User $courier;
    protected User $hubOperator;
    protected Shop $shop;
    protected Product $product;
    protected Order $order;
    protected Delivery $delivery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create([
            'role' => 'buyer',
            'name' => 'Juan Dela Cruz',
            'phone' => '+63 917 123 4567',
        ]);

        $this->seller = User::factory()->create([
            'role' => 'seller',
            'status' => 'approved',
            'kyc_status' => 'approved',
        ]);

        $this->courier = User::factory()->create([
            'role' => 'courier',
            'status' => 'approved',
            'kyc_status' => 'approved',
            'name' => 'Rider Carlos',
        ]);

        $this->hubOperator = User::factory()->create([
            'role' => 'logistics',
            'status' => 'approved',
            'kyc_status' => 'approved',
            'name' => 'Hub Operator Mike',
        ]);

        $this->shop = Shop::create([
            'user_id' => $this->seller->id,
            'name' => 'Artisan Leather Studio',
            'slug' => 'artisan-leather-studio',
            'description' => 'Handcrafted goods',
            'address' => '123 Artisan Hub',
            'city' => 'Pasig City',
            'phone' => '+63 912 345 6789',
        ]);

        $this->product = Product::create([
            'shop_id' => $this->shop->id,
            'name' => 'Handmade Leather Tote',
            'slug' => 'handmade-leather-tote',
            'description' => 'Genuine full-grain leather tote bag',
            'price' => 2500.00,
            'stock' => 20,
            'is_active' => true,
        ]);

        $this->order = Order::create([
            'order_number' => 'BGO-TRACK-TEST-001',
            'buyer_id' => $this->buyer->id,
            'total_amount' => 2560.00,
            'subtotal' => 2500.00,
            'shipping_fee' => 60.00,
            'status' => 'preparing',
            'payment_status' => 'unpaid',
            'payment_method' => 'cod',
            'shipping_address' => 'Unit 502, Pioneer Woodlands Tower 1, EDSA',
            'shipping_city' => 'Mandaluyong City',
            'recipient_name' => 'Juan Dela Cruz',
            'recipient_phone' => '+63 917 123 4567',
        ]);

        OrderItem::create([
            'order_id' => $this->order->id,
            'product_id' => $this->product->id,
            'shop_id' => $this->shop->id,
            'quantity' => 1,
            'unit_price' => 2500.00,
            'subtotal' => 2500.00,
        ]);

        $this->delivery = Delivery::create([
            'order_id' => $this->order->id,
            'tracking_number' => 'BGO-TRK-749210',
            'status' => 'ready_for_pickup',
            'pickup_store_name' => 'Artisan Leather Studio',
            'pickup_address' => '123 Artisan Hub, Pasig City',
            'delivery_address' => 'Unit 502, Pioneer Woodlands Tower 1, EDSA, Mandaluyong City, Metro Manila',
            'delivery_recipient_name' => 'Juan Dela Cruz',
            'delivery_phone' => '+63 917 123 4567',
            'estimated_delivery_at' => now()->addDays(2),
        ]);

        DeliveryCheckpoint::record(
            $this->delivery,
            'order_placed',
            'Online Platform',
            'Order placed and payment verified via COD',
            $this->buyer
        );

        DeliveryCheckpoint::record(
            $this->delivery,
            'seller_pack',
            'Artisan Leather Studio',
            'Parcel packed and thermal waybill printed',
            $this->seller
        );
    }

    public function test_guest_can_access_universal_tracking_page(): void
    {
        $response = $this->get('/track/' . $this->delivery->tracking_number);

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Public/Tracking')
            ->has('parcel')
            ->where('parcel.tracking_number', 'BGO-TRK-749210')
            ->where('parcel.status', 'ready_for_pickup')
            ->has('parcel.checkpoints', 2)
        );
    }

    public function test_sensitive_buyer_information_is_masked_for_public_guest_view(): void
    {
        $response = $this->get('/track/' . $this->delivery->tracking_number);

        $response->assertStatus(200);
        $response->assertInertia(function ($page) {
            $parcel = $page->toArray()['props']['parcel'];
            // Phone number must not expose complete digits
            $this->assertStringContainsString('•••', $parcel['delivery_phone']);
            $this->assertStringNotContainsString('+63 917 123 4567', $parcel['delivery_phone']);
            // Address must conceal exact building and unit number
            $this->assertStringContainsString('Protected Location', $parcel['delivery_address']);
            $this->assertStringNotContainsString('Unit 502', $parcel['delivery_address']);
            return true;
        });
    }

    public function test_empty_or_not_found_tracking_number_returns_clean_search_state(): void
    {
        $response = $this->get('/track');
        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Public/Tracking')
            ->where('parcel', null)
            ->where('notFound', false)
        );

        $notFoundResponse = $this->get('/track/BGO-INVALID-CODE-999');
        $notFoundResponse->assertStatus(200);
        $notFoundResponse->assertInertia(fn ($page) => $page
            ->component('Public/Tracking')
            ->where('parcel', null)
            ->where('notFound', true)
        );
    }

    public function test_api_track_endpoint_returns_json_for_flutter_mobile_apps(): void
    {
        $response = $this->getJson('/api/track/' . $this->delivery->tracking_number);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'parcel' => [
                'id',
                'tracking_number',
                'status',
                'order_number',
                'payment_method',
                'pickup_store_name',
                'delivery_recipient_name',
                'delivery_address',
                'checkpoints' => [
                    '*' => [
                        'id',
                        'checkpoint_type',
                        'location_name',
                        'notes',
                        'created_at',
                    ],
                ],
                'items' => [
                    '*' => [
                        'id',
                        'product_name',
                        'quantity',
                    ],
                ],
            ],
        ]);
        $this->assertTrue($response->json('success'));
        $this->assertEquals('BGO-TRK-749210', $response->json('parcel.tracking_number'));
    }

    public function test_authenticated_courier_can_execute_pickup_transition(): void
    {
        $response = $this->actingAs($this->courier)
            ->post('/track/' . $this->delivery->tracking_number . '/action', [
                'action' => 'courier_pickup',
                'location_name' => 'Artisan Leather Studio',
                'notes' => 'Courier scanned parcel QR code at merchant shop',
            ]);

        $response->assertRedirect();
        $this->delivery->refresh();
        $this->assertEquals('picked_up', $this->delivery->status);
        $this->assertEquals('shipped', $this->delivery->order->status);
        $this->assertNotNull($this->delivery->picked_up_at);

        $this->assertDatabaseHas('delivery_checkpoints', [
            'delivery_id' => $this->delivery->id,
            'checkpoint_type' => 'courier_pickup',
            'scanned_by_id' => $this->courier->id,
        ]);
    }

    public function test_authenticated_courier_can_execute_doorstep_delivery(): void
    {
        $this->delivery->update([
            'status' => 'out_for_delivery',
            'courier_id' => $this->courier->id,
        ]);

        $response = $this->actingAs($this->courier)
            ->post('/track/' . $this->delivery->tracking_number . '/action', [
                'action' => 'delivered',
                'notes' => 'Handed parcel to customer at doorstep',
            ]);

        $response->assertRedirect();
        $this->delivery->refresh();
        $this->assertEquals('delivered', $this->delivery->status);
        $this->assertEquals('delivered', $this->delivery->order->status);
        $this->assertEquals('paid', $this->delivery->order->payment_status);
        $this->assertNotNull($this->delivery->delivered_at);

        $this->assertDatabaseHas('delivery_checkpoints', [
            'delivery_id' => $this->delivery->id,
            'checkpoint_type' => 'doorstep_handover',
            'scanned_by_id' => $this->courier->id,
        ]);

        $this->assertDatabaseHas('commission_ledgers', [
            'order_id' => $this->order->id,
            'seller_id' => $this->seller->id,
            'courier_id' => $this->courier->id,
            'status' => 'settled',
        ]);
    }

    public function test_authenticated_hub_operator_can_execute_intake_scan(): void
    {
        $this->delivery->update([
            'status' => 'picked_up',
            'courier_id' => $this->courier->id,
        ]);

        $response = $this->actingAs($this->hubOperator)
            ->post('/track/' . $this->delivery->tracking_number . '/action', [
                'action' => 'hub_intake',
                'location_name' => 'Metro Manila Central Sorting Station',
                'notes' => 'Arrived via courier shuttle van',
            ]);

        $response->assertRedirect();
        $this->delivery->refresh();
        $this->assertEquals('in_transit', $this->delivery->status);

        $this->assertDatabaseHas('delivery_checkpoints', [
            'delivery_id' => $this->delivery->id,
            'checkpoint_type' => 'hub_intake',
            'scanned_by_id' => $this->hubOperator->id,
        ]);
    }

    public function test_unauthenticated_guest_cannot_execute_staff_action(): void
    {
        $response = $this->post('/track/' . $this->delivery->tracking_number . '/action', [
            'action' => 'courier_pickup',
        ]);

        // Redirect to login because of auth middleware
        $response->assertRedirect('/login');
    }

    public function test_unauthorized_buyer_cannot_execute_courier_pickup(): void
    {
        $response = $this->actingAs($this->buyer)
            ->post('/track/' . $this->delivery->tracking_number . '/action', [
                'action' => 'courier_pickup',
            ]);

        $response->assertStatus(403);
    }
}
