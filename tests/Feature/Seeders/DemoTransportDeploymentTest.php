<?php

namespace Tests\Feature\Seeders;

use App\Models\CourierProfile;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DemoTransportDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_26_000000_repair_existing_demo_transport';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_migration_does_not_seed_an_empty_installation(): void
    {
        $this->migration()->up();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('logistics_companies', 0);
        $this->assertDatabaseCount('logistics_fleet', 0);
    }

    public static function demoOwners(): array
    {
        return [['motherhub@bagoo.test'], ['logistics.admin@bagoo.test']];
    }

    #[DataProvider('demoOwners')]
    public function test_normal_migrate_repairs_legacy_demo_transport_once(string $ownerEmail): void
    {
        $this->legacyNetwork();
        $owner = $ownerEmail === 'motherhub@bagoo.test'
            ? User::where('email', $ownerEmail)->sole()
            : User::factory()->create(['email' => $ownerEmail, 'role' => 'logistics', 'status' => 'active', 'kyc_status' => 'approved']);
        LogisticsCompany::sole()->update(['user_id' => $owner->id]);
        $owner->update(['password' => 'KeepMyExistingPassword123!']);
        $hash = $owner->fresh()->password;
        $motherVan = LogisticsFleet::where('plate_number', 'BG-LAG-201')->sole();
        $companyBefore = LogisticsCompany::sole()->getRawOriginal();
        $usersBefore = User::orderBy('id')->get()->map->getRawOriginal()->all();
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertSame($companyBefore, LogisticsCompany::sole()->getRawOriginal());
        $this->assertSame($usersBefore, User::whereNotIn('email', [
            'origin.transport@bagoo.test', 'mother.transport@bagoo.test',
        ])->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($hash, $owner->fresh()->password);
        $this->assertSame($motherVan->id, LogisticsFleet::where('plate_number', 'BG-LAG-201')->sole()->id);
        foreach (['BG-LBN-201', 'BG-LAG-201'] as $plate) {
            $vehicle = LogisticsFleet::ready()->where('plate_number', $plate)->sole();
            $profile = CourierProfile::operational()->where('user_id', $vehicle->assigned_driver_id)->sole();
            $this->assertTrue($profile->is_available);
            $this->assertSame($vehicle->id, $profile->vehicle_id);
            $this->assertSame($vehicle->hub_id, $profile->assigned_hub_id);
        }
        $counts = [User::count(), LogisticsFleet::count(), CourierProfile::count()];
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertSame($counts, [User::count(), LogisticsFleet::count(), CourierProfile::count()]);
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
    }

    public function test_matching_company_owned_by_another_account_is_untouched(): void
    {
        $this->legacyNetwork();
        $owner = User::factory()->create(['email' => 'owner@operator.test', 'role' => 'logistics', 'status' => 'active', 'kyc_status' => 'approved']);
        LogisticsCompany::sole()->update(['user_id' => $owner->id]);
        $before = [User::count(), LogisticsFleet::count(), CourierProfile::count()];
        $this->migration()->up();
        $this->assertSame($before, [User::count(), LogisticsFleet::count(), CourierProfile::count()]);
        $this->assertNull(LogisticsFleet::where('plate_number', 'BG-LAG-201')->sole()->assigned_driver_id);
    }

    public function test_restricted_demo_company_is_not_reactivated(): void
    {
        $this->legacyNetwork();
        LogisticsCompany::sole()->update(['status' => 'suspended']);
        $before = [User::count(), LogisticsFleet::count(), CourierProfile::count()];
        $this->migration()->up();
        $this->assertSame($before, [User::count(), LogisticsFleet::count(), CourierProfile::count()]);
        $this->assertSame('suspended', LogisticsCompany::sole()->status);
    }

    public function test_restricted_company_owner_is_not_reapproved_or_given_transport(): void
    {
        $this->legacyNetwork();
        $owner = LogisticsCompany::sole()->user;
        $owner->update(['status' => 'suspended']);
        $before = [$owner->fresh()->getRawOriginal(), User::count(), LogisticsFleet::count()];
        $this->migration()->up();
        $this->assertSame($before, [$owner->fresh()->getRawOriginal(), User::count(), LogisticsFleet::count()]);
    }

    public function test_unavailable_required_mother_hub_rolls_back_origin_transport_creation(): void
    {
        $this->legacyNetwork();
        LogisticsHub::where('code', 'MH-LAG-01')->sole()->update(['is_active' => false]);
        $before = [User::count(), LogisticsFleet::count(), CourierProfile::count()];
        try {
            $this->migration()->up();
            $this->fail('The required Mother Hub must remain active before provisioning this route.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('MH-LAG-01', $error->getMessage());
        }
        $this->assertSame($before, [User::count(), LogisticsFleet::count(), CourierProfile::count()]);
        $this->assertDatabaseMissing('logistics_fleet', ['plate_number' => 'BG-LBN-201']);
        $this->assertFalse(LogisticsHub::where('code', 'MH-LAG-01')->sole()->is_active);
    }

    public function test_conflict_fails_migration_without_retaining_partial_repair_or_success_record(): void
    {
        $this->legacyNetwork();
        LogisticsFleet::where('plate_number', 'BG-LAG-201')->sole()->update(['status' => 'maintenance']);
        $before = [User::count(), LogisticsFleet::count(), CourierProfile::count()];
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        try {
            $this->artisan('migrate', ['--force' => true]);
            $this->fail('A conflicting vehicle must stop deployment instead of reporting a repaired network.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('conflicting', $error->getMessage());
        }
        $this->assertSame($before, [User::count(), LogisticsFleet::count(), CourierProfile::count()]);
        $this->assertDatabaseMissing('users', ['email' => 'origin.transport@bagoo.test']);
        $this->assertDatabaseMissing('logistics_fleet', ['plate_number' => 'BG-LBN-201']);
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        $this->assertSame('maintenance', LogisticsFleet::where('plate_number', 'BG-LAG-201')->sole()->status);
    }

    public function test_existing_driver_password_availability_and_transport_are_retained_on_replay_and_rollback(): void
    {
        $this->seed(DatabaseSeeder::class);
        $driver = User::where('email', 'origin.transport@bagoo.test')->sole();
        $driver->update(['password' => 'ChangedDriverPassword123!']);
        $driver->courierProfile->update(['is_available' => false]);
        $before = [$driver->fresh()->getRawOriginal(), $driver->courierProfile->fresh()->getRawOriginal(),
            LogisticsFleet::orderBy('id')->get()->map->getRawOriginal()->all()];
        $this->migration()->up();
        $this->migration()->down();
        $this->assertSame($before, [$driver->fresh()->getRawOriginal(), $driver->courierProfile->fresh()->getRawOriginal(),
            LogisticsFleet::orderBy('id')->get()->map->getRawOriginal()->all()]);
    }

    private function legacyNetwork(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (['origin.transport@bagoo.test', 'mother.transport@bagoo.test'] as $email) {
            $driver = User::where('email', $email)->sole();
            $vehicle = $driver->courierProfile->vehicle;
            $driver->courierProfile->delete();
            $vehicle->update(['assigned_driver_id' => null]);
            if ($email === 'origin.transport@bagoo.test') {
                $vehicle->delete();
            }
            $driver->delete();
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }
}
