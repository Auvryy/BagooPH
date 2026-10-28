<?php

namespace Tests\Feature\Seeders;

use App\Models\CourierProfile;
use App\Models\LogisticsCompany;
use App\Models\LogisticsFleet;
use App\Models\LogisticsHub;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DemoRecoveryTransportDeploymentTest extends TestCase
{
    use RefreshDatabase;

    private const EMAILS = ['mother.backup.transport@bagoo.test', 'return.transport@bagoo.test'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_empty_installation_is_not_seeded_by_the_upgrade(): void
    {
        $this->upgrade();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('logistics_fleet', 0);
    }

    public function test_approved_demo_upgrade_adds_only_missing_transport_and_preserves_accounts_hours_and_repeat_runs(): void
    {
        $this->legacyTransport();
        $owner = LogisticsCompany::sole()->user;
        $owner->update(['password' => 'KeepThisReviewedPassword123!']);
        LogisticsHub::where('code', 'BH-LBN-01')->update(['operating_hours' => null]);
        $before = User::orderBy('id')->get()->map->getRawOriginal()->all();
        $this->upgrade();
        $this->assertSame($before, User::whereNotIn('email', self::EMAILS)->orderBy('id')->get()->map->getRawOriginal()->all());
        foreach (['BG-LAG-202' => 'MH-LAG-01', 'BG-SCZ-201' => 'BH-SCZ-01'] as $plate => $hubCode) {
            $vehicle = LogisticsFleet::ready()->where('plate_number', $plate)->sole();
            $this->assertSame(LogisticsHub::where('code', $hubCode)->sole()->id, $vehicle->hub_id);
            $this->assertSame($vehicle->id, CourierProfile::operational()->where('user_id', $vehicle->assigned_driver_id)->sole()->vehicle_id);
        }
        $this->assertNull(LogisticsHub::where('code', 'BH-LBN-01')->sole()->operating_hours);
        $counts = [User::count(), LogisticsFleet::count(), CourierProfile::count()];
        $this->upgrade();
        $this->assertSame($counts, [User::count(), LogisticsFleet::count(), CourierProfile::count()]);
    }

    public function test_an_unrelated_owner_is_not_given_reserved_demo_accounts_or_vehicles(): void
    {
        $this->legacyTransport();
        $owner = User::factory()->create(['role' => 'logistics', 'status' => 'active', 'kyc_status' => 'approved']);
        LogisticsCompany::sole()->update(['user_id' => $owner->id]);
        $before = [User::count(), LogisticsFleet::count()];
        $this->upgrade();
        $this->assertSame($before, [User::count(), LogisticsFleet::count()]);
    }

    public function test_a_restricted_reserved_driver_is_not_reactivated_and_partial_transport_is_rolled_back(): void
    {
        $this->legacyTransport();
        $driver = User::factory()->create(['email' => self::EMAILS[1], 'role' => 'courier', 'status' => 'suspended']);
        $before = [User::count(), LogisticsFleet::count(), $driver->fresh()->getRawOriginal()];
        try {
            $this->upgrade();
            $this->fail('A restricted driver cannot be silently reapproved during migration.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('restricted', $error->getMessage());
        }
        $this->assertSame($before, [User::count(), LogisticsFleet::count(), $driver->fresh()->getRawOriginal()]);
        $this->assertDatabaseMissing('users', ['email' => self::EMAILS[0]]);
    }

    private function legacyTransport(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (User::whereIn('email', self::EMAILS)->get() as $driver) {
            $vehicle = $driver->courierProfile->vehicle;
            $driver->courierProfile->delete();
            $vehicle->delete();
            $driver->delete();
        }
    }

    private function upgrade(): void
    {
        (require database_path('migrations/2026_10_28_000000_complete_reserved_demo_transport.php'))->up();
    }
}
