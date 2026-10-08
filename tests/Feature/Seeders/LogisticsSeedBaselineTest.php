<?php

namespace Tests\Feature\Seeders;

use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LogisticsSeedBaselineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_existing_reserved_demo_accounts_are_marked_verified_by_the_data_migration(): void
    {
        $rider = User::factory()->unverified()->create([
            'email' => 'rider@bagoo.test',
            'role' => 'courier',
        ]);

        $migration = require database_path('migrations/2026_10_02_120000_mark_seeded_demo_accounts_as_verified.php');
        $migration->up();

        $this->assertNotNull($rider->fresh()->email_verified_at);
    }

    public function test_database_seeder_creates_an_active_logistics_operator_and_network(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            0,
            User::where('email', 'like', '%@bagoo.test')
                ->whereNull('email_verified_at')
                ->count(),
            'Seeded demo accounts must be pre-verified because .test addresses cannot receive email.'
        );

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

        $expectedFacilityAccounts = [
            'MH-LAG-01' => 'motherhub@bagoo.test',
            'BH-SCZ-01' => 'logistics@bagoo.test',
            'BH-LBN-01' => 'losbanos.hub@bagoo.test',
        ];

        foreach ($expectedFacilityAccounts as $hubCode => $email) {
            $facility = LogisticsHub::where('logistics_company_id', $company->id)
                ->where('code', $hubCode)
                ->firstOrFail();
            $facilityOperator = User::where('email', $email)->firstOrFail();

            $this->assertSame('logistics', $facilityOperator->role);
            $this->assertSame('active', $facilityOperator->status);
            $this->assertSame('approved', $facilityOperator->kyc_status);
            $this->assertTrue(Hash::check('Password1234', $facilityOperator->password));
            $this->assertDatabaseHas('hub_handlers', [
                'user_id' => $facilityOperator->id,
                'hub_id' => $facility->id,
                'is_active' => true,
            ]);
        }

        $this->assertSame(
            3,
            HubHandler::query()
                ->where('is_active', true)
                ->whereHas('hub', fn ($query) => $query->where('logistics_company_id', $company->id))
                ->distinct('hub_id')
                ->count('hub_id')
        );

        $this->assertSame(3, LogisticsHub::where('logistics_company_id', $company->id)->count());
        $this->assertSame(2, User::where('role', 'courier')->count());
        $this->assertDatabaseHas('courier_profiles', [
            'user_id' => User::where('email', 'pickup.rider@bagoo.test')->firstOrFail()->id,
            'logistics_company_id' => $company->id,
            'assigned_hub_id' => LogisticsHub::where('code', 'BH-LBN-01')->firstOrFail()->id,
        ]);
        $this->assertDatabaseHas('courier_profiles', [
            'user_id' => User::where('email', 'rider@bagoo.test')->firstOrFail()->id,
            'logistics_company_id' => $company->id,
            'assigned_hub_id' => $hub->id,
            'assigned_barangay' => 'Poblacion III',
        ]);

        $this->assertSame('Los Banos, Laguna', User::where('email', 'seller@bagoo.test')->value('city'));
        $this->assertSame('Santa Cruz, Laguna', User::where('email', 'buyer@bagoo.test')->value('city'));
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Review::count());
        $this->assertTrue(Product::all()->every(
            fn (Product $product) => $product->sales_count === 0 && (float) $product->rating === 0.0
        ));
    }
}
