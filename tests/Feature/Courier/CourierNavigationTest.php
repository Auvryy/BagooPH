<?php

namespace Tests\Feature\Courier;

use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\InteractsWithCodCollection;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class CourierNavigationTest extends TestCase
{
    use CreatesE2EOrders;
    use InteractsWithCodCollection;
    use InteractsWithRoles;
    use RefreshDatabase;

    public function test_guest_cannot_access_courier_navigation(): void
    {
        $response = $this->get('/courier/navigation');
        $response->assertRedirect('/login');
    }

    public function test_approved_courier_can_render_live_navigation_page(): void
    {
        $rider = $this->createApprovedUser('courier');
        $owner = $this->createApprovedUser('logistics');

        $company = LogisticsCompany::create([
            'user_id' => $owner->id,
            'name' => 'Laguna Express',
            'slug' => 'laguna-express-'.Str::lower(Str::random(6)),
            'code' => 'LGE-'.Str::upper(Str::random(6)),
            'status' => 'active',
            'is_active' => true,
        ]);

        $hub = LogisticsHub::create([
            'logistics_company_id' => $company->id,
            'name' => 'Calamba Central Hub',
            'code' => 'BH-CAL-'.Str::upper(Str::random(4)),
            'tier' => 'local_bayan_hub',
            'province' => 'Laguna',
            'city_municipality' => 'Calamba',
            'is_active' => true,
            'latitude' => 14.2084,
            'longitude' => 121.1574,
            'address' => 'National Highway, Calamba, Laguna',
        ]);

        $rider->courierProfile->update([
            'logistics_company_id' => $company->id,
            'assigned_hub_id' => $hub->id,
            'assigned_barangay' => 'Real',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'BG-CAL-101',
            'license_number' => 'N01-26-880001',
            'or_cr_status' => 'Verified',
            'is_available' => true,
        ]);

        $response = $this->actingAs($rider)->get('/courier/navigation');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Courier/Navigation')
            ->has('queues')
            ->has('scope')
            ->where('isOnline', true)
        );
    }
}
