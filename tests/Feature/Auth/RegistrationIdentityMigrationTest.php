<?php

namespace Tests\Feature\Auth;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationIdentityMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_13_190000_enforce_unique_account_mobiles_and_shop_names.php');
    }

    public function test_migration_normalizes_legacy_numbers_without_changing_shop_review_values(): void
    {
        $shop = Shop::factory()->approved()->create(['name' => ' Bagoo  Original Shop ']);
        $user = $shop->user;
        $empty = User::factory()->create(['phone' => null]);
        $shopBefore = $shop->fresh()->getAttributes();
        $reviewsBefore = DB::table('shop_review_decisions')->get()->toJson();
        $counts = [User::count(), Shop::count()];
        $migration = $this->migration();
        $migration->down();
        DB::table('users')->where('id', $user->id)->update(['phone' => '(0917) 123-4567']);
        DB::table('users')->where('id', $empty->id)->update(['phone' => '   ']);
        $migration->up();
        $this->assertSame('+639171234567', $user->fresh()->phone);
        $this->assertNull($empty->fresh()->phone);
        $shopAfter = $shop->fresh()->getAttributes();
        ksort($shopBefore);
        ksort($shopAfter);
        $this->assertSame($shopBefore, $shopAfter);
        $this->assertSame($reviewsBefore, DB::table('shop_review_decisions')->get()->toJson());
        $this->assertSame($counts, [User::count(), Shop::count()]);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public static function legacyConflicts(): array
    {
        return [['mobile'], ['shop'], ['landline']];
    }

    #[DataProvider('legacyConflicts')]
    public function test_legacy_conflicts_stop_the_migration_before_any_record_or_schema_changes(string $conflict): void
    {
        $first = Shop::factory()->create(['name' => 'Bagoo First Shop']);
        $second = Shop::factory()->create(['name' => 'Bagoo Second Shop']);
        $migration = $this->migration();
        $migration->down();
        DB::table('users')->where('id', $first->user_id)->update(['phone' => '09171234567']);
        if ($conflict === 'mobile') {
            DB::table('users')->where('id', $second->user_id)->update(['phone' => '+639171234567']);
        } elseif ($conflict === 'shop') {
            DB::table('shops')->where('id', $second->id)->update(['name' => ' BAGOO   FIRST SHOP ']);
        } else {
            DB::table('users')->where('id', $second->user_id)->update(['phone' => '(02) 8123-4567']);
        }
        $usersBefore = DB::table('users')->get()->toJson();
        $shopsBefore = DB::table('shops')->get()->toJson();
        try {
            $migration->up();
            $this->fail('Legacy conflicts must require an explicit correction.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no records were changed', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('shops', 'name_key'));
        $this->assertFalse(Schema::hasIndex('users', 'users_mobile_unique'));
        $this->assertSame($usersBefore, DB::table('users')->get()->toJson());
        $this->assertSame($shopsBefore, DB::table('shops')->get()->toJson());
    }
}
