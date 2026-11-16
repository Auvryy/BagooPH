<?php

namespace Database\Seeders;

use App\Models\CourierProfile;
use App\Models\Delivery;
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
                'base_shipping_cents' => 5000, 'included_weight_grams' => 1000, 'additional_kg_cents' => 2000,
            ]);
            if ($company->user_id !== $owner->id || ! LogisticsCompany::eligible()->whereKey($company->id)->exists()) {
                throw new RuntimeException('Reserved regional demo company is restricted or has conflicting ownership.');
            }
            $laguna = $this->hub($company, 'BGR-MH-LAG', 'Laguna Mother Hub', 'Laguna', 'Calamba City', [], null);
            $pila = $this->hub($company, 'BGR-MH-PIL', 'Pila Mother Hub', 'Laguna', 'Pila', [], null);
            $sanPablo = $this->hub($company, 'BGR-MH-SPB', 'San Pablo Mother Hub', 'Laguna', 'San Pablo City', [], null);
            $lipa = $this->hub($company, 'BGR-MH-LIP', 'Lipa Mother Hub', 'Batangas', 'Lipa City', [], null);
            $metro = $this->hub($company, 'BGR-MH-NCR', 'Metro Manila Mother Hub', 'Metro Manila', 'Pasig City', [], null);
            $apayao = $this->hub($company, 'BGR-MH-APY', 'Apayao Mother Hub', 'Apayao', 'Kabugao', [], null);
            foreach ([
                ['CLN', 'Calanasan', 'Apayao', ['3814'], $apayao, null],
                ['LBN', 'Los Banos', 'Laguna', ['4030'], $laguna, null],
                ['CAL', 'Calamba City', 'Laguna', ['4027', '4028', '4029'], $laguna, null],
                ['CBY', 'Cabuyao City', 'Laguna', ['4025'], $laguna, null],
                ['SCZ', 'Santa Cruz', 'Laguna', ['4009'], $pila, $laguna],
                ['NGC', 'Nagcarlan', 'Laguna', ['4002'], $sanPablo, null],
                ['DLR', 'Dolores', 'Quezon', ['4326'], $sanPablo, null],
                ['TAL', 'Taal', 'Batangas', ['4208'], $lipa, null],
                ['IBN', 'Ibaan', 'Batangas', ['4230'], $lipa, null],
                ['MNL', 'Manila', 'Metro Manila', ['1000', '1004', '1008', '1012'], $metro, null],
                ['QC', 'Quezon City', 'Metro Manila', ['1100', '1101', '1104', '1105'], $metro, null],
                ['MKT', 'Makati City', 'Metro Manila', ['1200', '1203', '1210', '1230'], $metro, null],
            ] as [$code, $city, $province, $postal, $parent, $legacyParent]) {
                $this->hub($company, 'BGR-BH-'.$code, $city.' Child Hub', $province, $city, $postal, $parent, $legacyParent);
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

    private function hub(LogisticsCompany $company, string $code, string $name, string $province, string $city, array $postal, ?LogisticsHub $parent, ?LogisticsHub $legacyParent = null): LogisticsHub
    {
        $coordinates = [
            'BGR-MH-LAG' => [14.2078, 121.1558],
            'BGR-MH-PIL' => [14.2333, 121.3636],
            'BGR-MH-SPB' => [14.0683, 121.3256],
            'BGR-MH-LIP' => [13.9411, 121.1631],
            'BGR-MH-NCR' => [14.5764, 121.0851],
            'BGR-MH-APY' => [18.0235, 121.1836],
            'BGR-BH-CLN' => [18.2345, 121.0294],
            'BGR-BH-LBN' => [14.1678, 121.2435],
            'BGR-BH-CAL' => [14.2117, 121.1653],
            'BGR-BH-CBY' => [14.2775, 121.1233],
            'BGR-BH-SCZ' => [14.2789, 121.4172],
            'BGR-BH-NGC' => [14.1369, 121.4139],
            'BGR-BH-DLR' => [14.0156, 121.4014],
            'BGR-BH-TAL' => [13.8808, 120.9235],
            'BGR-BH-IBN' => [13.8186, 121.1333],
            'BGR-BH-MNL' => [14.5995, 120.9842],
            'BGR-BH-QC' => [14.6760, 121.0437],
            'BGR-BH-MKT' => [14.5547, 121.0244],
        ][$code] ?? [null, null];

        $hub = LogisticsHub::firstOrCreate(['code' => $code], [
            'logistics_company_id' => $company->id, 'name' => $name,
            'tier' => $parent ? 'local_bayan_hub' : 'regional_mother_hub', 'parent_hub_id' => $parent?->id,
            'province' => $province, 'city_municipality' => $city, 'postal_codes' => $postal,
            'coverage_barangays' => [], 'address' => 'Demo dispatch facility, '.$city.', '.$province,
            'latitude' => $coordinates[0], 'longitude' => $coordinates[1],
            'capacity' => $parent ? 1500 : 12000, 'is_active' => true,
            'allows_self_pickup' => (bool) $parent, 'operating_hours' => 'Monday to Saturday, 8 AM to 6 PM',
        ]);

        if (($hub->latitude === null || $hub->longitude === null) && $coordinates[0] !== null && $coordinates[1] !== null) {
            $hub->update(['latitude' => $coordinates[0], 'longitude' => $coordinates[1]]);
        }
        if ($legacyParent && $parent && $hub->logistics_company_id === $company->id && $hub->is_active
            && $hub->parent_hub_id === $legacyParent->id
            && ! Delivery::where(fn ($q) => $q->where('origin_bayan_hub_id', $hub->id)->orWhere('destination_bayan_hub_id', $hub->id))->exists()) {
            $hub->update(['parent_hub_id' => $parent->id]);
        }
        if ($hub->logistics_company_id !== $company->id || ! $hub->is_active || $hub->parent_hub_id !== $parent?->id) {
            throw new RuntimeException('Reserved demo facility has conflicting ownership, status or parent.');
        }
        $key = strtolower($code);
        $operator = $this->account($key.'.operator', $name.' Operator', 'logistics');
        HubHandler::firstOrCreate(['user_id' => $operator->id, 'hub_id' => $hub->id], ['role_title' => 'Facility Operator', 'is_active' => true]);
        // Mother hubs also run Mother-to-Mother line-haul, which needs a wing truck and its own driver.
        $vehicles = ['transport' => ['l300_van', 'Demo closed transfer van', 1200, 'Closed van'],
            'linehaul' => ['wing_truck', 'Demo line-haul wing truck', 8000, 'Wing truck']];
        foreach ($parent ? ['pickup', 'delivery', 'transport'] : ['transport', 'linehaul'] as $duty) {
            $driver = $this->account($key.'.'.$duty, $name.' '.($duty === 'linehaul' ? 'Line-haul Driver' : ucfirst($duty).' Rider'), 'courier');
            $plate = $code.'-'.strtoupper(substr($duty, 0, 1));
            [$type, $model, $capacity] = $vehicles[$duty] ?? ['motorcycle', 'Demo delivery motorcycle', 40];
            $fleet = LogisticsFleet::firstOrCreate(['plate_number' => $plate], [
                'logistics_company_id' => $company->id, 'hub_id' => $hub->id,
                'assigned_driver_id' => $driver->id, 'vehicle_type' => $type,
                'model' => $model, 'capacity_kg' => $capacity, 'status' => 'active',
            ]);
            if ($fleet->assigned_driver_id !== $driver->id || $fleet->hub_id !== $hub->id || $fleet->logistics_company_id !== $company->id) {
                throw new RuntimeException('Reserved demo vehicle assignment conflicts with existing records.');
            }
            $profile = CourierProfile::firstOrCreate(['user_id' => $driver->id], [
                'logistics_company_id' => $company->id, 'assigned_hub_id' => $hub->id,
                'vehicle_id' => $fleet->id, 'vehicle_type' => $vehicles[$duty][3] ?? 'Motorcycle',
                'plate_number' => $plate, 'license_number' => 'DEMO-'.$plate, 'or_cr_status' => 'Verified', 'is_available' => true,
            ]);
            if ($profile->vehicle_id !== $fleet->id || $profile->assigned_hub_id !== $hub->id || $profile->logistics_company_id !== $company->id) {
                throw new RuntimeException('Reserved demo rider assignment conflicts with existing records.');
            }
        }

        return $hub;
    }
}
