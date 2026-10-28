<?php

namespace Tests\Feature\Flows;

use App\Models\CodAccount;
use App\Models\CodCashEvent;
use App\Models\CommissionLedger;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\DeliveryRecoveryEvent;
use App\Models\DeliveryReturnRoute;
use App\Models\LogisticsHub;
use App\Models\PickupClaim;
use App\Models\SellerSettlement;
use App\Models\User;
use App\Services\Finance\SellerSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

/** Connected HTTP journeys: fixtures establish eligibility, never transaction evidence. */
class ExceptionJourneyAcceptanceTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    // Historical orders retain buyer receipt and cash reconciliation before seller payment.
    protected function flowContractVersion(): int
    {
        return 1;
    }

    public function test_failed_delivery_returns_to_hub_and_retries_before_buyer_completion_and_seller_payment(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $buyer = $parcel->order->buyer;
        $admin = $this->createApprovedUser('admin');
        $stock = $parcel->order->items->first()->product->stock;
        $outbound = $this->originalCheckpoints($parcel);
        $this->reportFlowFailure($parcel);
        $attempt = DeliveryAttempt::sole();
        $attemptBefore = $attempt->getAttributes();

        $this->assertSame(['kind' => 'courier', 'user_id' => $rider->id], DeliveryCheckpoint::lastCustody($parcel->fresh()));
        $this->assertUncollected($parcel);
        $approval = $this->retryInput($parcel);
        $this->actingAs($hub->company->user)->post(route('hub.recovery.retry', $parcel), $approval)->assertSessionHas('error');
        $this->assertDatabaseCount('delivery_recovery_events', 0);
        $this->receiveFailureFlow($parcel);
        $this->actingAs($handler)->postJson(route('hub.assignRider', $parcel), ['rider_id' => $rider->id])->assertUnprocessable();

        $this->actingAs($hub->company->user)->post(route('hub.recovery.retry', $parcel), $approval)->assertSessionHas('success');
        $approvedBefore = DeliveryRecoveryEvent::where('event_type', 'retry_approved')->sole()->getAttributes();
        $this->post(route('hub.recovery.retry', $parcel), $approval)->assertSessionHas('success');
        $this->assertSame($approvedBefore, DeliveryRecoveryEvent::where('event_type', 'retry_approved')->sole()->getAttributes());
        $this->post(route('hub.recovery.retry', $parcel), array_replace($approval, ['attempt_reference' => 'DATT-ANOTHER-ATTEMPT']))
            ->assertSessionHas('error');
        $this->assertSame($approvedBefore, DeliveryRecoveryEvent::where('event_type', 'retry_approved')->sole()->getAttributes());
        $this->actingAs($handler)->postJson(route('hub.scan'), $this->retryScan($parcel))->assertConflict();
        $this->travel(11)->minutes();
        $this->departRetry($parcel, $rider);
        $this->assertSame(1, $parcel->fresh()->failure_attempts);
        $this->assertSame(2, $parcel->checkpoints()->where('checkpoint_type', 'out_for_delivery')->count());
        $this->assertNotSame($attempt->departure_checkpoint_id, $parcel->checkpoints()->where('checkpoint_type', 'out_for_delivery')->latest('id')->value('id'));
        $this->assertUncollected($parcel);
        $deliveryInput = $this->codCollectionInput($parcel) + ['status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('retry-handover.jpg')];
        $this->patch(route('courier.updateStatus', $parcel), $deliveryInput)->assertSessionHas('success');
        $cash = CodAccount::sole();
        $this->assertSame([90000, 6000, 0, 96000], [$cash->product_subtotal_cents, $cash->shipping_cents, $cash->discount_cents, $cash->expected_cents]);
        $this->assertSame($rider->id, $cash->collector_id);
        $this->assertSame($cash->expected_cents, $cash->state['balances']['rider:'.$rider->id]);
        $this->assertSame('delivered', $parcel->order->fresh()->status);
        $this->assertSettlementBlocked($parcel, $admin);
        $this->actingAs($buyer)->post(route('buyer.orders.confirm', $parcel->order))->assertSessionHas('success');
        $this->assertSame('completed', $parcel->order->fresh()->status);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
        $this->assertSettlementBlocked($parcel, $admin);
        $this->handover($cash, $rider, $handler);
        $this->handover($cash, $handler, $admin);
        $this->reconcile($cash, $admin);
        $this->paySeller($parcel, $admin);

        $this->assertSame($attemptBefore, $attempt->fresh()->getAttributes());
        $this->assertTrue(Storage::disk('local')->exists($attempt->proof_path));
        $this->assertSame(1, DeliveryAttempt::count());
        $this->assertSame(1, CodCashEvent::where('event_type', 'rider_collection')->count());
        $this->assertSame(1, $parcel->checkpoints()->where('checkpoint_type', 'buyer_completed')->count());
        $this->assertSame($outbound, $this->originalCheckpoints($parcel, array_keys($outbound)));
        $this->assertSame($stock, $parcel->order->items->first()->product->fresh()->stock);
        $this->assertDatabaseCount('delivery_return_routes', 0);
    }

    public function test_original_retry_replay_keeps_its_own_attempt_after_a_second_failure(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->reportFlowFailure($parcel);
        $this->receiveFailureFlow($parcel);
        $original = $this->retryInput($parcel);
        $this->actingAs($hub->company->user)->post(route('hub.recovery.retry', $parcel), $original)->assertSessionHas('success');
        $this->travel(11)->minutes();
        $this->departRetry($parcel, $rider);
        $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel), $this->flowFailurePayload($parcel))->assertSessionHas('success');
        $this->receiveFailureFlow($parcel);
        $latest = DeliveryAttempt::where('delivery_id', $parcel->id)->latest('attempt_number')->firstOrFail();
        $this->assertSame(2, $latest->attempt_number);
        $this->assertNotSame($original['attempt_reference'], $latest->reference);
        $before = DeliveryRecoveryEvent::orderBy('id')->get()->map->getAttributes()->all();
        $checkpoints = $this->originalCheckpoints($parcel);
        $this->actingAs($hub->company->user)->post(route('hub.recovery.retry', $parcel), $original)->assertSessionHas('success');
        $this->post(route('hub.recovery.retry', $parcel), array_replace($original, ['attempt_reference' => $latest->reference]))->assertSessionHas('error');
        $this->assertSame($before, DeliveryRecoveryEvent::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($checkpoints, $this->originalCheckpoints($parcel));
        $this->assertSame('delivery_failed', $parcel->fresh()->status);
        $this->assertNull($parcel->fresh()->assigned_rider_id);
        $this->assertSame($hub->id, $parcel->fresh()->current_hub_id);
        $this->assertUncollected($parcel);
    }

    public static function returnReasons(): array
    {
        return ['customer refusal' => ['customer_refused', 1], 'third failed attempt' => ['customer_unreachable', 3]];
    }

    #[DataProvider('returnReasons')]
    public function test_failed_parcel_returns_through_mother_hub_and_only_seller_receipt_finishes_without_payment(string $reason, int $attempts): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $outbound = $this->originalCheckpoints($parcel);
        $tracking = $parcel->tracking_number;
        $stock = $parcel->order->items->first()->product->stock;
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $seller = $parcel->order->shop->user;
        $admin = $this->createApprovedUser('admin');
        for ($n = 1; $n <= $attempts; $n++) {
            $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel), $this->flowFailurePayload($parcel, $reason))->assertSessionHas('success');
            $this->assertSame($n, $parcel->fresh()->failure_attempts);
            $this->assertSame($n, DeliveryAttempt::count());
            $this->assertUncollected($parcel);
            $this->receiveFailureFlow($parcel);
            if ($n < $attempts) {
                $this->retryFailureFlow($parcel);
            }
        }

        $route = DeliveryReturnRoute::sole();
        $routeBefore = $route->getAttributes();
        $this->assertSame([$hub->id, $parcel->origin_mother_hub_id, $parcel->origin_bayan_hub_id], $route->hub_ids);
        $this->assertSame('return_to_sender', $parcel->fresh()->status);
        $this->assertFalse(Delivery::riderHasActiveWork($rider->id));
        $this->assertSettlementBlocked($parcel, $admin);
        $this->actingAs($parcel->order->buyer)->post(route('buyer.orders.confirm', $parcel->order))->assertSessionHas('error');
        $this->actingAs($hub->company->user)->post(route('hub.recovery.retry', $parcel), $this->retryInput($parcel))->assertSessionHas('error');
        $receipt = ['barcode' => $tracking, 'route_reference' => $route->reference,
            'request_token' => (string) Str::uuid(), 'notes' => 'The original parcel was received from its origin hub.'];
        $this->actingAs($seller)->postJson(route('seller.orders.return-receipt', $parcel->order), $receipt)->assertConflict();

        foreach (array_slice($route->hub_ids, 1) as $hubId) {
            $source = LogisticsHub::findOrFail($parcel->fresh()->current_hub_id);
            $this->confirmFlowManifest($parcel, $source, true, direction: 'return');
            $this->assertSame('delivery_failed', $parcel->order->fresh()->status);
            $this->assertUncollected($parcel);
            $this->confirmFlowManifest($parcel, LogisticsHub::findOrFail($hubId), false, direction: 'return');
            $this->assertSame($hubId, $parcel->fresh()->current_hub_id);
            $this->assertSame('delivery_failed', $parcel->order->fresh()->status);
        }
        $origin = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
        $this->actingAs($this->flowHandler($origin))->postJson(route('hub.scan'), [
            'barcode' => $tracking, 'hub_id' => $origin->id, 'mode' => 'confirm',
            'action' => 'STAGE_SELLER_RETURN', 'expected_status' => 'return_to_sender', 'route_reference' => $route->reference,
        ])->assertOk();
        $this->assertSame('delivery_failed', $parcel->order->fresh()->status);
        $this->actingAs($seller)->postJson(route('seller.orders.return-receipt', $parcel->order), $receipt)->assertOk();
        $returned = $this->originalCheckpoints($parcel);
        $this->postJson(route('seller.orders.return-receipt', $parcel->order), $receipt)->assertOk();
        $this->assertSame($returned, $this->originalCheckpoints($parcel));
        $this->assertSame('returned', $parcel->order->fresh()->status);
        $this->assertSame('returned', $parcel->fresh()->status);
        $this->assertSame($tracking, $parcel->fresh()->tracking_number);
        $this->assertSame($outbound, $this->originalCheckpoints($parcel, array_keys($outbound)));
        $this->assertSame($routeBefore, $route->fresh()->getAttributes());
        $this->assertSame($stock, $parcel->order->items->first()->product->fresh()->stock);
        $this->assertUncollected($parcel);
        $this->assertSettlementBlocked($parcel, $admin);
        $this->actingAs($parcel->order->buyer)->post(route('buyer.orders.confirm', $parcel->order))->assertSessionHas('error');
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_counter_cash_can_reconcile_before_buyer_confirmation_but_payment_still_requires_that_buyer(): void
    {
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $hub = LogisticsHub::where('tier', 'local_bayan_hub')->where('city_municipality', 'Santa Cruz')->sole();
        $hub->update(['allows_self_pickup' => true]);
        $this->actingAs($hub->company->user)->postJson('/hub/counter/hours', ['hub_id' => $hub->id,
            'operating_hours' => 'Monday to Saturday, 08:00 to 17:00'])->assertOk();
        $order = $this->checkoutFlowOrder($this->createApprovedUser('buyer'), $shop,
            shipping: ['delivery_type' => 'hub_self_pickup', 'pickup_hub_id' => $hub->id]);
        $parcel = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $handler = $this->flowHandler($hub);
        $outbound = $this->originalCheckpoints($parcel);
        $admin = $this->createApprovedUser('admin');
        $this->actingAs($order->buyer)->post(route('buyer.orders.confirm', $order))->assertSessionHas('error');
        $this->confirmFlowScan($parcel, $hub, 'STAGE_FOR_PICKUP');
        $claim = PickupClaim::sole();
        $this->assertTrue($claim->expires_at->equalTo($claim->ready_at->timezone('Asia/Manila')->addDays(7)->utc()));
        $code = $this->actingAs($order->buyer)->postJson('/buyer/orders/'.$order->id.'/pickup-code')->assertOk()->json('code');
        $input = ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'claim_code' => $code,
            'buyer_id' => $order->buyer_id, 'recipient_name' => $order->buyer->name, 'identity_confirmed' => true,
            'request_token' => (string) Str::uuid(), 'cash_received' => (string) $order->total_amount,
            'change_given' => '0.00', 'cash_confirmed' => true, 'notes' => 'The buyer identity and original waybill matched the counted cash.'];
        $this->assertUncollected($parcel);
        $this->actingAs($handler)->postJson('/hub/release', array_replace($input, ['cash_confirmed' => false]))->assertConflict();
        $this->assertNull($claim->fresh()->consumed_at);
        $this->assertUncollected($parcel);
        $this->postJson('/hub/release', $input)->assertOk();
        $cash = CodAccount::sole();
        $this->assertSame([90000, 0, 0, 90000], [$cash->product_subtotal_cents, $cash->shipping_cents, $cash->discount_cents, $cash->expected_cents]);
        $this->assertSame($handler->id, $cash->collector_id);
        $this->assertSame('customer_collected', $parcel->fresh()->status);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertNull($parcel->fresh()->assigned_rider_id);
        $this->assertSame($cash->expected_cents, $cash->state['balances']['hub:'.$handler->id]);
        $this->assertSettlementBlocked($parcel, $admin);
        $this->handover($cash, $handler, $admin);
        $this->reconcile($cash, $admin);
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSettlementBlocked($parcel, $admin);
        foreach ([$handler, $admin, $this->createApprovedUser('buyer')] as $other) {
            $this->actingAs($other)->post(route('buyer.orders.confirm', $order))->assertForbidden();
        }
        $this->actingAs($order->buyer)->post(route('buyer.orders.confirm', $order))->assertSessionHas('success');
        $completed = $this->originalCheckpoints($parcel);
        $this->post(route('buyer.orders.confirm', $order))->assertSessionHas('success');
        $this->assertSame($completed, $this->originalCheckpoints($parcel));
        $this->paySeller($parcel, $admin);
        $cashBefore = $cash->fresh()->getAttributes();
        $this->actingAs($handler)->postJson('/hub/release', $input)->assertOk();
        $this->assertSame($cashBefore, $cash->fresh()->getAttributes());
        $this->assertSame(1, CodCashEvent::where('event_type', 'counter_collection')->count());
        $this->assertSame(0, CodCashEvent::where('event_type', 'rider_collection')->count());
        $this->assertSame($outbound, $this->originalCheckpoints($parcel, array_keys($outbound)));
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('delivery_return_routes', 0);
        foreach (['notifications', 'delivery_checkpoints', 'pickup_claim_events'] as $table) {
            $this->assertStringNotContainsString($code, json_encode(DB::table($table)->get()));
        }
    }

    private function retryInput(Delivery $parcel): array
    {
        return ['attempt_reference' => DeliveryAttempt::where('delivery_id', $parcel->id)->latest('attempt_number')->firstOrFail()->reference,
            'request_token' => (string) Str::uuid(), 'retry_at' => now('Asia/Manila')->addMinutes(10)->format('Y-m-d\TH:i'),
            'notes' => 'The buyer confirmed availability at the original address within the saved delivery area.'];
    }

    private function retryScan(Delivery $parcel): array
    {
        return ['barcode' => $parcel->tracking_number, 'hub_id' => $parcel->destination_bayan_hub_id,
            'mode' => 'confirm', 'action' => 'RELEASE_APPROVED_RETRY', 'expected_status' => 'delivery_failed',
            'attempt_reference' => DeliveryAttempt::where('delivery_id', $parcel->id)->latest('attempt_number')->firstOrFail()->reference];
    }

    private function departRetry(Delivery $parcel, User $rider): void
    {
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.scan'), $this->retryScan($parcel))->assertOk();
        $this->postJson(route('hub.sort'), ['delivery_id' => $parcel->id, 'barangay' => $parcel->order->destination_barangay])->assertOk();
        $this->postJson(route('hub.assignRider', $parcel), ['rider_id' => $rider->id])->assertOk();
        $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel), ['status' => 'out_for_delivery', 'barcode' => $parcel->tracking_number])
            ->assertSessionHas('success');
    }

    private function assertUncollected(Delivery $parcel): void
    {
        $this->assertDatabaseMissing('cod_accounts', ['delivery_id' => $parcel->id]);
        $this->assertDatabaseMissing('cod_custody_entries', ['delivery_id' => $parcel->id]);
        $this->assertDatabaseMissing('commission_ledgers', ['order_id' => $parcel->order_id]);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
    }

    private function assertSettlementBlocked(Delivery $parcel, User $admin): void
    {
        $this->actingAs($admin)->postJson('/seller-settlements/'.$parcel->order_id.'/authorize', $this->settlementInput())->assertConflict();
        $this->assertDatabaseMissing('seller_settlements', ['order_id' => $parcel->order_id]);
        $this->assertDatabaseMissing('commission_ledgers', ['order_id' => $parcel->order_id]);
    }

    private function handover(CodAccount $cash, User $holder, User $recipient): void
    {
        $offer = $this->actingAs($holder)->postJson('/cash-handover/'.$cash->id.'/offer', [
            'holder_id' => $holder->id, 'recipient_id' => $recipient->id,
            'amount' => SellerSettlementService::pesos($cash->expected_cents), 'expected_version' => $cash->fresh()->version,
            'request_token' => (string) Str::uuid(), 'evidence_reference' => 'JOURNEY-CASH-HANDOVER',
        ])->assertOk()->json('reference');
        $this->actingAs($recipient)->postJson('/cash-handover/'.$cash->id.'/receive', [
            'offer_reference' => $offer, 'received_amount' => SellerSettlementService::pesos($cash->expected_cents),
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(),
            'evidence_reference' => 'JOURNEY-CASH-RECEIPT', 'receipt_confirmed' => true,
            'reason' => 'The actual cash counted matches this original order collection.',
        ])->assertOk();
    }

    private function reconcile(CodAccount $cash, User $admin): void
    {
        $this->actingAs($admin)->postJson('/cash-handover/'.$cash->id.'/reconcile', [
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(),
            'evidence_reference' => 'JOURNEY-PLATFORM-CASH-REVIEW',
            'reason' => 'The original collection, accepted handovers and actual platform receipt match.',
        ])->assertOk();
        $this->assertNotNull($cash->fresh()->reconciled_at);
        $this->assertSame('paid', $cash->order->fresh()->payment_status);
        $this->assertDatabaseMissing('commission_ledgers', ['order_id' => $cash->order_id]);
    }

    private function settlementInput(int $version = 0): array
    {
        return ['expected_version' => $version, 'request_token' => (string) Str::uuid(),
            'reason' => 'The buyer confirmation, original seller and reconciled cash were checked.'];
    }

    private function paySeller(Delivery $parcel, User $admin): void
    {
        $this->actingAs($admin)->postJson('/seller-settlements/'.$parcel->order_id.'/authorize', $this->settlementInput())->assertOk();
        $payment = $this->settlementInput(1) + ['payment_reference' => 'JOURNEY-SELLER-PAYMENT',
            'payment_confirmed' => true, 'proof' => UploadedFile::fake()->image('seller-receipt.jpg')];
        $reference = $this->postJson('/seller-settlements/'.$parcel->order_id.'/record-payment', $payment)->assertOk()->json('reference');
        $this->postJson('/seller-settlements/'.$parcel->order_id.'/record-payment', $payment)->assertOk()->assertJsonPath('reference', $reference);
        $record = SellerSettlement::where('order_id', $parcel->order_id)->sole();
        $this->assertSame($parcel->order->shop->user_id, $record->seller_id);
        $this->assertSame([90000, 81000, 9000], [$record->product_cents, $record->seller_cents, $record->commission_cents]);
        $this->getJson('/seller-settlements/'.$parcel->order_id)->assertOk()->assertJsonPath('record.status', 'settled');
        $this->assertSame(1, $record->events()->where('event_type', 'payment_recorded')->count());
        $ledger = CommissionLedger::where('order_id', $parcel->order_id)->sole();
        $this->assertSame(SellerSettlementService::pesos($record->seller_cents), $ledger->seller_amount);
        $this->assertSame(SellerSettlementService::pesos($record->commission_cents), $ledger->platform_commission);
        $this->assertSame($record->product_cents, $record->seller_cents + $record->commission_cents);
        $this->assertSame(['810.00', '90.00'], [$ledger->seller_amount, $ledger->platform_commission]);
        $this->assertSame($parcel->delivery_type === 'hub_self_pickup' ? '0.00' : '60.00', $ledger->delivery_fee);
    }

    private function originalCheckpoints(Delivery $parcel, ?array $ids = null): array
    {
        return $parcel->checkpoints()->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->orderBy('id')->get()->mapWithKeys(fn ($checkpoint) => [$checkpoint->id => $checkpoint->getAttributes()])->all();
    }
}
