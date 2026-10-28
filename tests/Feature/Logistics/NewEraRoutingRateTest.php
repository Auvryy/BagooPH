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
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewEraRoutingRateTest extends TestCase
{
    use RefreshDatabase;

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
        $this->seed(DatabaseSeeder::class);
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
        $this->seed(DatabaseSeeder::class);
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
        $this->seed(DatabaseSeeder::class);
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
        $this->assertFalse($parcel->same_hub_bypass);
        $this->assertSame('QA-CAV-MH', app(LogisticsRoutingEngine::class)->forwardSortTarget($parcel, LogisticsHub::findOrFail($origin->parent_hub_id))['code']);
        $before = $parcel->route_hub_ids;
        $destination->update(['parent_hub_id' => $origin->parent_hub_id]);
        $this->assertSame($before, $parcel->fresh()->route_hub_ids);
        $this->assertSame(4, count($order->fresh()->shipping_quote['route']));
    }
}
