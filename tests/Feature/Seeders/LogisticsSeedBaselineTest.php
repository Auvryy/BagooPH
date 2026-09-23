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

        $companyAdmin = User::where('email', 'logistics.admin@bagoo.test')->firstOrFail();
        $operator = User::where('email', 'logistics@bagoo.test')->firstOrFail();
        $motherHubOperator = User::where('email', 'motherhub@bagoo.test')->firstOrFail();
        $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        $hub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
        $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();

        $this->assertSame($companyAdmin->id, $company->user_id);
        $this->assertTrue(Hash::check('Password1234', $companyAdmin->password));
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
        $this->assertSame('logistics', $motherHubOperator->role);
        $this->assertTrue(Hash::check('Password1234', $motherHubOperator->password));
        $this->assertDatabaseHas('hub_handlers', [
            'user_id' => $motherHubOperator->id,
            'hub_id' => $motherHub->id,
            'role_title' => 'Mother Hub Sortation Operator',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('hub_handlers', [
            'user_id' => $companyAdmin->id,
        ]);
    }
}
