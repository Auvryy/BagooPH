<?php

namespace Tests\Concerns;

use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Shop;
use App\Models\User;

trait InteractsWithCheckoutNetwork
{
    /** @param array<string, string> $destinations City => province. */
    private function createCheckoutNetwork(Shop $shop, array $destinations, string $originProvince = 'Metro Manila'): void
    {
        $owner = User::factory()->create(['role' => 'logistics', 'status' => 'active', 'kyc_status' => 'approved']);
        $company = LogisticsCompany::create([
            'user_id' => $owner->id, 'name' => 'Bagoo Checkout Network',
            'slug' => 'bagoo-checkout-network', 'code' => 'CHECKOUT', 'status' => 'active', 'is_active' => true,
        ]);

        $cities = [$shop->city => $originProvince] + $destinations;
        foreach (array_values(array_unique(array_values($cities))) as $index => $province) {
            LogisticsHub::create([
                'logistics_company_id' => $company->id, 'name' => $province.' Mother Hub', 'code' => 'CHECKOUT-MH-'.$index,
                'tier' => 'regional_mother_hub', 'province' => $province,
                'city_municipality' => array_search($province, $cities, true),
                'address' => '100 Mother Hub Road', 'is_active' => true,
            ]);
        }

        foreach (array_keys($cities) as $index => $city) {
            LogisticsHub::create([
                'logistics_company_id' => $company->id, 'name' => $city.' Bayan Hub', 'code' => 'CHECKOUT-BH-'.$index,
                'tier' => 'local_bayan_hub', 'province' => $cities[$city], 'city_municipality' => $city,
                'address' => '100 Bayan Hub Road', 'is_active' => true,
            ]);
        }
    }
}
