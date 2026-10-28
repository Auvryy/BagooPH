<?php

namespace Tests\Feature\Logistics;

use App\Models\CodAccount;
use App\Models\LogisticsCompany;
use App\Models\LogisticsEarning;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use App\Models\WalletTransaction;
use App\Services\Courier\RiderShiftService;
use App\Services\Finance\CodCashService;
use App\Services\Finance\LogisticsEarningsService;
use App\Services\Logistics\DeliveryReturnService;
use App\Services\Returns\BuyerReturnService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletPaymentService;
use App\Services\Wallet\WalletReturnMoneyService;
use App\Services\Wallet\WalletSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class NewEraLocalFlowTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function localOrder(string $payment = 'cod')
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $seller->shop;
        $this->flowNetwork($shop, $shop->city);
        LogisticsCompany::where('name', 'Bagoo Acceptance Network')->update(['shipping_earnings_model' => 'standard']);
        $product = $this->createE2EProduct($shop, ['price' => '1000.00']);

        return $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $product, 'quantity' => 1]], shipping: [
            'shipping_city' => $shop->city, 'shipping_postal_code' => '4030', 'payment_method' => $payment,
        ]);
    }

    private function pay($order): void
    {
        $credits = app(WalletCreditService::class);
        $request = $credits->request($order->buyer, ['amount' => '2000.00', 'reason' => 'Isolated test virtual credits.', 'request_token' => (string) Str::uuid()]);
        $credits->review($this->createApprovedUser('admin'), $request, ['decision' => 'approved', 'reason' => 'Isolated synthetic credit approval.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
        app(WalletPaymentService::class)->command($order->buyer, $order->walletPayment, 'pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()]);
    }

    public function test_local_cod_intake_bypasses_mother_and_standard_earnings_keep_the_product_and_shipping_pools_separate(): void
    {
        $order = $this->localOrder();
        $parcel = $this->flowDelivery($order, 'delivered');
        $this->assertTrue($parcel->same_hub_bypass);
        $this->assertSame([$parcel->origin_bayan_hub_id], $parcel->route_hub_ids);
        $this->assertNull($parcel->origin_mother_hub_id);
        $this->assertSame(0, LogisticsManifest::count());
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertDatabaseHas('logistics_earnings', ['delivery_id' => $parcel->id, 'role' => 'seller', 'amount_cents' => 90000]);
        $this->assertDatabaseHas('logistics_earnings', ['delivery_id' => $parcel->id, 'role' => 'platform', 'amount_cents' => 10000]);
        $this->assertDatabaseHas('logistics_earnings', ['delivery_id' => $parcel->id, 'role' => 'company', 'amount_cents' => 3000]);
        $this->assertDatabaseHas('logistics_earnings', ['delivery_id' => $parcel->id, 'role' => 'delivery', 'amount_cents' => 3000, 'released_at' => null]);
        $this->assertDatabaseMissing('logistics_earnings', ['delivery_id' => $parcel->id, 'role' => 'pickup']);
    }

    public function test_local_refusal_returns_on_the_one_hub_route_and_requires_actual_seller_receipt(): void
    {
        $order = $this->localOrder();
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $this->assertSame([$parcel->origin_bayan_hub_id], app(DeliveryReturnService::class)->route($parcel->fresh())->hub_ids);
        $this->returnToSellerFlow($parcel);
        $this->assertSame('returned', $order->fresh()->status);
        $this->assertSame(0, LogisticsManifest::count());
        $this->assertSame(0, CodAccount::count());
        $this->assertSame(0, LogisticsEarning::whereIn('role', ['seller', 'platform', 'delivery'])->count());
    }

    public function test_prepaid_local_delivery_automatically_credits_balanced_virtual_proceeds_and_collects_no_cash(): void
    {
        $order = $this->localOrder('wallet');
        $this->pay($order);
        $parcel = $this->flowDelivery($order, 'delivered');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(0, CodAccount::count());
        $this->assertNotNull($order->walletPayment->fresh()->settlement_transaction_id);
        $this->assertSame(90000, WalletAccount::where('user_id', $order->shop->user_id)->sole()->balance_cents);
        $this->assertSame(10000, WalletAccount::where('kind', 'commission')->sole()->balance_cents);
        $this->assertSame(0, (int) WalletEntry::sum('delta_cents'));
        $count = WalletTransaction::count();
        app(WalletSettlementService::class)->settleOnDelivery($order->fresh());
        $this->assertSame($count, WalletTransaction::count());
        $this->assertNotNull(LogisticsEarning::where('delivery_id', $parcel->id)->where('role', 'delivery')->sole()->released_at);
    }

    public function test_regional_refusal_reverses_every_frozen_hop_using_sealed_bags_before_seller_receipt(): void
    {
        $order = $this->newFlowOrder();
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $forward = $parcel->route_hub_ids;
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $this->assertSame(array_reverse($forward), app(DeliveryReturnService::class)->route($parcel->fresh())->hub_ids);
        $this->returnToSellerFlow($parcel);
        $this->assertSame('returned', $order->fresh()->status);
        $this->assertSame(2, LogisticsManifest::where('direction', 'return')->where('status', 'closed')->count());
        $this->assertSame(0, CodAccount::count());
    }

    public function test_a_local_third_unreachable_attempt_uses_two_recorded_retries_then_returns_to_the_seller(): void
    {
        $order = $this->localOrder();
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $parcel->refresh();
            $this->actingAs(User::findOrFail($parcel->assigned_rider_id))->patch(route('courier.updateStatus', $parcel), $this->flowFailurePayload($parcel))
                ->assertSessionHasNoErrors()->assertSessionHas('success');
            $this->assertSame($attempt, $parcel->fresh()->failure_attempts);
            $this->receiveFailureFlow($parcel);
            if ($attempt < 3) {
                $this->retryFailureFlow($parcel);
            }
        }
        $this->returnToSellerFlow($parcel);
        $this->assertSame('returned', $order->fresh()->status);
        $this->assertSame(0, LogisticsManifest::count());
    }

    public function test_prepaid_completion_defers_an_ineligible_seller_then_the_scheduled_release_credits_once(): void
    {
        $order = $this->localOrder('wallet');
        $this->pay($order);
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $seller = $order->shop->user;
        $seller->update(['status' => 'suspended']);
        Storage::fake('public');
        $this->actingAs(User::findOrFail($parcel->assigned_rider_id))->patch(route('courier.updateStatus', $parcel), [
            'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('synthetic-pod.png'), ...$this->codCollectionInput($parcel),
        ])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNull($order->walletPayment->fresh()->settlement_transaction_id);
        $settlement = app(WalletSettlementService::class);
        $this->assertSame(['released' => 0, 'deferred' => 1, 'failed' => 0], $settlement->releaseDelivered());
        $seller->update(['status' => 'active']);
        $this->artisan('wallet:release-delivered')->assertSuccessful();
        $this->assertNotNull($order->walletPayment->fresh()->settlement_transaction_id);
        $this->assertSame(90000, WalletAccount::where('user_id', $seller->id)->sole()->balance_cents);
        $this->assertSame(['released' => 0, 'deferred' => 0, 'failed' => 0], $settlement->releaseDelivered());
        $this->assertSame(1, WalletTransaction::where('order_id', $order->id)->where('kind', 'wallet_settlement')->count());
        $this->assertSame(0, (int) WalletEntry::sum('delta_cents'));
    }

    public function test_shift_shortfall_requires_counted_recovery_and_review_and_a_same_second_new_shift_starts_at_zero(): void
    {
        $this->freezeTime();
        $order = $this->localOrder();
        $parcel = $this->flowDelivery($order, 'delivered');
        $cash = CodAccount::where('delivery_id', $parcel->id)->sole();
        $rider = User::findOrFail($cash->collector_id);
        $handler = $this->flowHandler(LogisticsHub::findOrFail($cash->hub_id));
        $service = app(CodCashService::class);
        $shift = app(RiderShiftService::class);
        $command = fn ($actor, $action, $data) => $service->command($actor, $cash, $action, $data + [
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(), 'receipt_confirmed' => true,
            'evidence_reference' => 'SYNTHETIC-COUNT-001', 'reason' => 'Isolated test: count and review the original physical cash.',
        ]);
        $offer = $command($rider, 'offer', ['holder_id' => $rider->id, 'recipient_id' => $handler->id, 'amount' => '1060.00']);
        $receipt = $command($handler, 'receive', ['offer_reference' => $offer->reference, 'received_amount' => '1059.00']);
        $earning = LogisticsEarning::where('delivery_id', $parcel->id)->where('role', 'delivery')->sole();
        $this->assertNull($earning->released_at);
        $this->assertSame(106000, $shift->ledger($rider)['collected_cents']);
        $this->assertSame(105900, $shift->ledger($rider)['remitted_cents']);
        $this->assertFalse($shift->ledger($rider)['can_close']);
        $offer = $command($rider, 'offer', ['holder_id' => $rider->id, 'recipient_id' => $handler->id, 'amount' => '1.00']);
        $recovery = $command($handler, 'receive', ['offer_reference' => $offer->reference, 'received_amount' => '1.00']);
        $this->assertNull($earning->fresh()->released_at);
        $this->assertFalse($shift->ledger($rider)['can_close']);
        $command($this->createApprovedUser('admin'), 'adjust', ['source_reference' => $receipt->reference,
            'adjustment_type' => 'shortage_recovered', 'amount' => '1.00', 'evidence_reference' => $recovery->reference]);
        $this->assertNotNull($earning->fresh()->released_at);
        $this->assertSame(106000, $shift->ledger($rider)['remitted_cents']);
        $closed = $shift->close($rider);
        $this->assertSame(106000, $closed->closing_snapshot['collected_cents']);
        $this->assertNull($shift->close($rider));
        $this->assertNotSame($closed->id, $shift->open($rider)->id);
        $this->assertSame(0, $shift->ledger($rider)['collected_cents']);
        $this->assertSame(0, $shift->ledger($rider)['remitted_cents']);
        $this->assertSame(0, $shift->ledger($rider)['held_cents']);
        $this->assertSame(1, DB::table('logistics_earning_events')->where('logistics_earning_id', $earning->id)->where('event_type', 'released')->count());
    }

    public function test_a_post_completion_wallet_return_uses_actual_local_intake_and_seller_receipt_and_adjusts_original_earnings(): void
    {
        $order = $this->localOrder('wallet');
        $this->pay($order);
        $this->flowDelivery($order, 'delivered');
        $service = app(BuyerReturnService::class);
        $case = $service->request($order->buyer, $order->fresh(), ['request_token' => $service->form($order->buyer, $order->fresh())['requestToken'],
            'reason' => 'damaged', 'description' => 'The delivered item has a broken fastening.',
            'items' => [['order_item_id' => $order->items->first()->id, 'quantity' => 1]]], [UploadedFile::fake()->image('synthetic-damage.png')]);
        $seller = $order->shop->user;
        $service->command($seller, $case, 'approve', ['expected_version' => 1, 'request_token' => (string) Str::uuid(), 'reason' => 'Isolated test: original damage evidence supports a physical return.']);
        $case = $case->fresh(['parcel']);
        $hub = LogisticsHub::findOrFail($case->parcel->hub_ids[0]);
        $payload = fn ($action, $extra = []) => ['action' => $action, 'expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(),
            'barcode' => $case->parcel->barcode, 'reason' => 'Isolated test: the actual named holder checked this original return.', ...$extra];
        $handler = $this->flowHandler($hub);
        $this->actingAs($handler)->post('/buyer-returns/'.$case->id.'/custody', $payload('intake', ['hub_id' => $hub->id, 'weight_kg' => '1.000', 'condition' => 'intact', 'photo' => UploadedFile::fake()->image('synthetic-intake.png')]))->assertSessionHasNoErrors();
        $this->post('/buyer-returns/'.$case->id.'/custody', $payload('stage', ['hub_id' => $hub->id]))->assertSessionHasNoErrors();
        $this->actingAs($seller)->post('/buyer-returns/'.$case->id.'/custody', $payload('seller-receive', ['condition' => 'accepted', 'photo' => UploadedFile::fake()->image('synthetic-return-receipt.png')]))->assertSessionHasNoErrors();
        $this->assertSame('seller_received', $case->fresh()->status);
        $admin = $this->createApprovedUser('admin');
        $money = app(WalletReturnMoneyService::class);
        $money->command($admin, $case, 'approve-repayment', ['expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(), 'reason' => 'Isolated test: approve original purchased units after their actual receipt.']);
        $refund = ['expected_version' => $case->fresh()->version, 'request_token' => (string) Str::uuid(), 'wallet_refund_confirmed' => true, 'reason' => 'Isolated test: credit the original prepaid buyer once.'];
        $money->command($admin, $case, 'refund-wallet', $refund);
        $money->command($admin, $case, 'refund-wallet', $refund);
        $this->assertSame(0, WalletAccount::where('user_id', $seller->id)->sole()->balance_cents);
        $this->assertSame(194000, WalletAccount::where('user_id', $order->buyer_id)->sole()->balance_cents);
        $this->assertSame(0, (int) WalletEntry::sum('delta_cents'));
        $earnings = app(LogisticsEarningsService::class);
        $this->assertSame('0', $earnings->summary($seller)['confirmed_cents']);
        $this->assertSame(90000, LogisticsEarning::where('order_id', $order->id)->where('role', 'seller')->sole()->net_amount_cents);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(0, CodAccount::count());
    }
}
