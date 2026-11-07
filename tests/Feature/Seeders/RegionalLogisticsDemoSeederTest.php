<?php

namespace Tests\Feature\Seeders;

use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Shop;
use App\Models\Product;
use App\Services\Logistics\LogisticsCheckoutService;
use App\Services\Logistics\LogisticsRoutingEngine;
use Database\Seeders\RegionalLogisticsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegionalLogisticsDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_network_is_operational_and_rerunning_preserves_accounts_and_assignments(): void
    {
        $this->seed(RegionalLogisticsDemoSeeder::class);
        $company = LogisticsCompany::eligible()->where('code', 'BGR')->firstOrFail();
        $this->assertTrue($company->rider_intake_enabled);
        $this->assertSame(24, \App\Models\CourierProfile::operational()->where('logistics_company_id', $company->id)->count());
        $this->assertSame(10, \App\Models\HubHandler::eligible()->where('logistics_company_id', $company->id)->count());
        $this->assertSame(3, $company->motherHubs()->count());
        $this->assertSame(7, $company->bayanHubs()->count());
        $this->assertSame(24, $company->fleet()->count());
        $this->assertSame(24, $company->couriers()->count());
        $owner = $company->user;
        $owner->update(['name' => 'Retained owner name']);
        $password = $owner->password;
        $this->seed(RegionalLogisticsDemoSeeder::class);
        $this->assertSame($password, $owner->fresh()->password);
        $this->assertSame('Retained owner name', $owner->fresh()->name);
        $this->assertSame(10, $company->hubs()->count());
        $this->assertSame(24, $company->fleet()->count());
        $this->assertSame(7, LogisticsHub::readyForSelfPickup()->where('logistics_company_id', $company->id)->count());
        $shop = Shop::factory()->create(['city' => 'Los Banos', 'pickup_province' => 'Laguna', 'pickup_postal_code' => '4030']);
        $items = collect([(object) ['product' => Product::factory()->create(['shop_id' => $shop->id])]]);
        $checkout = app(LogisticsCheckoutService::class);
        $engine = app(LogisticsRoutingEngine::class);
        foreach ($company->bayanHubs()->get() as $hub) {
            $this->assertTrue($engine->serves($company, $shop, [
                'shipping_province' => $hub->province, 'shipping_city' => $hub->city_municipality,
                'shipping_postal_code' => $hub->postal_codes[0], 'destination_barangay' => 'Demo Barangay',
            ]), $hub->name.' must have a complete route');
        }
        $address = ['shipping_province' => 'Apayao', 'shipping_city' => 'Calanasan', 'shipping_postal_code' => '3814'];
        $this->assertSame([$company->id], array_column($checkout->choices($items, $address), 'id'));
        $this->assertSame($company->id, $checkout->select($items, $address, $company->id)->id);
        $this->assertSame([], $checkout->choices($items, [...$address, 'shipping_postal_code' => '4012']));
        $company->update(['is_active' => false]);
        $this->assertSame([], $checkout->choices($items, $address));
        $this->assertFalse($engine->serves($company, $shop, ['shipping_province' => 'Cebu', 'shipping_city' => 'Cebu City', 'shipping_postal_code' => '6000']));
    }
    public function test_rerunning_preserves_the_owners_decision_to_pause_rider_applications(): void
    {
        $this->seed(RegionalLogisticsDemoSeeder::class);
        $company = LogisticsCompany::where('code', 'BGR')->sole();
        app(\App\Services\Logistics\RiderCompanyRequestService::class)->changeIntake($company->user, [
            'enabled' => false, 'expected_version' => 1, 'request_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);
        $this->seed(RegionalLogisticsDemoSeeder::class);
        $this->assertFalse($company->fresh()->rider_intake_enabled);
        $this->assertSame(2, $company->fresh()->rider_intake_version);
        $this->assertSame(2, $company->riderIntakeEvents()->count());
    }

}
