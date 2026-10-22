<?php

namespace Tests\Feature\Seeders;

use App\Models\CourierProfile;
use App\Models\HubHandler;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use App\Services\Logistics\LogisticsEligibilityService;
use App\Services\Logistics\LogisticsManifestService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LogisticsTransportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        $this->assertDatabaseMissing('users', ['email' => 'logistics.admin@bagoo.test']);
        $operator = User::where('email', 'logistics@bagoo.test')->firstOrFail();
        $motherHubOperator = User::where('email', 'motherhub@bagoo.test')->firstOrFail();
        $company = LogisticsCompany::where('code', 'BGX')->firstOrFail();
        $hub = LogisticsHub::where('code', 'BH-SCZ-01')->firstOrFail();
        $motherHub = LogisticsHub::where('code', 'MH-LAG-01')->firstOrFail();

        $this->assertSame($motherHubOperator->id, $company->user_id);
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
        $this->assertSame(4, User::where('role', 'courier')->count());
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

    public function test_seeded_transport_is_repeatable_and_ready_without_a_separate_company_login(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = [User::count(), LogisticsHub::count(), LogisticsFleet::count(), HubHandler::count()];
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($counts, [User::count(), LogisticsHub::count(), LogisticsFleet::count(), HubHandler::count()]);
        $company = LogisticsCompany::where('code', 'BGX')->sole();
        $mother = User::where('email', 'motherhub@bagoo.test')->sole();
        $bayan = User::where('email', 'losbanos.hub@bagoo.test')->sole();
        $eligibility = app(LogisticsEligibilityService::class);
        $this->assertTrue($eligibility->isCompanyManager($mother, $company->id));
        $this->assertFalse($eligibility->isCompanyManager($bayan, $company->id));
        $this->assertFalse($eligibility->canScan($mother, LogisticsHub::where('code', 'BH-LBN-01')->sole()));
        foreach (['origin.transport@bagoo.test' => 'BH-LBN-01', 'mother.transport@bagoo.test' => 'MH-LAG-01'] as $email => $code) {
            $driver = User::where('email', $email)->sole();
            $hub = LogisticsHub::where('code', $code)->sole();
            $vehicle = LogisticsFleet::where('assigned_driver_id', $driver->id)->sole();
            $this->assertSame($hub->id, $vehicle->hub_id);
            $this->assertSame('l300_van', $vehicle->vehicle_type);
            $this->assertTrue(Hash::check('Password1234', $driver->password));
            $this->assertSame($vehicle->id, app(LogisticsManifestService::class)
                ->vehicle($company->id, $hub->id, $vehicle->id, $driver->id, true, 'feeder')->id);
        }
        $mother->update(['status' => 'suspended']);
        $this->assertFalse($eligibility->isCompanyManager($mother, $company->id));
    }

    public function test_bounded_transport_repair_preserves_passwords_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $drivers = User::whereIn('email', ['origin.transport@bagoo.test', 'mother.transport@bagoo.test'])->get();
        foreach ($drivers as $driver) {
            $driver->courierProfile->delete();
            LogisticsFleet::where('assigned_driver_id', $driver->id)->delete();
            $driver->delete();
        }
        $this->seed(LogisticsTransportSeeder::class);
        $driver = User::where('email', 'origin.transport@bagoo.test')->sole();
        $driver->update(['password' => 'ChangedPassword123!']);
        $hash = $driver->fresh()->password;
        $counts = [User::count(), LogisticsFleet::count(), CourierProfile::count()];
        $this->seed(LogisticsTransportSeeder::class);
        $this->assertSame($hash, $driver->fresh()->password);
        $this->assertSame($counts, [User::count(), LogisticsFleet::count(), CourierProfile::count()]);
    }

    public function test_transport_repair_rejects_restricted_drivers_without_overriding_the_review(): void
    {
        $this->seed(DatabaseSeeder::class);
        $driver = User::where('email', 'mother.transport@bagoo.test')->sole();
        $driver->update(['status' => 'suspended']);
        $hash = $driver->password;
        try {
            $this->seed(LogisticsTransportSeeder::class);
            $this->fail('Restricted transport drivers require review.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('restricted', $error->getMessage());
        }
        $this->assertSame('suspended', $driver->fresh()->status);
        $this->assertSame($hash, $driver->fresh()->password);
    }

    public function test_reserved_vehicle_conflict_rolls_back_the_entire_transport_repair(): void
    {
        $this->seed(DatabaseSeeder::class);
        $origin = User::where('email', 'origin.transport@bagoo.test')->sole();
        $origin->courierProfile->delete();
        LogisticsFleet::where('assigned_driver_id', $origin->id)->delete();
        $origin->delete();
        $motherVan = LogisticsFleet::where('plate_number', 'BG-LAG-201')->sole();
        $motherVan->update(['status' => 'maintenance']);
        $counts = [User::count(), LogisticsFleet::count(), CourierProfile::count()];
        try {
            $this->seed(LogisticsTransportSeeder::class);
            $this->fail('Conflicting reserved vehicles must not be overwritten.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('conflicting', $error->getMessage());
        }
        $this->assertSame($counts, [User::count(), LogisticsFleet::count(), CourierProfile::count()]);
        $this->assertDatabaseMissing('users', ['email' => 'origin.transport@bagoo.test']);
        $this->assertSame('maintenance', $motherVan->fresh()->status);
    }

    public function test_legacy_unassigned_demo_van_is_repaired_without_replacing_the_vehicle(): void
    {
        $this->seed(DatabaseSeeder::class);
        $driver = User::where('email', 'mother.transport@bagoo.test')->sole();
        $van = LogisticsFleet::where('plate_number', 'BG-LAG-201')->sole();
        $driver->courierProfile->delete();
        $van->update(['assigned_driver_id' => null]);
        $driver->delete();
        $count = LogisticsFleet::count();
        $this->seed(LogisticsTransportSeeder::class);
        $newDriver = User::where('email', 'mother.transport@bagoo.test')->sole();
        $this->assertSame($count, LogisticsFleet::count());
        $this->assertSame($newDriver->id, $van->fresh()->assigned_driver_id);
        $this->assertSame($van->id, $newDriver->courierProfile->vehicle_id);
    }

    public function test_unassigned_van_with_a_retained_profile_is_not_silently_reassigned(): void
    {
        $this->seed(DatabaseSeeder::class);
        $driver = User::where('email', 'mother.transport@bagoo.test')->sole();
        $van = LogisticsFleet::where('plate_number', 'BG-LAG-201')->sole();
        $van->update(['assigned_driver_id' => null]);
        try {
            $this->seed(LogisticsTransportSeeder::class);
            $this->fail('Retained vehicle assignments need a reviewed repair.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('retained transport or assignment evidence', $error->getMessage());
        }
        $this->assertNull($van->fresh()->assigned_driver_id);
        $this->assertSame($van->id, $driver->courierProfile->vehicle_id);
    }

    public function test_unassigned_van_with_closed_manifest_history_requires_review(): void
    {
        $this->seed(DatabaseSeeder::class);
        $driver = User::where('email', 'mother.transport@bagoo.test')->sole();
        $van = LogisticsFleet::where('plate_number', 'BG-LAG-201')->sole();
        $company = LogisticsCompany::where('code', 'BGX')->sole();
        $manifest = LogisticsManifest::create([
            'logistics_company_id' => $company->id, 'source_hub_id' => $van->hub_id,
            'destination_hub_id' => LogisticsHub::where('code', 'BH-SCZ-01')->sole()->id,
            'vehicle_id' => $van->id, 'driver_id' => $driver->id, 'created_by_id' => $company->user_id,
            'creation_token' => (string) Str::uuid(), 'creation_fingerprint' => str_repeat('a', 64),
            'type' => 'feeder', 'status' => 'closed',
        ]);
        $driver->courierProfile->delete();
        $van->update(['assigned_driver_id' => null]);
        try {
            $this->seed(LogisticsTransportSeeder::class);
            $this->fail('Retained transport history requires reviewed repair.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('retained transport or assignment evidence', $error->getMessage());
        }
        $this->assertNull($van->fresh()->assigned_driver_id);
        $this->assertSame($driver->id, $manifest->fresh()->driver_id);
        $this->assertFalse($driver->courierProfile()->exists());
    }
}
