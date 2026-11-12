<?php

namespace Tests\Feature\Returns;

use App\Models\LogisticsHub;
use App\Models\Voucher;
use App\Services\Returns\BuyerReturnAmounts;
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
}
