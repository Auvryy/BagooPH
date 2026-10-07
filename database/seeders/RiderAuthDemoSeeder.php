<?php

namespace Database\Seeders;

use App\Models\CourierProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

class RiderAuthDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || config('database.default') !== 'sqlite'
            || ! str_ends_with((string) config('database.connections.sqlite.database'), 'rider-demo.sqlite')) {
            throw new \LogicException('The rider demo uses its own isolated local database.');
        }
        $user = User::firstOrCreate(['email' => 'rider@bagoo.test'], [
            'name' => 'Bagoo Demo Rider', 'password' => 'Password1234', 'role' => 'courier',
            'birthday' => '1998-01-01', 'email_verified_at' => now(), 'status' => 'active', 'kyc_status' => 'approved',
        ]);
        CourierProfile::firstOrCreate(['user_id' => $user->id], ['vehicle_type' => 'Motorcycle',
            'plate_number' => 'DEMO-001', 'license_number' => 'DEMO-001', 'is_available' => false]);
    }
}
