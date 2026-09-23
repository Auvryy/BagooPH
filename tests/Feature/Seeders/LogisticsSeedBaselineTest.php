<?php

namespace Tests\Feature\Seeders;

use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LogisticsSeedBaselineTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_an_active_logistics_operator_and_network(): void
    {
        $this->seed(DatabaseSeeder::class);

        $operator = User::where('email', 'logistics@bagoo.test')->firstOrFail();
        $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        $hub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();

        $this->assertSame('logistics', $operator->role);
        $this->assertSame('active', $operator->status);
        $this->assertSame('approved', $operator->kyc_status);
        $this->assertTrue(Hash::check('Password1234', $operator->password));
        $this->assertSame($company->id, $hub->logistics_company_id);
        $this->assertDatabaseHas('hub_handlers', [
            'user_id' => $operator->id,
            'hub_id' => $hub->id,
            'is_active' => true,
        ]);
    }
}
