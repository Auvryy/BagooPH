<?php

namespace Tests\Feature\Finance;

use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\ShipmentFinancialEvent;
use App\Models\WalletRefundCreditRequest;
use App\Models\WalletTransaction;
use App\Services\Finance\ShipmentLossReview;
use App\Services\Finance\ShipmentLossSource;
use App\Services\Returns\BuyerReturnPlanApproval;
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
use Tests\Feature\Finance\Support\CreatesMissingShipments;
use Tests\Feature\Returns\Support\CreatesBuyerReturns;
use Tests\TestCase;

class ShipmentLossReviewTest extends TestCase
{
    use CreatesBuyerReturns, CreatesE2EOrders, CreatesMissingShipments, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    public static function sourceOutcomes(): array
    {
        return [['wallet', false, false, 25000, 18833], ['cod', false, false, 0, 18833], ['wallet', false, true, 23000, 18833],
            ['wallet', true, false, 12500, 12500], ['cod', true, false, 12500, 12500], ['wallet', true, true, 11500, 11500]];
    }

    #[DataProvider('sourceOutcomes')]
    public function test_original_loss_review_separates_paid_buyer_refund_seller_protection_and_only_actual_earned_shipping(string $mode, bool $return, bool $voucher, int $refund, int $funding): void
    {
        [$source, $admin, $order, $case] = $this->missing($mode, $return, $voucher);
        $before = [$order->getAttributes(), $order->delivery->getAttributes(), $order->delivery->checkpoints()->get()->toArray(), WalletTransaction::count(), $case?->getAttributes(), $case?->parcel->getAttributes()];
        $input = $this->approval($source, $admin, $return ? '0.00' : '180.00');
        $proof = UploadedFile::fake()->image('actual-independent-loss-investigation.png');
        $plan = app(ShipmentLossReview::class)->approve($admin, $source, $input, $proof);
        $this->assertSame($plan->id, app(ShipmentLossReview::class)->approve($admin, $source, $input, $proof)->id);
        $this->assertSame($refund, $plan->snapshot['buyer_refund_cents']);
        $this->assertSame($funding, $plan->snapshot['company_funding_due_cents']);
        $this->assertSame($return ? 0 : 18000, $plan->snapshot['seller_protection_cents']);
        $this->assertSame($return ? 0 : 833, $plan->snapshot['protected_earned_shipping_cents']);
        $this->assertSame($mode === 'wallet' && ! $return ? $refund : 0, $plan->snapshot['original_escrow_required_cents']);
        $this->assertSame($funding + $plan->snapshot['original_escrow_required_cents'], $plan->snapshot['reserve_goal_cents']);
        $this->assertSame($return ? null : $order->id, $plan->active_order_id);
        $this->assertSame($case?->id, $plan->active_return_case_id);
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes(), $order->delivery->checkpoints()->get()->toArray(),
            WalletTransaction::count(), $case?->fresh()->getAttributes(), $case?->parcel->fresh()->getAttributes()]);
        $this->assertDatabaseCount('shipment_financial_events', 1);
        $this->assertSame(0, ShipmentFinancialEvent::sole()->amount_cents);
        $this->assertFalse($source->manifest->events()->whereIn('event_type', ['parcel_received', 'buyer-return-received'])->exists());
        if ($case) {
            $this->assertFalse($case->events()->where('event_type', 'return-seller-receive')->exists());
            try {
                app(BuyerReturnPlanApproval::class)->preview($admin, $case);
                $this->fail('The original claimed units cannot enter a second independent repayment authority.');
            } catch (HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
        }
    }

    public function test_original_loss_review_requires_independent_current_admin_bounded_protection_and_actual_investigation_evidence(): void
    {
        [$source, $admin, $order] = $this->missing();
        $service = app(ShipmentLossReview::class);
        foreach ([$order->buyer, $order->shop->user, LogisticsCompany::findOrFail($source->manifest->logistics_company_id)->user] as $other) {
            try {
                $service->preview($other, $source, ['seller_protection' => '180.00', 'investigation_reference' => 'REVIEW-ORIGINAL']);
                $this->fail('A party cannot independently approve its own original company liability.');
            } catch (HttpException $error) {
                $this->assertSame(403, $error->getStatusCode());
            }
        }
        try {
            $this->approval($source, $admin, '180.01');
            $this->fail('Protection cannot exceed the original eligible net seller proceeds.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $input = $this->approval($source, $admin);
        try {
            $service->approve($admin, $source, $input, UploadedFile::fake()->createWithContent('investigation.pdf', 'A filename is not actual PDF evidence.'));
            $this->fail('Original private investigation evidence is required.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('proof', $error->errors());
        }
        $this->assertDatabaseCount('shipment_financial_plans', 0);
    }

    public function test_unfunded_loss_void_retains_the_original_review_and_allows_a_new_independent_review_without_changing_custody(): void
    {
        [$source, $admin, $order] = $this->missing('cod');
        $review = app(ShipmentLossReview::class);
        $original = $review->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('original-investigation.png'));
        try {
            DB::table('shipment_financial_plans')->where('id', $original->id)->update(['active_order_id' => null]);
            $this->fail('Raw SQL cannot release an active claim without its original unfunded void.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retain', $error->getMessage());
        }
        $before = [$order->getAttributes(), $order->delivery->getAttributes()];
        $input = ['expected_version' => 1, 'request_token' => (string) Str::uuid(), 'unfunded_void_confirmed' => true,
            'reason' => 'The original unfunded investigation needs a fresh independently evidenced decision; no actual funding or offered transfer exists.'];
        $event = $review->voidUnfunded($admin, $original, $input);
        $this->assertSame($event->id, $review->voidUnfunded($admin, $original, $input)->id);
        $this->assertNull($original->fresh()->active_order_id);
        $this->assertSame($order->id, $original->fresh()->undelivered_order_id);
        $new = $review->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('fresh-investigation.png'));
        $this->assertNotSame($original->id, $new->id);
        $this->assertSame($order->id, $new->active_order_id);
        try {
            DB::table('shipment_financial_plans')->where('id', $original->id)->delete();
            $this->fail('Schema extension must retain the original raw SQL deletion guard too.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retain the original financial authority', $error->getMessage());
        }
        $this->assertSame($before, [$order->fresh()->getAttributes(), $order->delivery->fresh()->getAttributes()]);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('cod_accounts', 0);
    }

    public function test_pending_loss_funding_request_retains_its_original_authority_and_prevents_an_unfunded_void(): void
    {
        [$source, $admin] = $this->missing();
        $review = app(ShipmentLossReview::class);
        $plan = $review->approve($admin, $source, $this->approval($source, $admin), UploadedFile::fake()->image('original-investigation.png'));
        $request = WalletRefundCreditRequest::create(['authority_reference' => $plan->reference, 'shipment_financial_plan_id' => $plan->id,
            'actor_id' => LogisticsCompany::findOrFail($plan->logistics_company_id)->user_id, 'amount_cents' => 100, 'repayment_plan_id' => null,
            'reason' => 'Isolated pending request for the named original company’s additional demonstration funding.', 'status' => 'pending', 'version' => 1,
            'active_key' => hash('sha256', $plan->reference.':'.LogisticsCompany::findOrFail($plan->logistics_company_id)->user_id), 'request_token_hash' => hash('sha256', (string) Str::uuid()),
            'request_fingerprint' => hash('sha256', 'isolated-original-loss-request')]);
        try {
            DB::table('wallet_refund_credit_requests')->where('id', $request->id)->update(['shipment_financial_plan_id' => null]);
            $this->fail('A pending request must retain its original shipment authority through raw SQL too.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Retain the original shipment funding-credit authority', $error->getMessage());
        }
        try {
            $review->voidUnfunded($admin, $plan, ['expected_version' => 1, 'request_token' => (string) Str::uuid(), 'unfunded_void_confirmed' => true,
                'reason' => 'The pending original funding request must first receive an independent disposition.']);
            $this->fail('A pending original funding request prevents releasing its active loss authority.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertSame($plan->id, $request->fresh()->shipment_financial_plan_id);
        $this->assertSame($plan->order_id, $plan->fresh()->active_order_id);
    }

    public function test_later_actual_receipt_invalidates_the_missing_source_while_exact_approval_retry_retains_its_original_history(): void
    {
        [$source, $admin, $order] = $this->missing();
        $review = app(ShipmentLossReview::class);
        $input = $this->approval($source, $admin);
        $proof = UploadedFile::fake()->image('original-investigation.png');
        $plan = $review->approve($admin, $source, $input, $proof);
        $manifest = $source->manifest->fresh();
        $receiver = $this->flowHandler(LogisticsHub::findOrFail($manifest->destination_hub_id));
        $this->openFlowMotherBags($manifest, $receiver);
        $this->actingAs($receiver)->postJson('/hub/manifests/'.$manifest->id.'/receive', $this->flowManifestPayload($manifest,
            ['barcode' => $order->delivery->tracking_number, 'notes' => 'The expected original parcel was found and actually received.']))->assertOk();
        $this->assertSame($plan->id, $review->approve($admin, $source, $input, $proof)->id);
        try {
            app(ShipmentLossSource::class)->snapshot($source, 18000);
            $this->fail('A later actual receipt cannot be described as an unresolved original transit loss.');
        } catch (HttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        $this->assertDatabaseCount('shipment_financial_plans', 1);
        $this->assertDatabaseCount('shipment_financial_events', 1);
    }
}
