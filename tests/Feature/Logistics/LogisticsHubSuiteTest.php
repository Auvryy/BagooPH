<?php

namespace Tests\Feature\Logistics;

use App\Models\Category;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Logistics\OrderStateMachineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LogisticsHubSuiteTest extends TestCase
{
    use RefreshDatabase;

    private User $logisticsUser;
    private LogisticsCompany $company;
    private LogisticsHub $motherHub;
    private LogisticsHub $bayanHub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logisticsUser = User::factory()->create([
            'role' => 'logistics',
            'status' => 'active',
            'kyc_status' => 'approved',
        ]);

        $this->company = LogisticsCompany::create([
            'user_id' => $this->logisticsUser->id,
            'name' => 'Bagoo Express Dispatch Fleet',
            'slug' => 'bagoo-express-dispatch-fleet',
            'code' => 'BGX',
            'contact_email' => 'dispatch@bagooph.shop',
            'contact_phone' => '+63 917 888 2246',
            'address' => 'Bagoo Central Dispatch, Pasig City',
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->motherHub = LogisticsHub::create([
            'logistics_company_id' => $this->company->id,
            'name' => 'Laguna Regional Mother Hub',
            'code' => 'MH-LAG-01',
            'tier' => 'regional_mother_hub',
            'province' => 'Laguna',
            'city_municipality' => 'Calamba City',
            'barangay' => 'Real',
            'address' => 'KM 54 National Highway',
            'latitude' => 14.2078,
            'longitude' => 121.1558,
            'capacity' => 15000,
            'coverage_barangays' => ['Calamba Real', 'Turbina'],
            'allows_self_pickup' => false,
            'is_active' => true,
        ]);

        $this->bayanHub = LogisticsHub::create([
            'logistics_company_id' => $this->company->id,
            'name' => 'Santa Cruz Bayan Hub',
            'code' => 'BH-SCZ-01',
            'tier' => 'local_bayan_hub',
            'province' => 'Laguna',
            'city_municipality' => 'Santa Cruz',
            'barangay' => 'Poblacion III',
            'address' => 'Pedro Guevara Avenue',
            'latitude' => 14.2789,
            'longitude' => 121.4172,
            'capacity' => 2500,
            'coverage_barangays' => ['Poblacion I', 'Poblacion II', 'Pagsawitan'],
            'allows_self_pickup' => true,
            'is_active' => true,
        ]);

        LogisticsFleet::create([
            'logistics_company_id' => $this->company->id,
            'hub_id' => $this->bayanHub->id,
            'plate_number' => 'BG-MTR-101',
            'vehicle_type' => 'motorcycle',
            'model' => 'Honda TMX 125',
            'capacity_kg' => 75.00,
            'status' => 'active',
        ]);
    }

    public function test_logistics_operator_can_view_hub_dashboard(): void
    {
        $response = $this->actingAs($this->logisticsUser)
            ->get(route('hub.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Hub/Dashboard')
            ->has('activeHub')
            ->has('hubs')
            ->has('stats')
            ->has('dailyDispatch')
            ->has('recentCheckpoints')
            ->has('hubFleet')
        );
    }

    public function test_hub_index_renders_seller_styled_dashboard(): void
    {
        $response = $this->actingAs($this->logisticsUser)
            ->get(route('hub.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Hub/Dashboard')
        );
    }

    public function test_logistics_operator_can_view_facility_network(): void
    {
        $response = $this->actingAs($this->logisticsUser)
            ->get(route('hub.network'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Hub/Network')
            ->has('hubs', 2)
            ->has('activeHub')
        );
    }

    public function test_logistics_operator_can_view_fleet_management(): void
    {
        $response = $this->actingAs($this->logisticsUser)
            ->get(route('hub.fleet'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Hub/Fleet')
            ->has('fleet')
            ->has('stats')
            ->has('filters')
        );
    }

    public function test_logistics_operator_can_view_deliveries_registry(): void
    {
        $response = $this->actingAs($this->logisticsUser)
            ->get(route('hub.deliveries'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Hub/Deliveries')
            ->has('deliveries')
            ->has('counts')
            ->has('filters')
        );
    }

    public function test_logistics_operator_can_view_counter_pickup_screen(): void
    {
        $response = $this->actingAs($this->logisticsUser)
            ->get(route('hub.counter'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Hub/CounterPickup')
            ->has('counterParcels')
            ->has('recentlyCollected')
        );
    }

    public function test_logistics_operator_can_switch_active_hub_facility(): void
    {
        $response = $this->actingAs($this->logisticsUser)
            ->post(route('hub.switchHub'), [
                'hub_id' => $this->motherHub->id,
            ]);

        $response->assertRedirect();
        $this->assertEquals($this->motherHub->id, session('active_hub_id'));
    }

    public function test_unauthorized_buyer_cannot_access_hub_dashboard(): void
    {
        $buyer = User::factory()->create([
            'role' => 'buyer',
            'status' => 'active',
        ]);

        $response = $this->actingAs($buyer)
            ->get(route('hub.dashboard'));

        $response->assertForbidden();
    }

    public function test_counter_pickup_release_successfully_hands_over_parcel(): void
    {
        $seller = User::factory()->create(['role' => 'seller', 'status' => 'active']);
        $shop = Shop::create([
            'user_id' => $seller->id,
            'name' => 'Laguna Leathercrafts',
            'slug' => 'laguna-leathercrafts',
            'status' => 'active',
        ]);

        $category = Category::create([
            'name' => 'Apparel',
            'slug' => 'apparel',
            'is_active' => true,
        ]);

        $product = Product::create([
            'shop_id' => $shop->id,
            'category_id' => $category->id,
            'name' => 'Artisan Messenger Bag',
            'slug' => 'artisan-messenger-bag',
            'price' => 1850.00,
            'stock' => 20,
            'status' => 'active',
        ]);

        $buyer = User::factory()->create(['role' => 'buyer', 'status' => 'active', 'name' => 'Juan Dela Cruz']);

        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'order_number' => 'ORD-TEST-COUNTER-01',
            'status' => 'shipped',
            'subtotal' => 1850.00,
            'total_amount' => 1850.00,
            'delivery_type' => 'hub_self_pickup',
            'shipping_fee' => 0.00,
            'shipping_city' => 'Santa Cruz',
            'destination_barangay' => 'Poblacion III',
            'recipient_name' => 'Juan Dela Cruz',
            'payment_method' => 'cod',
            'payment_status' => 'pending',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'shop_id' => $shop->id,
            'quantity' => 1,
            'unit_price' => 1850.00,
            'subtotal' => 1850.00,
        ]);

        $delivery = Delivery::factory()->create([
            'order_id' => $order->id,
            'tracking_number' => 'BGX-TEST-PICKUP-01',
            'status' => OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
            'delivery_type' => 'hub_self_pickup',
            'current_hub_id' => $this->bayanHub->id,
            'destination_bayan_hub_id' => $this->bayanHub->id,
            'destination_bin' => 'SHELF-A1',
        ]);

        $response = $this->actingAs($this->logisticsUser)
            ->post(route('hub.release'), [
                'barcode' => $delivery->tracking_number,
                'recipient_name' => 'Juan Dela Cruz',
                'hub_id' => $this->bayanHub->id,
                'notes' => 'Govt ID verified',
            ]);

        $response->assertRedirect();
        $this->assertEquals(
            OrderStateMachineService::STATUS_CUSTOMER_COLLECTED,
            $delivery->fresh()->status
        );
    }
}
