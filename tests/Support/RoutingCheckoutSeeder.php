<?php

namespace Tests\Support;

use App\Models\User;
use Database\Seeders\LogisticsNetworkSeeder;
use Database\Seeders\MasterCategorySeeder;
use Database\Seeders\SellerDemoSeeder;
use Illuminate\Database\Seeder;

/** Minimal SQLite fixture; never runs the manual demonstration reset seeder. */
class RoutingCheckoutSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['admin' => 'admin', 'buyer' => 'buyer', 'rider' => 'courier'] as $email => $role) {
            User::factory()->create(['email' => $email.'@bagoo.test', 'role' => $role]);
        }
        $this->call([MasterCategorySeeder::class, SellerDemoSeeder::class, LogisticsNetworkSeeder::class]);
    }
}
