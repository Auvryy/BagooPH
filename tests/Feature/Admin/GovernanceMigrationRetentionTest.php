<?php

namespace Tests\Feature\Admin;

use App\Models\AccountClosure;
use App\Models\Shop;
use App\Models\ShopReviewDecision;
use App\Models\User;
use App\Services\AccountClosureService;
use App\Services\IdentityCorrectionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GovernanceMigrationRetentionTest extends TestCase
{
    use RefreshDatabase;

    public static function operations(): array
    {
        return [['update'], ['delete']];
    }

    #[DataProvider('operations')]
    public function test_sqlite_rollback_refuses_to_rebuild_referenced_shop_reviews_and_preserves_guards(string $operation): void
    {
        $shop = Shop::factory()->approved()->create();
        $before = ShopReviewDecision::where('shop_id', $shop->id)->firstOrFail()->getRawOriginal();
        $migration = require database_path('migrations/2026_10_05_140000_create_identity_corrections.php');
        try {
            $migration->down();
            $this->fail('Referenced shop reviews must prevent a SQLite table rebuild.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('must be retained', $error->getMessage());
        }
        try {
            $operation === 'update' ? DB::table('shop_review_decisions')->update(['reason' => 'Changed after rollback.']) : DB::table('shop_review_decisions')->delete();
            $this->fail('Prior shop review guards must survive the empty rollback.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Shop review decisions are immutable', $error->getMessage());
        }
        $this->assertSame($before, ShopReviewDecision::where('shop_id', $shop->id)->firstOrFail()->getRawOriginal());
    }

    public function test_recorded_identity_requests_refuse_destructive_migration_rollback(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('kyc_documents/correction.pdf', '%PDF-1.4 Bagoo identity evidence');
        $subject = User::factory()->create(['id_document_path' => 'kyc_documents/correction.pdf']);
        $form = app(IdentityCorrectionService::class)->form($subject, $subject);
        $request = Request::create('/account/identity-corrections', 'POST', ['source_token' => $form['source_token'],
            'changes' => ['birthday' => '1990-01-01'], 'reason' => 'The birth date needs evidence-backed correction.']);
        $request->setUserResolver(fn () => $subject);
        $correction = app(IdentityCorrectionService::class)->submit($request, $subject);
        $before = $correction->getAttributes();
        $migration = require database_path('migrations/2026_10_05_140000_create_identity_corrections.php');
        try {
            $migration->down();
            $this->fail('Recorded correction evidence must prevent rollback.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('must be retained', $error->getMessage());
        }
        $this->assertEquals($before, $correction->fresh()->getAttributes());
        $this->assertTrue(Storage::disk('local')->exists('kyc_documents/correction.pdf'));
    }

    public function test_recorded_closure_refuses_destructive_migration_rollback(): void
    {
        $actor = User::factory()->admin()->create();
        $subject = User::factory()->create();
        $form = app(AccountClosureService::class)->presentation($actor, $subject->id);
        app(AccountClosureService::class)->close($actor, $subject->id, ['password' => 'password', 'source_token' => $form['source_token'],
            'reason' => 'This unused account is ready to close.']);
        $before = AccountClosure::sole()->getAttributes();
        $migration = require database_path('migrations/2026_10_05_150000_create_account_closures.php');
        try {
            $migration->down();
            $this->fail('A recorded closure must prevent rollback.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('must be retained', $error->getMessage());
        }
        $this->assertSame($before, AccountClosure::sole()->getAttributes());
        $this->assertNull($subject->fresh());
    }
}
