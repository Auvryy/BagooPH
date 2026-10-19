<?php

namespace Tests\Feature\Wallet;

use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WalletCreditTest extends TestCase
{
    use RefreshDatabase;

    private function input(string $amount = '1000.00'): array
    {
        return ['amount' => $amount, 'reason' => 'Credits for a Bagoo demonstration purchase.', 'request_token' => (string) Str::uuid()];
    }

    private function review(string $decision = 'approved'): array
    {
        return ['decision' => $decision, 'reason' => 'Reviewed demo credit request for this buyer.',
            'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()];
    }

    public function test_request_does_not_create_money_and_approved_credit_is_balanced_and_replayable(): void
    {
        $buyer = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $service = app(WalletCreditService::class);
        $input = $this->input();
        $request = $service->request($buyer, $input);
        $this->assertSame($request->id, $service->request($buyer, $input)->id);
        $this->assertDatabaseCount('wallet_accounts', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $review = $this->review();
        $approved = $service->review($admin, $request, $review);
        $this->assertSame($approved->id, $service->review($admin, $request, $review)->id);
        $this->assertSame('approved', $approved->status);
        $this->assertNull($approved->pending_buyer_id);
        $this->assertSame(100000, WalletAccount::where('user_id', $buyer->id)->sole()->balance_cents);
        $this->assertSame(-100000, WalletAccount::where('kind', 'demo_issuance')->sole()->balance_cents);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame(0, (int) WalletEntry::sum('delta_cents'));
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('seller_settlements', 0);
    }

    public function test_rejection_preserves_the_request_and_allows_a_new_request_without_crediting_funds(): void
    {
        $buyer = User::factory()->create();
        $service = app(WalletCreditService::class);
        $request = $service->request($buyer, $this->input('1.00'));
        $service->review(User::factory()->admin()->create(), $request, $this->review('rejected'));
        $new = $service->request($buyer, $this->input('2.00'));
        $this->assertNotSame($request->id, $new->id);
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertDatabaseCount('wallet_accounts', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_a_second_pending_request_is_rejected(): void
    {
        $buyer = User::factory()->create();
        app(WalletCreditService::class)->request($buyer, $this->input());
        $this->expectException(ValidationException::class);
        app(WalletCreditService::class)->request($buyer, $this->input());
    }

    #[DataProvider('badAmounts')]
    public function test_credit_amounts_reject_invalid_format_precision_and_boundaries(mixed $amount): void
    {
        $input = $this->input();
        $input['amount'] = $amount;
        $this->expectException(ValidationException::class);
        app(WalletCreditService::class)->request(User::factory()->create(), $input);
    }

    public static function badAmounts(): array
    {
        return array_map(fn ($value) => [$value], ['', '0', '0.99', '-1', '1.001', '100000.01', '1e3', '1,000', ' 1', '01', '١٠', 100, true, []]);
    }

    #[DataProvider('restrictedActors')]
    public function test_only_a_current_verified_admin_can_approve(array $attributes): void
    {
        $buyer = User::factory()->create();
        $request = app(WalletCreditService::class)->request($buyer, $this->input());
        $actor = User::factory()->create($attributes);
        $this->expectException(HttpException::class);
        app(WalletCreditService::class)->review($actor, $request, $this->review());
    }

    public static function restrictedActors(): array
    {
        return [[['role' => 'buyer']], [['role' => 'seller']], [['role' => 'courier']], [['role' => 'logistics']],
            [['role' => 'admin', 'status' => 'suspended']], [['role' => 'admin', 'email_verified_at' => null]]];
    }

    public function test_a_second_admin_cannot_approve_the_same_credit_request_again(): void
    {
        $service = app(WalletCreditService::class);
        $buyer = User::factory()->create();
        $request = $service->request($buyer, $this->input());
        $service->review(User::factory()->admin()->create(), $request, $this->review());
        try {
            $service->review(User::factory()->admin()->create(), $request, $this->review());
            $this->fail('A second approval must be rejected.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame(100000, WalletAccount::where('user_id', $buyer->id)->sole()->balance_cents);
    }

    public function test_failed_journal_insert_rolls_back_both_balances_and_the_approval(): void
    {
        $service = app(WalletCreditService::class);
        $request = $service->request(User::factory()->create(), $this->input());
        Event::listen('eloquent.created: '.WalletEntry::class, fn () => throw new RuntimeException('Simulated journal failure'));
        try {
            $service->review(User::factory()->admin()->create(), $request, $this->review());
            $this->fail('The journal failure must reject approval.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated journal failure', $error->getMessage());
        } finally {
            Event::forget('eloquent.created: '.WalletEntry::class);
        }
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertDatabaseCount('wallet_accounts', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_direct_database_changes_cannot_rewrite_wallet_history(): void
    {
        $service = app(WalletCreditService::class);
        $request = $service->request(User::factory()->create(), $this->input());
        $service->review(User::factory()->admin()->create(), $request, $this->review());
        $this->expectException(QueryException::class);
        DB::table('wallet_entries')->update(['delta_cents' => 123]);
    }

    public function test_a_modified_cached_balance_cannot_create_spendable_credit(): void
    {
        $buyer = User::factory()->create();
        $ledger = app(WalletLedger::class);
        $account = $ledger->user($buyer);
        $platform = $ledger->platform('escrow');
        DB::table('wallet_accounts')->where('id', $account->id)->update(['balance_cents' => 100000]);
        $this->expectException(HttpException::class);
        DB::transaction(fn () => $ledger->post('test-payment', 'payment', 100, [$account->id => -100, $platform->id => 100], $buyer, 'Demo purchase test.'));
    }
}
