<?php

namespace Tests\Feature\Returns;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\BuyerReturnRepaymentPlan;
use App\Models\CodAccount;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Models\WalletAccount;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletRefundReserveEntry;
use App\Models\WalletTransaction;
use App\Services\AccountClosureService;
use App\Services\Finance\CodCashService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Returns\BuyerReturnAmounts;
use App\Services\Returns\BuyerReturnCashExecution;
use App\Services\Returns\BuyerReturnPlanApproval;
use App\Services\Returns\BuyerReturnRefundPlan;
use App\Services\Returns\BuyerReturnService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletRefundCreditService;
use App\Services\Wallet\WalletReturnReserve;
use App\Services\Wallet\WalletSettlementService;
use App\Services\Wallet\WalletSuccessorReturnMoneyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class BuyerReturnRefundPlanTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private int $contractVersion = 2;

    protected function flowContractVersion(): int
    {
        return $this->contractVersion;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function received(string $mode = 'cod', string $fault = 'seller', bool $shippingVoucher = false, bool $finish = true, bool $sellerPaid = false, bool $productVoucher = false): array
    {
        $buyer = $this->createApprovedUser('buyer');
        $admin = $this->createApprovedUser('admin');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $shipping = ['payment_method' => $mode];
        if ($shippingVoucher) {
            $voucher = Voucher::factory()->funded()->create(['code' => 'PLAN_SHIPPING', 'discount_type' => 'free_shipping', 'discount_value' => 0, 'min_spend' => 0, 'max_discount' => 20]);
            $shipping['platform_voucher_revision_id'] = $voucher->current_revision_id;
        }
        if ($productVoucher) {
            $voucher = Voucher::factory()->funded()->create(['code' => 'PLAN_PRODUCT', 'discount_type' => 'fixed', 'discount_value' => 40, 'min_spend' => 0]);
            $shipping['platform_voucher_revision_id'] = $voucher->current_revision_id;
        }
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $this->createE2EProduct($shop, ['price' => '100.00']), 'quantity' => 2]], shipping: $shipping);
        if ($mode === 'wallet') {
            $credits = app(WalletCreditService::class);
            $request = $credits->request($buyer, ['amount' => '1000.00', 'reason' => 'Virtual credits for isolated successor refund preparation.', 'request_token' => (string) Str::uuid()]);
            $credits->review($admin, $request, ['decision' => 'approved', 'reason' => 'Approved original isolated demonstration credits.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
            $this->actingAs($buyer)->postJson('/wallet/payments/'.$order->walletPayment->id.'/pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()])->assertOk();
        }
        $this->flowDelivery($order, 'delivered');
        if ($sellerPaid) {
            $this->settleFlowOrder($order);
        }
        $order->refresh();
        // Future-policy fixture only; production checkout continues issuing policy one.
        $order->update(['shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => 2]]);
        $order->load('buyer', 'items');
        $returns = app(BuyerReturnService::class);
        $case = $returns->request($buyer, $order, ['request_token' => $returns->form($buyer, $order)['requestToken'],
            'reason' => 'damaged', 'description' => 'The original delivered unit has a broken fastening.',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]], [UploadedFile::fake()->image('original-damage.png')]);
        $returns->command($shop->user, $case, 'approve', ['expected_version' => $case->version, 'request_token' => (string) Str::uuid(),
            'reason' => 'The original item evidence supports this actual return.', 'fault' => 'seller']);
        if ($fault === 'courier') {
            $returns->command($admin, $case->fresh(), 'review-fault', ['expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(),
                'reason' => 'Independent review identifies actual transit damage at the original company.', 'fault' => 'courier',
                'fault_evidence' => 'The original recipient handover and retained photos identify the damaged parcel in company custody.']);
        }
        $case = $case->fresh(['order', 'parcel']);
        if ($finish) {
            $this->intake($case);
            while ($case->parcel->fresh()->route_position < count($case->parcel->hub_ids) - 1) {
                $this->leg($case);
            }
            $hub = LogisticsHub::findOrFail($case->parcel->fresh()->current_hub_id);
            $this->actingAs($this->flowHandler($hub))->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'stage', ['hub_id' => $hub->id]))
                ->assertRedirect('/buyer-returns/'.$case->id)->assertSessionHasNoErrors();
            $this->actingAs($shop->user)->post('/buyer-returns/'.$case->id.'/custody', $this->custodyPayload($case, 'seller-receive', [
                'condition' => 'accepted', 'photo' => UploadedFile::fake()->image('actual-seller-receipt.png'),
            ]))->assertRedirect('/buyer-returns/'.$case->id)->assertSessionHasNoErrors();
        }

        return [$case->fresh(['order', 'items']), $admin];
    }

    public static function paidOutcomes(): array
    {
        return [['cod', 'seller'], ['cod', 'courier'], ['wallet', 'seller'], ['wallet', 'courier']];
    }

    #[DataProvider('paidOutcomes')]
    public function test_successor_plan_separates_buyer_entitlement_from_original_seller_and_carrier_obligations(string $mode, string $fault): void
    {
        [$case, $admin] = $this->received($mode, $fault);
        $order = $case->order;
        $before = [$order->getAttributes(), $order->delivery->getAttributes(), DB::table('wallet_entries')->get()->map(fn ($row) => (array) $row)->all()];
        $basis = app(BuyerReturnAmounts::class)->successorForCase($case);
        $plan = app(BuyerReturnRefundPlan::class)->postDelivery($case);
        $this->assertSame($basis['buyer_cents'] + $basis['buyer_paid_shipping_cents'], $plan['buyer_refund_cents']);
        $this->assertSame(10000, $plan['original_buyer_merchandise_cents']);
        $this->assertSame($basis['source_snapshot']['shipping_cents'], $plan['protected_shipping_cents']);
        $this->assertSame(0, $plan['buyer_return_charge_cents']);
        $this->assertSame(0, $plan['seller_protection_cents']);
        if ($fault === 'seller') {
            $this->assertSame(9000, $plan['seller_reversal_cents']);
            $this->assertSame(1000, $plan['commission_reversal_cents']);
            $this->assertSame($basis['buyer_paid_shipping_cents'] + ($mode === 'wallet' ? 9000 : 0), $plan['seller_recovery_due_cents']);
            $this->assertSame(0, $plan['company_funding_due_cents']);
        } else {
            $this->assertSame(0, $plan['seller_reversal_cents']);
            $this->assertSame(0, $plan['seller_recovery_due_cents']);
            $this->assertSame(0, $plan['commission_reversal_cents']);
            $this->assertSame($plan['buyer_refund_cents'], $plan['company_funding_due_cents']);
            $this->assertSame($case->parcel->logistics_company_id, $plan['liable_company_id']);
        }
        $this->assertSame($mode, $plan['source_snapshot']['payment']['mode']);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), DB::table('wallet_entries')->get()->map(fn ($row) => (array) $row)->all()]);
        $this->assertDatabaseCount('buyer_return_adjustments', 0);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
        if ($mode === 'wallet') {
            try {
                app(BuyerReturnCashExecution::class)->command($admin, $case, 'approve-repayment', []);
                $this->fail('A virtual order must never enter the cash transfer writer.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $this->actingAs($admin)->post('/buyer-returns/'.$case->id.'/money', ['action' => 'approve-repayment', 'expected_version' => $case->version,
            'request_token' => (string) Str::uuid(), 'reason' => 'A legacy approval payload must not bypass review of the successor purchased terms.'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->assertDatabaseCount('buyer_return_adjustments', 0);
    }

    public function test_successor_shipping_refund_excludes_subsidy_and_cannot_fabricate_backing(): void
    {
        [$case] = $this->received(shippingVoucher: true);
        $plan = app(BuyerReturnRefundPlan::class)->postDelivery($case);
        $shipping = $plan['source_snapshot']['shipping'];
        $this->assertSame(2000, $shipping['shipping_discount_cents']);
        $this->assertSame($shipping['shipping_cents'] - 2000, $shipping['buyer_paid_shipping_cents']);
        $this->assertSame(intdiv($shipping['buyer_paid_shipping_cents'], 2), $plan['outbound_shipping_refund_cents']);
        $this->assertSame($plan['outbound_shipping_refund_cents'], $plan['seller_recovery_due_cents']);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
    }

    public static function findings(): array
    {
        return [['seller'], ['courier']];
    }

    #[DataProvider('findings')]
    public function test_actual_prior_cod_seller_payout_is_recovered_only_for_seller_fault(string $fault): void
    {
        [$case] = $this->received(fault: $fault, sellerPaid: true);
        $plan = app(BuyerReturnRefundPlan::class)->postDelivery($case);
        $this->assertSame(18000, $plan['source_snapshot']['seller_payment']['amount_cents']);
        $this->assertSame($fault === 'seller' ? 9000 + $plan['outbound_shipping_refund_cents'] : 0, $plan['seller_recovery_due_cents']);
        $this->assertSame(0, $plan['seller_protection_cents']);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
    }

    public function test_return_approval_alone_does_not_make_a_financial_plan_or_a_payment(): void
    {
        [$case] = $this->received(finish: false);
        try {
            app(BuyerReturnRefundPlan::class)->postDelivery($case);
            $this->fail('Complete actual reverse custody and seller receipt first.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('buyer_return_adjustments', 0);
        $this->assertDatabaseCount('buyer_return_money_events', 0);
    }

    public function test_changed_paid_label_or_unreviewed_successor_terms_cannot_generate_a_refund_plan(): void
    {
        [$case] = $this->received();
        $case->order->update(['payment_status' => 'pending']);
        try {
            app(BuyerReturnRefundPlan::class)->postDelivery($case);
            $this->fail('Retain actual original collection as well as the paid label.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $case->order->update(['payment_status' => 'paid', 'shipping_quote' => [...$case->order->shipping_quote, 'refund_policy_version' => 99]]);
        try {
            app(BuyerReturnRefundPlan::class)->postDelivery($case);
            $this->fail('An unknown purchased policy cannot use a successor calculation.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
    }

    #[DataProvider('findings')]
    public function test_plan_approval_freezes_original_amounts_without_payment_and_only_seller_fault_reduces_seller_proceeds(string $fault): void
    {
        [$case, $admin] = $this->received(fault: $fault);
        $before = [$case->order->getAttributes(), $case->order->delivery->getAttributes(), DB::table('wallet_entries')->get()->map(fn ($row) => (array) $row)->all()];
        $service = app(BuyerReturnPlanApproval::class);
        $preview = $service->preview($admin, $case);
        $input = ['expected_version' => $case->version, 'request_token' => (string) Str::uuid(),
            'reason' => 'Reviewed the original units, actual seller receipt and independent fault before retaining this financial plan.', 'review_token' => $preview['review_token']];
        $plan = $service->approve($admin, $case, $input);
        $this->assertSame($preview['snapshot'], $plan->snapshot);
        $this->assertSame($plan->id, $service->approve($admin, $case, $input)->id);
        $this->assertSame(0, $plan->approval->amount_cents);
        $this->assertTrue($plan->approval->context['no_money_moved']);
        $this->assertSame('repayment_ready', $case->fresh()->status);
        $this->assertSame(10000, $plan->adjustment->buyer_cents);
        $this->assertGreaterThan($plan->adjustment->buyer_cents, $plan->snapshot['buyer_refund_cents']);
        $basis = app(BuyerReturnAmounts::class)->sellerBasis($case->order, 18000);
        $this->assertSame($fault === 'seller' ? 9000 : 18000, $basis['payable_seller_cents']);
        $this->assertSame($fault === 'seller' ? 9000 : 0, $basis['return_seller_cents']);
        $this->assertSame($fault === 'seller' ? 1000 : 0, app(BuyerReturnAmounts::class)->adjustmentFinancials($case->order)['commission_cents']);
        $this->assertDatabaseCount('buyer_return_repayment_plans', 1);
        $this->assertDatabaseCount('buyer_return_adjustments', 1);
        $this->assertDatabaseCount('buyer_return_money_events', 1);
        $this->assertSame($before, [$case->order->fresh()->getAttributes(), $case->order->delivery->fresh()->getAttributes(), DB::table('wallet_entries')->get()->map(fn ($row) => (array) $row)->all()]);
        foreach ([['snapshot' => json_encode(['buyer_refund_cents' => 999999])], ['actor_id' => $case->seller_id]] as $changes) {
            try {
                DB::table('buyer_return_repayment_plans')->where('id', $plan->id)->update($changes);
                $this->fail('Retain the originally approved repayment basis.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('Retain', $error->getMessage());
            }
        }
    }

    public function test_plan_approval_rejects_stale_sources_unauthorized_actors_and_conflicting_retries(): void
    {
        [$case, $admin] = $this->received();
        $service = app(BuyerReturnPlanApproval::class);
        $preview = $service->preview($admin, $case);
        $input = ['expected_version' => $case->version, 'request_token' => (string) Str::uuid(),
            'reason' => 'Review this exact retained original plan and its private actual receipt.', 'review_token' => $preview['review_token']];
        foreach ([$case->order->buyer, $case->order->shop->user, $this->createApprovedUser('logistics')] as $actor) {
            try {
                $service->approve($actor, $case, $input);
                $this->fail('Only an independent current approved admin may approve this plan.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        foreach ([['expected_version' => 999], ['review_token' => str_repeat('0', 64)]] as $changes) {
            try {
                $service->approve($admin, $case, [...$input, ...$changes]);
                $this->fail('A stale source or review must not approve new amounts.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $this->assertDatabaseCount('buyer_return_repayment_plans', 0);
        $plan = $service->approve($admin, $case, $input);
        try {
            $service->approve($admin, $case, [...$input, 'reason' => 'A different financial reason cannot replace the original approval retry.']);
            $this->fail('Retain the exact original approval for a used request token.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($plan->id, $service->approve($admin, $case, $input)->id);
        $this->assertDatabaseCount('buyer_return_money_events', 1);
    }

    private function reconcileCash(Order $order, User $admin): void
    {
        $cash = CodAccount::where('order_id', $order->id)->sole();
        $service = app(CodCashService::class);
        $command = fn ($actor, $action, $data) => $service->command($actor, $cash, $action, $data + [
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(), 'receipt_confirmed' => true,
            'evidence_reference' => 'SUCCESSOR-ORIGINAL-'.$cash->id, 'reason' => 'The named receiver checked the actual original COD against its retained collection.']);
        foreach (['rider' => $this->flowHandler(LogisticsHub::findOrFail($cash->hub_id)), 'hub' => $admin] as $stage => $recipient) {
            foreach ($cash->fresh()->state['balances'] as $key => $cents) {
                if ($cents > 0 && str_starts_with($key, $stage.':')) {
                    $offer = $command(User::findOrFail(explode(':', $key)[1]), 'offer', ['holder_id' => (int) explode(':', $key)[1],
                        'recipient_id' => $recipient->id, 'amount' => SellerSettlementService::pesos($cents)]);
                    $command($recipient, 'receive', ['offer_reference' => $offer->reference, 'received_amount' => SellerSettlementService::pesos($cents)]);
                }
            }
        }
        $command($admin, 'reconcile', []);
    }

    private function cashInput(BuyerReturnCase $case, array $extra = []): array
    {
        return ['expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(),
            'reason' => 'The named participant checked the actual transfer and its original retained financial obligation.', ...$extra];
    }

    public static function cashOutcomes(): array
    {
        return [['seller', false], ['seller', true], ['courier', false], ['courier', true]];
    }

    #[DataProvider('cashOutcomes')]
    public function test_actual_received_seller_or_company_cash_funds_exact_original_refund_without_repaying_or_reversing_twice(string $fault, bool $sellerPaid): void
    {
        [$case, $admin] = $this->received(fault: $fault, sellerPaid: $sellerPaid);
        if (! $sellerPaid) {
            $this->reconcileCash($case->order, $admin);
        }
        $original = [$case->order->fresh()->getAttributes(), $case->order->delivery->getAttributes()];
        $approval = app(BuyerReturnPlanApproval::class)->preview($admin, $case);
        $service = app(BuyerReturnCashExecution::class);
        $service->command($admin, $case, 'approve-repayment', $this->cashInput($case, ['review_token' => $approval['review_token']]));
        $plan = BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->sole();
        $this->assertSame(0, $service->backing($case->order->fresh())['received_cents']);
        $repay = fn () => $service->command($admin, $case, 'record-repayment', $this->cashInput($case, ['cash_reference' => 'ACTUAL-BUYER-'.$plan->id,
            'payment_confirmed' => true]), UploadedFile::fake()->image('actual-buyer-transfer.png'));
        try {
            $repay();
            $this->fail('A liability or approval alone cannot fund a cash refund.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $kind = $fault === 'seller' ? 'recovery' : 'compensation';
        $funder = $fault === 'seller' ? $case->order->shop->user : LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $required = $service->funding($case)[$kind.'_due_cents'];
        $offerInput = $this->cashInput($case, ['amount' => SellerSettlementService::pesos($required), 'recipient_id' => $admin->id,
            'cash_reference' => 'ACTUAL-SOURCE-'.$plan->id, 'payment_confirmed' => true]);
        $proof = UploadedFile::fake()->image('actual-source-transfer.png');
        $offer = $service->command($funder, $case, 'offer-'.$kind, $offerInput, $proof);
        $this->assertSame($offer->id, $service->command($funder, $case, 'offer-'.$kind, $offerInput, $proof)->id);
        $this->assertSame(0, $service->backing($case->order->fresh())['received_cents']);
        try {
            $repay();
            $this->fail('A transfer offer is not actual received backing.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $receipt = $service->command($admin, $case, 'receive-'.$kind, $this->cashInput($case, ['source_event_id' => $offer->id,
            'payment_confirmed' => true]), UploadedFile::fake()->image('actual-source-received.png'));
        $this->assertSame($required, $receipt->amount_cents);
        $this->assertSame(0, $service->funding($case)[$kind.'_remaining_cents']);
        $input = $this->cashInput($case, ['cash_reference' => 'ACTUAL-BUYER-'.$plan->id, 'payment_confirmed' => true]);
        $proof = UploadedFile::fake()->image('actual-buyer-transfer.png');
        $payment = $service->command($admin, $case, 'record-repayment', $input, $proof);
        $this->assertSame($plan->snapshot['buyer_refund_cents'], $payment->amount_cents);
        $this->assertSame($case->buyer_id, $payment->recipient_id);
        $this->assertSame('payment_recorded', $case->fresh()->status);
        $this->assertSame($payment->id, $service->command($admin, $case, 'record-repayment', $input, $proof)->id);
        $service->command($case->order->buyer, $case, 'confirm-receipt', $this->cashInput($case, ['source_event_id' => $payment->id]));
        $this->assertSame('repaid', $case->fresh()->status);
        $this->assertSame($payment->amount_cents, $service->backing($case->order->fresh())['outgoing_cents']);
        $this->assertSame($plan->snapshot['protected_shipping_cents'], $service->backing($case->order->fresh())['protected_shipping_cents']);
        $this->assertSame($fault === 'seller' ? 9000 : 18000, $this->app->make(BuyerReturnAmounts::class)->sellerBasis($case->order, 18000)['payable_seller_cents']);
        $this->assertSame($original, [$case->order->fresh()->getAttributes(), $case->order->delivery->fresh()->getAttributes()]);
        $this->assertDatabaseCount('buyer_return_money_events', 5);
    }

    private function approvedCash(string $fault = 'courier', bool $reconcile = true): array
    {
        [$case, $admin] = $this->received(fault: $fault);
        if ($reconcile) {
            $this->reconcileCash($case->order, $admin);
        }
        $preview = app(BuyerReturnPlanApproval::class)->preview($admin, $case);
        $service = app(BuyerReturnCashExecution::class);
        $service->command($admin, $case, 'approve-repayment', $this->cashInput($case, ['review_token' => $preview['review_token']]));

        return [$case, $admin, $service, BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->sole()];
    }

    public function test_actual_platform_advance_can_fund_the_refund_while_original_company_debt_remains_until_received(): void
    {
        [$case, $admin, $service, $plan] = $this->approvedCash();
        $funder = $this->createApprovedUser('admin');
        $amount = $plan->snapshot['buyer_refund_cents'];
        $offer = $service->command($funder, $case, 'offer-funding', $this->cashInput($case, ['amount' => SellerSettlementService::pesos($amount),
            'recipient_id' => $admin->id, 'cash_reference' => 'ACTUAL-PLATFORM-ADVANCE', 'payment_confirmed' => true]), UploadedFile::fake()->image('actual-advance.png'));
        $this->assertSame(0, $service->funding($case)['received_backing_cents']);
        $service->command($admin, $case, 'receive-funding', $this->cashInput($case, ['source_event_id' => $offer->id, 'payment_confirmed' => true]), UploadedFile::fake()->image('advance-received.png'));
        $payment = $service->command($admin, $case, 'record-repayment', $this->cashInput($case, ['cash_reference' => 'ADVANCED-BUYER-REFUND', 'payment_confirmed' => true]), UploadedFile::fake()->image('advanced-refund.png'));
        $service->command($case->order->buyer, $case, 'confirm-receipt', $this->cashInput($case, ['source_event_id' => $payment->id]));
        $this->assertSame('repaid', $case->fresh()->status);
        $this->assertSame($amount, $service->funding($case)['compensation_remaining_cents']);
        $company = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $this->assertContains('buyer_return_compensation', array_column(app(AccountClosureService::class)->state($company)['blockers'], 'code'));
        $company->update(['status' => 'suspended']);
        $offer = $service->command($company, $case, 'offer-compensation', $this->cashInput($case, ['amount' => SellerSettlementService::pesos($amount),
            'recipient_id' => $admin->id, 'cash_reference' => 'COMPANY-REPAYS-ADVANCE', 'payment_confirmed' => true]), UploadedFile::fake()->image('actual-company-recovery.png'));
        $this->assertSame($amount, $service->funding($case)['compensation_remaining_cents']);
        $service->command($admin, $case, 'receive-compensation', $this->cashInput($case, ['source_event_id' => $offer->id, 'payment_confirmed' => true]), UploadedFile::fake()->image('company-received.png'));
        $this->assertSame(0, $service->funding($case)['compensation_remaining_cents']);
        $this->assertNotContains('buyer_return_compensation', array_column(app(AccountClosureService::class)->state($company->fresh())['blockers'], 'code'));
        $this->assertSame($amount, $service->backing($case->order->fresh())['outgoing_cents']);
        $this->assertSame(1, DB::table('buyer_return_money_events')->where('event_type', 'record-repayment')->count());
    }

    public function test_cash_sources_are_bounded_and_a_receiver_must_acknowledge_only_its_own_original_offer_once(): void
    {
        [$case, $admin, $service, $plan] = $this->approvedCash(reconcile: false);
        $company = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $amount = $plan->snapshot['buyer_refund_cents'];
        $input = $this->cashInput($case, ['amount' => SellerSettlementService::pesos($amount), 'recipient_id' => $admin->id,
            'cash_reference' => 'ORIGINAL-COMPANY-TRANSFER', 'payment_confirmed' => true]);
        try {
            $service->command($company, $case, 'offer-compensation', [...$input, 'amount' => SellerSettlementService::pesos($amount + 1)], UploadedFile::fake()->image('too-much.png'));
            $this->fail('Compensation cannot exceed the frozen original obligation.');
        } catch (HttpException $error) {
            $this->assertSame(422, $error->getStatusCode());
        }
        $proof = UploadedFile::fake()->image('original-company-proof.png');
        $offer = $service->command($company, $case, 'offer-compensation', $input, $proof);
        try {
            $service->command($company, $case, 'offer-compensation', [...$input, 'cash_reference' => 'CHANGED-COMPANY-TRANSFER'], $proof);
            $this->fail('A used request token cannot change the original source transfer.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $other = $this->createApprovedUser('admin');
        foreach ([$company, $other] as $wrongReceiver) {
            try {
                $service->command($wrongReceiver, $case, 'receive-compensation', $this->cashInput($case, ['source_event_id' => $offer->id, 'payment_confirmed' => true]), UploadedFile::fake()->image('wrong-receiver.png'));
                $this->fail('Only the actual named distinct receiving admin may confirm receipt.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $receiptInput = $this->cashInput($case, ['source_event_id' => $offer->id, 'payment_confirmed' => true]);
        $receiptProof = UploadedFile::fake()->image('actual-compensation-receipt.png');
        $receipt = $service->command($admin, $case, 'receive-compensation', $receiptInput, $receiptProof);
        $this->assertSame($receipt->id, $service->command($admin, $case, 'receive-compensation', $receiptInput, $receiptProof)->id);
        try {
            $service->command($admin, $case, 'receive-compensation', $this->cashInput($case, ['source_event_id' => $offer->id, 'payment_confirmed' => true]), $receiptProof);
            $this->fail('A different request cannot receive the same source twice.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        try {
            $service->command($admin, $case, 'record-repayment', $this->cashInput($case, ['cash_reference' => 'BEFORE-RECONCILIATION', 'payment_confirmed' => true]), UploadedFile::fake()->image('early-cash.png'));
            $this->fail('Actual company funding cannot replace original COD reconciliation.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertFalse($service->backing($case->order->fresh())['verified']);
        $this->assertDatabaseCount('buyer_return_money_events', 3);
    }

    public function test_failed_private_cash_receipt_rolls_back_the_new_event_case_version_and_saved_file(): void
    {
        [$case, $admin, $service, $plan] = $this->approvedCash();
        $funder = $this->createApprovedUser('admin');
        $offer = $service->command($funder, $case, 'offer-funding', $this->cashInput($case, ['amount' => SellerSettlementService::pesos($plan->snapshot['buyer_refund_cents']),
            'recipient_id' => $admin->id, 'cash_reference' => 'ROLLBACK-ACTUAL-ADVANCE', 'payment_confirmed' => true]), UploadedFile::fake()->image('rollback-advance.png'));
        $before = [$case->fresh()->getAttributes(), Storage::disk('local')->allFiles(), $case->events()->count()];
        $reject = true;
        BuyerReturnMoneyEvent::creating(function ($event) use (&$reject) {
            if ($reject && $event->event_type === 'receive-funding') {
                throw new \RuntimeException('Simulated private cash-receipt persistence failure.');
            }
        });
        $input = $this->cashInput($case, ['source_event_id' => $offer->id, 'payment_confirmed' => true]);
        $proof = UploadedFile::fake()->image('actual-received-proof.png');
        try {
            $service->command($admin, $case, 'receive-funding', $input, $proof);
            $this->fail('Private-proof failure must roll back the entire receipt.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated private cash-receipt persistence failure.', $error->getMessage());
        }
        $reject = false;
        $this->assertSame($before, [$case->fresh()->getAttributes(), Storage::disk('local')->allFiles(), $case->events()->count()]);
        $this->assertSame(0, $service->funding($case)['received_backing_cents']);
        $this->assertDatabaseCount('buyer_return_money_events', 2);
        $service->command($admin, $case, 'receive-funding', $input, $proof);
        $this->assertSame($plan->snapshot['buyer_refund_cents'], $service->funding($case)['received_backing_cents']);
    }

    public function test_nonreceipt_review_preserves_one_original_cash_payment_and_requires_buyer_confirmation(): void
    {
        [$case, $admin, $service, $plan] = $this->approvedCash();
        $company = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $offer = $service->command($company, $case, 'offer-compensation', $this->cashInput($case, ['amount' => SellerSettlementService::pesos($plan->snapshot['buyer_refund_cents']),
            'recipient_id' => $admin->id, 'cash_reference' => 'NONRECEIPT-SOURCE', 'payment_confirmed' => true]), UploadedFile::fake()->image('source.png'));
        $service->command($admin, $case, 'receive-compensation', $this->cashInput($case, ['source_event_id' => $offer->id, 'payment_confirmed' => true]), UploadedFile::fake()->image('received.png'));
        $payment = $service->command($admin, $case, 'record-repayment', $this->cashInput($case, ['cash_reference' => 'NONRECEIPT-ORIGINAL-PAYMENT', 'payment_confirmed' => true]), UploadedFile::fake()->image('buyer-transfer.png'));
        $original = $payment->fresh()->getAttributes();
        $service->command($case->order->buyer, $case, 'report-nonreceipt', $this->cashInput($case, ['source_event_id' => $payment->id]));
        $this->assertSame('repayment_disputed', $case->fresh()->status);
        $resolution = $service->command($admin, $case, 'resolve-nonreceipt', $this->cashInput($case, ['source_event_id' => $payment->id, 'payment_confirmed' => true]), UploadedFile::fake()->image('original-payment-reviewed.png'));
        $this->assertSame(0, $resolution->amount_cents);
        $this->assertTrue($resolution->context['no_second_payment']);
        $this->assertSame('payment_recorded', $case->fresh()->status);
        $this->assertSame($original, $payment->fresh()->getAttributes());
        $this->assertSame(1, DB::table('buyer_return_money_events')->where('event_type', 'record-repayment')->count());
        $service->command($case->order->buyer, $case, 'confirm-receipt', $this->cashInput($case, ['source_event_id' => $payment->id]));
        $this->assertSame('repaid', $case->fresh()->status);
    }

    public function test_cash_transfer_form_rejects_invalid_amounts_details_actual_uploads_and_client_authority(): void
    {
        [$case, $admin, $service, $plan] = $this->approvedCash();
        $company = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $input = $this->cashInput($case, ['amount' => SellerSettlementService::pesos($plan->snapshot['buyer_refund_cents']),
            'recipient_id' => $admin->id, 'cash_reference' => 'BOUNDED-ACTUAL-COMPANY-FUNDING', 'payment_confirmed' => true]);
        foreach ([['amount' => '1e3'], ['reason' => 'too short'], ['reason' => str_repeat('a', 1001)], ['buyer_id' => $case->buyer_id],
            ['payment_confirmed' => false], ['cash_reference' => str_repeat('A', 121)]] as $changes) {
            try {
                $service->command($company, $case, 'offer-compensation', [...$input, ...$changes], UploadedFile::fake()->image('valid.png'));
                $this->fail('Bounded server validation must reject the invalid actual transfer input.');
            } catch (ValidationException $error) {
                $this->assertNotEmpty($error->errors());
            }
        }
        foreach ([null, UploadedFile::fake()->create('large.png', 5121, 'image/png'), UploadedFile::fake()->createWithContent('fake.png', 'This is not a real private receipt.')] as $proof) {
            try {
                $service->command($company, $case, 'offer-compensation', [...$input, 'proof' => UploadedFile::fake()->image('untrusted-valid.png')], $proof);
                $this->fail('Validate the actual file argument; client payload cannot substitute a different receipt.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('proof', $error->errors());
            }
        }
        $this->assertSame(0, $service->funding($case)['received_backing_cents']);
        $this->assertDatabaseCount('buyer_return_money_events', 1);
    }

    private function approvedWallet(string $fault = 'seller', bool $settled = true, bool $productVoucher = false): array
    {
        $this->contractVersion = $settled ? 2 : 1;
        [$case, $admin] = $this->received(mode: 'wallet', fault: $fault, productVoucher: $productVoucher);
        $approval = app(BuyerReturnPlanApproval::class);
        $preview = $approval->preview($admin, $case);
        $plan = $approval->approve($admin, $case, ['expected_version' => $case->version, 'request_token' => (string) Str::uuid(),
            'reason' => 'Reviewed the actual return and original virtual sources before approving its retained plan.', 'review_token' => $preview['review_token']]);

        return [$case, $admin, app(WalletReturnReserve::class), $plan];
    }

    public function test_actual_seller_virtual_funding_is_reserved_once_and_refund_does_not_debit_seller_again(): void
    {
        [$case, $admin, $reserve, $plan] = $this->approvedWallet();
        $seller = $case->order->shop->user;
        $reason = 'The named participant reviewed this actual approved virtual reserve and its original source.';
        $originalToken = (string) Str::uuid();
        $original = DB::transaction(fn () => $reserve->original($admin, $case, $reason, $originalToken));
        $this->assertSame($original->id, DB::transaction(fn () => $reserve->original($admin, $case, $reason, $originalToken))->id);
        $this->assertSame(1000, $original->amount_cents);
        $this->assertSame(18000, WalletAccount::where('user_id', $seller->id)->sole()->balance_cents);
        $amount = $plan->snapshot['seller_recovery_due_cents'];
        $token = (string) Str::uuid();
        $funding = DB::transaction(fn () => $reserve->liability($seller, $case, $amount, $reason, $token));
        $this->assertSame($funding->id, DB::transaction(fn () => $reserve->liability($seller, $case, $amount, $reason, $token))->id);
        $balance = WalletAccount::where('user_id', $seller->id)->sole()->balance_cents;
        $this->assertSame(18000 - $amount, $balance);
        $buyerBefore = WalletAccount::where('user_id', $case->buyer_id)->sole()->balance_cents;
        $token = (string) Str::uuid();
        $refund = DB::transaction(fn () => $reserve->refund($admin, $case, $reason, $token));
        $this->assertSame($refund->id, DB::transaction(fn () => $reserve->refund($admin, $case, $reason, $token))->id);
        $this->assertSame($plan->snapshot['buyer_refund_cents'], $refund->amount_cents);
        $this->assertSame($balance, WalletAccount::where('user_id', $seller->id)->sole()->balance_cents);
        $this->assertSame($buyerBefore + $refund->amount_cents, WalletAccount::where('user_id', $case->buyer_id)->sole()->balance_cents);
        $this->assertSame(5000, WalletAccount::where('kind', 'shipping')->sole()->balance_cents);
        $this->assertSame(1000, WalletAccount::where('kind', 'commission')->sole()->balance_cents);
        $this->assertSame(9000, app(WalletSettlementService::class)->basis($case->order->fresh())['seller_cents']);
        $this->assertSame(0, app(WalletSettlementService::class)->basis($case->order->fresh())['escrow_cents']);
        $this->assertSame(0, $reserve->summary($plan)['balance_cents']);
        $this->assertSame(0, $reserve->summary($plan)['liability_remaining_cents']);
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
        $this->assertDatabaseCount('wallet_refund_reserve_entries', 3);
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_return_refund')->count());
    }

    public function test_received_demonstration_advance_refunds_courier_fault_without_reducing_original_virtual_seller_or_carrier_earnings(): void
    {
        [$case, $admin, $reserve, $plan] = $this->approvedWallet('courier');
        $reason = 'Explicit approved demonstration funding for the original reviewed company liability.';
        $company = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        try {
            DB::transaction(fn () => $reserve->liability($company, $case, $plan->snapshot['company_funding_due_cents'], $reason, (string) Str::uuid()));
            $this->fail('A company cannot transfer an unbacked or negative virtual balance.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $amount = $plan->snapshot['company_funding_due_cents'];
        DB::transaction(fn () => $reserve->advance($admin, $case, $amount, $reason, (string) Str::uuid()));
        $refund = DB::transaction(fn () => $reserve->refund($admin, $case, $reason, (string) Str::uuid()));
        $this->assertSame($amount, $refund->amount_cents);
        $this->assertSame(18000, WalletAccount::where('user_id', $case->seller_id)->sole()->balance_cents);
        $this->assertSame(2000, WalletAccount::where('kind', 'commission')->sole()->balance_cents);
        $this->assertSame(5000, WalletAccount::where('kind', 'shipping')->sole()->balance_cents);
        $this->assertSame($amount, $reserve->summary($plan)['liability_remaining_cents']);
        $this->assertSame($amount, $reserve->summary($plan)['advance_outstanding_cents']);
        $this->assertSame(18000, app(WalletSettlementService::class)->basis($case->order->fresh())['seller_cents']);
        $this->assertSame(2000, app(WalletSettlementService::class)->basis($case->order->fresh())['commission_cents']);
        $this->assertSame(0, $reserve->summary($plan)['balance_cents']);
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
    }

    public function test_before_settlement_only_original_item_escrow_is_reserved_and_external_shipping_refund_does_not_reduce_it_twice(): void
    {
        [$case, $admin, $reserve, $plan] = $this->approvedWallet(settled: false);
        $reason = 'Review the original unsettled item escrow and separately approved virtual shipping advance.';
        $this->assertNull($plan->snapshot['source_snapshot']['seller_payment']);
        DB::transaction(fn () => $reserve->original($admin, $case, $reason, (string) Str::uuid()));
        $this->assertSame(10000, (int) WalletRefundReserveEntry::where('source_kind', 'original_escrow')->sum('delta_cents'));
        $this->assertSame(15000, WalletAccount::where('kind', 'escrow')->sole()->balance_cents);
        DB::transaction(fn () => $reserve->advance($admin, $case, $plan->snapshot['seller_recovery_due_cents'], $reason, (string) Str::uuid()));
        $refund = DB::transaction(fn () => $reserve->refund($admin, $case, $reason, (string) Str::uuid()));
        $this->assertSame(12500, $refund->amount_cents);
        $this->assertSame(15000, WalletAccount::where('kind', 'escrow')->sole()->balance_cents);
        $this->assertSame(0, $reserve->summary($plan)['balance_cents']);
        $this->assertSame(2500, $reserve->summary($plan)['liability_remaining_cents']);
        $basis = app(WalletSettlementService::class)->basis($case->order->fresh());
        $this->assertSame(12500, $basis['refunded_cents']);
        $this->assertSame(10000, $basis['original_escrow_reserved_cents']);
        $this->assertSame(15000, $basis['escrow_cents']);
        $this->assertSame(9000, $basis['seller_cents']);
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
    }

    public function test_signed_original_product_subsidy_is_restored_from_actual_reserve_without_resetting_campaign_funding(): void
    {
        [$case, $admin, $reserve, $plan] = $this->approvedWallet(productVoucher: true);
        $reason = 'Retain the original signed promotion contribution and actual separate reserve funding.';
        $before = [DB::table('voucher_funding_entries')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('voucher_redemptions')->get()->map(fn ($row) => (array) $row)->all()];
        $this->assertSame(2000, $plan->snapshot['promotion_reversal_cents']);
        $this->assertSame(10500, $plan->snapshot['buyer_refund_cents']);
        DB::transaction(fn () => $reserve->original($admin, $case, $reason, (string) Str::uuid()));
        DB::transaction(fn () => $reserve->liability($case->order->shop->user, $case, $plan->snapshot['seller_recovery_due_cents'], $reason, (string) Str::uuid()));
        $promotionBefore = WalletAccount::where('kind', 'promotion')->sole()->balance_cents;
        $refund = DB::transaction(fn () => $reserve->refund($admin, $case, $reason, (string) Str::uuid()));
        $this->assertSame(10500, $refund->amount_cents);
        $this->assertSame($promotionBefore + 2000, WalletAccount::where('kind', 'promotion')->sole()->balance_cents);
        $this->assertSame(0, $reserve->summary($plan)['balance_cents']);
        $this->assertSame(5000, WalletAccount::where('kind', 'shipping')->sole()->balance_cents);
        $this->assertSame($before, [DB::table('voucher_funding_entries')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('voucher_redemptions')->get()->map(fn ($row) => (array) $row)->all()]);
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
    }

    public function test_actual_late_seller_funding_retires_only_the_received_demonstration_advance_without_another_buyer_credit(): void
    {
        [$case, $admin, $reserve, $plan] = $this->approvedWallet();
        $reason = 'Review the original paid virtual advance and the seller actual separate recovery transfer.';
        DB::transaction(fn () => $reserve->original($admin, $case, $reason, (string) Str::uuid()));
        $amount = $plan->snapshot['seller_recovery_due_cents'];
        DB::transaction(fn () => $reserve->advance($admin, $case, $amount, $reason, (string) Str::uuid()));
        $refund = DB::transaction(fn () => $reserve->refund($admin, $case, $reason, (string) Str::uuid()));
        $this->assertSame($amount, $reserve->summary($plan)['liability_remaining_cents']);
        $issued = WalletAccount::where('kind', 'demo_issuance')->sole()->balance_cents;
        $buyer = WalletAccount::where('user_id', $case->buyer_id)->sole()->balance_cents;
        $token = (string) Str::uuid();
        $funding = DB::transaction(fn () => $reserve->liability($case->order->shop->user, $case, $amount, $reason, $token));
        $this->assertSame($funding->id, DB::transaction(fn () => $reserve->liability($case->order->shop->user, $case, $amount, $reason, $token))->id);
        $state = $reserve->summary($plan);
        $this->assertSame(0, $state['balance_cents']);
        $this->assertSame(0, $state['liability_remaining_cents']);
        $this->assertSame(0, $state['advance_outstanding_cents']);
        $this->assertSame($amount, $state['advance_retired_cents']);
        $this->assertSame($issued + $amount, WalletAccount::where('kind', 'demo_issuance')->sole()->balance_cents);
        $this->assertSame($buyer, WalletAccount::where('user_id', $case->buyer_id)->sole()->balance_cents);
        $this->assertSame($refund->amount_cents, (int) WalletTransaction::where('kind', 'wallet_return_refund')->sum('amount_cents'));
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_return_advance_retirement')->count());
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
    }

    public function test_one_original_virtual_reserve_cannot_fund_another_case_and_its_source_record_is_immutable(): void
    {
        [$first, $admin, $reserve, $plan] = $this->approvedWallet();
        $reason = 'Retain each named original virtual reserve and its separate reviewed financial authority.';
        DB::transaction(fn () => $reserve->original($admin, $first, $reason, (string) Str::uuid()));
        DB::transaction(fn () => $reserve->liability($first->order->shop->user, $first, $plan->snapshot['seller_recovery_due_cents'], $reason, (string) Str::uuid()));
        $firstBalance = $reserve->summary($plan)['balance_cents'];
        [$second, $otherAdmin] = $this->approvedWallet();
        $buyer = WalletAccount::where('user_id', $second->buyer_id)->sole()->balance_cents;
        try {
            DB::transaction(fn () => $reserve->refund($otherAdmin, $second, $reason, (string) Str::uuid()));
            $this->fail('Another original case’s reserved credits cannot back this buyer refund.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($firstBalance, $reserve->summary($plan)['balance_cents']);
        $this->assertSame($buyer, WalletAccount::where('user_id', $second->buyer_id)->sole()->balance_cents);
        $entry = WalletRefundReserveEntry::firstOrFail();
        $attributes = $entry->getAttributes();
        unset($attributes['id']);
        try {
            DB::transaction(fn () => WalletRefundReserveEntry::create([...$attributes, 'delta_cents' => $entry->delta_cents + 1]));
            $this->fail('A reserve source record must equal its actual journal entry.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        try {
            DB::table('wallet_refund_reserve_entries')->where('id', $entry->id)->update(['delta_cents' => 1]);
            $this->fail('Raw SQL must preserve the original actual reserve.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retain', $error->getMessage());
        }
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
    }

    public function test_failed_virtual_reserve_record_rolls_back_the_actual_transaction_and_all_cached_balances(): void
    {
        [$case, $admin, $reserve] = $this->approvedWallet();
        $accounts = fn () => DB::table('wallet_accounts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $before = [$accounts(), DB::table('wallet_transactions')->count(), DB::table('wallet_entries')->count()];
        $reject = true;
        WalletRefundReserveEntry::creating(function () use (&$reject) {
            if ($reject) {
                throw new \RuntimeException('Simulated virtual reserve-source persistence failure.');
            }
        });
        $reason = 'Check the exact original virtual source and roll back incomplete reserve evidence.';
        $token = (string) Str::uuid();
        try {
            DB::transaction(fn () => $reserve->original($admin, $case, $reason, $token));
            $this->fail('A missing reserve-source record cannot leave a debit or credited reserve.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated virtual reserve-source persistence failure.', $error->getMessage());
        }
        $reject = false;
        $this->assertSame($before, [$accounts(), DB::table('wallet_transactions')->count(), DB::table('wallet_entries')->count()]);
        $this->assertDatabaseCount('wallet_refund_reserve_entries', 0);
        DB::transaction(fn () => $reserve->original($admin, $case, $reason, $token));
        $this->assertDatabaseCount('wallet_refund_reserve_entries', 1);
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
    }

    public function test_virtual_orchestrator_retains_actual_source_references_and_marks_repaid_only_after_one_buyer_credit(): void
    {
        [$case, $admin] = $this->received(mode: 'wallet');
        $service = app(WalletSuccessorReturnMoneyService::class);
        $input = fn ($extra = []) => ['expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(),
            'reason' => 'The named participant reviewed this exact original virtual return and its actual reserve source.', ...$extra];
        $review = app(BuyerReturnPlanApproval::class)->preview($admin, $case);
        $service->command($admin, $case, 'approve-repayment', $input(['review_token' => $review['review_token']]));
        $plan = BuyerReturnRepaymentPlan::where('buyer_return_case_id', $case->id)->sole();
        $buyer = WalletAccount::where('user_id', $case->buyer_id)->sole()->balance_cents;
        try {
            $service->command($admin, $case, 'refund-wallet', $input(['wallet_refund_confirmed' => true]));
            $this->fail('A retained plan alone cannot credit a virtual refund.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $originalInput = $input(['wallet_reserve_confirmed' => true]);
        $source = $service->command($admin, $case, 'reserve-wallet-original', $originalInput);
        $this->assertSame($source->id, $service->command($admin, $case, 'reserve-wallet-original', $originalInput)->id);
        $this->assertSame('platform_refund_reserve', $source->context['recipient_kind']);
        $this->assertSame('repayment_ready', $case->fresh()->status);
        $this->assertSame($buyer, WalletAccount::where('user_id', $case->buyer_id)->sole()->balance_cents);
        $seller = $case->order->shop->user;
        $fundingInput = $input(['amount' => SellerSettlementService::pesos($plan->snapshot['seller_recovery_due_cents']), 'wallet_funding_confirmed' => true]);
        $funding = $service->command($seller, $case, 'fund-wallet-liability', $fundingInput);
        $this->assertSame('platform_refund_reserve', $funding->context['recipient_kind']);
        $this->assertSame('repayment_ready', $case->fresh()->status);
        $refundInput = $input(['wallet_refund_confirmed' => true]);
        $refund = $service->command($admin, $case, 'refund-wallet', $refundInput);
        $this->assertSame('repaid', $case->fresh()->status);
        $this->assertSame('buyer_wallet', $refund->context['recipient_kind']);
        $this->assertNotNull($refund->context['wallet_reference']);
        $this->assertSame($buyer + $plan->snapshot['buyer_refund_cents'], WalletAccount::where('user_id', $case->buyer_id)->sole()->balance_cents);
        $this->assertSame($refund->id, $service->command($admin, $case, 'refund-wallet', $refundInput)->id);
        $this->assertSame($funding->id, $service->command($seller, $case, 'fund-wallet-liability', $fundingInput)->id);
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_return_refund')->count());
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
        $this->assertDatabaseCount('buyer_return_money_events', 4);
        $this->assertNotContains('buyer_return_recovery', array_column(app(AccountClosureService::class)->state($seller)['blockers'], 'code'));
    }

    public function test_remaining_original_proceeds_use_actual_reserved_escrow_and_late_seller_recovery_keeps_its_frozen_amount(): void
    {
        [$case, $admin, $reserve, $plan] = $this->approvedWallet(settled: false);
        $service = app(WalletSuccessorReturnMoneyService::class);
        $input = fn ($extra = []) => ['expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(),
            'reason' => 'Reviewed the original unspent source, actual reserve and remaining original virtual proceeds.', ...$extra];
        $service->command($admin, $case, 'reserve-wallet-original', $input(['wallet_reserve_confirmed' => true]));
        $service->command($admin, $case, 'advance-wallet-funding', $input(['amount' => '25.00', 'demo_advance_confirmed' => true]));
        $service->command($admin, $case, 'refund-wallet', $input(['wallet_refund_confirmed' => true]));
        $this->assertSame('repaid', $case->fresh()->status);
        $this->assertSame(15000, WalletAccount::where('kind', 'escrow')->sole()->balance_cents);
        $order = $case->order->fresh();
        $this->actingAs($order->buyer)->post('/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
        $settlements = app(WalletSettlementService::class);
        $view = $settlements->present($order->fresh());
        $this->assertTrue($view['can_settle']);
        $this->assertSame(9000, $view['seller_cents']);
        $this->assertSame(15000, $view['escrow_cents']);
        $settlements->settle($admin, $order, ['request_token' => (string) Str::uuid(), 'consent' => $view['consent'],
            'credit_proceeds_confirmed' => true, 'reason' => 'Reviewed the actual remaining original escrow after the backed partial return.']);
        $this->assertSame(9000, WalletAccount::where('user_id', $case->seller_id)->sole()->balance_cents);
        $this->assertSame(1000, WalletAccount::where('kind', 'commission')->sole()->balance_cents);
        $this->assertSame(5000, WalletAccount::where('kind', 'shipping')->sole()->balance_cents);
        $this->assertSame(0, WalletAccount::where('kind', 'escrow')->sole()->balance_cents);
        $this->assertSame(2500, $reserve->summary($plan)['liability_remaining_cents']);
        $this->assertContains('buyer_return_recovery', array_column(app(AccountClosureService::class)->state($order->shop->user)['blockers'], 'code'));
        $service->command($order->shop->user, $case, 'fund-wallet-liability', $input(['amount' => '25.00', 'wallet_funding_confirmed' => true]));
        $this->assertSame(6500, WalletAccount::where('user_id', $case->seller_id)->sole()->balance_cents);
        $this->assertSame(0, $reserve->summary($plan)['liability_remaining_cents']);
        $this->assertSame(0, $reserve->summary($plan)['advance_outstanding_cents']);
        $this->assertNotContains('buyer_return_recovery', array_column(app(AccountClosureService::class)->state($order->shop->user)['blockers'], 'code'));
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
    }

    private function fundingCreditInput(BuyerReturnCase $case, int $amount, array $extra = []): array
    {
        return ['expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(), 'amount' => SellerSettlementService::pesos($amount),
            'reason' => 'Request independently reviewed demonstration credits for this original unfunded company liability.', ...$extra];
    }

    private function financialHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
    }

    public function test_public_wallet_funding_uses_financial_only_company_access_and_keeps_buyer_history_private(): void
    {
        [$case, $admin, , $plan] = $this->approvedWallet('courier');
        $company = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $company->update(['status' => 'suspended']);
        $url = '/return-liabilities/'.$case->id;
        $page = $this->actingAs($company)->getJson($url, $this->financialHeaders())->assertOk();
        $page->assertJsonPath('component', 'Returns/Liability')->assertJsonPath('props.financialCase.canViewReturn', false)
            ->assertJsonPath('props.auth.user.canManageReturnLiabilities', true);
        $financial = $page->json('props.financialCase');
        $this->assertSame(['id', 'order_number'], array_keys($financial['order']));
        foreach (['buyer', 'items', 'evidence', 'parcel', 'shipping_address', 'source_snapshot'] as $private) {
            $this->assertArrayNotHasKey($private, $financial);
        }
        $this->assertContains('fund-wallet-liability', $financial['money']['actions']);
        $this->getJson('/return-liabilities', $this->financialHeaders())->assertOk()->assertJsonPath('props.cases.data.0.id', $case->id);
        $this->getJson('/return-liabilities?search='.urlencode($case->reference), $this->financialHeaders())->assertOk()->assertJsonPath('props.cases.total', 1);
        $this->getJson('/return-liabilities?search=%25', $this->financialHeaders())->assertOk()->assertJsonPath('props.cases.total', 0);
        $this->getJson('/return-liabilities?page=0')->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->getJson('/return-liabilities?search='.str_repeat('a', 81))->assertUnprocessable()->assertJsonValidationErrors('search');
        $request = $this->postJson('/buyer-returns/'.$case->id.'/wallet-funding-credits', $this->fundingCreditInput($case, $plan->snapshot['company_funding_due_cents']))
            ->assertCreated()->json('request');
        $funding = WalletRefundCreditRequest::findOrFail($request['id']);
        $this->postJson('/buyer-returns/'.$case->id.'/wallet-funding-credits/'.$funding->id.'/review', $this->fundingReviewInput($funding))->assertForbidden();
        $this->actingAs($admin)->postJson('/buyer-returns/'.$case->id.'/wallet-funding-credits/'.$funding->id.'/review', $this->fundingReviewInput($funding))
            ->assertOk()->assertJsonPath('request.status', 'approved');
        $moneyUrl = '/buyer-returns/'.$case->id.'/money';
        $this->actingAs($company)->post($moneyUrl, $this->cashInput($case, ['action' => 'fund-wallet-liability',
            'amount' => SellerSettlementService::pesos($plan->snapshot['company_funding_due_cents']), 'wallet_funding_confirmed' => true]))
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $buyerPage = $this->actingAs($case->order->buyer)->getJson('/buyer-returns/'.$case->id, $this->financialHeaders())->assertOk();
        $money = $buyerPage->json('props.returnCase.money');
        $this->assertArrayNotHasKey('reserve', $money);
        $this->assertArrayNotHasKey('funding_credit_requests', $money);
        $this->assertArrayNotHasKey('company_funding_due_cents', $money['plan']);
        $this->assertSame(['approve-repayment'], array_column($money['history'], 'event_type'));
        $this->assertNotContains('money-fund-wallet-liability', array_column($buyerPage->json('props.returnCase.history'), 'event_type'));
        $this->assertNotContains('wallet-credit-approved', array_column($buyerPage->json('props.returnCase.history'), 'event_type'));
        $this->actingAs($admin)->post($moneyUrl, $this->cashInput($case, ['action' => 'refund-wallet', 'wallet_refund_confirmed' => true]))
            ->assertRedirect('/buyer-returns/'.$case->id)->assertSessionHasNoErrors();
        $this->assertSame('repaid', $case->fresh()->status);
        $floor = $this->flowHandler(LogisticsHub::findOrFail($case->parcel->hub_ids[0]));
        if ($floor->id === $company->id) {
            $floor = $this->createApprovedUser('logistics');
        }
        $this->actingAs($floor)->getJson($url, $this->financialHeaders())->assertNotFound();
        $this->actingAs($this->createApprovedUser('logistics'))->getJson($url, $this->financialHeaders())->assertNotFound();
        $this->actingAs($this->createApprovedUser('courier'))->getJson($url, $this->financialHeaders())->assertForbidden();
    }

    public function test_public_cod_company_receipts_are_private_to_the_source_and_never_mistaken_for_buyer_payment(): void
    {
        [$case, $admin, $cash, $plan] = $this->approvedCash();
        $company = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $moneyUrl = '/buyer-returns/'.$case->id.'/money';
        $this->actingAs($company)->post($moneyUrl, $this->cashInput($case, ['action' => 'offer-compensation',
            'amount' => SellerSettlementService::pesos($plan->snapshot['company_funding_due_cents']), 'recipient_id' => $admin->id,
            'cash_reference' => 'PUBLIC-COMPANY-TRANSFER', 'payment_confirmed' => true, 'proof' => UploadedFile::fake()->image('company.png')]))
            ->assertRedirect('/return-liabilities/'.$case->id)->assertSessionHasNoErrors();
        $offer = BuyerReturnMoneyEvent::where('event_type', 'offer-compensation')->sole();
        $proof = '/buyer-returns/'.$case->id.'/money/'.$offer->id.'/proof';
        $this->get($proof)->assertOk();
        $this->assertSame(0, $cash->funding($case)['compensation_received_cents']);
        $this->actingAs($case->order->buyer)->get($proof)->assertNotFound();
        $this->actingAs($case->order->shop->user)->get($proof)->assertNotFound();
        $this->actingAs($admin)->post($moneyUrl, $this->cashInput($case, ['action' => 'receive-compensation', 'source_event_id' => $offer->id,
            'payment_confirmed' => true, 'proof' => UploadedFile::fake()->image('received.png')]))->assertSessionHasNoErrors();
        $this->post($moneyUrl, $this->cashInput($case, ['action' => 'record-repayment', 'cash_reference' => 'PUBLIC-BUYER-REPAYMENT',
            'payment_confirmed' => true, 'proof' => UploadedFile::fake()->image('buyer.png')]))->assertSessionHasNoErrors();
        $repayment = BuyerReturnMoneyEvent::where('event_type', 'record-repayment')->sole();
        $buyerPage = $this->actingAs($case->order->buyer)->getJson('/buyer-returns/'.$case->id, $this->financialHeaders())->assertOk();
        $this->assertSame(['approve-repayment', 'record-repayment'], array_column($buyerPage->json('props.returnCase.money.history'), 'event_type'));
        $this->get('/buyer-returns/'.$case->id.'/money/'.$repayment->id.'/proof')->assertOk();
        $this->post($moneyUrl, $this->cashInput($case, ['action' => 'confirm-receipt', 'source_event_id' => $repayment->id]))->assertSessionHasNoErrors();
        $this->assertSame('repaid', $case->fresh()->status);
    }

    public function test_public_successor_approval_requires_the_displayed_review_and_keeps_unknown_policies_closed(): void
    {
        [$case, $admin] = $this->received();
        $url = '/buyer-returns/'.$case->id;
        $page = $this->actingAs($admin)->getJson($url, $this->financialHeaders())->assertOk();
        $page->assertJsonPath('props.returnCase.money.plan.is_preview', true);
        $review = $page->json('props.returnCase.money.review_token');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $review);
        $this->postJson($url.'/money', $this->cashInput($case, ['action' => 'approve-repayment']))->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->post($url.'/money', $this->cashInput($case, ['action' => 'approve-repayment', 'review_token' => $review]))->assertSessionHasNoErrors();
        $this->assertSame('repayment_ready', $case->fresh()->status);
        $case->order->update(['shipping_quote' => [...$case->order->shipping_quote, 'refund_policy_version' => 99]]);
        $this->postJson($url.'/money', $this->cashInput($case, ['action' => 'record-repayment']))->assertConflict();
        $this->assertSame(0, BuyerReturnMoneyEvent::where('event_type', 'record-repayment')->count());
    }

    private function fundingReviewInput(WalletRefundCreditRequest $request, array $extra = []): array
    {
        return ['version' => $request->fresh()->version, 'request_token' => (string) Str::uuid(), 'decision' => 'approved',
            'reason' => 'Reviewed the original virtual liability, available credits and remaining demonstration budget.', 'demo_credit_confirmed' => true, ...$extra];
    }

    public function test_original_company_requests_reviewed_demo_credits_and_clears_liability_only_after_actual_reserve_funding(): void
    {
        [$case, $admin, $reserve, $plan] = $this->approvedWallet('courier');
        $company = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $credits = app(WalletRefundCreditService::class);
        $amount = $plan->snapshot['company_funding_due_cents'];
        $before = WalletTransaction::count();
        $input = $this->fundingCreditInput($case, $amount);
        $request = $credits->request($company, $case, $input);
        $this->assertSame($request->id, $credits->request($company, $case, $input)->id);
        $this->assertContains('wallet_return_funding_request', array_column(app(AccountClosureService::class)->state($company)['blockers'], 'code'));
        $this->assertSame($before, WalletTransaction::count());
        $this->assertSame($amount, $reserve->summary($plan)['liability_remaining_cents']);
        $reviewInput = $this->fundingReviewInput($request);
        try {
            $credits->review($company, $request, $reviewInput);
            $this->fail('The liable account cannot grant its own funding credits.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $reviewed = $credits->review($admin, $request, $reviewInput);
        $this->assertSame('approved', $reviewed->status);
        $this->assertNotContains('wallet_return_funding_request', array_column(app(AccountClosureService::class)->state($company)['blockers'], 'code'));
        $this->assertNotNull($reviewed->wallet_transaction_id);
        $this->assertSame($amount, WalletAccount::where('user_id', $company->id)->sole()->balance_cents);
        $this->assertSame(0, $reserve->summary($plan)['balance_cents']);
        $this->assertSame($amount, $reserve->summary($plan)['liability_remaining_cents']);
        $this->assertContains('buyer_return_compensation', array_column(app(AccountClosureService::class)->state($company)['blockers'], 'code'));
        $money = app(WalletSuccessorReturnMoneyService::class);
        $money->command($company, $case, 'fund-wallet-liability', $this->cashInput($case, ['amount' => SellerSettlementService::pesos($amount), 'wallet_funding_confirmed' => true]));
        $this->assertSame(0, $reserve->summary($plan)['liability_remaining_cents']);
        $money->command($admin, $case, 'refund-wallet', $this->cashInput($case, ['wallet_refund_confirmed' => true]));
        $this->assertSame('repaid', $case->fresh()->status);
        $this->assertSame(18000, WalletAccount::where('user_id', $case->seller_id)->sole()->balance_cents);
        $this->assertSame(5000, WalletAccount::where('kind', 'shipping')->sole()->balance_cents);
        $this->assertNotContains('buyer_return_compensation', array_column(app(AccountClosureService::class)->state($company)['blockers'], 'code'));
        $this->assertSame($reviewed->id, $credits->review($admin, $request, $reviewInput)->id);
        $this->assertSame(1, WalletTransaction::where('kind', 'demo_return_funding_credit')->count());
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
    }

    public function test_demo_funding_requests_reject_other_parties_excess_budget_duplicate_pending_requests_and_source_changes(): void
    {
        [$case, $admin, , $plan] = $this->approvedWallet('courier');
        $company = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $credits = app(WalletRefundCreditService::class);
        $amount = $plan->snapshot['company_funding_due_cents'];
        foreach ([$case->order->buyer, $case->order->shop->user, $admin] as $wrongActor) {
            try {
                $credits->request($wrongActor, $case, $this->fundingCreditInput($case, $amount));
                $this->fail('Only the original liable account can request this budget.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        try {
            $credits->request($company, $case, $this->fundingCreditInput($case, $amount + 1));
            $this->fail('The original unpaid liability bounds the budget.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $request = $credits->request($company, $case, $this->fundingCreditInput($case, $amount));
        try {
            $credits->request($company, $case, $this->fundingCreditInput($case, $amount));
            $this->fail('A second pending request cannot duplicate the original budget.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        try {
            DB::table('wallet_refund_credit_requests')->where('id', $request->id)->update(['amount_cents' => $amount + 1]);
            $this->fail('Raw SQL must retain the original requested amount.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retain', $error->getMessage());
        }
        $company->update(['kyc_status' => 'rejected']);
        try {
            $credits->review($admin, $request, $this->fundingReviewInput($request));
            $this->fail('Changed original funding eligibility requires review before new credit.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $data = $this->fundingReviewInput($request, ['decision' => 'rejected']);
        unset($data['demo_credit_confirmed']);
        $this->assertSame('rejected', $credits->review($admin, $request, $data)->status);
        $this->assertSame(0, WalletTransaction::where('kind', 'demo_return_funding_credit')->count());
    }

    public function test_demo_funding_grant_persistence_failure_rolls_back_credits_and_can_retry_exactly_once(): void
    {
        [$case, $admin, , $plan] = $this->approvedWallet('courier');
        $company = LogisticsCompany::findOrFail($case->parcel->logistics_company_id)->user;
        $credits = app(WalletRefundCreditService::class);
        $request = $credits->request($company, $case, $this->fundingCreditInput($case, $plan->snapshot['company_funding_due_cents']));
        $before = [WalletTransaction::count(), DB::table('wallet_entries')->count(), $case->fresh()->version];
        $reject = true;
        WalletRefundCreditRequest::updating(function () use (&$reject) {
            if ($reject) {
                throw new \RuntimeException('Simulated demonstration-credit review persistence failure.');
            }
        });
        $input = $this->fundingReviewInput($request);
        try {
            $credits->review($admin, $request, $input);
            $this->fail('A missing funding review must not leave credited virtual money.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated demonstration-credit review persistence failure.', $error->getMessage());
        }
        $reject = false;
        $this->assertSame($before, [WalletTransaction::count(), DB::table('wallet_entries')->count(), $case->fresh()->version]);
        $this->assertSame('pending', $request->fresh()->status);
        $reviewed = $credits->review($admin, $request, $input);
        $this->assertSame($reviewed->id, $credits->review($admin, $request, $input)->id);
        $this->assertSame(1, WalletTransaction::where('kind', 'demo_return_funding_credit')->count());
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
    }
}
