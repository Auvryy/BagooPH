<?php

namespace Tests\Feature\Finance;

use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletRefundReserveEntry;
use App\Models\WalletTransaction;
use App\Services\AccountClosureService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Finance\ShipmentLossWallet;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletLossReserve;
use App\Services\Wallet\WalletSettlementService;
use App\Services\Wallet\WalletViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentLossWalletTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function plan(bool $return = false, bool $voucher = false): array
    {
        [$source, $admin, $order, $case] = $this->missing('wallet', $return, $voucher);
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, $return ? '0.00' : '180.00'), UploadedFile::fake()->image('actual-investigation.png'));

        return [$plan, $admin, $order, $case];
    }

    private function commandInput(ShipmentFinancialPlan $plan, string $action, ?int $amount = null): array
    {
        $field = ['reserve-original' => 'original_reserve_confirmed', 'contribute-company' => 'company_transfer_confirmed',
            'approve-advance' => 'demo_advance_confirmed', 'resolve-wallet' => 'loss_settlement_confirmed'][$action];

        return ['expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'reason' => 'The named account confirms the original separate actual balanced loss funding and approved recipient amounts.', $field => true,
            ...($amount === null ? [] : ['amount' => SellerSettlementService::pesos($amount)])];
    }

    private function command(User $actor, ShipmentFinancialPlan $plan, string $action, ?int $amount = null)
    {
        $input = $this->commandInput($plan, $action, $amount);
        $service = app(ShipmentLossWallet::class);
        $event = $service->command($actor, $plan, $action, $input);
        $this->assertSame($event->id, $service->command($actor, $plan, $action, $input)->id);

        return $event;
    }

    private function balancedOwnerCredits(User $owner, User $admin, int $amount): void
    {
        // Test-only actual balanced credit source; no grant or transfer is inferred from a request label.
        DB::transaction(function () use ($owner, $admin, $amount) {
            $ledger = app(WalletLedger::class);
            $ledger->post('isolated-loss-funding:'.$owner->id, 'demo_credit', $amount,
                [$ledger->platform('demo_issuance')->id => -$amount, $ledger->user($owner)->id => $amount], $admin,
                'Explicit isolated demonstration issuance for the named company’s actual transfer.');
        });
    }

    public static function originalClaims(): array
    {
        return [[false, false, 25000, 18000, 833], [false, true, 23000, 18000, 833], [true, false, 12500, 0, 0], [true, true, 11500, 0, 0]];
    }

    #[DataProvider('originalClaims')]
    public function test_paid_loss_consumes_its_actual_reserve_once_and_separates_buyer_seller_and_earned_shipping(bool $return, bool $voucher, int $buyerRefund, int $protection, int $earned): void
    {
        [$plan, $admin, $order, $case] = $this->plan($return, $voucher);
        $ledger = app(WalletLedger::class);
        $reserve = app(WalletLossReserve::class);
        $before = ['buyer' => $ledger->balance($order->buyer), 'seller' => $ledger->balance($order->items->first()->shop->user),
            'shipping' => WalletAccount::where('kind', 'shipping')->value('balance_cents') ?? 0,
            'commission' => WalletAccount::where('kind', 'commission')->value('balance_cents') ?? 0];
        $physical = [$order->getAttributes(), $order->delivery->getAttributes(), $order->delivery->checkpoints()->get()->toArray(),
            $case?->getAttributes(), $case?->parcel->getAttributes()];
        if (! $return) {
            $this->command($admin, $plan, 'reserve-original');
        }
        $this->command($admin, $plan, 'approve-advance', $plan->snapshot['company_funding_due_cents']);
        $event = $this->command($admin, $plan, 'resolve-wallet');
        $transaction = WalletTransaction::findOrFail($event->wallet_transaction_id);
        $this->assertSame('wallet_loss_refund', $transaction->kind);
        $this->assertSame($buyerRefund, $transaction->amount_cents);
        $paymentView = app(WalletViewService::class)->payment($order->buyer, $order->walletPayment);
        $this->assertSame($buyerRefund, $paymentView['refunded_cents']);
        $this->assertSame($transaction->reference, $paymentView['refund_reference']);
        $this->assertSame($buyerRefund, $ledger->balance($order->buyer) - $before['buyer']);
        $this->assertSame($protection, $ledger->balance($order->items->first()->shop->user) - $before['seller']);
        $this->assertSame($earned, (WalletAccount::where('kind', 'shipping')->value('balance_cents') ?? 0) - $before['shipping']);
        $this->assertSame($before['commission'], WalletAccount::where('kind', 'commission')->value('balance_cents') ?? 0);
        $this->assertSame(0, $reserve->summary($plan)['balance_cents']);
        $this->assertSame($plan->snapshot['company_funding_due_cents'], $reserve->summary($plan)['company_remaining_cents']);
        $this->assertTrue($reserve->summary($plan)['resolved']);
        $this->assertSame(0, (int) $transaction->entries()->sum('delta_cents'));
        $this->assertSame($physical, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $order->delivery->checkpoints()->get()->toArray(),
            $case?->fresh()->getAttributes(), $case?->parcel->fresh()->getAttributes()]);
        $this->assertSame($return && $voucher ? 1 : 0, DB::table('wallet_promotion_expenses')->count());
        $this->assertSame(! $return && $voucher ? 1 : 0, DB::table('wallet_voucher_releases')->count());
        if (! $return) {
            $view = app(WalletSettlementService::class)->present($order->fresh(['walletPayment']));
            $this->assertFalse($view['can_settle']);
            $this->assertFalse($view['can_refund_delivery']);
        }
    }

    public function test_company_debt_clears_only_with_actual_own_credits_and_late_transfer_retires_actual_advance_without_paying_twice(): void
    {
        [$plan, $admin, $order] = $this->plan();
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $amount = $plan->snapshot['company_funding_due_cents'];
        $this->assertContains('shipment_loss_review', array_column(app(AccountClosureService::class)->state($owner)['blockers'], 'code'));
        $this->command($admin, $plan, 'reserve-original');
        $this->command($admin, $plan, 'approve-advance', $amount);
        $this->command($admin, $plan, 'resolve-wallet');
        $codes = array_column(app(AccountClosureService::class)->state($owner)['blockers'], 'code');
        $this->assertNotContains('shipment_loss_review', $codes);
        $this->assertContains('shipment_loss_company_liability', $codes);
        $buyer = app(WalletLedger::class)->balance($order->buyer);
        $seller = app(WalletLedger::class)->balance($order->items->first()->shop->user);
        $this->balancedOwnerCredits($owner, $admin, $amount);
        $owner->update(['status' => 'suspended']);
        $this->command($owner, $plan, 'contribute-company', $amount);
        $state = app(WalletLossReserve::class)->summary($plan);
        $this->assertSame(0, $state['company_remaining_cents']);
        $this->assertNotContains('shipment_loss_company_liability', array_column(app(AccountClosureService::class)->state($owner->fresh())['blockers'], 'code'));
        $this->assertSame(0, $state['advance_outstanding_cents']);
        $this->assertSame(0, $state['balance_cents']);
        $this->assertSame(0, app(WalletLedger::class)->balance($owner));
        $this->assertSame($buyer, app(WalletLedger::class)->balance($order->buyer));
        $this->assertSame($seller, app(WalletLedger::class)->balance($order->items->first()->shop->user));
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_loss_refund')->count());
        $this->assertSame($amount, WalletTransaction::where('kind', 'wallet_loss_advance_retirement')->sole()->amount_cents);
    }

    public function test_funding_caps_original_recipient_roles_unfunded_execution_and_void_after_funding_are_enforced_atomically(): void
    {
        [$plan, $admin, $order] = $this->plan();
        $service = app(ShipmentLossWallet::class);
        $before = WalletTransaction::count();
        foreach ([[$admin, 'resolve-wallet', null, 409], [$order->buyer, 'approve-advance', 100, 403],
            [$this->createApprovedUser('logistics'), 'contribute-company', 100, 403],
            [$admin, 'approve-advance', $plan->snapshot['company_funding_due_cents'] + 1, 409]] as [$actor, $action, $amount, $status]) {
            try {
                $service->command($actor, $plan, $action, $this->commandInput($plan, $action, $amount));
                $this->fail('Unfunded, unowned or excessive credits cannot execute an original loss.');
            } catch (HttpException $error) {
                $this->assertSame($status, $error->getStatusCode());
            }
        }
        $this->assertSame($before, WalletTransaction::count());
        $this->assertDatabaseCount('wallet_refund_reserve_entries', 0);
        $this->command($admin, $plan, 'reserve-original');
        try {
            app(ShipmentLossReview::class)->voidUnfunded($admin, $plan, ['expected_version' => 2, 'request_token' => (string) Str::uuid(),
                'unfunded_void_confirmed' => true, 'reason' => 'This original review already reserved actual credits and cannot be described as unfunded.']);
            $this->fail('An actual reserve cannot disappear through an unfunded void.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($order->id, $plan->fresh()->active_order_id);
    }

    public function test_later_actual_receipt_stops_new_loss_money_while_exact_original_funding_retry_preserves_its_reference(): void
    {
        [$plan, $admin, $order] = $this->plan();
        $service = app(ShipmentLossWallet::class);
        $input = $this->commandInput($plan, 'reserve-original');
        $event = $service->command($admin, $plan, 'reserve-original', $input);
        $manifest = LogisticsManifest::findOrFail($plan->snapshot['source']['manifest_id']);
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $this->openFlowMotherBags($manifest, $receiver);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest,
            ['barcode' => $order->delivery->tracking_number, 'notes' => 'The original missing parcel was actually found and received.']))->assertOk();
        $this->assertSame($event->id, $service->command($admin, $plan, 'reserve-original', $input)->id);
        try {
            $this->command($admin, $plan, 'approve-advance', $plan->snapshot['company_funding_due_cents']);
            $this->fail('A later actual receipt invalidates the frozen loss source before new money.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('wallet_refund_reserve_entries', 1);
    }

    public static function mislabeledSources(): array
    {
        return [['original_escrow'], ['advance']];
    }

    #[DataProvider('mislabeledSources')]
    public function test_a_balanced_wrong_account_debit_cannot_be_labeled_as_the_original_loss_reserve_source(string $kind): void
    {
        [$plan, $admin, $order] = $this->plan();
        $before = WalletTransaction::count();
        $buyer = app(WalletLedger::class)->balance($order->buyer);
        try {
            DB::transaction(function () use ($plan, $admin, $order, $kind) {
                $ledger = app(WalletLedger::class);
                $wrong = $ledger->user($order->buyer);
                $reserve = $ledger->platform('return_reserve');
                $transaction = $ledger->post('isolated-invalid-loss-source', 'wallet_loss_funding', 100,
                    [$wrong->id => -100, $reserve->id => 100], $admin, 'A balanced debit from the buyer cannot impersonate original escrow or a platform advance.',
                    ['shipment_financial_plan_reference' => $plan->reference, 'reserve_source_kind' => $kind, 'source_account_id' => $wrong->id], orderId: $plan->order_id);
                WalletRefundReserveEntry::create(['order_id' => $plan->order_id, 'authority_reference' => $plan->reference,
                    'shipment_financial_plan_id' => $plan->id, 'wallet_transaction_id' => $transaction->id, 'wallet_account_id' => $reserve->id,
                    'source_kind' => $kind, 'delta_cents' => 100, 'context' => ['demo_only' => true], 'created_at' => now()]);
            });
            $this->fail('Retained loss evidence must identify the actual original debit counterparty.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($before, WalletTransaction::count());
        $this->assertSame($buyer, app(WalletLedger::class)->balance($order->buyer));
        $this->assertDatabaseCount('wallet_refund_reserve_entries', 0);
    }

    public function test_late_reserve_failure_rolls_back_the_entire_balanced_credit_journal(): void
    {
        [$plan, $admin] = $this->plan();
        $count = WalletTransaction::count();
        $balance = WalletAccount::where('kind', 'escrow')->value('balance_cents');
        WalletRefundReserveEntry::creating(function () {
            throw new \RuntimeException('Isolated late reserve failure.');
        });
        try {
            $this->command($admin, $plan, 'reserve-original');
            $this->fail('The isolated failure must roll back its actual journal.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Isolated late reserve failure.', $error->getMessage());
        } finally {
            WalletRefundReserveEntry::flushEventListeners();
            WalletRefundReserveEntry::clearBootedModels();
        }
        $this->assertSame($count, WalletTransaction::count());
        $this->assertSame($balance, WalletAccount::where('kind', 'escrow')->value('balance_cents'));
        $this->assertDatabaseCount('wallet_refund_reserve_entries', 0);
        $this->assertSame(1, $plan->events()->count());
    }
}
