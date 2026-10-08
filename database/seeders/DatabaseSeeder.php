<?php

namespace Database\Seeders;

use App\Models\CourierProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(MasterCategorySeeder::class);

        // 1. Platform Admin
        User::updateOrCreate(
            ['email' => 'admin@bagoo.test'],
            [
                'name' => 'Bagoo Admin',
                'email_verified_at' => now(),
                'password' => 'Password1234',
                'role' => 'admin',
                'phone' => '+63 917 000 0001',
                'address' => 'Bagoo HQ, BGC',
                'city' => 'Taguig, Metro Manila',
                'postal_code' => '1634',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // 2. Buyer
        User::updateOrCreate(
            ['email' => 'buyer@bagoo.test'],
            [
                'name' => 'Santa Cruz Buyer',
                'email_verified_at' => now(),
                'password' => 'Password1234',
                'role' => 'buyer',
                'phone' => '+63 917 000 0002',
                'address' => 'Pedro Guevara Avenue, Poblacion III',
                'city' => 'Santa Cruz, Laguna',
                'postal_code' => '4009',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // 4. Santa Cruz final-mile rider
        $courierUser = User::updateOrCreate(
            ['email' => 'rider@bagoo.test'],
            [
                'name' => 'Santa Cruz Delivery Rider',
                'email_verified_at' => now(),
                'password' => 'Password1234',
                'role' => 'courier',
                'phone' => '+63 917 000 0004',
                'address' => 'Poblacion III Dispatch Area',
                'city' => 'Santa Cruz, Laguna',
                'postal_code' => '4009',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        CourierProfile::updateOrCreate(
            ['user_id' => $courierUser->id],
            [
                'vehicle_type' => 'Motorcycle',
                'plate_number' => 'BG-2026-PH',
                'license_number' => 'N01-26-888999',
                'or_cr_status' => 'Verified',
                'is_available' => true,
            ]
        );

        // 5. Logistics / Hub Operator
        User::updateOrCreate(
            ['email' => 'logistics@bagoo.test'],
            [
                'name' => 'Test Logistics',
                'email_verified_at' => now(),
                'password' => 'Password1234',
                'role' => 'logistics',
                'phone' => '+63 917 000 0005',
                'address' => 'Hub Station 01, C5 Road',
                'city' => 'Pasig City',
                'postal_code' => '1604',
                'status' => 'active',
                'kyc_status' => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        $this->call(SellerDemoSeeder::class);

        // Multi-tenant logistics network and supported road topology.
        $this->call(LogisticsNetworkSeeder::class);
    }
}
