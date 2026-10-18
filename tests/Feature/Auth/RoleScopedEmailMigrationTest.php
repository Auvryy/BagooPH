<?php

namespace Tests\Feature\Auth;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoleScopedEmailMigrationTest extends TestCase
{
    private function legacySchema(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        foreach (glob(database_path('migrations/*.php')) as $path) {
            if (basename($path) >= '2026_10_18_090000_scope_account_emails_by_role.php') {
                break;
            }
            (require $path)->up();
        }
    }

    public function test_populated_upgrade_preserves_accounts_originals_contacts_and_existing_guards(): void
    {
        $this->legacySchema();
        $buyer = DB::table('users')->insertGetId(['name' => 'Existing Buyer', 'email' => 'old@bagoo.test', 'role' => 'buyer', 'password' => 'test-only-hash']);
        DB::table('account_emails')->insert(['user_id' => $buyer, 'email' => 'contact@bagoo.test', 'is_original' => false, 'verified_at' => now()]);
        $before = DB::table('users')->where('id', $buyer)->first();
        $addresses = DB::table('account_emails')->orderBy('id')->get();
        $triggers = DB::table('sqlite_master')->where('type', 'trigger')->pluck('name')->all();

        (require database_path('migrations/2026_10_18_090000_scope_account_emails_by_role.php'))->up();

        $this->assertEquals($before, DB::table('users')->where('id', $buyer)->first());
        foreach ($addresses as $address) {
            $after = DB::table('account_emails')->where('id', $address->id)->first();
            $this->assertSame('buyer', $after->role);
            unset($after->role);
            $this->assertEquals($address, $after);
        }
        $this->assertSame([], array_diff($triggers, DB::table('sqlite_master')->where('type', 'trigger')->pluck('name')->all()));
        $seller = DB::table('users')->insertGetId(['name' => 'New Seller', 'email' => 'OLD@BAGOO.TEST', 'role' => 'seller', 'password' => 'test-only-hash']);
        $this->assertDatabaseHas('account_emails', ['user_id' => $seller, 'email' => 'old@bagoo.test', 'role' => 'seller']);
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $buyer)->update(['email' => 'replacement@bagoo.test']);
    }

    public function test_company_name_upgrade_refuses_existing_duplicates_without_rewriting_data(): void
    {
        $this->legacySchema();
        foreach (['Bagoo Transport', ' BAGOO  TRANSPORT '] as $index => $name) {
            $owner = DB::table('users')->insertGetId(['name' => 'Company Owner', 'email' => 'owner'.$index.'@bagoo.test', 'role' => 'logistics', 'password' => 'test-only-hash']);
            DB::table('logistics_companies')->insert(['user_id' => $owner, 'name' => $name, 'code' => 'CODE'.$index, 'slug' => 'company-'.$index]);
        }
        $before = DB::table('logistics_companies')->orderBy('id')->get();
        try {
            (require database_path('migrations/2026_10_18_090100_enforce_unique_logistics_company_names.php'))->up();
            $this->fail('Existing conflicting names must be reviewed first.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('share a name', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('logistics_companies', 'name_key'));
        $this->assertEquals($before, DB::table('logistics_companies')->orderBy('id')->get());
    }
}
