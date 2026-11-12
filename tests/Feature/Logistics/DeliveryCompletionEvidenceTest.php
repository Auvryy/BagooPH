<?php

namespace Tests\Feature\Logistics;

use App\Models\CodAccount;
use App\Models\CodCashEvent;
use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsEarning;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\Courier\RiderShiftService;
use App\Services\Finance\CodCashService;
use App\Services\Finance\CodMoney;
use App\Services\Finance\LogisticsEarningsService;
use App\Services\Orders\AutomaticDeliveryCompletionService;
use App\Services\Wallet\WalletCreditService;
use App\Services\Wallet\WalletHandoverService;
use App\Services\Wallet\WalletPaymentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class DeliveryCompletionEvidenceTest extends TestCase
{
    use CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function purchase(string $payment = 'cod', bool $counter = false): Order
    {
        $seller = $this->createApprovedUser('seller');
        $shop = $seller->shop;
        $this->flowNetwork($shop, $shop->city);
        $hub = LogisticsHub::where('tier', 'local_bayan_hub')->where('city_municipality', $shop->city)->sole();
        $hub->company->update(['shipping_earnings_model' => 'standard']);
        $hub->update(['allows_self_pickup' => true, 'operating_hours' => 'Monday to Saturday 08:00 to 17:00']);
        $product = $this->createE2EProduct($shop, ['price' => '269.00', 'weight_kg' => 1.9,
            'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 10]);
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop, [['product' => $product, 'quantity' => 1]], shipping: [
            'shipping_city' => $shop->city, 'shipping_postal_code' => '4030', 'payment_method' => $payment,
            'delivery_type' => $counter ? 'hub_self_pickup' : 'doorstep', ...($counter ? ['pickup_hub_id' => $hub->id] : []),
        ]);
        if ($payment === 'wallet') {
            $credit = app(WalletCreditService::class);
            $request = $credit->request($order->buyer, ['amount' => '1000.00', 'reason' => 'Isolated delivery evidence fixture.', 'request_token' => (string) Str::uuid()]);
            $credit->review($this->createApprovedUser('admin'), $request, ['decision' => 'approved', 'reason' => 'Synthetic original payment evidence.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
            app(WalletPaymentService::class)->command($order->buyer, $order->walletPayment, 'pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()]);
        }

        return $order->fresh(['buyer', 'delivery']);
    }

    private function counter(Order $order): Delivery
    {
        $parcel = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = $parcel->destinationBayanHub;
        $handler = $this->flowHandler($hub);
        $this->confirmFlowScan($parcel, $hub, 'STAGE_FOR_PICKUP');
        $code = $this->actingAs($order->buyer)->postJson('/buyer/orders/'.$order->id.'/pickup-code')->assertOk()->json('code');
        $this->actingAs($handler)->postJson('/hub/release', ['hub_id' => $hub->id, 'barcode' => $parcel->tracking_number,
            'claim_code' => $code, 'buyer_id' => $order->buyer_id, 'recipient_name' => $order->buyer->name,
            'identity_confirmed' => true, 'request_token' => (string) Str::uuid(),
            'cash_received' => $order->payment_method === 'cod' ? (string) $order->total_amount : '0.00',
            'change_given' => '0.00', 'cash_confirmed' => $order->payment_method === 'cod'])->assertOk();

        return $parcel->fresh(['order']);
    }

    public static function channels(): array
    {
        return [['cod', false], ['wallet', false], ['cod', true], ['wallet', true]];
    }

    #[DataProvider('channels')]
    public function test_original_payment_and_handover_complete_each_channel_once_with_separate_product_and_shipping_pools(string $payment, bool $counter): void
    {
        $order = $this->purchase($payment, $counter);
        $parcel = $counter ? $this->counter($order) : $this->flowDelivery($order, 'delivered');
        $order->refresh();
        $this->assertSame($counter ? 26900 : 33900, CodMoney::cents($order->total_amount));
        $this->assertSame('completed', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $entries = LogisticsEarning::where('delivery_id', $parcel->id)->get();
        $this->assertSame(26900, $entries->where('pool', 'products')->sum('amount_cents'));
        $this->assertSame($counter ? 0 : 7000, $entries->where('pool', 'shipping')->sum('amount_cents'));
        $proof = $parcel->checkpoints()->whereIn('checkpoint_type', ['delivered', 'customer_collected'])->sole();
        $counts = [$parcel->checkpoints()->count(), LogisticsEarning::count(), DB::table('logistics_earning_events')->count(), CodAccount::count(), WalletEntry::count()];
        app(AutomaticDeliveryCompletionService::class)->complete($parcel, $proof, User::findOrFail($proof->scanned_by_id));
        $this->assertSame($counts, [$parcel->checkpoints()->count(), LogisticsEarning::count(), DB::table('logistics_earning_events')->count(), CodAccount::count(), WalletEntry::count()]);
        $this->assertNotNull(app(AutomaticDeliveryCompletionService::class)->evidence($order));
        if ($payment === 'wallet') {
            $this->assertDatabaseCount('cod_accounts', 0);
            $this->assertSame(0, (int) WalletEntry::sum('delta_cents'));
            $this->assertNotNull($order->walletPayment->settlement_transaction_id);
        } else {
            $this->assertSame(CodMoney::cents($order->total_amount), CodAccount::sole()->state['collected_cents']);
            $this->assertDatabaseCount('seller_settlements', 0); // Ledger release is not a cash/bank payout.
            if (! $counter) {
                $this->assertNull($entries->where('role', 'delivery')->sole()->released_at);
            }
        }
    }

    public function test_unsaved_caller_proof_and_changed_model_attributes_cannot_complete_an_undelivered_order(): void
    {
        $order = $this->purchase();
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $parcel->status = 'delivered';
        $parcel->delivered_at = now();
        $proof = new DeliveryCheckpoint(['delivery_id' => $parcel->id, 'checkpoint_type' => 'delivered',
            'barcode_scanned' => $parcel->tracking_number, 'scanned_by_id' => $parcel->assigned_rider_id,
            'custody_after' => ['kind' => 'buyer', 'user_id' => $order->buyer_id]]);
        try {
            app(AutomaticDeliveryCompletionService::class)->complete($parcel, $proof, User::findOrFail($parcel->assigned_rider_id));
            $this->fail('Unsaved proof completed the order.');
        } catch (DomainException) {
            $this->assertSame('out_for_delivery', $order->fresh()->status);
            $this->assertSame('out_for_delivery', $parcel->fresh()->status);
            $this->assertDatabaseCount('logistics_earnings', 0);
            $this->assertDatabaseCount('cod_accounts', 0);
        }
    }

    public function test_completion_replay_cannot_attribute_an_original_handover_to_another_operator(): void
    {
        $parcel = $this->flowDelivery($this->purchase(), 'delivered');
        $proof = $parcel->checkpoints()->where('checkpoint_type', 'delivered')->sole();
        $count = $parcel->checkpoints()->count();
        try {
            app(AutomaticDeliveryCompletionService::class)->complete($parcel, $proof, $this->createApprovedUser('courier'));
            $this->fail('A foreign actor was accepted as the original operator.');
        } catch (DomainException) {
            $this->assertSame($count, $parcel->checkpoints()->count());
        }
    }

    public static function payments(): array
    {
        return [['cod'], ['wallet']];
    }

    #[DataProvider('payments')]
    public function test_a_paid_flag_without_the_actual_collection_or_prepaid_handover_rolls_back_the_delivery(string $payment): void
    {
        $order = $this->purchase($payment);
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $this->actingAs(User::findOrFail($parcel->assigned_rider_id));
        if ($payment === 'cod') {
            $this->partialMock(CodCashService::class)->shouldReceive('collectRider')->once()->andReturnUsing(function ($parcel) {
                $parcel->order->update(['payment_status' => 'paid']);

                return new CodCashEvent;
            });
        } else {
            $this->mock(WalletHandoverService::class)->shouldReceive('record')->once();
        }
        $payload = [...$this->codCollectionInput($parcel), 'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('original-pod.jpg')];
        $this->patch(route('courier.updateStatus', $parcel), $payload)->assertSessionHas('error');
        $this->assertSame('out_for_delivery', $parcel->fresh()->status);
        $this->assertSame('out_for_delivery', $order->fresh()->status);
        $this->assertDatabaseCount('logistics_earnings', 0);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertSame(0, $parcel->checkpoints()->where('checkpoint_type', 'delivery_completed')->count());
    }

    public function test_earnings_failure_rolls_back_handover_cash_and_completion_then_original_request_can_retry(): void
    {
        $order = $this->purchase();
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $this->partialMock(LogisticsEarningsService::class)->shouldReceive('completed')->once()->andThrow(new DomainException('Original allocation needs review.'));
        $payload = [...$this->codCollectionInput($parcel), 'status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('rollback-pod.jpg')];
        $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel), $payload)->assertSessionHas('error');
        $this->assertSame('out_for_delivery', $parcel->fresh()->status);
        $this->assertSame('pending', $order->fresh()->payment_status);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('logistics_earnings', 0);
        $this->app->forgetInstance(LogisticsEarningsService::class);
        $this->patch(route('courier.updateStatus', $parcel), $payload)->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(1, $parcel->checkpoints()->where('checkpoint_type', 'delivery_completed')->count());
        $this->assertDatabaseCount('cod_accounts', 1);
    }

    public function test_caller_modified_cash_state_cannot_hide_an_unresolved_count_difference_or_release_earnings(): void
    {
        $parcel = $this->flowDelivery($this->purchase(), 'delivered');
        $cash = CodAccount::sole();
        $rider = User::findOrFail($cash->collector_id);
        $handler = $this->flowHandler(LogisticsHub::findOrFail($cash->hub_id));
        $service = app(CodCashService::class);
        $command = fn ($actor, $action, $input) => $service->command($actor, $cash, $action, $input + [
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(),
            'receipt_confirmed' => true, 'evidence_reference' => 'QA-COUNTED-CASH',
            'reason' => 'Actual cash counted for isolated receipt evidence.',
        ]);
        $offer = $command($rider, 'offer', ['holder_id' => $rider->id, 'recipient_id' => $handler->id, 'amount' => '339.00']);
        $short = $command($handler, 'receive', ['offer_reference' => $offer->reference, 'received_amount' => '338.00']);
        $offer = $command($rider, 'offer', ['holder_id' => $rider->id, 'recipient_id' => $handler->id, 'amount' => '1.00']);
        $recovery = $command($handler, 'receive', ['offer_reference' => $offer->reference, 'received_amount' => '1.00']);
        $earning = LogisticsEarning::where('delivery_id', $parcel->id)->where('role', 'delivery')->sole();
        $fake = clone $recovery;
        $fake->target_state = ['balances' => [], 'excess' => [], 'pending' => null, 'discrepancies' => []];
        app(LogisticsEarningsService::class)->cashReceived($fake);
        $this->assertNull($earning->fresh()->released_at);
        $this->assertFalse(app(RiderShiftService::class)->ledger($rider)['can_close']);
        $review = $command($this->createApprovedUser('admin'), 'adjust', ['source_reference' => $short->reference,
            'adjustment_type' => 'shortage_recovered', 'amount' => '1.00', 'evidence_reference' => $recovery->reference]);
        app(LogisticsEarningsService::class)->cashReceived($review);
        app(LogisticsEarningsService::class)->cashReceived($review);
        $this->assertNotNull($earning->fresh()->released_at);
        $this->assertTrue(app(RiderShiftService::class)->ledger($rider)['can_close']);
        $this->assertSame(1, DB::table('logistics_earning_events')->where('logistics_earning_id', $earning->id)->where('event_type', 'released')->count());
    }

    public function test_an_unsaved_origin_receipt_cannot_earn_a_pickup_share_before_actual_intake(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'picked_up');
        $proof = new DeliveryCheckpoint(['delivery_id' => $parcel->id, 'checkpoint_type' => 'arrived_at_origin_hub',
            'hub_id' => $parcel->origin_bayan_hub_id, 'barcode_scanned' => $parcel->tracking_number]);
        try {
            app(LogisticsEarningsService::class)->originReceipt($parcel, $proof);
            $this->fail('An unsaved origin receipt earned a pickup share.');
        } catch (DomainException) {
            $this->assertDatabaseCount('logistics_earnings', 0);
        }
        $this->confirmFlowScan($parcel, $parcel->originBayanHub, 'RECEIVE_FROM_PICKUP_RIDER');
        $receipt = $parcel->checkpoints()->where('checkpoint_type', 'arrived_at_origin_hub')->sole();
        app(LogisticsEarningsService::class)->originReceipt($parcel->fresh(), $receipt);
        $this->assertSame(1, LogisticsEarning::where('delivery_id', $parcel->id)->where('role', 'pickup')->count());
        $this->assertSame(833, LogisticsEarning::where('delivery_id', $parcel->id)->where('role', 'pickup')->sole()->amount_cents);
    }

    public function test_the_earnings_writer_cannot_release_money_from_an_existing_departure_checkpoint(): void
    {
        $parcel = $this->flowDelivery($this->purchase(), 'out_for_delivery');
        $departure = $parcel->checkpoints()->where('checkpoint_type', 'out_for_delivery')->sole();
        try {
            app(LogisticsEarningsService::class)->completed($parcel, $departure);
            $this->fail('Departure evidence was accepted as a completed paid handover.');
        } catch (DomainException) {
            $this->assertDatabaseCount('logistics_earnings', 0);
            $this->assertDatabaseCount('cod_accounts', 0);
            $this->assertSame('out_for_delivery', $parcel->order->fresh()->status);
        }
    }
}
