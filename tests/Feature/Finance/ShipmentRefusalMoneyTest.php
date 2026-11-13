<?php

namespace Tests\Feature\Finance;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryReturnRoute;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\ShipmentFinancialEvent;
use App\Models\ShipmentFinancialPlan;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherOrderAllocation;
use App\Models\VoucherRevisionBalance;
use App\Models\WalletAccount;
use App\Models\WalletTransaction;
use App\Services\AccountClosureService;
use App\Services\Finance\CodMoney;
use App\Services\Finance\ShipmentRefusalLiability;
use App\Services\Finance\ShipmentRefusalMoney;
use App\Services\Finance\ShipmentRefusalReview;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletDeliveryRefundService;
use App\Services\Wallet\WalletSettlementService;
use App\Services\Wallet\WalletViewService;
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
use Tests\TestCase;

class ShipmentRefusalMoneyTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->withoutVite();
    }

    private function refused(string $mode = 'wallet', string $promotion = '', bool $finish = true): array
    {
        $buyer = $this->createApprovedUser('buyer');
        $admin = $this->createApprovedUser('admin');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $shipping = ['payment_method' => $mode];
        if ($promotion !== '') {
            $voucher = Voucher::factory()->funded()->create(['min_spend' => 0, 'discount_type' => $promotion === 'shipping' ? 'free_shipping' : 'fixed',
                'discount_value' => $promotion === 'shipping' ? 0 : 40, 'max_discount' => $promotion === 'shipping' ? 20 : null]);
            $shipping['platform_voucher_revision_id'] = $voucher->current_revision_id;
        }
        $order = $this->checkoutFlowOrder($buyer, $shop, [['product' => $this->createE2EProduct($shop, ['price' => '500.00']), 'quantity' => 1]], shipping: $shipping);
        // Future-policy acceptance fixture; production checkout remains policy one until the complete rollout gate.
        $order->update(['shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => 2]]);
        $this->assertSame('50.00', $order->shipping_fee);
        if ($mode === 'wallet') {
            $credits = app(WalletCreditService::class);
            $request = $credits->request($buyer, ['amount' => '1000.00', 'reason' => 'Original demonstration credits for the actual refusal journey.', 'request_token' => (string) Str::uuid()]);
            $credits->review($admin, $request, ['decision' => 'approved', 'reason' => 'Independently reviewed original demonstration credits.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
            $this->actingAs($buyer)->postJson('/wallet/payments/'.$order->walletPayment->id.'/pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()])->assertOk();
        }
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $this->actingAs(User::findOrFail($parcel->assigned_rider_id))->patch(route('courier.updateStatus', $parcel), $this->flowFailurePayload($parcel, 'customer_refused'))->assertSessionHas('success');
        $this->receiveFailureFlow($parcel);
        if ($finish) {
            $route = DeliveryReturnRoute::where('delivery_id', $parcel->id)->sole();
            foreach (array_slice($route->hub_ids, 1) as $hubId) {
                $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($parcel->fresh()->current_hub_id), true, direction: 'return');
                $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($hubId), false, direction: 'return');
            }
            $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
            $this->actingAs($this->flowHandler($origin))->postJson(route('hub.scan'), ['barcode' => $parcel->tracking_number, 'hub_id' => $origin->id,
                'mode' => 'confirm', 'action' => 'STAGE_SELLER_RETURN', 'expected_status' => 'return_to_sender', 'route_reference' => $route->reference])->assertOk();
            $this->actingAs($shop->user)->postJson(route('seller.orders.return-receipt', $order), ['barcode' => $parcel->tracking_number,
                'route_reference' => $route->reference, 'request_token' => (string) Str::uuid(), 'notes' => 'Received the actual conforming parcel after its original refusal and reverse transport.'])->assertOk();
        }

        return [$order->fresh(['delivery', 'items.shop', 'buyer']), $admin];
    }

    private function approval(Order $order, User $admin, string $fee = '40.00'): array
    {
        $details = ['reverse_fee' => $fee, 'cost_reference' => 'ACTUAL-REVERSE-COST-'.$order->id];
        $review = app(ShipmentRefusalReview::class)->preview($admin, $order, $details);

        return [...$details, 'review_token' => $review['review_token'], 'request_token' => (string) Str::uuid(),
            'reason' => 'Reviewed the original conformity, actual refusal, complete seller receipt and evidenced reverse cost.', 'conforming_shipment_confirmed' => true];
    }

    private function execution(ShipmentFinancialPlan $plan): array
    {
        return ['expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'reason' => 'Execute only the independently reviewed original refusal amounts and retained actual payment source.',
            $plan->snapshot['payment_method'] === 'wallet' ? 'wallet_refund_confirmed' : 'assessment_confirmed' => true];
    }

    public static function outcomes(): array
    {
        return ['ordinary prepaid' => ['wallet', '40.00', '', 46000, 0], 'no reverse fee' => ['wallet', '0.00', '', 50000, 0],
            'fee exceeds items' => ['wallet', '600.00', '', 0, 10000], 'unpaid cash' => ['cod', '40.00', '', 0, 9000],
            'prepaid shipping subsidy' => ['wallet', '40.00', 'shipping', 46000, 0], 'prepaid discounted product' => ['wallet', '40.00', 'product', 42000, 0],
            'unpaid cash shipping subsidy' => ['cod', '40.00', 'shipping', 0, 7000], 'unpaid cash discounted product' => ['cod', '40.00', 'product', 0, 9000]];
    }

    #[DataProvider('outcomes')]
    public function test_evidenced_refusal_preserves_spent_shipping_and_deducts_actual_reverse_fee_once(string $mode, string $fee, string $promotion, int $refund, int $debt): void
    {
        [$order, $admin] = $this->refused($mode, $promotion);
        $source = [$order->getAttributes(), $order->delivery->getAttributes(), $order->delivery->checkpoints()->get()->toArray()];
        $ledgerBefore = DB::table('wallet_entries')->count();
        $buyerBefore = WalletAccount::where('user_id', $order->buyer_id)->value('balance_cents') ?? 0;
        $input = $this->approval($order, $admin, $fee);
        $proof = UploadedFile::fake()->image('actual-conforming-return-cost.png');
        $review = app(ShipmentRefusalReview::class);
        $plan = $review->approve($admin, $order, $input, $proof);
        $this->assertSame($plan->id, $review->approve($admin, $order, $input, $proof)->id);
        $this->assertSame($ledgerBefore, DB::table('wallet_entries')->count());
        $this->assertSame($refund, $plan->snapshot['buyer_refund_cents']);
        $this->assertSame($debt, $plan->snapshot['buyer_liability_cents']);
        $execute = $this->execution($plan);
        $service = app(ShipmentRefusalMoney::class);
        $event = $service->execute($admin, $plan, $execute);
        $this->assertSame($event->id, $service->execute($admin, $plan, $execute)->id);
        $this->assertSame($refund, $event->amount_cents);
        $this->assertSame($buyerBefore + $refund, WalletAccount::where('user_id', $order->buyer_id)->value('balance_cents') ?? 0);
        $this->assertSame($refund > 0 ? $order->buyer_id : null, $event->recipient_id);
        $this->assertSame($mode === 'wallet' ? 5000 : 0, WalletAccount::where('kind', 'shipping')->value('balance_cents') ?? 0);
        $this->assertSame($mode === 'wallet' ? min(50000 - ($promotion === 'product' ? 4000 : 0), CodMoney::cents($fee)) : 0,
            WalletAccount::where('kind', 'reverse_shipping')->value('balance_cents') ?? 0);
        $this->assertSame(0, WalletAccount::where('kind', 'escrow')->value('balance_cents') ?? 0);
        $this->assertSame(0, WalletAccount::where('user_id', $order->shop->user_id)->value('balance_cents') ?? 0);
        $this->assertSame(0, WalletAccount::where('kind', 'commission')->value('balance_cents') ?? 0);
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('buyer_return_cases', 0);
        $this->assertDatabaseCount('shipment_financial_events', 2);
        $this->assertSame($source, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $order->delivery->checkpoints()->get()->toArray()]);
        if ($mode === 'wallet') {
            $view = app(WalletSettlementService::class)->present($order->fresh());
            $this->assertFalse($view['can_settle']);
            $this->assertFalse($view['can_refund_delivery']);
            $this->assertSame($refund, $view['refunded_cents']);
            $this->assertSame(0, $view['seller_cents']);
            $this->assertSame(5000, $view['shipping_cents']);
            $this->assertSame($refund > 0, $view['refund_reference'] !== null);
            $this->assertNotNull($view['resolution_reference']);
            $buyerView = app(WalletViewService::class)->payment($order->buyer, $order->fresh()->walletPayment);
            $this->assertSame($refund, $buyerView['refunded_cents']);
            $this->assertSame($refund > 0, $buyerView['refund_reference'] !== null);
            $this->assertSame('/shipment-finances/'.$plan->id, $buyerView['shipment_financial_url']);
            $this->assertFalse(app(WalletDeliveryRefundService::class)->eligible($order->fresh()));
        }
        if ($promotion !== '') {
            $part = VoucherOrderAllocation::where('order_id', $order->id)->sole();
            $balance = VoucherRevisionBalance::where('voucher_revision_id', $part->redemption->voucher_revision_id)->sole();
            $this->assertSame(0, $balance->committed_cents);
            $this->assertSame($promotion === 'shipping' ? 2000 : 0, $balance->spent_cents);
            $this->assertSame($promotion === 'product' ? 4000 : 0, $balance->released_cents);
            $this->assertSame(1, $balance->redemption_count);
            $this->assertSame(1, $part->redemption->revision->voucher->used_count);
        }
    }

    public function test_refusal_review_requires_actual_seller_receipt_instead_of_inventing_it_from_a_failure_label(): void
    {
        [$order, $admin] = $this->refused(finish: false);
        try {
            $this->approval($order, $admin);
            $this->fail('The refusal still has an incomplete physical reverse journey.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('shipment_financial_plans', 0);
        $this->assertSame('return_to_sender', $order->delivery->fresh()->status);
    }

    public function test_refusal_review_rejects_other_parties_stale_amounts_invalid_private_proof_and_original_policy_one(): void
    {
        [$order, $admin] = $this->refused();
        $review = app(ShipmentRefusalReview::class);
        foreach ([$order->buyer, $order->shop->user] as $other) {
            try {
                $review->preview($other, $order, ['reverse_fee' => '40.00', 'cost_reference' => 'ACTUAL-REVERSE-COST']);
                $this->fail('Only a current independent admin can approve these financial terms.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        $input = $this->approval($order, $admin);
        try {
            $review->approve($admin, $order, [...$input, 'reverse_fee' => '41.00'], UploadedFile::fake()->image('cost.png'));
            $this->fail('The displayed fee must match the signed review.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        try {
            $review->approve($admin, $order, $input, UploadedFile::fake()->createWithContent('receipt.pdf', 'A renamed document is not original PDF evidence.'));
            $this->fail('A filename cannot replace actual private evidence.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('proof', $error->errors());
        }
        $order->update(['shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => 1]]);
        try {
            $review->approve($admin, $order, $input, UploadedFile::fake()->image('cost.png'));
            $this->fail('Do not silently rewrite an original policy-one purchase.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('shipment_financial_plans', 0);
        $this->assertCount(0, Storage::disk('local')->files('shipment-finance'));
    }

    public function test_failed_financial_execution_rolls_back_original_escrow_voucher_release_and_refund_then_retries_once(): void
    {
        [$order, $admin] = $this->refused(promotion: 'product');
        $plan = app(ShipmentRefusalReview::class)->approve($admin, $order, $this->approval($order, $admin), UploadedFile::fake()->image('cost.png'));
        $before = [DB::table('wallet_entries')->count(), WalletTransaction::count(), VoucherRevisionBalance::first()->getAttributes()];
        $reject = true;
        ShipmentFinancialEvent::creating(function () use (&$reject) {
            if ($reject) {
                throw new \RuntimeException('Simulated original financial event persistence failure.');
            }
        });
        $input = $this->execution($plan);
        $service = app(ShipmentRefusalMoney::class);
        try {
            $service->execute($admin, $plan, $input);
            $this->fail('A missing retained financial event must roll back every actual credit and released commitment.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated original financial event persistence failure.', $error->getMessage());
        }
        $reject = false;
        $this->assertSame($before, [DB::table('wallet_entries')->count(), WalletTransaction::count(), VoucherRevisionBalance::first()->getAttributes()]);
        $this->assertDatabaseCount('wallet_voucher_releases', 0);
        $event = $service->execute($admin, $plan, $input);
        $this->assertSame($event->id, $service->execute($admin, $plan, $input)->id);
        try {
            DB::table('shipment_financial_plans')->where('id', $plan->id)->update(['reason' => 'Overwrite the original review.']);
            $this->fail('Raw SQL cannot change the original financial decision.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retain', $error->getMessage());
        }
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_refusal_refund')->count());
    }

    public function test_unpaid_cod_shipping_debt_clears_only_after_the_named_admin_receives_the_actual_buyer_transfer(): void
    {
        [$order, $admin] = $this->refused('cod');
        $plan = app(ShipmentRefusalReview::class)->approve($admin, $order, $this->approval($order, $admin), UploadedFile::fake()->image('reverse-cost.png'));
        app(ShipmentRefusalMoney::class)->execute($admin, $plan, $this->execution($plan));
        $service = app(ShipmentRefusalLiability::class);
        $this->assertSame(9000, $service->state($plan)['remaining_cents']);
        $this->assertContains('shipment_shipping_liability', array_column(app(AccountClosureService::class)->state($order->buyer)['blockers'], 'code'));
        $input = ['expected_version' => 2, 'request_token' => (string) Str::uuid(), 'amount' => '90.00', 'recipient_id' => $admin->id,
            'cash_reference' => 'ACTUAL-BUYER-SHIPPING-001', 'payment_confirmed' => true,
            'reason' => 'The buyer made this actual transfer for the reviewed original shipping obligation.'];
        $proof = UploadedFile::fake()->image('actual-buyer-transfer.png');
        $offer = $service->command($order->buyer, $plan, 'offer-shipping-payment', $input, $proof);
        $this->assertSame($offer->id, $service->command($order->buyer, $plan, 'offer-shipping-payment', $input, $proof)->id);
        $this->assertSame(9000, $service->state($plan)['remaining_cents']);
        $this->assertSame(0, $service->state($plan)['received_cents']);
        $this->assertContains('shipment_shipping_receipt', array_column(app(AccountClosureService::class)->state($admin)['blockers'], 'code'));
        $receipt = ['expected_version' => 3, 'request_token' => (string) Str::uuid(), 'source_event_id' => $offer->id, 'payment_confirmed' => true,
            'reason' => 'The named admin checked the actual original incoming buyer transfer and its private receipt.'];
        try {
            $service->command($this->createApprovedUser('admin'), $plan, 'receive-shipping-payment', $receipt, UploadedFile::fake()->image('wrong-receiver.png'));
            $this->fail('Another admin cannot claim to have received the named original transfer.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $proof = UploadedFile::fake()->image('actual-receipt.png');
        $received = $service->command($admin, $plan, 'receive-shipping-payment', $receipt, $proof);
        $this->assertSame($received->id, $service->command($admin, $plan, 'receive-shipping-payment', $receipt, $proof)->id);
        $this->assertSame(0, $service->state($plan)['remaining_cents']);
        $this->assertSame(5000, $received->context['outbound_received_cents']);
        $this->assertSame(4000, $received->context['reverse_received_cents']);
        $this->assertNotContains('shipment_shipping_liability', array_column(app(AccountClosureService::class)->state($order->buyer)['blockers'], 'code'));
        $this->assertNotContains('shipment_shipping_receipt', array_column(app(AccountClosureService::class)->state($admin)['blockers'], 'code'));
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_prepaid_excess_shipping_is_a_separate_voluntary_wallet_transfer_without_an_automatic_debit_or_second_refund(): void
    {
        [$order, $admin] = $this->refused();
        $plan = app(ShipmentRefusalReview::class)->approve($admin, $order, $this->approval($order, $admin, '600.00'), UploadedFile::fake()->image('reverse-cost.png'));
        $buyerBefore = WalletAccount::where('user_id', $order->buyer_id)->sole()->balance_cents;
        $execution = $this->execution($plan);
        $event = app(ShipmentRefusalMoney::class)->execute($admin, $plan, $execution);
        $this->assertSame(0, $event->amount_cents);
        $this->assertNull($event->recipient_id);
        $this->assertSame($buyerBefore, WalletAccount::where('user_id', $order->buyer_id)->sole()->balance_cents);
        $service = app(ShipmentRefusalLiability::class);
        $this->assertSame(10000, $service->state($plan)['remaining_cents']);
        $this->assertContains('shipment_shipping_liability', array_column(app(AccountClosureService::class)->state($order->buyer)['blockers'], 'code'));
        $input = ['expected_version' => 2, 'request_token' => (string) Str::uuid(), 'amount' => '100.00', 'payment_confirmed' => true,
            'reason' => 'I approve this separate transfer of my available virtual credits to settle my reviewed excess reverse fee.'];
        $payment = $service->command($order->buyer, $plan, 'pay-wallet-shipping', $input);
        $this->assertSame($payment->id, $service->command($order->buyer, $plan, 'pay-wallet-shipping', $input)->id);
        $this->assertSame($buyerBefore - 10000, WalletAccount::where('user_id', $order->buyer_id)->sole()->balance_cents);
        $this->assertSame(60000, WalletAccount::where('kind', 'reverse_shipping')->sole()->balance_cents);
        $this->assertSame(0, $service->state($plan)['remaining_cents']);
        $this->assertNotContains('shipment_shipping_liability', array_column(app(AccountClosureService::class)->state($order->buyer)['blockers'], 'code'));
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_refusal_refund')->count());
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_refusal_shipping_payment')->count());
        $this->assertSame(0, (int) DB::table('wallet_entries')->sum('delta_cents'));
    }

    public function test_shipping_payment_cannot_make_a_wallet_negative_and_keeps_the_original_retry_after_insufficient_credits(): void
    {
        [$order, $admin] = $this->refused();
        $plan = app(ShipmentRefusalReview::class)->approve($admin, $order, $this->approval($order, $admin, '1000.00'), UploadedFile::fake()->image('reverse-cost.png'));
        app(ShipmentRefusalMoney::class)->execute($admin, $plan, $this->execution($plan));
        $service = app(ShipmentRefusalLiability::class);
        $input = ['expected_version' => 2, 'request_token' => (string) Str::uuid(), 'amount' => '500.00', 'payment_confirmed' => true,
            'reason' => 'Attempt to settle only the original reviewed shipping debt with actual available virtual credits.'];
        $before = [WalletTransaction::count(), DB::table('wallet_entries')->count(), $service->state($plan)];
        try {
            $service->command($order->buyer, $plan, 'pay-wallet-shipping', $input);
            $this->fail('Insufficient credits must not create an automatic negative balance.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($before, [WalletTransaction::count(), DB::table('wallet_entries')->count(), $service->state($plan)]);
        $credits = app(WalletCreditService::class);
        $topup = $credits->request($order->buyer, ['amount' => '50.00', 'reason' => 'Review additional original virtual credits before paying the shipping debt.', 'request_token' => (string) Str::uuid()]);
        $credits->review($admin, $topup, ['decision' => 'approved', 'reason' => 'Reviewed the original available credits and explicit demonstration request.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
        $event = $service->command($order->buyer, $plan, 'pay-wallet-shipping', $input);
        $this->assertSame($event->id, $service->command($order->buyer, $plan, 'pay-wallet-shipping', $input)->id);
        $this->assertSame(0, WalletAccount::where('user_id', $order->buyer_id)->sole()->balance_cents);
        $this->assertSame(0, $service->state($plan)['remaining_cents']);
    }

    public function test_failed_refusal_approval_retains_no_orphan_private_file_or_financial_plan(): void
    {
        [$order, $admin] = $this->refused();
        $input = $this->approval($order, $admin);
        $proof = UploadedFile::fake()->image('original-reviewed-cost.png');
        $reject = true;
        ShipmentFinancialEvent::creating(function () use (&$reject) {
            if ($reject) {
                throw new \RuntimeException('Simulated review-event persistence failure.');
            }
        });
        try {
            app(ShipmentRefusalReview::class)->approve($admin, $order, $input, $proof);
            $this->fail('The original private file and financial review must roll back together.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated review-event persistence failure.', $error->getMessage());
        }
        $reject = false;
        $this->assertDatabaseCount('shipment_financial_plans', 0);
        $this->assertDatabaseCount('shipment_financial_events', 0);
        $this->assertCount(0, Storage::disk('local')->files('shipment-finance'));
        $plan = app(ShipmentRefusalReview::class)->approve($admin, $order, $input, $proof);
        $this->assertSame($plan->id, app(ShipmentRefusalReview::class)->approve($admin, $order, $input, $proof)->id);
        $this->assertCount(1, Storage::disk('local')->files('shipment-finance'));
    }

    public function test_reviewed_reverse_fee_rejects_ambiguous_amounts_and_forged_entitlements_without_creating_financial_evidence(): void
    {
        [$order, $admin] = $this->refused();
        foreach (['-1.00', '01.00', '1e2', '40.001', '1,000.00', ' 40.00 ', '1000000000'] as $amount) {
            try {
                app(ShipmentRefusalReview::class)->preview($admin, $order, ['reverse_fee' => $amount, 'cost_reference' => 'ORIGINAL-BOUNDED-COST']);
                $this->fail('Ambiguous or unbounded reverse amounts require correction.');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('reverse_fee', $error->errors());
            }
        }
        try {
            app(ShipmentRefusalReview::class)->preview($admin, $order, ['reverse_fee' => '40.00', 'cost_reference' => 'ORIGINAL-COST', 'buyer_refund_cents' => 999999]);
            $this->fail('The client cannot supply its own buyer entitlement.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('buyer_refund_cents', $error->errors());
        }
        $this->assertDatabaseCount('shipment_financial_plans', 0);
        $this->assertDatabaseCount('shipment_financial_events', 0);
        $this->assertDatabaseCount('wallet_refund_reserve_entries', 0);
    }

    public function test_uncollected_cash_review_rejects_unsupported_payment_labels_without_collection_evidence(): void
    {
        [$order, $admin] = $this->refused('cod');
        foreach (['paid', 'refunded'] as $label) {
            $order->update(['payment_status' => $label]);
            try {
                $this->approval($order, $admin);
                $this->fail('An unsupported payment label cannot establish original uncollected cash.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
        $order->update(['payment_status' => 'pending']);
        $review = app(ShipmentRefusalReview::class)->preview($admin, $order, ['reverse_fee' => '40.00', 'cost_reference' => 'ACTUAL-COD-REVERSE']);
        $this->assertSame(0, $review['snapshot']['actual_buyer_paid_cents']);
        $this->assertSame(0, $review['snapshot']['buyer_refund_cents']);
        $this->assertDatabaseCount('shipment_financial_plans', 0);
    }

    private function financialHeaders(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()) ?? ''];
    }

    public function test_public_refusal_review_previews_actual_amounts_then_scopes_zero_refund_and_wallet_debt_to_the_original_parties(): void
    {
        [$order, $admin] = $this->refused();
        $root = '/shipment-finances/orders/'.$order->id;
        $this->actingAs($admin)->getJson('/return-liabilities?view=shipments', $this->financialHeaders())->assertOk()
            ->assertJsonPath('props.reviewCandidates.data.0.id', $order->id)->assertJsonPath('props.cases.total', 0);
        $this->getJson($root.'/review', $this->financialHeaders())->assertOk()->assertJsonPath('component', 'Finance/ShipmentReview')
            ->assertJsonPath('props.preview', null)->assertJsonPath('props.blocker', null);
        $attempt = DeliveryAttempt::where('delivery_id', $order->delivery->id)->sole();
        $this->getJson('/exceptions/attempt/'.$attempt->id)->assertOk()->assertJsonPath('exception.financialReviewUrl', $root.'/review');
        $details = ['reverse_fee' => '600.00', 'cost_reference' => 'HTTP-REVERSE-COST'];
        $this->post($root.'/preview', $details)->assertRedirect($root.'/review');
        $this->assertSame(['review_token', 'input', 'amounts'], array_keys(session()->get('shipment_refusal_preview.value')));
        $this->getJson($root.'/review', $this->financialHeaders())->assertOk()->assertJsonPath('props.preview.amounts.buyer_refund_cents', 0);
        $this->actingAs($order->buyer)->getJson($root.'/review')->assertForbidden();
        $preview = $this->actingAs($admin)->postJson($root.'/preview', $details)->assertOk()->assertJsonPath('preview.amounts.buyer_refund_cents', 0)
            ->assertJsonPath('preview.amounts.buyer_liability_cents', 10000)->json('preview');
        $input = [...$details, 'review_token' => $preview['review_token'], 'request_token' => (string) Str::uuid(),
            'reason' => 'The current independent admin reviewed the actual original conformity and complete reverse cost evidence.',
            'conforming_shipment_confirmed' => true, 'proof' => UploadedFile::fake()->image('reviewed-http-cost.png')];
        $saved = $this->postJson($root.'/approve', $input)->assertCreated()->json('plan');
        $plan = ShipmentFinancialPlan::findOrFail($saved['id']);
        $url = '/shipment-finances/'.$plan->id;
        $this->get($root.'/review')->assertRedirect($url);
        $this->get($url.'/proof')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($url, $this->financialHeaders())->assertOk()->assertJsonPath('props.financialPlan.actions.0', 'execute-wallet-refusal');
        $this->postJson($url.'/actions', ['action' => 'execute-wallet-refusal', ...$this->execution($plan), 'proof' => 'guessed-existing-receipt.png'])
            ->assertUnprocessable()->assertJsonValidationErrors('proof');
        $this->postJson($url.'/actions', ['action' => 'execute-wallet-refusal', ...$this->execution($plan)])->assertOk()->assertJsonPath('event.amount_cents', 0);
        $buyer = $this->actingAs($order->buyer)->getJson($url, $this->financialHeaders())->assertOk()
            ->assertJsonPath('props.financialPlan.status', 'shipping_due')->assertJsonPath('props.financialPlan.actions.0', 'pay-wallet-shipping')
            ->assertJsonPath('props.financialPlan.review_proof_url', null)->json('props.financialPlan');
        $this->assertSame(['id', 'order_number'], array_keys($buyer['order']));
        $this->assertArrayNotHasKey('snapshot', $buyer);
        $this->assertArrayNotHasKey('source_actor_ids', $buyer);
        $this->get($url.'/proof')->assertNotFound();
        $this->getJson('/buyer/orders/'.$order->id, $this->financialHeaders())->assertOk()->assertJsonPath('props.shipmentFinance.id', $plan->id);
        $payment = ['action' => 'pay-wallet-shipping', 'expected_version' => 2, 'request_token' => (string) Str::uuid(), 'amount' => '100.00',
            'payment_confirmed' => true, 'reason' => 'The original buyer authorizes this separate actual virtual shipping payment.'];
        $this->postJson($url.'/actions', [...$payment, 'proof' => 'guessed-wallet-receipt.png'])->assertUnprocessable()->assertJsonValidationErrors('proof');
        $paid = $this->postJson($url.'/actions', $payment)->assertOk()->json('event.id');
        $this->postJson($url.'/actions', $payment)->assertOk()->assertJsonPath('event.id', $paid);
        $this->getJson('/return-liabilities?view=shipments&status=resolved', $this->financialHeaders())->assertOk()->assertJsonPath('props.cases.total', 1);
        $this->getJson('/return-liabilities?view=shipments&status=shipping_due', $this->financialHeaders())->assertOk()->assertJsonPath('props.cases.total', 0);
        $company = LogisticsCompany::findOrFail($plan->logistics_company_id)->user;
        $company->update(['status' => 'suspended']);
        $companyPage = $this->actingAs($company)->getJson($url, $this->financialHeaders())->assertOk()->json('props.financialPlan');
        $this->assertSame([], $companyPage['actions']);
        $this->assertNull($companyPage['wallet_balance_cents']);
        $this->assertNull($companyPage['review_reason']);
        $this->assertSame(['approved', 'zero-refund-assessed'], array_column($companyPage['history'], 'event_type'));
        $this->assertSame([null, null], array_column($companyPage['history'], 'reason'));
        $this->postJson($url.'/actions', ['action' => 'pay-wallet-shipping', 'expected_version' => 3, 'request_token' => (string) Str::uuid(),
            'amount' => '1.00', 'payment_confirmed' => true, 'reason' => 'A company owner must never claim to make the original buyer virtual payment.'])->assertForbidden();
        $this->get($url.'/proof')->assertNotFound();
        $this->actingAs($this->createApprovedUser('buyer'))->getJson($url)->assertNotFound();
        $this->actingAs($this->createApprovedUser('seller'))->getJson($url)->assertNotFound();
        $this->actingAs($this->createApprovedUser('logistics'))->getJson($url)->assertNotFound();
        $this->actingAs($this->createApprovedUser('courier'))->getJson($url)->assertForbidden();
    }

    public function test_public_cod_refusal_requires_named_cash_receipt_and_keeps_private_transfers_from_sellers_and_company_owners(): void
    {
        [$order, $admin] = $this->refused('cod');
        $root = '/shipment-finances/orders/'.$order->id;
        $details = ['reverse_fee' => '40.00', 'cost_reference' => 'ACTUAL-HTTP-COD-REVERSE'];
        $preview = $this->actingAs($admin)->postJson($root.'/preview', $details)->assertOk()->json('preview');
        $saved = $this->postJson($root.'/approve', [...$details, 'review_token' => $preview['review_token'], 'request_token' => (string) Str::uuid(),
            'reason' => 'Independently reviewed this original uncollected COD refusal and its actual complete reverse transport.',
            'conforming_shipment_confirmed' => true, 'proof' => UploadedFile::fake()->image('cod-cost.png')])->assertCreated()->json('plan');
        $plan = ShipmentFinancialPlan::findOrFail($saved['id']);
        $url = '/shipment-finances/'.$plan->id;
        $this->postJson($url.'/actions', ['action' => 'assess-cod-refusal', ...$this->execution($plan)])->assertOk()->assertJsonPath('event.amount_cents', 0);
        $offer = $this->actingAs($order->buyer)->postJson($url.'/actions', ['action' => 'offer-shipping-payment', 'expected_version' => 2,
            'request_token' => (string) Str::uuid(), 'amount' => '90.00', 'recipient_id' => $admin->id, 'cash_reference' => 'ACTUAL-HTTP-BUYER-SHIPPING',
            'payment_confirmed' => true, 'reason' => 'I made this actual shipping transfer to the named independent receiving admin.',
            'proof' => UploadedFile::fake()->image('actual-offer.png')])->assertOk()->json('event');
        $proof = $url.'/events/'.$offer['id'].'/proof';
        $this->get($proof)->assertOk();
        $this->getJson($url, $this->financialHeaders())->assertOk()->assertJsonPath('props.financialPlan.state.remaining_cents', 9000);
        foreach ([$order->shop->user, LogisticsCompany::findOrFail($plan->logistics_company_id)->user] as $observer) {
            $data = $this->actingAs($observer)->getJson($url, $this->financialHeaders())->assertOk()->json('props.financialPlan');
            $this->assertNotContains('offer-shipping-payment', array_column($data['history'], 'event_type'));
            $this->get($proof)->assertNotFound();
        }
        $this->actingAs($this->createApprovedUser('admin'))->getJson($url, $this->financialHeaders())->assertOk()->assertJsonPath('props.financialPlan.pending_offers', []);
        $receipt = ['action' => 'receive-shipping-payment', 'expected_version' => 3, 'request_token' => (string) Str::uuid(), 'source_event_id' => $offer['id'],
            'payment_confirmed' => true, 'reason' => 'The named admin independently confirms receiving the original actual shipping transfer.',
            'proof' => UploadedFile::fake()->image('actual-receipt.png')];
        $this->postJson($url.'/actions', $receipt)->assertConflict();
        $received = $this->actingAs($admin)->postJson($url.'/actions', $receipt)->assertOk()->json('event');
        $this->postJson($url.'/actions', $receipt)->assertOk()->assertJsonPath('event.id', $received['id']);
        $this->actingAs($order->buyer)->get($url.'/events/'.$received['id'].'/proof')->assertOk();
        $this->getJson($url, $this->financialHeaders())->assertOk()->assertJsonPath('props.financialPlan.status', 'resolved');
        $this->from($url)->post($url.'/actions', ['action' => 'offer-shipping-payment', 'expected_version' => 1, 'request_token' => (string) Str::uuid(),
            'amount' => '90.00', 'recipient_id' => $admin->id, 'cash_reference' => 'STALE-TRANSFER', 'payment_confirmed' => true,
            'reason' => 'A stale browser must return a readable error and retain the original financial case.', 'proof' => UploadedFile::fake()->image('stale.png')])
            ->assertRedirect($url)->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_public_refusal_review_keeps_policy_one_on_its_original_writer_and_blocks_unknown_terms(): void
    {
        [$order, $admin] = $this->refused();
        $root = '/shipment-finances/orders/'.$order->id;
        foreach ([1, 99] as $policy) {
            $order->update(['shipping_quote' => [...$order->shipping_quote, 'refund_policy_version' => $policy]]);
            $this->actingAs($admin)->getJson($root.'/review', $this->financialHeaders())->assertOk()
                ->assertJsonPath('props.preview', null)->assertJsonPath('props.source', null);
            $this->assertNotEmpty($this->getJson($root.'/review', $this->financialHeaders())->json('props.blocker'));
            $this->postJson($root.'/preview', ['reverse_fee' => '40.00', 'cost_reference' => 'ORIGINAL-REVIEW'])->assertConflict();
        }
        $this->assertDatabaseCount('shipment_financial_plans', 0);
        $this->assertSame(0, WalletTransaction::where('kind', 'wallet_refusal_refund')->count());
    }

    public function test_public_execution_failure_returns_a_safe_retry_without_partial_credits_or_a_second_refund(): void
    {
        [$order, $admin] = $this->refused();
        $plan = app(ShipmentRefusalReview::class)->approve($admin, $order, $this->approval($order, $admin), UploadedFile::fake()->image('reviewed-cost.png'));
        $before = [WalletTransaction::count(), DB::table('wallet_entries')->count(), $plan->events()->count()];
        $reject = true;
        ShipmentFinancialEvent::creating(function () use (&$reject) {
            if ($reject) {
                throw new \RuntimeException('Simulated private financial persistence detail.');
            }
        });
        $input = ['action' => 'execute-wallet-refusal', ...$this->execution($plan)];
        $url = '/shipment-finances/'.$plan->id.'/actions';
        $failure = $this->actingAs($admin)->postJson($url, $input)->assertStatus(503);
        $this->assertStringNotContainsString('private financial persistence detail', $failure->getContent());
        $this->assertSame($before, [WalletTransaction::count(), DB::table('wallet_entries')->count(), $plan->events()->count()]);
        $reject = false;
        $event = $this->postJson($url, $input)->assertOk()->json('event.id');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('event.id', $event);
        $this->assertSame(1, WalletTransaction::where('kind', 'wallet_refusal_refund')->count());
    }
}
