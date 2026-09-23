<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\User;
use Illuminate\Database\Seeder;

class LogisticsNetworkSeeder extends Seeder
{
    public function run(): void
    {
        $buyer = User::where('email', 'buyer@bagoo.test')->firstOrFail();
        $finalMileRider = User::where('email', 'rider@bagoo.test')->firstOrFail();

        $companyAdmin = $this->logisticsUser(
            'logistics.admin@bagoo.test',
            'Bagoo Express Administrator',
            '+63 917 000 0010',
            'Bagoo Express Operations Office',
            'Calamba City, Laguna',
            '4027'
        );
        $losBanosHandler = $this->logisticsUser(
            'losbanos.hub@bagoo.test',
            'Los Banos Hub Operator',
            '+63 917 000 0014',
            'Los Banos Bayan Hub',
            'Los Banos, Laguna',
            '4030'
        );
        $motherHubHandler = $this->logisticsUser(
            'motherhub@bagoo.test',
            'Laguna Mother Hub Operator',
            '+63 917 000 0011',
            'Laguna Regional Mother Hub',
            'Calamba City, Laguna',
            '4027'
        );
        $santaCruzHandler = $this->logisticsUser(
            'logistics@bagoo.test',
            'Santa Cruz Hub Operator',
            '+63 917 000 0005',
            'Santa Cruz Bayan Hub',
            'Santa Cruz, Laguna',
            '4009'
        );

        $pickupRider = User::updateOrCreate(
            ['email' => 'pickup.rider@bagoo.test'],
            [
                'name' => 'Los Banos Pickup Rider',
                'password' => 'Password1234',
                'role' => 'courier',
                'phone' => '+63 917 000 0006',
                'address' => 'Batong Malake Pickup Area',
                'city' => 'Los Banos, Laguna',
                'postal_code' => '4030',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        $company = LogisticsCompany::updateOrCreate(
            ['code' => 'BGX'],
            [
                'user_id' => $companyAdmin->id,
                'name' => 'Bagoo Express Dispatch Fleet',
                'slug' => 'bagoo-express-dispatch-fleet',
                'contact_email' => 'dispatch@bagooph.shop',
                'contact_phone' => '+63 917 888 2246',
                'address' => 'Calamba City, Laguna',
                'status' => 'active',
                'is_active' => true,
                'accreditation_details' => [
                    'license_type' => 'Road Freight Operator',
                    'franchise_number' => 'LTFRB-2026-BGX-9901',
                    'fleet_size' => 3,
                ],
            ]
        );

        $losBanosHub = LogisticsHub::updateOrCreate(
            ['code' => 'BH-LBN-01'],
            [
                'logistics_company_id' => $company->id,
                'name' => 'Los Banos Bayan Hub',
                'tier' => 'local_bayan_hub',
                'province' => 'Laguna',
                'city_municipality' => 'Los Banos',
                'barangay' => 'Batong Malake',
                'address' => 'Lopez Avenue, Batong Malake, Los Banos, Laguna',
                'latitude' => 14.1678,
                'longitude' => 121.2435,
                'capacity' => 2200,
                'coverage_barangays' => ['Batong Malake', 'Bayog', 'Anos', 'San Antonio'],
                'allows_self_pickup' => true,
                'is_active' => true,
            ]
        );

        $motherHub = LogisticsHub::updateOrCreate(
            ['code' => 'MH-LAG-01'],
            [
                'logistics_company_id' => $company->id,
                'name' => 'Laguna Regional Mother Hub',
                'tier' => 'regional_mother_hub',
                'province' => 'Laguna',
                'city_municipality' => 'Calamba City',
                'barangay' => 'Real',
                'address' => 'KM 54 National Highway, Real, Calamba City, Laguna',
                'latitude' => 14.2078,
                'longitude' => 121.1558,
                'capacity' => 15000,
                'coverage_barangays' => ['Real', 'Turbina', 'Canlubang'],
                'allows_self_pickup' => false,
                'is_active' => true,
            ]
        );

        $santaCruzHub = LogisticsHub::updateOrCreate(
            ['code' => 'BH-SCZ-01'],
            [
                'logistics_company_id' => $company->id,
                'name' => 'Santa Cruz Bayan Hub',
                'tier' => 'local_bayan_hub',
                'province' => 'Laguna',
                'city_municipality' => 'Santa Cruz',
                'barangay' => 'Poblacion III',
                'address' => 'Pedro Guevara Avenue, Poblacion III, Santa Cruz, Laguna',
                'latitude' => 14.2789,
                'longitude' => 121.4172,
                'capacity' => 2500,
                'coverage_barangays' => ['Poblacion I', 'Poblacion II', 'Poblacion III', 'Poblacion IV'],
                'allows_self_pickup' => true,
                'is_active' => true,
            ]
        );

        $this->assignHandler($losBanosHandler, $losBanosHub, 'Bayan Hub Operations Handler');
        $this->assignHandler($motherHubHandler, $motherHub, 'Mother Hub Sortation Operator');
        $this->assignHandler($santaCruzHandler, $santaCruzHub, 'Bayan Hub Operations Handler');

        $pickupMotorcycle = LogisticsFleet::updateOrCreate(
            ['plate_number' => 'BG-LBN-101'],
            [
                'logistics_company_id' => $company->id,
                'hub_id' => $losBanosHub->id,
                'vehicle_type' => 'motorcycle',
                'model' => 'First-mile delivery motorcycle',
                'capacity_kg' => 75,
                'assigned_driver_id' => $pickupRider->id,
                'status' => 'active',
            ]
        );
        $finalMileMotorcycle = LogisticsFleet::updateOrCreate(
            ['plate_number' => 'BG-SCZ-102'],
            [
                'logistics_company_id' => $company->id,
                'hub_id' => $santaCruzHub->id,
                'vehicle_type' => 'motorcycle',
                'model' => 'Final-mile delivery motorcycle',
                'capacity_kg' => 75,
                'assigned_driver_id' => $finalMileRider->id,
                'status' => 'active',
            ]
        );
        LogisticsFleet::updateOrCreate(
            ['plate_number' => 'BG-LAG-201'],
            [
                'logistics_company_id' => $company->id,
                'hub_id' => $motherHub->id,
                'vehicle_type' => 'l300_van',
                'model' => 'Laguna feeder van',
                'capacity_kg' => 1200,
                'status' => 'active',
            ]
        );

        CourierProfile::updateOrCreate(
            ['user_id' => $pickupRider->id],
            [
                'logistics_company_id' => $company->id,
                'assigned_hub_id' => $losBanosHub->id,
                'assigned_barangay' => 'Batong Malake',
                'vehicle_id' => $pickupMotorcycle->id,
                'vehicle_type' => 'Motorcycle',
                'plate_number' => 'BG-LBN-101',
                'license_number' => 'N01-26-880001',
                'or_cr_status' => 'Verified',
                'is_available' => true,
            ]
        );
        CourierProfile::updateOrCreate(
            ['user_id' => $finalMileRider->id],
            [
                'logistics_company_id' => $company->id,
                'assigned_hub_id' => $santaCruzHub->id,
                'assigned_barangay' => 'Poblacion III',
                'vehicle_id' => $finalMileMotorcycle->id,
                'vehicle_type' => 'Motorcycle',
                'plate_number' => 'BG-SCZ-102',
                'license_number' => 'N01-26-888999',
                'or_cr_status' => 'Verified',
                'is_available' => true,
            ]
        );

        Address::updateOrCreate(
            ['user_id' => $buyer->id, 'recipient_name' => $buyer->name],
            [
                'phone' => $buyer->phone,
                'province' => 'Laguna',
                'city' => 'Santa Cruz',
                'barangay' => 'Poblacion III',
                'street' => 'Pedro Guevara Avenue',
                'postal_code' => '4009',
                'latitude' => 14.2789,
                'longitude' => 121.4172,
                'landmark' => 'Near the municipal hall',
                'is_default' => true,
            ]
        );
    }

    private function logisticsUser(
        string $email,
        string $name,
        string $phone,
        string $address,
        string $city,
        string $postalCode
    ): User {
        return User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'Password1234',
                'role' => 'logistics',
                'phone' => $phone,
                'address' => $address,
                'city' => $city,
                'postal_code' => $postalCode,
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );
    }

    private function assignHandler(User $user, LogisticsHub $hub, string $roleTitle): void
    {
        HubHandler::updateOrCreate(
            ['user_id' => $user->id, 'hub_id' => $hub->id],
            [
                'role_title' => $roleTitle,
                'is_active' => true,
            ]
        );
    }
}
