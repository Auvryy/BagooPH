<?php

namespace Tests\Feature\Logistics;

use App\Models\CartItem;
use App\Models\Delivery;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Logistics\LogisticsRateService;
use App\Services\Logistics\LogisticsRoutingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Support\RoutingCheckoutSeeder;
use Tests\TestCase;

class NewEraRoutingRateTest extends TestCase
{
    use RefreshDatabase;

    private string $testStoragePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testStoragePath = sys_get_temp_dir().'/bagoo-route-tests-'.bin2hex(random_bytes(6));
        app()->useStoragePath($this->testStoragePath);
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testStoragePath);
        parent::tearDown();
    }

    public function test_volumetric_weight_controls_the_tariff_and_flat_baseline_is_sixty_pesos(): void
    {
        $product = new Product(['weight_kg' => '1.00', 'length_cm' => '70.00', 'width_cm' => '50.00', 'height_cm' => '10.00']);
        $item = new CartItem(['quantity' => 1]);
        $item->setRelation('product', $product);
        $rates = app(LogisticsRateService::class);
        $flat = $rates->quote(collect([$item]));
        $this->assertSame(1000, $flat['actual_weight_grams']);
        $this->assertSame(10000, $flat['volumetric_weight_grams']);
        $this->assertSame(10000, $flat['billable_weight_grams']);
        $this->assertSame(6000, $flat['shipping_cents']);
        $company = new LogisticsCompany(['base_shipping_cents' => 6000, 'included_weight_grams' => 1000, 'additional_kg_cents' => 2000]);
        $weighted = $rates->quote(collect([$item]), $company);
        $this->assertSame(9, $weighted['additional_kilograms']);
        $this->assertSame(24000, $weighted['shipping_cents']);
        $product->weight_kg = '12.01';
        $this->assertSame(12010, $rates->quote(collect([$item]), $company)['billable_weight_grams']);
    }

    public function test_shipping_models_conserve_every_cent_and_match_the_pdf_examples(): void
    {
        $rates = app(LogisticsRateService::class);
        $this->assertSame(['pickup' => 0, 'delivery' => 3000, 'company' => 3000], $rates->shippingSplit(6000, 'standard'));
        $this->assertSame(['pickup' => 1000, 'delivery' => 2500, 'company' => 2500], $rates->shippingSplit(6000, 'dedicated_fleet'));
        foreach ([0, 1, 2, 59, 6001, 999999] as $cents) {
            foreach (['standard', 'dedicated_fleet'] as $model) {
                $this->assertSame($cents, array_sum($rates->shippingSplit($cents, $model)));
            }
        }
    }

    public function test_same_child_route_omits_mother_hubs_and_freezes_one_hop(): void
    {
        $this->seed(RoutingCheckoutSeeder::class);
        $shop = User::where('email', 'seller@bagoo.test')->sole()->shop;
        $hub = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $order = Order::factory()->create(['shipping_city' => $hub->city_municipality, 'shipping_province' => $hub->province, 'shipping_postal_code' => '4030', 'destination_barangay' => 'Batong Malake', 'logistics_flow_version' => 2]);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'logistics_company_id' => $hub->logistics_company_id,
            'status' => 'unassigned', 'courier_id' => null, 'assigned_rider_id' => null, 'current_hub_id' => null]);
        app(LogisticsRoutingEngine::class)->planDeliveryRoute($parcel, $order, $shop);
        $this->assertTrue($parcel->same_hub_bypass);
        $this->assertSame([$hub->id], $parcel->route_hub_ids);
        $this->assertNull($parcel->origin_mother_hub_id);
        $this->assertNull($parcel->destination_mother_hub_id);
    }

    public function test_postal_coverage_and_explicit_parent_determine_the_regional_path(): void
    {
        $this->seed(RoutingCheckoutSeeder::class);
        $shop = User::where('email', 'seller@bagoo.test')->sole()->shop;
        $origin = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $destination = LogisticsHub::where('code', 'BH-SCZ-01')->sole();
        $mother = LogisticsHub::where('code', 'MH-LAG-01')->sole();
        $origin->update(['parent_hub_id' => $mother->id]);
        $destination->update(['parent_hub_id' => $mother->id, 'postal_codes' => ['4008'], 'coverage_barangays' => []]);
        $order = Order::factory()->create(['shipping_city' => 'Pagsanjan', 'shipping_province' => 'Laguna', 'shipping_postal_code' => '4008', 'destination_barangay' => null, 'logistics_flow_version' => 2]);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'logistics_company_id' => $origin->logistics_company_id,
            'status' => 'unassigned', 'courier_id' => null, 'assigned_rider_id' => null, 'current_hub_id' => null]);
        app(LogisticsRoutingEngine::class)->planDeliveryRoute($parcel, $order, $shop);
        $this->assertFalse($parcel->same_hub_bypass);
        $this->assertSame([$origin->id, $mother->id, $destination->id], $parcel->route_hub_ids);
        $this->assertNull(app(LogisticsRoutingEngine::class)->resolveDestinationBayanHub('Laguna', 'Pagsanjan', null, $origin->company, '9999'));
    }

    public function test_cross_region_routes_retain_both_explicit_mothers_and_cannot_skip_between_children(): void
    {
        $this->seed(RoutingCheckoutSeeder::class);
        $shop = User::where('email', 'seller@bagoo.test')->sole()->shop;
        $origin = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $mother = LogisticsHub::create(['logistics_company_id' => $origin->logistics_company_id, 'code' => 'QA-CAV-MH', 'name' => 'Cavite Mother',
            'tier' => 'regional_mother_hub', 'province' => 'Cavite', 'city_municipality' => 'Dasmariñas', 'address' => '100 Test Road', 'is_active' => true]);
        $destination = LogisticsHub::create(['logistics_company_id' => $origin->logistics_company_id, 'code' => 'QA-CAV-CH', 'name' => 'Cavite Child',
            'tier' => 'local_bayan_hub', 'parent_hub_id' => $mother->id, 'province' => 'Cavite', 'city_municipality' => 'Dasmariñas',
            'postal_codes' => ['4114'], 'address' => '101 Test Road', 'is_active' => true]);
        $order = Order::factory()->create(['shipping_city' => 'Dasmariñas', 'shipping_province' => 'Cavite', 'shipping_postal_code' => '4114',
            'destination_barangay' => 'Paliparan I', 'logistics_flow_version' => 2]);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'logistics_company_id' => $origin->logistics_company_id,
            'status' => 'unassigned', 'courier_id' => null, 'assigned_rider_id' => null, 'current_hub_id' => null]);
        app(LogisticsRoutingEngine::class)->planDeliveryRoute($parcel, $order, $shop);
        $this->assertSame([$origin->id, $origin->parent_hub_id, $mother->id, $destination->id], $parcel->route_hub_ids);
        $preview = app(LogisticsRoutingEngine::class)->previewRoute($origin->company, $shop, $order->only([
            'shipping_city', 'shipping_province', 'shipping_postal_code', 'destination_barangay',
        ]));
        $this->assertSame($parcel->route_hub_ids, array_column($preview['route'], 'id'));

        $this->assertFalse($parcel->same_hub_bypass);
        $this->assertSame('QA-CAV-MH', app(LogisticsRoutingEngine::class)->forwardSortTarget($parcel, LogisticsHub::findOrFail($origin->parent_hub_id))['code']);
        $before = $parcel->route_hub_ids;
        $destination->update(['parent_hub_id' => $origin->parent_hub_id]);
        $this->assertSame($before, $parcel->fresh()->route_hub_ids);
        $this->assertSame(4, count($order->fresh()->shipping_quote['route']));
        try {
            app(LogisticsRoutingEngine::class)->planDeliveryRoute($parcel, $order, $shop);
            $this->fail('A confirmed route must not be silently recalculated after a parent hub edit.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('controlled recovery', $exception->getMessage());
        }
        $this->assertSame($before, $parcel->fresh()->route_hub_ids);
        $this->assertSame(4, count($order->fresh()->shipping_quote['route']));

    }

    public function test_preview_has_no_custody_side_effects_and_matches_the_confirmed_route(): void
    {
        $this->seed(RoutingCheckoutSeeder::class);
        $shop = User::where('email', 'seller@bagoo.test')->sole()->shop;
        $origin = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $address = ['shipping_province' => 'Laguna', 'shipping_city' => 'Santa Cruz',
            'shipping_postal_code' => '4009', 'destination_barangay' => 'Poblacion III'];
        $engine = app(LogisticsRoutingEngine::class);
        $preview = $engine->previewRoute($origin->company, $shop, $address);
        $this->assertSame(['BH-LBN-01', 'MH-LAG-01', 'BH-SCZ-01'], array_column($preview['route'], 'code'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('deliveries', 0);
        $order = Order::factory()->create([...$address, 'logistics_flow_version' => 2]);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'logistics_company_id' => $origin->logistics_company_id,
            'status' => 'unassigned', 'courier_id' => null, 'assigned_rider_id' => null, 'current_hub_id' => null]);
        $engine->planDeliveryRoute($parcel, $order, $shop);
        $this->assertSame($preview['route'], $order->fresh()->shipping_quote['route']);
        $this->assertSame('unassigned', $parcel->status);
        $this->assertSame(0, $parcel->checkpoints()->count());
    }

    public function test_local_preview_does_not_require_an_active_mother_and_foreign_pickup_is_rejected(): void
    {
        $this->seed(RoutingCheckoutSeeder::class);
        $shop = User::where('email', 'seller@bagoo.test')->sole()->shop;
        $origin = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        LogisticsHub::whereKey($origin->parent_hub_id)->update(['is_active' => false]);
        $address = ['shipping_province' => 'Laguna', 'shipping_city' => 'Los Baños',
            'shipping_postal_code' => '4030', 'destination_barangay' => 'Batong Malake'];
        $engine = app(LogisticsRoutingEngine::class);
        $plan = $engine->previewRoute($origin->company, $shop, $address);
        $this->assertTrue($plan['same_hub_bypass']);
        $this->assertSame([$origin->id], array_column($plan['route'], 'id'));
        $this->assertFalse($engine->serves($origin->company, $shop, [...$address,
            'delivery_type' => 'hub_self_pickup', 'pickup_hub_id' => 2147483647]));
        $this->assertFalse($engine->serves($origin->company, $shop, [...$address,
            'shipping_city' => 'Santa Cruz', 'shipping_postal_code' => '4009', 'destination_barangay' => 'Poblacion III']));
    }

    public function test_ambiguous_or_inactive_company_routes_are_unavailable(): void
    {
        $this->seed(RoutingCheckoutSeeder::class);
        $shop = User::where('email', 'seller@bagoo.test')->sole()->shop;
        $origin = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $destination = LogisticsHub::where('code', 'BH-SCZ-01')->sole();
        $address = ['shipping_province' => 'Laguna', 'shipping_city' => 'Santa Cruz',
            'shipping_postal_code' => '4009', 'destination_barangay' => 'Poblacion III'];
        $duplicate = $destination->replicate();
        $duplicate->code = 'QA-AMBIGUOUS';
        $duplicate->save();
        $engine = app(LogisticsRoutingEngine::class);
        $this->assertFalse($engine->serves($origin->company, $shop, $address));
        $duplicate->delete();
        $company = $origin->company;
        $company->update(['is_active' => false]);
        $this->assertFalse($engine->serves($company, $shop, $address));
    }

    public function test_legacy_same_child_orders_keep_their_original_mother_hub_requirement(): void
    {
        $this->seed(RoutingCheckoutSeeder::class);
        $shop = User::where('email', 'seller@bagoo.test')->sole()->shop;
        $hub = LogisticsHub::where('code', 'BH-LBN-01')->sole();
        $order = Order::factory()->create(['shipping_city' => $hub->city_municipality, 'shipping_province' => $hub->province,
            'shipping_postal_code' => '4030', 'destination_barangay' => 'Batong Malake', 'logistics_flow_version' => 1]);
        $parcel = Delivery::factory()->create(['order_id' => $order->id, 'logistics_company_id' => $hub->logistics_company_id,
            'status' => 'unassigned', 'courier_id' => null, 'assigned_rider_id' => null, 'current_hub_id' => null]);
        app(LogisticsRoutingEngine::class)->planDeliveryRoute($parcel, $order, $shop);
        $this->assertFalse($parcel->same_hub_bypass);
        $this->assertSame($hub->parent_hub_id, $parcel->origin_mother_hub_id);
        $this->assertSame($hub->parent_hub_id, $parcel->destination_mother_hub_id);
        $this->assertNull($parcel->route_hub_ids);
    }
}
