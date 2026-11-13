<?php

namespace Tests\Feature\Finance;

use App\Models\CodAccount;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Services\AccountClosureService;
use App\Services\Finance\CodCashService;
use App\Services\Finance\SellerSettlementService;
use App\Services\Finance\ShipmentLossCash;
use App\Services\Finance\ShipmentLossControls;
use App\Services\Finance\ShipmentLossDispositionService;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Returns\BuyerReturnRefundPlan;
use App\Services\Vouchers\VoucherFundingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentLossCashTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function plan(bool $return = false, bool $voucher = false, bool $sellerPaid = false): array
    {
        [$source, $admin, $order, $case] = $this->missing('cod', $return, $voucher, $sellerPaid);
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $this->approval($source, $admin, $return ? '0.00' : '180.00'),
            UploadedFile::fake()->image('original-independent-loss.png'));

        return [$plan, $admin, $order, $case];
    }

    private function input(ShipmentFinancialPlan $plan, string $action, array $extra = []): array
    {
        $proof = in_array($action, ['offer-company', 'offer-advance', 'receive-company', 'receive-advance',
            'record-buyer-payment', 'record-seller-protection', 'retire-advance', 'resolve-buyer-nonreceipt', 'resolve-seller-nonreceipt'], true);

        return ['expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(),
            'reason' => 'The named participant checked the original actual transfer and its separate retained loss obligation.',
            ...($proof ? ['payment_confirmed' => true] : []),
            ...($action === 'assess-zero-buyer' ? ['zero_refund_confirmed' => true] : []),
            ...($action === 'resolve-cash' ? ['financial_resolution_confirmed' => true] : []), ...$extra];
    }

    private function command(User $actor, ShipmentFinancialPlan $plan, string $action, array $extra = []): ShipmentFinancialEvent
    {
        $input = $this->input($plan, $action, $extra);
        $proof = isset($input['payment_confirmed']) ? UploadedFile::fake()->image('actual-cash-'.$action.'.png') : null;
        $service = app(ShipmentLossCash::class);
        $event = $service->command($actor, $plan, $action, $input, $proof);
        $this->assertSame($event->id, $service->command($actor, $plan, $action, $input, $proof)->id);

        return $event;
    }

    private function reject(callable $operation, int $status = 409): void
    {
        $events = ShipmentFinancialEvent::count();
        try {
            $operation();
            $this->fail('Unfunded, unowned or already consumed loss cash cannot execute.');
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
        $this->assertSame($events, ShipmentFinancialEvent::count());
    }

    private function reconcile(Order $order, User $admin): void
    {
        $cash = CodAccount::where('order_id', $order->id)->sole();
        $service = app(CodCashService::class);
        $command = fn ($actor, $action, $data) => $service->command($actor, $cash, $action, $data + [
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(), 'receipt_confirmed' => true,
            'evidence_reference' => 'LOSS-ORIGINAL-'.$cash->id, 'reason' => 'The named receiver checked the actual original collection and its acknowledged cash handover.']);
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

    public static function vouchers(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('vouchers')]
    public function test_uncollected_forward_cod_has_no_buyer_refund_and_only_received_company_money_can_pay_separate_seller_protection(bool $voucher): void
    {
        [$plan, $admin, $order] = $this->plan(voucher: $voucher);
        $service = app(ShipmentLossCash::class);
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $physical = [$order->getAttributes(), $order->delivery->getAttributes(), $order->delivery->checkpoints()->get()->toArray()];
        $due = $plan->snapshot['company_funding_due_cents'];
        $this->assertSame(0, $plan->snapshot['buyer_refund_cents']);
        $this->assertSame(18833, $due);
        $offer = $this->command($owner, $plan, 'offer-company', ['amount' => SellerSettlementService::pesos($due),
            'recipient_id' => $admin->id, 'cash_reference' => '  actual-company-loss-funding  ']);
        $this->assertSame('ACTUAL-COMPANY-LOSS-FUNDING', $offer->cash_reference);
        $this->assertSame(0, $service->state($plan)['received_backing_cents']);
        $this->assertSame($due, $service->state($plan)['company_remaining_cents']);
        $this->reject(fn () => $this->command($admin, $plan, 'record-seller-protection', ['cash_reference' => 'UNBACKED-SELLER-PROTECTION']));
        $this->reject(fn () => $this->command($this->createApprovedUser('admin'), $plan, 'receive-company', ['source_event_id' => $offer->id]));
        $receipt = $this->command($admin, $plan, 'receive-company', ['source_event_id' => $offer->id]);
        $this->assertSame($due, $service->state($plan)['received_backing_cents']);
        $this->assertSame(0, $service->state($plan)['company_remaining_cents']);
        $this->reject(fn () => $this->command($admin, $plan, 'receive-company', ['source_event_id' => $offer->id]));
        $this->reject(fn () => $this->command($admin, $plan, 'record-buyer-payment', ['cash_reference' => 'NO-BUYER-PAYMENT-EXISTS']));
        $this->command($admin, $plan, 'assess-zero-buyer');
        $payment = $this->command($admin, $plan, 'record-seller-protection', ['cash_reference' => 'ACTUAL-SEPARATE-SELLER-PROTECTION']);
        $this->assertSame(18000, $payment->amount_cents);
        $this->assertSame($plan->seller_id, $payment->recipient_id);
        $this->reject(fn () => $this->command($admin, $plan, 'resolve-cash'));
        $seller = $order->items->first()->shop->user;
        $this->reject(fn () => $this->command($order->buyer, $plan, 'confirm-seller-receipt', ['source_event_id' => $payment->id]), 403);
        $this->command($seller, $plan, 'report-seller-nonreceipt', ['source_event_id' => $payment->id]);
        $this->command($admin, $plan, 'resolve-seller-nonreceipt', ['source_event_id' => $payment->id]);
        $this->command($seller, $plan, 'confirm-seller-receipt', ['source_event_id' => $payment->id]);
        $this->command($admin, $plan, 'resolve-cash');
        $count = $plan->events()->count();
        app(ShipmentLossDispositionService::class)->record($admin, $plan, [
            'expected_version' => (int) $plan->events()->max('sequence'), 'request_token' => (string) Str::uuid(), 'disposition_confirmed' => true,
            'reason' => 'Separate operational evidence retains the exact original already settled cash loss without claiming any receipt or extra payment.'],
            UploadedFile::fake()->image('original-settled-cash-disposition.png'));
        $this->assertSame($count + 1, $plan->events()->count());
        $this->assertTrue($service->state($plan)['resolved']);
        $this->assertSame(833, $service->state($plan)['balance_cents']);
        $this->assertSame($physical, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $order->delivery->checkpoints()->get()->toArray()]);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('seller_settlement_events', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame(0, $plan->events()->where('event_type', 'loss-record-buyer-payment')->count());
        $this->assertSame($offer->id, $receipt->consumed_source_id);
        if ($voucher) {
            $this->assertNotEmpty(app(VoucherFundingService::class)->amounts($order->fresh())['promotion_blockers']);
            $this->assertDatabaseHas('voucher_funding_entries', ['event_type' => 'release', 'amount_cents' => 2000]);
            $this->assertDatabaseMissing('voucher_funding_entries', ['event_type' => 'expense']);
        }
    }

    public static function partialSources(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    #[DataProvider('partialSources')]
    public function test_original_paid_partial_return_requires_actual_cod_reconciliation_and_funding_then_one_buyer_payment_and_confirmation(bool $voucher, bool $sellerPaid): void
    {
        [$plan, $admin, $order, $case] = $this->plan(return: true, voucher: $voucher, sellerPaid: $sellerPaid);
        $refund = $voucher ? 11500 : 12500;
        $originalProceeds = $plan->snapshot['original_seller_payment'];
        $this->assertSame($sellerPaid, $originalProceeds !== null);
        $service = app(ShipmentLossCash::class);
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $physical = [$order->getAttributes(), $order->delivery->getAttributes(), $case->getAttributes(), $case->parcel->getAttributes()];
        $offer = $this->command($owner, $plan, 'offer-company', ['amount' => SellerSettlementService::pesos($refund), 'recipient_id' => $admin->id, 'cash_reference' => 'PARTIAL-ORIGINAL-LOSS-FUNDING']);
        $this->command($admin, $plan, 'receive-company', ['source_event_id' => $offer->id]);
        if (! $sellerPaid) {
            $this->reject(fn () => $this->command($admin, $plan, 'record-buyer-payment', ['cash_reference' => 'NOT-RECONCILED-ORIGINAL']));
            $this->reconcile($order, $admin);
        }
        $cash = $order->codAccount->fresh()->getAttributes();
        $payment = $this->command($admin, $plan, 'record-buyer-payment', ['cash_reference' => 'ACTUAL-PARTIAL-BUYER-LOSS-REFUND']);
        $this->assertSame($refund, $payment->amount_cents);
        $this->assertSame($order->buyer_id, $payment->recipient_id);
        $this->reject(fn () => $this->command($admin, $plan, 'record-buyer-payment', ['cash_reference' => 'NO-SECOND-PARTIAL-REFUND']));
        $this->command($order->buyer, $plan, 'report-buyer-nonreceipt', ['source_event_id' => $payment->id]);
        $this->assertSame('disputed', $service->state($plan)['buyer']['status']);
        $this->reject(fn () => $this->command($admin, $plan, 'resolve-cash'));
        $this->command($admin, $plan, 'resolve-buyer-nonreceipt', ['source_event_id' => $payment->id]);
        $this->assertSame('payment_recorded', $service->state($plan)['buyer']['status']);
        $this->command($order->buyer, $plan, 'confirm-buyer-receipt', ['source_event_id' => $payment->id]);
        $this->command($admin, $plan, 'resolve-cash');
        $this->assertTrue($service->state($plan)['resolved']);
        $this->assertSame(0, $service->state($plan)['balance_cents']);
        $this->assertSame($physical, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $case->fresh()->getAttributes(), $case->parcel->fresh()->getAttributes()]);
        $this->assertSame($cash, $order->codAccount->fresh()->getAttributes());
        $this->assertSame(1, $plan->events()->where('event_type', 'loss-record-buyer-payment')->count());
        $this->assertSame(0, $plan->events()->where('event_type', 'loss-record-seller-protection')->count());
        $this->assertFalse($case->events()->where('event_type', 'return-seller-receive')->exists());
        $this->assertDatabaseCount('buyer_return_adjustments', 0);
        $this->assertSame($originalProceeds, app(BuyerReturnRefundPlan::class)->sellerPayment($order->fresh()));
    }

    public function test_actual_cash_advance_preserves_company_debt_and_only_an_actual_excess_return_can_retire_the_original_advance(): void
    {
        [$plan, $admin, $order] = $this->plan();
        $service = app(ShipmentLossCash::class);
        $funder = $this->createApprovedUser('admin');
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $offer = $this->command($funder, $plan, 'offer-advance', ['amount' => '188.33', 'recipient_id' => $admin->id, 'cash_reference' => 'ACTUAL-ORIGINAL-ADVANCE']);
        $receipt = $this->command($admin, $plan, 'receive-advance', ['source_event_id' => $offer->id]);
        $this->reject(fn () => $this->command($admin, $plan, 'retire-advance', ['source_event_id' => $receipt->id, 'amount' => '1.00', 'cash_reference' => 'DO-NOT-RETIRE-PROTECTED-FUNDS']));
        $this->command($admin, $plan, 'assess-zero-buyer');
        $payment = $this->command($admin, $plan, 'record-seller-protection', ['cash_reference' => 'ADVANCED-SELLER-PROTECTION']);
        $this->command($order->items->first()->shop->user, $plan, 'confirm-seller-receipt', ['source_event_id' => $payment->id]);
        $this->command($admin, $plan, 'resolve-cash');
        $this->assertContains('shipment_loss_company_liability', array_column(app(AccountClosureService::class)->state($owner)['blockers'], 'code'));
        $this->assertContains('shipment_loss_advance', array_column(app(AccountClosureService::class)->state($funder)['blockers'], 'code'));
        $owner->update(['status' => 'suspended']);
        $offer = $this->command($owner, $plan, 'offer-company', ['amount' => '188.33', 'recipient_id' => $admin->id, 'cash_reference' => 'ACTUAL-LATE-COMPANY-CASH']);
        $this->assertSame(18833, $service->state($plan)['company_remaining_cents']);
        $this->assertContains('shipment_loss_cash_receipt', array_column(app(AccountClosureService::class)->state($owner)['blockers'], 'code'));
        $this->command($admin, $plan, 'receive-company', ['source_event_id' => $offer->id]);
        $this->assertSame(0, $service->state($plan)['company_remaining_cents']);
        $this->reject(fn () => $this->command($funder, $plan, 'retire-advance', ['source_event_id' => $receipt->id, 'amount' => '188.33', 'cash_reference' => 'SELF-REPORTED-ADVANCE-RETURN']), 403);
        $this->command($admin, $plan, 'retire-advance', ['source_event_id' => $receipt->id, 'amount' => '188.33', 'cash_reference' => 'ACTUAL-ADVANCE-RETURN']);
        $this->assertSame(0, $service->state($plan)['advance_outstanding_cents']);
        $this->assertSame(833, $service->state($plan)['balance_cents']);
        $this->assertNotContains('shipment_loss_company_liability', array_column(app(AccountClosureService::class)->state($owner)['blockers'], 'code'));
        $this->assertNotContains('shipment_loss_advance', array_column(app(AccountClosureService::class)->state($funder)['blockers'], 'code'));
        $this->assertSame(1, $plan->events()->where('event_type', 'loss-record-seller-protection')->count());
    }

    public function test_original_roles_limits_actual_proof_and_transfer_references_remain_enforced_before_cash_can_move(): void
    {
        [$plan, $admin, $order] = $this->plan();
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $this->reject(fn () => $this->command($order->buyer, $plan, 'offer-advance', ['amount' => '1.00', 'recipient_id' => $admin->id, 'cash_reference' => 'BUYER-CANNOT-ADVANCE']), 403);
        $this->reject(fn () => $this->command($this->createApprovedUser('logistics'), $plan, 'offer-company', ['amount' => '1.00', 'recipient_id' => $admin->id, 'cash_reference' => 'FOREIGN-COMPANY-CANNOT-FUND']), 403);
        $this->reject(fn () => $this->command($owner, $plan, 'offer-company', ['amount' => '188.34', 'recipient_id' => $admin->id, 'cash_reference' => 'EXCESS-COMPANY-FUNDING']));
        $input = $this->input($plan, 'offer-company', ['amount' => '1.00', 'recipient_id' => $admin->id, 'cash_reference' => 'INVALID-ACTUAL-PROOF']);
        try {
            app(ShipmentLossCash::class)->command($owner, $plan, 'offer-company', $input, UploadedFile::fake()->createWithContent('proof.pdf', 'A filename is not a financial receipt.'));
            $this->fail('A real private bounded receipt is required.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('proof', $error->errors());
        }
        $offer = $this->command($owner, $plan, 'offer-company', ['amount' => '1.00', 'recipient_id' => $admin->id, 'cash_reference' => 'ORIGINAL-UNIQUE-LOSS-TRANSFER']);
        $this->reject(fn () => $this->command($owner, $plan, 'offer-company', ['amount' => '1.00', 'recipient_id' => $admin->id, 'cash_reference' => ' original-unique-loss-transfer ']));
        $this->assertSame(0, app(ShipmentLossCash::class)->state($plan)['received_backing_cents']);
        $this->assertSame(100, app(ShipmentLossCash::class)->state($plan)['pending_company_cents']);
        $this->assertSame($owner->id, $offer->actor_id);
    }

    public function test_an_already_offered_actual_transfer_can_be_received_after_parcel_recovery_but_cannot_authorize_a_stale_loss_payment(): void
    {
        [$plan, $admin, $order] = $this->plan();
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $input = $this->input($plan, 'offer-company', ['amount' => '188.33', 'recipient_id' => $admin->id, 'cash_reference' => 'ORIGINAL-BEFORE-FOUND-TRANSFER']);
        $proof = UploadedFile::fake()->image('original-before-found-transfer.png');
        $service = app(ShipmentLossCash::class);
        $offer = $service->command($owner, $plan, 'offer-company', $input, $proof);
        $this->quarantineMissing($plan);
        $this->assertSame($offer->id, $service->command($owner, $plan, 'offer-company', $input, $proof)->id);
        $receipt = $this->command($admin, $plan, 'receive-company', ['source_event_id' => $offer->id]);
        $this->assertTrue($receipt->context['no_new_beneficiary_payment_authorized']);
        $this->assertSame(18833, $service->state($plan)['balance_cents']);
        $this->reject(fn () => $this->command($admin, $plan, 'record-seller-protection', ['cash_reference' => 'FOUND-PARCEL-CANNOT-USE-OLD-LOSS']));
        $this->assertSame(0, $plan->events()->where('event_type', 'loss-record-seller-protection')->count());
        $this->assertFalse($service->state($plan)['resolved']);
    }

    public function test_actual_found_quarantine_preserves_seller_nonreceipt_review_without_another_payment_or_financial_resolution(): void
    {
        [$plan, $admin, $order] = $this->plan();
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $offer = $this->command($owner, $plan, 'offer-company', ['amount' => '188.33', 'recipient_id' => $admin->id, 'cash_reference' => 'BEFORE-FOUND-SELLER-FUNDING']);
        $this->command($admin, $plan, 'receive-company', ['source_event_id' => $offer->id]);
        $payment = $this->command($admin, $plan, 'record-seller-protection', ['cash_reference' => 'BEFORE-FOUND-ORIGINAL-SELLER-PAYMENT']);
        $this->quarantineMissing($plan);
        $service = app(ShipmentLossCash::class);
        $before = $service->state($plan);
        $this->command($order->shop->user, $plan, 'report-seller-nonreceipt', ['source_event_id' => $payment->id]);
        $reviewer = $this->createApprovedUser('admin');
        $controls = app(ShipmentLossControls::class)->present($reviewer, $plan, $service->state($plan), $plan->events()->get());
        $this->assertContains('resolve-seller-nonreceipt', array_column($controls['actions'], 'id'));
        $resolved = $this->command($reviewer, $plan, 'resolve-seller-nonreceipt', ['source_event_id' => $payment->id]);
        $this->assertSame(0, $resolved->amount_cents);
        $this->assertTrue($resolved->context['no_second_payment']);
        $this->command($order->shop->user, $plan, 'confirm-seller-receipt', ['source_event_id' => $payment->id]);
        $this->assertSame($before['balance_cents'], $service->state($plan)['balance_cents']);
        $this->assertSame(1, $plan->events()->where('event_type', 'loss-record-seller-protection')->count());
        $this->reject(fn () => $this->command($admin, $plan, 'record-seller-protection', ['cash_reference' => 'FOUND-NO-SECOND-PAYMENT']));
        $this->reject(fn () => $this->command($admin, $plan, 'resolve-cash'));
        $this->assertFalse($service->state($plan)['resolved']);
    }

    public function test_missing_actual_received_cash_evidence_blocks_new_payouts_without_erasing_the_original_company_receipt(): void
    {
        [$plan, $admin] = $this->plan();
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $offer = $this->command($owner, $plan, 'offer-company', ['amount' => '188.33', 'recipient_id' => $admin->id, 'cash_reference' => 'ORIGINAL-RETAINED-RECEIPT']);
        $receipt = $this->command($admin, $plan, 'receive-company', ['source_event_id' => $offer->id]);
        $bytes = Storage::disk('local')->get($receipt->proof_path);
        Storage::disk('local')->delete($receipt->proof_path);
        $this->reject(fn () => $this->command($admin, $plan, 'record-seller-protection', ['cash_reference' => 'NO-MISSING-RECEIPT-PAYOUT']));
        $this->assertSame(18833, app(ShipmentLossCash::class)->state($plan)['received_backing_cents']);
        Storage::disk('local')->put($receipt->proof_path, $bytes);
        $payment = $this->command($admin, $plan, 'record-seller-protection', ['cash_reference' => 'RESTORED-ORIGINAL-RECEIPT-PAYOUT']);
        $this->assertSame(18000, $payment->amount_cents);
    }

    public function test_late_cash_event_failure_rolls_back_actual_history_and_the_new_private_file_then_exact_retry_succeeds(): void
    {
        [$plan, $admin] = $this->plan();
        $owner = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $input = $this->input($plan, 'offer-company', ['amount' => '188.33', 'recipient_id' => $admin->id, 'cash_reference' => 'RETRY-ORIGINAL-LOSS-TRANSFER']);
        $proof = UploadedFile::fake()->image('actual-transfer.png');
        $before = Storage::disk('local')->allFiles('shipment-finance');
        ShipmentFinancialEvent::creating(function ($event) {
            if ($event->event_type === 'loss-offer-company') {
                throw new \RuntimeException('Isolated late cash event failure.');
            }
        });
        try {
            app(ShipmentLossCash::class)->command($owner, $plan, 'offer-company', $input, $proof);
            $this->fail('The isolated store failure must roll back the original actual transfer event.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Isolated late cash event failure.', $error->getMessage());
        } finally {
            ShipmentFinancialEvent::flushEventListeners();
            ShipmentFinancialEvent::clearBootedModels();
        }
        $this->assertSame($before, Storage::disk('local')->allFiles('shipment-finance'));
        $this->assertSame(1, $plan->events()->count());
        $service = app(ShipmentLossCash::class);
        $saved = $service->command($owner, $plan, 'offer-company', $input, $proof);
        $this->assertSame($saved->id, $service->command($owner, $plan, 'offer-company', $input, $proof)->id);
    }
}
