<?php

namespace Tests\Feature\Finance;

use App\Models\DeliveryReturnRoute;
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
use App\Services\Finance\CodMoney;
use App\Services\Finance\ShipmentRefusalMoney;
use App\Services\Finance\ShipmentRefusalReview;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletDeliveryRefundService;
use App\Services\Wallet\WalletSettlementService;
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
}
