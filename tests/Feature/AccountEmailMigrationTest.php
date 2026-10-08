<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountEmailMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_preserves_original_identity_verification_and_existing_expression_indexes(): void
    {
        $migration = require database_path('migrations/2026_10_08_000001_create_account_emails_table.php');
        $migration->down();
        $user = User::factory()->buyer()->create(['email' => 'Buyer@BAGOO.TEST']);
        $before = $user->fresh()->getRawOriginal();
        $index = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'users_email_case_insensitive_unique'")->sql;
        $migration->up();
        $after = $user->fresh()->getRawOriginal();
        unset($after['preferred_contact_email_id']);
        $this->assertSame($before, $after);
        $this->assertSame($index, DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'users_email_case_insensitive_unique'")->sql);
        $this->assertDatabaseHas('account_emails', ['user_id' => $user->id, 'email' => 'buyer@bagoo.test', 'is_original' => true, 'verified_at' => $user->email_verified_at]);
        $user->update(['email_verified_at' => null]);
        $this->assertNull($user->accountEmails()->first()->verified_at);
    }

    public function test_upgrade_refuses_ambiguous_legacy_accounts_without_rewriting_them(): void
    {
        $migration = require database_path('migrations/2026_10_08_000001_create_account_emails_table.php');
        $migration->down();
        DB::statement('DROP INDEX users_email_case_insensitive_unique');
        $first = User::factory()->create(['email' => 'duplicate@bagoo.test']);
        $second = User::factory()->create(['email' => 'DUPLICATE@bagoo.test']);
        $before = [$first->fresh()->getRawOriginal(), $second->fresh()->getRawOriginal()];
        try {
            $migration->up();
            $this->fail('Ambiguous owners must be resolved before the migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('overlapping email addresses', $exception->getMessage());
        }
        $this->assertSame($before, [$first->fresh()->getRawOriginal(), $second->fresh()->getRawOriginal()]);
    }
}
