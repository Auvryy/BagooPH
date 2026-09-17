<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Platform Admin
        User::updateOrCreate(
            ['email' => 'admin@bagoo.test'],
            [
                'name'            => 'Bagoo Admin',
                'password'        => 'Password1234',
                'role'            => 'admin',
                'phone'           => '+63 917 000 0001',
                'address'         => 'Bagoo HQ, BGC',
                'city'            => 'Taguig, Metro Manila',
                'postal_code'     => '1634',
                'status'          => 'active',
                'kyc_status'      => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // Buyer
        User::updateOrCreate(
            ['email' => 'buyer@bagoo.test'],
            [
                'name'            => 'Test Buyer',
                'password'        => 'Password1234',
                'role'            => 'buyer',
                'phone'           => '+63 917 000 0002',
                'address'         => 'Unit 1, Test Street',
                'city'            => 'Quezon City',
                'postal_code'     => '1100',
                'status'          => 'active',
                'kyc_status'      => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // Seller
        User::updateOrCreate(
            ['email' => 'seller@bagoo.test'],
            [
                'name'            => 'Test Seller',
                'password'        => 'Password1234',
                'role'            => 'seller',
                'phone'           => '+63 917 000 0003',
                'address'         => 'Warehouse 1, Test Ave',
                'city'            => 'Pasig City',
                'postal_code'     => '1600',
                'status'          => 'active',
                'kyc_status'      => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // Courier / Rider
        User::updateOrCreate(
            ['email' => 'rider@bagoo.test'],
            [
                'name'            => 'Test Rider',
                'password'        => 'Password1234',
                'role'            => 'courier',
                'phone'           => '+63 917 000 0004',
                'address'         => 'Block 5, Rider Lane',
                'city'            => 'Makati City',
                'postal_code'     => '1200',
                'status'          => 'active',
                'kyc_status'      => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );

        // Logistics / Hub Operator
        User::updateOrCreate(
            ['email' => 'logistics@bagoo.test'],
            [
                'name'            => 'Test Logistics',
                'password'        => 'Password1234',
                'role'            => 'logistics',
                'phone'           => '+63 917 000 0005',
                'address'         => 'Hub Station 01, C5 Road',
                'city'            => 'Pasig City',
                'postal_code'     => '1604',
                'status'          => 'active',
                'kyc_status'      => 'approved',
                'kyc_reviewed_at' => now(),
            ]
        );
    }
}
