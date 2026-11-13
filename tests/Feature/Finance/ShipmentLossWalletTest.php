<?php

namespace Tests\Feature\Finance;

use App\Models\LogisticsCompany;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletRefundReserveEntry;
use App\Models\WalletTransaction;
use App\Services\AccountClosureService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Finance\ShipmentLossControls;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Finance\ShipmentLossWallet;
use App\Services\Wallet\WalletFundingCredits;
use App\Services\Wallet\WalletLedger;
use App\Services\Wallet\WalletLossCreditService;
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

    private function reviewedOwnerCredits(User $owner, User $admin, int $amount, ShipmentFinancialPlan $plan): void
    {
        $credits = app(WalletLossCreditService::class);
        $before = app(WalletLossReserve::class)->summary($plan);
        $ownedBefore = app(WalletLedger::class)->balance($owner);
        $input = ['expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'amount' => SellerSettlementService::pesos($amount), 'reason' => 'The original liable company requests independently reviewed demonstration credits for its own loss debt.'];
        $count = WalletTransaction::count();
        $request = $credits->request($owner, $plan, $input);
        $this->assertSame($request->id, $credits->request($owner, $plan, $input)->id);
        $this->assertSame($count, WalletTransaction::count());
        $review = ['version' => 1, 'request_token' => (string) Str::uuid(), 'decision' => 'approved', 'demo_credit_confirmed' => true,
            'reason' => 'Independently checked the original named company, actual remaining need and prior grants before explicit demonstration issuance.'];
        $saved = $credits->review($admin, $request, $review);
        $this->assertSame($saved->id, $credits->review($admin, $request, $review)->id);
        $this->assertSame($before, app(WalletLossReserve::class)->summary($plan));
        $this->assertSame($ownedBefore + $amount, app(WalletLedger::class)->balance($owner));
        $this->assertSame($amount, WalletTransaction::findOrFail($saved->wallet_transaction_id)->amount_cents);
    }

    public function test_two_actual_loss_cases_cannot_spend_the_other_original_reviewed_grant(): void
    {
        [$first, $admin] = $this->plan();
        [$second] = $this->plan();
        $this->assertSame($first->logistics_company_id, $second->logistics_company_id);
        $owner = LogisticsCompany::findOrFail($first->logistics_company_id)->user;
        $this->reviewedOwnerCredits($owner, $admin, 18833, $first);
        $before = app(WalletLossReserve::class)->summary($second);
        try {
            app(ShipmentLossWallet::class)->command($owner, $second, 'contribute-company', $this->commandInput($second, 'contribute-company', 18833));
            $this->fail('Credits reviewed for the first original loss cannot leave that authority unfunded by paying another loss.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($before, app(WalletLossReserve::class)->summary($second));
        $this->assertSame(18833, app(WalletLossCreditService::class)->maximum($owner, $second));
        $this->reviewedOwnerCredits($owner, $admin, 18833, $second);
        foreach ([$second, $first] as $plan) {
            $this->command($owner, $plan, 'contribute-company', 18833);
            $this->command($admin, $plan, 'reserve-original');
            $this->command($admin, $plan, 'resolve-wallet');
        }
        $this->assertSame(0, app(WalletLedger::class)->balance($owner));
        $this->assertSame(2, WalletTransaction::where('kind', 'wallet_loss_refund')->count());
        $this->assertSame(0, app(WalletLossCreditService::class)->maximum($owner, $first));
        $this->assertSame(0, app(WalletLossCreditService::class)->maximum($owner, $second));
    }

    public function test_finding_after_an_actual_advance_backed_refund_holds_new_grants_and_funding_without_erasing_paid_beneficiaries(): void
    {
        [$plan, $admin, $order] = $this->plan();
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $this->command($admin, $plan, 'reserve-original');
        $this->command($admin, $plan, 'approve-advance', 18833);
        $paidInput = $this->commandInput($plan, 'resolve-wallet');
        $paid = app(ShipmentLossWallet::class)->command($admin, $plan, 'resolve-wallet', $paidInput);
        $credits = app(WalletLossCreditService::class);
        $request = $credits->request($owner, $plan, ['expected_version' => (int) $plan->events()->max('sequence'),
            'request_token' => (string) Str::uuid(), 'amount' => '188.33', 'reason' => 'The actual original company still owes its funded original advance.']);
        $finding = $this->quarantineMissing($plan);
        $before = [WalletTransaction::count(), app(WalletLossReserve::class)->summary($plan), app(WalletLedger::class)->balance($order->buyer),
            app(WalletLedger::class)->balance($order->shop->user)];
        foreach ([fn () => $credits->maximum($owner, $plan),
            fn () => $credits->review($admin, $request, ['version' => 1, 'request_token' => (string) Str::uuid(), 'decision' => 'approved',
                'demo_credit_confirmed' => true, 'reason' => 'The original finding must hold this new grant even after a paid refund.']),
            fn () => app(ShipmentLossWallet::class)->command($owner, $plan, 'contribute-company', $this->commandInput($plan, 'contribute-company', 100))] as $action) {
            try {
                $action();
                $this->fail('A resolved refund cannot bypass the actual found-parcel hold.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $this->assertSame($paid->id, app(ShipmentLossWallet::class)->command($admin, $plan, 'resolve-wallet', $paidInput)->id);
        $this->assertSame($before, [WalletTransaction::count(), app(WalletLossReserve::class)->summary($plan), app(WalletLedger::class)->balance($order->buyer),
            app(WalletLedger::class)->balance($order->shop->user)]);
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('loss_quarantine', $finding->custody_after['kind']);
        $state = app(ShipmentLossControls::class)->present($owner, $plan, app(WalletLossReserve::class)->summary($plan), $plan->events()->get());
        $this->assertNotNull($state['hold_message']);
        $this->assertNotContains('contribute-company', array_column($state['actions'], 'id'));
        $this->assertSame(0, $state['credits']['maximum_cents']);
    }

    public function test_an_earlier_owned_contribution_cannot_consume_a_later_reviewed_grant_for_another_case(): void
    {
        [$first, $admin] = $this->plan();
        [$second] = $this->plan();
        $owner = LogisticsCompany::findOrFail($first->logistics_company_id)->user;
        $ledger = app(WalletLedger::class);
        DB::transaction(fn () => $ledger->post('isolated-general-owned-before-grant', 'demo_credit', 10000,
            [$ledger->user($owner)->id => 10000, $ledger->platform('demo_issuance')->id => -10000], $admin,
            'Explicit balanced existing owned credits for the isolated contribution chronology check.', ['demo_only' => true]));
        $this->command($owner, $first, 'contribute-company', 10000);
        $this->reviewedOwnerCredits($owner, $admin, 8833, $first);
        $this->assertSame(8833, app(WalletFundingCredits::class)->available($owner, $first->reference));
        $this->assertSame(0, app(WalletFundingCredits::class)->available($owner, $second->reference));
        try {
            app(ShipmentLossWallet::class)->command($owner, $second, 'contribute-company', $this->commandInput($second, 'contribute-company', 8833));
            $this->fail('An owned contribution made before issuance cannot consume the later original grant.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->command($owner, $first, 'contribute-company', 8833);
        $this->assertSame(0, app(WalletLossReserve::class)->summary($first)['company_remaining_cents']);
        $this->assertSame(18833, app(WalletLossCreditService::class)->maximum($owner, $second));
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
        $owner->update(['status' => 'suspended']);
        $this->reviewedOwnerCredits($owner, $admin, $amount, $plan);
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

    public function test_later_actual_quarantine_stops_new_loss_money_while_exact_original_funding_retry_preserves_its_reference(): void
    {
        [$plan, $admin, $order] = $this->plan();
        $service = app(ShipmentLossWallet::class);
        $input = $this->commandInput($plan, 'reserve-original');
        $event = $service->command($admin, $plan, 'reserve-original', $input);
        $this->quarantineMissing($plan);
        $this->assertSame($event->id, $service->command($admin, $plan, 'reserve-original', $input)->id);
        try {
            $this->command($admin, $plan, 'approve-advance', $plan->snapshot['company_funding_due_cents']);
            $this->fail('A later actual receipt invalidates the frozen loss source before new money.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('wallet_refund_reserve_entries', 1);
    }

    public function test_reviewed_company_credits_are_separate_from_reserve_funding_and_the_actual_refund(): void
    {
        [$plan, $admin, $order] = $this->plan(true);
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $due = $plan->snapshot['company_funding_due_cents'];
        $this->reviewedOwnerCredits($owner, $admin, $due, $plan);
        $this->assertFalse(app(WalletLossReserve::class)->summary($plan)['resolved']);
        $this->assertSame($due, app(WalletLossReserve::class)->summary($plan)['company_remaining_cents']);
        $this->assertSame(0, app(WalletLossReserve::class)->summary($plan)['balance_cents']);
        $this->command($owner, $plan, 'contribute-company', $due);
        $this->assertSame(0, app(WalletLossReserve::class)->summary($plan)['company_remaining_cents']);
        $this->command($admin, $plan, 'resolve-wallet');
        $this->assertSame(0, app(WalletLedger::class)->balance($owner));
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_loss_refund')->count());
        $this->assertSame(0, app(WalletLossCreditService::class)->maximum($owner, $plan));
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_stale_loss_credit_approval_is_rejected_but_original_pending_request_can_be_rejected_then_unfunded_review_voided(): void
    {
        [$plan, $admin, $order] = $this->plan();
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $credits = app(WalletLossCreditService::class);
        $input = ['expected_version' => 1, 'request_token' => (string) Str::uuid(), 'amount' => '188.33',
            'reason' => 'The original owner requests independent demonstration backing for its recorded loss liability.'];
        $request = $credits->request($owner, $plan, $input);
        $this->quarantineMissing($plan);
        $this->assertSame($request->id, $credits->request($owner, $plan, $input)->id);
        $before = WalletTransaction::count();
        try {
            $credits->review($admin, $request, ['version' => 1, 'request_token' => (string) Str::uuid(), 'decision' => 'approved', 'demo_credit_confirmed' => true,
                'reason' => 'The actual original missing source must remain valid before any new demonstration issuance.']);
            $this->fail('Found original custody invalidates a new loss-funding grant.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($before, WalletTransaction::count());
        $this->assertSame('pending', $request->fresh()->status);
        $reject = ['version' => 1, 'request_token' => (string) Str::uuid(), 'decision' => 'rejected',
            'reason' => 'Original actual receipt invalidated the loss source; retain this rejection without issuing any credits.'];
        $credits->review($admin, $request, $reject);
        $this->assertSame('rejected', $credits->review($admin, $request, $reject)->status);
        app(ShipmentLossReview::class)->voidUnfunded($admin, $plan, ['expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'unfunded_void_confirmed' => true, 'reason' => 'The original parcel was actually found, its pending request rejected and no loss money moved.']);
        $this->assertNull($plan->fresh()->active_order_id);
        $this->assertSame($request->id, $credits->request($owner, $plan, $input)->id);
        $this->assertSame('rejected', $credits->review($admin, $request, $reject)->status);
        $this->assertSame($before, WalletTransaction::count());
    }

    public function test_loss_funding_requests_enforce_original_owner_single_pending_current_need_and_globally_used_request_token(): void
    {
        [$plan, $admin, $order] = $this->plan();
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $credits = app(WalletLossCreditService::class);
        $input = ['expected_version' => 1, 'request_token' => (string) Str::uuid(), 'amount' => '188.33',
            'reason' => 'The original company asks for independently reviewed demonstration funding, followed by a separate actual transfer.'];
        foreach ([$order->buyer, $order->items->first()->shop->user, $this->createApprovedUser('logistics')] as $other) {
            try {
                $credits->request($other, $plan, $input);
                $this->fail('Only the retained original company owner can request loss funding credits.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        try {
            $credits->request($owner, $plan, [...$input, 'amount' => '188.34']);
            $this->fail('Demonstration credit need cannot exceed the original company liability.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $request = $credits->request($owner, $plan, $input);
        try {
            $credits->request($owner, $plan, [...$input, 'expected_version' => 2, 'request_token' => (string) Str::uuid()]);
            $this->fail('Retain one pending independently reviewed request per original owner and loss plan.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        try {
            app(ShipmentLossWallet::class)->command($admin, $plan, 'approve-advance',
                [...$this->commandInput($plan, 'approve-advance', 100), 'request_token' => $input['request_token']]);
            $this->fail('A loss funding request token cannot be spent again as an advance command.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertStringContainsString('original loss financial request', strtolower($error->getMessage()));
        }
        $this->assertDatabaseCount('wallet_refund_credit_requests', 1);
        $this->assertSame(0, app(WalletLedger::class)->balance($owner));
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_existing_owned_credits_reduce_new_grant_need_and_a_changed_pending_amount_requires_rejection_and_fresh_review(): void
    {
        [$plan, $admin] = $this->plan();
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $credits = app(WalletLossCreditService::class);
        $request = $credits->request($owner, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(), 'amount' => '188.33',
            'reason' => 'The original owner requests only the current independent demonstration need for its reviewed loss.']);
        DB::transaction(function () use ($owner, $admin) {
            $ledger = app(WalletLedger::class);
            $ledger->post('isolated-preexisting-owned-credit', 'demo_credit', 10000,
                [$ledger->platform('demo_issuance')->id => -10000, $ledger->user($owner)->id => 10000], $admin,
                'Explicit balanced test fixture for other owned credits arriving before this pending independent review.');
        });
        $this->assertSame(8833, $credits->maximum($owner, $plan));
        $before = WalletTransaction::count();
        try {
            $credits->review($admin, $request, ['version' => 1, 'request_token' => (string) Str::uuid(), 'decision' => 'approved', 'demo_credit_confirmed' => true,
                'reason' => 'A pending original request must still fit actual owned credits and remaining need when independently approved.']);
            $this->fail('A changed actual owned balance prevents an excessive stale funding grant.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
            $this->assertStringContainsString('remaining need changed', $error->getMessage());
        }
        $this->assertSame($before, WalletTransaction::count());
        $this->assertSame('pending', $request->fresh()->status);
        $credits->review($admin, $request, ['version' => 1, 'request_token' => (string) Str::uuid(), 'decision' => 'rejected',
            'reason' => 'Actual available owned credits reduced the original remaining need, so this old pending amount is declined.']);
        $new = $credits->request($owner, $plan, ['expected_version' => 3, 'request_token' => (string) Str::uuid(), 'amount' => '88.33',
            'reason' => 'The original owner now requests the exact remaining demonstration need after its available owned credits.']);
        $saved = $credits->review($admin, $new, ['version' => 1, 'request_token' => (string) Str::uuid(), 'decision' => 'approved', 'demo_credit_confirmed' => true,
            'reason' => 'Independent review confirms the reduced actual original company need and separate subsequent owned transfer.']);
        $this->assertSame(18833, app(WalletLedger::class)->balance($owner));
        $this->assertSame(8833, WalletTransaction::findOrFail($saved->wallet_transaction_id)->amount_cents);
        $this->assertSame(0, $credits->maximum($owner, $plan));
        $this->assertSame(18833, app(WalletLossReserve::class)->summary($plan)['company_remaining_cents']);
        $this->assertSame(0, app(WalletLossReserve::class)->summary($plan)['balance_cents']);
    }

    public function test_a_late_loss_grant_store_failure_rolls_back_issuance_request_review_and_financial_history(): void
    {
        [$plan, $admin] = $this->plan();
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $credits = app(WalletLossCreditService::class);
        $request = $credits->request($owner, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(), 'amount' => '188.33',
            'reason' => 'The original company requests actual independent demonstration funding before its separate repayment.']);
        $count = WalletTransaction::count();
        WalletRefundCreditRequest::updating(function () {
            throw new \RuntimeException('Isolated late loss credit-store failure.');
        });
        try {
            $credits->review($admin, $request, ['version' => 1, 'request_token' => (string) Str::uuid(), 'decision' => 'approved', 'demo_credit_confirmed' => true,
                'reason' => 'The original independent approval and actual issuance must remain atomic on a late store failure.']);
            $this->fail('A failed credit review cannot retain orphaned actual issuance.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Isolated late loss credit-store failure.', $error->getMessage());
        } finally {
            WalletRefundCreditRequest::flushEventListeners();
            WalletRefundCreditRequest::clearBootedModels();
        }
        $this->assertSame($count, WalletTransaction::count());
        $this->assertSame(0, app(WalletLedger::class)->balance($owner));
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->version);
        $this->assertSame(2, $plan->events()->count());
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
