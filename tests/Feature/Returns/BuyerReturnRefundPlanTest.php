<?php

namespace Tests\Feature\Returns;

use App\Models\BuyerReturnCase;
use App\Models\BuyerReturnMoneyEvent;
use App\Models\BuyerReturnRepaymentPlan;
use App\Models\CodAccount;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Finance\CodCashService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Returns\BuyerReturnAmounts;
use App\Services\Returns\BuyerReturnCashExecution;
use App\Services\Returns\BuyerReturnPlanApproval;
use App\Services\Returns\BuyerReturnRefundPlan;
use App\Services\Returns\BuyerReturnService;
use App\Services\Wallet\WalletCreditService;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function received(string $mode = 'cod', string $fault = 'seller', bool $shippingVoucher = false, bool $finish = true, bool $sellerPaid = false): array
    {
        $buyer = $this->createApprovedUser('buyer');
        $admin = $this->createApprovedUser('admin');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $shipping = ['payment_method' => $mode];
        if ($shippingVoucher) {
            $voucher = Voucher::factory()->funded()->create(['code' => 'PLAN_SHIPPING', 'discount_type' => 'free_shipping', 'discount_value' => 0, 'min_spend' => 0, 'max_discount' => 20]);
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
            'request_token' => (string) Str::uuid(), 'reason' => 'A policy-one writer must not substitute its older terms for this successor plan.'], ['Accept' => 'application/json'])->assertConflict();
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
        $company->update(['status' => 'suspended']);
        $offer = $service->command($company, $case, 'offer-compensation', $this->cashInput($case, ['amount' => SellerSettlementService::pesos($amount),
            'recipient_id' => $admin->id, 'cash_reference' => 'COMPANY-REPAYS-ADVANCE', 'payment_confirmed' => true]), UploadedFile::fake()->image('actual-company-recovery.png'));
        $this->assertSame($amount, $service->funding($case)['compensation_remaining_cents']);
        $service->command($admin, $case, 'receive-compensation', $this->cashInput($case, ['source_event_id' => $offer->id, 'payment_confirmed' => true]), UploadedFile::fake()->image('company-received.png'));
        $this->assertSame(0, $service->funding($case)['compensation_remaining_cents']);
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
}
