<?php

namespace Database\Seeders;

use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Logistics\RiderCompanyRequestService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Additive demo network; never resets orders, accounts, or existing assignments. */
class RegionalLogisticsDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $owner = $this->account('regional.owner', 'Bagoo Regional Company Owner', 'logistics');
            $company = LogisticsCompany::firstOrCreate(['code' => 'BGR'], [
                'user_id' => $owner->id, 'name' => 'Bagoo Regional Express', 'slug' => 'bagoo-regional-express',
                'service_areas' => ['NCR', 'Region IV-A', 'CAR'], 'contact_email' => 'regional.owner@bagoo.test',
                'address' => 'Calamba City, Laguna', 'status' => 'active', 'is_active' => true,
                'base_shipping_cents' => 6000, 'included_weight_grams' => 1000, 'additional_kg_cents' => 2000,
            ]);
            if ($company->user_id !== $owner->id || ! LogisticsCompany::eligible()->whereKey($company->id)->exists()) {
                throw new RuntimeException('Reserved regional demo company is restricted or has conflicting ownership.');
            }
            $laguna = $this->hub($company, 'BGR-MH-LAG', 'Laguna Mother Hub', 'Laguna', 'Calamba City', [], null);
            $metro = $this->hub($company, 'BGR-MH-NCR', 'Metro Manila Mother Hub', 'Metro Manila', 'Pasig City', [], null);
            $apayao = $this->hub($company, 'BGR-MH-APY', 'Apayao Mother Hub', 'Apayao', 'Kabugao', [], null);
            foreach ([
                ['CLN', 'Calanasan', 'Apayao', ['3814'], $apayao],
                ['LBN', 'Los Banos', 'Laguna', ['4030'], $laguna],
                ['SCZ', 'Santa Cruz', 'Laguna', ['4009'], $laguna],
                ['CAL', 'Calamba City', 'Laguna', ['4027', '4028', '4029'], $laguna],
                ['MNL', 'Manila', 'Metro Manila', ['1000', '1004', '1008', '1012'], $metro],
                ['QC', 'Quezon City', 'Metro Manila', ['1100', '1101', '1104', '1105'], $metro],
                ['MKT', 'Makati City', 'Metro Manila', ['1200', '1203', '1210', '1230'], $metro],
            ] as [$code, $city, $province, $postal, $parent]) {
                $this->hub($company, 'BGR-BH-'.$code, $city.' Child Hub', $province, $city, $postal, $parent);
            }
            $company->refresh();
            if ($company->rider_intake_version === 0 && ! $company->rider_intake_enabled) {
                app(RiderCompanyRequestService::class)->changeIntake($owner, [
                    'enabled' => true, 'expected_version' => 0, 'request_token' => (string) Str::uuid(),
                ]);
            }
        });
    }

    private function account(string $key, string $name, string $role): User
    {
        $user = User::firstOrCreate(['email' => $key.'@bagoo.test', 'role' => $role], [
            'name' => $name, 'password' => 'Password1234', 'status' => 'active',
            'kyc_status' => 'approved', 'email_verified_at' => now(), 'kyc_reviewed_at' => now(),
        ]);
        if (! $user->canAccessPortal()) {
            throw new RuntimeException('Reserved demo worker is restricted; existing account was preserved.');
        }

        return $user;
    }

    private function hub(LogisticsCompany $company, string $code, string $name, string $province, string $city, array $postal, ?LogisticsHub $parent): LogisticsHub
    {
        $hub = LogisticsHub::firstOrCreate(['code' => $code], [
            'logistics_company_id' => $company->id, 'name' => $name,
            'tier' => $parent ? 'local_bayan_hub' : 'regional_mother_hub', 'parent_hub_id' => $parent?->id,
            'province' => $province, 'city_municipality' => $city, 'postal_codes' => $postal,
            'coverage_barangays' => [], 'address' => 'Demo dispatch facility, '.$city.', '.$province,
            'capacity' => $parent ? 1500 : 12000, 'is_active' => true,
            'allows_self_pickup' => (bool) $parent, 'operating_hours' => 'Monday to Saturday, 8 AM to 6 PM',
        ]);
        if ($hub->logistics_company_id !== $company->id || ! $hub->is_active || $hub->parent_hub_id !== $parent?->id) {
            throw new RuntimeException('Reserved demo facility has conflicting ownership, status or parent.');
        }
        $key = strtolower($code);
        $operator = $this->account($key.'.operator', $name.' Operator', 'logistics');
        HubHandler::firstOrCreate(['user_id' => $operator->id, 'hub_id' => $hub->id], ['role_title' => 'Facility Operator', 'is_active' => true]);
        foreach ($parent ? ['pickup', 'delivery', 'transport'] : ['transport'] as $duty) {
            $driver = $this->account($key.'.'.$duty, $name.' '.ucfirst($duty).' Rider', 'courier');
            $plate = $code.'-'.strtoupper(substr($duty, 0, 1));
            $fleet = LogisticsFleet::firstOrCreate(['plate_number' => $plate], [
                'logistics_company_id' => $company->id, 'hub_id' => $hub->id,
                'assigned_driver_id' => $driver->id, 'vehicle_type' => $duty === 'transport' ? 'l300_van' : 'motorcycle',
                'model' => $duty === 'transport' ? 'Demo closed transfer van' : 'Demo delivery motorcycle',
                'capacity_kg' => $duty === 'transport' ? 1200 : 40, 'status' => 'active',
            ]);
            if ($fleet->assigned_driver_id !== $driver->id || $fleet->hub_id !== $hub->id || $fleet->logistics_company_id !== $company->id) {
                throw new RuntimeException('Reserved demo vehicle assignment conflicts with existing records.');
            }
            $profile = CourierProfile::firstOrCreate(['user_id' => $driver->id], [
                'logistics_company_id' => $company->id, 'assigned_hub_id' => $hub->id,
                'vehicle_id' => $fleet->id, 'vehicle_type' => $duty === 'transport' ? 'Closed van' : 'Motorcycle',
                'plate_number' => $plate, 'license_number' => 'DEMO-'.$plate, 'or_cr_status' => 'Verified', 'is_available' => true,
            ]);
            if ($profile->vehicle_id !== $fleet->id || $profile->assigned_hub_id !== $hub->id || $profile->logistics_company_id !== $company->id) {
                throw new RuntimeException('Reserved demo rider assignment conflicts with existing records.');
            }
        }

        return $hub;
    }
}
