<?php

namespace Tests\Feature\Finance;

use App\Models\CodAccount;
use App\Models\CodCashEvent;
use App\Models\CodCustodyEntry;
use App\Models\HubHandler;
use App\Models\LogisticsHub;
use App\Models\NotificationDelivery;
use App\Models\Order;
use App\Models\User;
use App\Services\AccountClosureService;
use App\Services\AccountRestrictionService;
use App\Services\Finance\CodCashService;
use App\Services\Finance\CodCashViewService;
use App\Services\Finance\CodMoney;
use App\Services\Notifications\CodNoticeService;
use App\Services\ResourceRestrictionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class CodCashCustodyTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function collected(): CodAccount
    {
        $delivery = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'delivered');

        return CodAccount::where('delivery_id', $delivery->id)->sole();
    }

    private function collectAtCounter(bool $legacy = false): array
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
        $prompt = $this->actingAs($handler)->postJson('/hub/scan', ['barcode' => $parcel->tracking_number,
            'hub_id' => $hub->id, 'mode' => 'inspect'])->assertOk()->json('prompt');
        $this->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => $prompt['action'], 'expected_status' => $prompt['expected_status']])->assertOk();
        $code = $this->actingAs($order->buyer)->postJson('/buyer/orders/'.$order->id.'/pickup-code')->assertOk()->json('code');
        $payload = ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'claim_code' => $code,
            'buyer_id' => $order->buyer_id, 'recipient_name' => $order->buyer->name, 'identity_confirmed' => true,
            'request_token' => (string) Str::uuid(), 'cash_received' => (string) $order->total_amount,
            'change_given' => '0.00', 'cash_confirmed' => true, 'notes' => 'Actual identity and counted cash checked at the counter.'];
        if ($legacy) {
            // Simulate the pre-B16 writer while retaining the real verified counter workflow and its immutable source.
            $this->mock(CodCashService::class)->shouldReceive('collectCounter')->once()->andReturn(new CodCashEvent);
        }
        $this->actingAs($handler)->postJson('/hub/release', $payload)->assertOk();
        if ($legacy) {
            $this->app->forgetInstance(CodCashService::class);
        }

        return [$parcel->fresh(), $handler, $payload];
    }

    public function test_actual_counter_collection_and_hub_remittance_keep_original_verified_evidence(): void
    {
        [$parcel, $handler, $payload] = $this->collectAtCounter();
        $account = CodAccount::sole();
        $source = CodCustodyEntry::sole();
        $event = $account->events()->sole();
        $this->assertSame($source->id, $account->pickup_cash_entry_id);
        $this->assertSame($source->reference, $event->evidence_reference);
        $this->assertSame($handler->id, $account->collector_id);
        $this->assertSame($account->expected_cents, $account->state['balances']['hub:'.$handler->id]);
        $before = $this->snapshot();
        $this->actingAs($handler)->postJson('/hub/release', $payload)->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->actingAs($parcel->order->buyer)->post('/buyer/orders/'.$parcel->order_id.'/confirm')->assertSessionHas('success');
        $this->assertNull($account->fresh()->reconciled_at);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
        $admin = $this->createApprovedUser('admin');
        $this->handover($account, $handler, $admin, $account->expected_cents);
        $this->command($account, $admin, 'reconcile', ['evidence_reference' => 'COUNTER-CASH-REVIEW-001',
            'reason' => 'The original counter collection and actual platform receipt match the saved COD.']);
        $this->assertSame('completed', $parcel->order->fresh()->status);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_verified_legacy_counter_review_keeps_source_time_actor_and_cash_with_no_backdated_movement(): void
    {
        [$parcel, $handler] = $this->collectAtCounter(true);
        $source = CodCustodyEntry::sole();
        $sourceBefore = $source->getAttributes();
        $this->assertDatabaseCount('cod_accounts', 0);
        // Older retained sources may name a closed handler; review must preserve that provenance.
        $handler->forceFill(['closed_at' => now(), 'status' => 'inactive'])->save();
        $admin = $this->createApprovedUser('admin');
        $this->travel(1)->days();
        $input = ['expected_status' => $parcel->status, 'request_token' => (string) Str::uuid(),
            'evidence_reference' => 'ORIGINAL-COUNTER-REVIEW-001', 'reason' => 'The original counter receipt has the verified buyer, code and exact amount.'];
        $this->actingAs($admin)->postJson('/admin/cod/legacy/'.$parcel->id, $input)->assertOk();
        $account = CodAccount::sole();
        $event = $account->events()->where('event_type', 'counter_collection')->sole();
        $this->assertSame($sourceBefore, $source->fresh()->getAttributes());
        $this->assertSame($handler->id, $account->collector_id);
        $this->assertSame($handler->id, $event->to_user_id);
        $this->assertSame($admin->id, $event->actor_id);
        $this->assertSame($source->created_at->toIso8601String(), $event->private_evidence['original_collected_at']);
        $this->assertSame($source->actor_id, $event->private_evidence['original_actor_id']);
        $this->assertTrue($event->created_at->greaterThan($source->created_at));
        $this->assertSame($source->amount_cents, $account->state['balances']['hub:'.$handler->id]);
        $before = $this->snapshot();
        $this->postJson('/admin/cod/legacy/'.$parcel->id, $input)->assertOk()->assertJsonPath('reference', $event->reference);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('verified_counter_history', $event->provenance);
        $this->assertNull($account->reconciled_at);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->actingAs($handler)->getJson('/cash-handover/'.$account->id)->assertForbidden();
        $manager = LogisticsHub::findOrFail($account->hub_id)->company->user;
        $offer = $this->command($account, $manager, 'offer', ['holder_id' => $handler->id, 'recipient_id' => $admin->id,
            'amount' => $this->pesos($account->expected_cents), 'evidence_reference' => 'RETAINED-HUB-CASH-001']);
        $this->assertSame($handler->id, $offer->from_user_id);
        $this->assertSame($manager->id, $offer->actor_id);
        $this->command($account, $admin, 'receive', ['offer_reference' => $offer->reference,
            'received_amount' => $this->pesos($account->expected_cents), 'evidence_reference' => 'PLATFORM-RECEIPT-001']);
        $this->assertSame($sourceBefore, $source->fresh()->getAttributes());
        $this->assertSame($handler->id, $account->fresh()->collector_id);
        $this->assertNotNull($handler->fresh()->closed_at);
    }

    private function handler(CodAccount $account): User
    {
        return $this->flowHandler(LogisticsHub::findOrFail($account->hub_id));
    }

    private function input(CodAccount $account, array $input): array
    {
        return $input + ['expected_version' => $account->fresh()->version, 'request_token' => (string) Str::uuid(), 'receipt_confirmed' => true];
    }

    private function pesos(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    private function command(CodAccount $account, User $actor, string $action, array $input): CodCashEvent
    {
        $response = $this->actingAs($actor)->postJson('/cash-handover/'.$account->id.'/'.$action, $this->input($account, $input))->assertOk();

        return CodCashEvent::where('reference', $response->json('reference'))->sole();
    }

    private function handover(CodAccount $account, User $holder, User $recipient, int $amount, ?int $received = null): CodCashEvent
    {
        $offer = $this->command($account, $holder, 'offer', ['holder_id' => $holder->id, 'recipient_id' => $recipient->id,
            'amount' => $this->pesos($amount), 'evidence_reference' => 'HANDOVER-001']);

        return $this->command($account, $recipient, 'receive', ['offer_reference' => $offer->reference,
            'received_amount' => $this->pesos($received ?? $amount), 'evidence_reference' => 'RECEIPT-001',
            'reason' => $received !== null && $received !== $amount ? 'Actual counted cash differs from the offered amount.' : null]);
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['orders', 'deliveries', 'cod_accounts', 'cod_cash_events', 'cod_custody_entries', 'delivery_checkpoints', 'commission_ledgers', 'notification_deliveries', 'notifications'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }

    public static function portals(): array
    {
        return [['/courier', '/hub', '/admin'], ['http://courier.localhost', 'http://hub.localhost', 'http://admin.localhost']];
    }

    #[DataProvider('portals')]
    public function test_recorded_rider_hub_and_platform_receipts_require_a_separate_reconciliation(string $riderBase, string $hubBase, string $adminBase): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $admin = $this->createApprovedUser('admin');
        $cash = app(CodCashService::class);
        $this->assertSame(CodMoney::cents($account->order->total_amount), $account->expected_cents);
        $this->assertSame(CodMoney::cents($account->order->shipping_fee), $account->shipping_cents);
        $this->assertSame('pending', $account->order->payment_status);
        $this->assertNull($account->reconciled_at);
        $offerInput = $this->input($account, ['holder_id' => $rider->id, 'recipient_id' => $handler->id,
            'amount' => $this->pesos($account->expected_cents), 'evidence_reference' => 'HUB-HANDOVER-001']);
        $offer = $this->actingAs($rider)->postJson($riderBase.'/cod/'.$account->id.'/offer', $offerInput)->assertOk()->json('reference');
        $this->assertSame($account->expected_cents, $cash->atStage($account->fresh()->state, 'rider'));
        $this->assertSame(0, $cash->atStage($account->fresh()->state, 'hub'));
        $receivedInput = $this->input($account, ['offer_reference' => $offer, 'received_amount' => $this->pesos($account->expected_cents), 'evidence_reference' => 'HUB-RECEIPT-001']);
        $this->actingAs($handler)->postJson($hubBase.'/cod/'.$account->id.'/receive', $receivedInput)->assertOk();
        $this->assertSame(0, $cash->atStage($account->fresh()->state, 'rider'));
        $this->assertSame($account->expected_cents, $cash->atStage($account->fresh()->state, 'hub'));
        $platformOffer = $this->actingAs($handler)->postJson($hubBase.'/cod/'.$account->id.'/offer', $this->input($account,
            ['holder_id' => $handler->id, 'recipient_id' => $admin->id, 'amount' => $this->pesos($account->expected_cents), 'evidence_reference' => 'PLATFORM-HANDOVER-001']))->assertOk()->json('reference');
        $this->actingAs($admin)->postJson($adminBase.'/cod/'.$account->id.'/receive', $this->input($account,
            ['offer_reference' => $platformOffer, 'received_amount' => $this->pesos($account->expected_cents), 'evidence_reference' => 'PLATFORM-RECEIPT-001']))->assertOk();
        $this->assertNull($account->fresh()->reconciled_at);
        $this->assertSame('pending', $account->order->fresh()->payment_status);
        $this->actingAs($account->order->buyer)->post('http://localhost/buyer/orders/'.$account->order_id.'/confirm')->assertSessionHas('success');
        $this->assertSame('completed', $account->order->fresh()->status);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->actingAs($admin)->postJson($adminBase.'/cod/'.$account->id.'/reconcile', $this->input($account,
            ['evidence_reference' => 'PLATFORM-RECONCILIATION-001', 'reason' => 'Original collections and both receipts match the full saved COD amount.']))->assertOk();
        $this->assertSame($account->expected_cents, $account->fresh()->state['reconciled_cents']);
        $this->assertSame('paid', $account->order->fresh()->payment_status);
        $this->assertSame('completed', $account->order->fresh()->status);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->actingAs($admin)->getJson($adminBase.'/cod/'.$account->id)->assertOk()->assertJsonPath('account.status', 'reconciled');
    }

    public static function badCollections(): array
    {
        return ['partial' => [['cash_received' => '0.01'], 302], 'wrong change' => [['change_given' => '0.01'], 302],
            'float' => [['cash_received' => 1999.99]], 'precision' => [['cash_received' => '1000.001']],
            'exponent' => [['cash_received' => '1e6']], 'negative' => [['cash_received' => '-1000']],
            'not received' => [['cash_confirmed' => false]], 'wrong waybill' => [['barcode' => 'BGO-FOREIGN']],
            'injected total' => [['total_amount' => '0.01']], 'missing recipient' => [['recipient_name' => '']],
            'missing retry' => [['request_token' => null]]];
    }

    #[DataProvider('badCollections')]
    public function test_invalid_collection_leaves_no_cash_or_handoff(array $overrides, int $status = 422): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'out_for_delivery');
        $before = $this->snapshot();
        $this->actingAs(User::findOrFail($parcel->assigned_rider_id))->patchJson(route('courier.updateStatus', $parcel),
            $this->codCollectionInput($parcel, $overrides) + ['status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('handoff.jpg')])
            ->assertStatus($status);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], Storage::disk('public')->allFiles('delivery-proofs'));
    }

    public function test_larger_tender_only_records_exact_due_after_change_and_retries_once(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'out_for_delivery');
        $due = CodMoney::cents($parcel->order->total_amount);
        $payload = $this->codCollectionInput($parcel, ['cash_received' => $this->pesos($due + 2000), 'change_given' => '20.00'])
            + ['status' => 'delivered', 'proof_image_file' => UploadedFile::fake()->image('handoff.jpg')];
        $this->actingAs(User::findOrFail($parcel->assigned_rider_id))->patch(route('courier.updateStatus', $parcel), $payload)->assertSessionHas('success');
        $before = $this->snapshot();
        unset($payload['proof_image_file']);
        $this->patch(route('courier.updateStatus', $parcel), $payload)->assertSessionHas('success');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($due, CodCashEvent::sole()->amount_cents);
        $this->assertSame($due + 2000, CodCashEvent::sole()->private_evidence['tender_cents']);
        $this->assertSame($due, array_sum(CodAccount::sole()->state['balances']));
        $payload['recipient_relationship'] = 'household';
        $this->patch(route('courier.updateStatus', $parcel), $payload)->assertSessionHas('error');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_identical_handover_retries_and_changed_or_competing_requests(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $offer = $this->input($account, ['holder_id' => $rider->id, 'recipient_id' => $handler->id, 'amount' => '10.00', 'evidence_reference' => 'HANDOVER-001']);
        $ref = $this->actingAs($rider)->postJson('/cash-handover/'.$account->id.'/offer', $offer)->assertOk()->json('reference');
        $before = $this->snapshot();
        $this->postJson('/cash-handover/'.$account->id.'/offer', $offer)->assertOk()->assertJsonPath('reference', $ref);
        $this->assertSame($before, $this->snapshot());
        $this->postJson('/cash-handover/'.$account->id.'/offer', array_replace($offer, ['amount' => '9.00']))->assertConflict();
        $this->postJson('/cash-handover/'.$account->id.'/offer', array_replace($offer, ['request_token' => (string) Str::uuid()]))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $receipt = $this->input($account, ['offer_reference' => $ref, 'received_amount' => '10.00', 'evidence_reference' => 'RECEIPT-001']);
        $this->actingAs($handler)->postJson('/cash-handover/'.$account->id.'/receive', $receipt)->assertOk();
        $before = $this->snapshot();
        $this->postJson('/cash-handover/'.$account->id.'/receive', $receipt)->assertOk();
        $this->postJson('/cash-handover/'.$account->id.'/receive', array_replace($receipt, ['received_amount' => '9.00']))->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_partial_receipt_preserves_both_holders_and_cannot_reconcile(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $admin = $this->createApprovedUser('admin');
        $this->handover($account, $rider, $handler, 1000);
        $this->assertSame($account->expected_cents - 1000, $account->fresh()->state['balances']['rider:'.$rider->id]);
        $this->assertSame(1000, $account->fresh()->state['balances']['hub:'.$handler->id]);
        $this->handover($account, $handler, $admin, 1000);
        $before = $this->snapshot();
        $this->actingAs($admin)->postJson('/cash-handover/'.$account->id.'/reconcile', $this->input($account,
            ['evidence_reference' => 'REVIEW-001', 'reason' => 'Review the actual partial remittance.']))->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_shortage_needs_actual_recovery_and_linked_adjustment_before_reconciliation(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $admin = $this->createApprovedUser('admin');
        $receipt = $this->handover($account, $rider, $handler, $account->expected_cents, $account->expected_cents - 100);
        $this->assertSame(-100, $receipt->discrepancy_cents);
        $this->assertSame(100, $account->fresh()->state['balances']['rider:'.$rider->id]);
        $adjustment = ['source_reference' => $receipt->reference, 'adjustment_type' => 'shortage_recovered', 'amount' => '1.00',
            'evidence_reference' => 'UNVERIFIED-RECOVERY', 'reason' => 'Review the missing cash against an actual receipt.'];
        $before = $this->snapshot();
        $this->actingAs($admin)->postJson('/cash-handover/'.$account->id.'/adjust', $this->input($account, $adjustment))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $recovery = $this->handover($account, $rider, $handler, 100);
        $adjustment['evidence_reference'] = $recovery->reference;
        $this->handover($account, $handler, $admin, $account->expected_cents);
        $before = $this->snapshot();
        $this->actingAs($admin)->postJson('/cash-handover/'.$account->id.'/reconcile', $this->input($account,
            ['evidence_reference' => 'REVIEW-001', 'reason' => 'Full COD has arrived but the original shortage is not reviewed.']))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $this->command($account, $admin, 'adjust', $adjustment);
        $this->assertSame(-100, $receipt->fresh()->discrepancy_cents);
        $this->assertSame(100, $account->events()->where('event_type', 'adjustment')->sole()->amount_cents);
        $before = $this->snapshot();
        $this->postJson('/cash-handover/'.$account->id.'/adjust', $this->input($account, $adjustment))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $this->command($account, $admin, 'reconcile', ['evidence_reference' => 'REVIEW-001', 'reason' => 'All COD and the recovered shortage have verified receipts.']);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_overage_is_retained_as_separate_cash_and_never_increases_order_due(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $admin = $this->createApprovedUser('admin');
        $receipt = $this->handover($account, $rider, $handler, $account->expected_cents, $account->expected_cents + 100);
        $this->assertSame(100, $receipt->discrepancy_cents);
        $this->assertSame(100, $account->fresh()->state['excess']['hub:'.$handler->id]);
        $this->command($account, $admin, 'adjust', ['source_reference' => $receipt->reference, 'adjustment_type' => 'overage_separated',
            'amount' => '1.00', 'evidence_reference' => 'EXTRA-CASH-REVIEW-001', 'reason' => 'The extra peso remains with the hub as separate unallocated cash.']);
        $this->assertSame(-100, $account->events()->where('event_type', 'adjustment')->sole()->amount_cents);
        $this->handover($account, $handler, $admin, $account->expected_cents);
        $this->command($account, $admin, 'reconcile', ['evidence_reference' => 'REVIEW-001', 'reason' => 'Exact order COD reached the platform. The extra peso remains separately recorded.']);
        $this->assertSame($account->expected_cents, $account->fresh()->state['reconciled_cents']);
        $this->assertSame(100, $account->fresh()->state['excess']['hub:'.$handler->id]);
        $this->assertSame(100, $receipt->fresh()->discrepancy_cents);
    }

    public function test_foreign_company_wrong_receiver_and_ordinary_roles_cannot_change_cash(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $outsider = $this->createApprovedUser('logistics');
        $ordinary = [$this->createApprovedUser('buyer'), $this->createApprovedUser('seller')];
        $offer = $this->command($account, $rider, 'offer', ['holder_id' => $rider->id, 'recipient_id' => $handler->id, 'amount' => '10.00', 'evidence_reference' => 'HANDOVER-001']);
        $before = $this->snapshot();
        $this->actingAs($outsider)->getJson('/cash-handover/'.$account->id)->assertNotFound();
        $this->postJson('/cash-handover/'.$account->id.'/receive', $this->input($account,
            ['offer_reference' => $offer->reference, 'received_amount' => '10.00', 'evidence_reference' => 'RECEIPT-001']))->assertNotFound();
        $this->actingAs($rider)->postJson('/cash-handover/'.$account->id.'/receive', $this->input($account,
            ['offer_reference' => $offer->reference, 'received_amount' => '10.00', 'evidence_reference' => 'RECEIPT-001']))->assertForbidden();
        foreach ($ordinary as $user) {
            $this->actingAs($user)->getJson('/cash-handover/'.$account->id)->assertForbidden();
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_offer_injection_unconfirmed_cash_and_changed_recipient_are_rejected_without_moving_cash(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $otherHub = LogisticsHub::where('id', '!=', $account->hub_id)->firstOrFail();
        $foreignHandler = $this->flowHandler($otherHub);
        $offer = ['holder_id' => $rider->id, 'recipient_id' => $handler->id, 'amount' => '10.00', 'evidence_reference' => 'HANDOVER-001'];
        $before = $this->snapshot();
        $this->actingAs($rider);
        foreach ([['amount' => 10.0], ['amount' => '1e2'], ['received_cents' => 1000], ['created_at' => '2020-01-01'],
            ['holder_user_id' => $handler->id]] as $bad) {
            $this->postJson('/cash-handover/'.$account->id.'/offer', $this->input($account, array_replace($offer, $bad)))->assertUnprocessable();
            $this->assertSame($before, $this->snapshot());
        }
        foreach ([['recipient_id' => $foreignHandler->id], ['amount' => $this->pesos($account->expected_cents + 1)]] as $bad) {
            $this->postJson('/cash-handover/'.$account->id.'/offer', $this->input($account, array_replace($offer, $bad)))->assertConflict();
            $this->assertSame($before, $this->snapshot());
        }
        $event = $this->command($account, $rider, 'offer', $offer);
        $before = $this->snapshot();
        $receipt = ['offer_reference' => $event->reference, 'received_amount' => '10.00', 'evidence_reference' => 'RECEIPT-001'];
        $this->actingAs($handler)->postJson('/cash-handover/'.$account->id.'/receive', $this->input($account, $receipt + ['receipt_confirmed' => false]))->assertUnprocessable();
        $this->postJson('/cash-handover/'.$account->id.'/receive', $this->input($account, array_replace($receipt, ['received_amount' => '9.00'])))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        HubHandler::where('user_id', $handler->id)->update(['hub_id' => $otherHub->id]);
        $this->postJson('/cash-handover/'.$account->id.'/receive', $this->input($account, $receipt))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $admin = $this->createApprovedUser('admin');
        $balance = $account->fresh()->state['balances'];
        $this->command($account, $admin, 'cancel', ['offer_reference' => $event->reference,
            'reason' => 'The named recipient changed placement before confirming any cash.']);
        $this->assertNull($account->fresh()->state['pending']);
        $this->assertSame($balance, $account->fresh()->state['balances']);
    }

    public function test_only_a_current_platform_admin_can_reconcile_even_after_all_cash_reaches_the_platform(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $admin = $this->createApprovedUser('admin');
        $manager = LogisticsHub::findOrFail($account->hub_id)->company->user;
        $this->handover($account, $rider, $handler, $account->expected_cents);
        $this->handover($account, $handler, $admin, $account->expected_cents);
        $input = ['evidence_reference' => 'RECONCILIATION-001', 'reason' => 'Full actual COD has reached the platform.'];
        $before = $this->snapshot();
        foreach ([$rider, $handler, $manager] as $actor) {
            $this->actingAs($actor)->postJson('/cash-handover/'.$account->id.'/reconcile', $this->input($account, $input))->assertForbidden();
            $this->postJson('/cash-handover/'.$account->id.'/adjust', $this->input($account, ['source_reference' => 'UNVERIFIED-001',
                'adjustment_type' => 'overage_separated', 'amount' => '1.00'] + $input))->assertForbidden();
        }
        $admin->update(['status' => 'inactive']);
        $this->actingAs($admin)->postJson('/cash-handover/'.$account->id.'/reconcile', $this->input($account, $input))->assertForbidden();
        $this->getJson('/cash-handover/'.$account->id)->assertForbidden();
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_failed_attempt_return_and_seller_receipt_do_not_invent_cash_collection(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder('ready_for_pickup'), 'out_for_delivery');
        $this->reportFlowFailure($parcel, 'Customer refused');
        $this->receiveFailureFlow($parcel);
        $this->returnToSellerFlow($parcel);
        $this->assertSame('returned', $parcel->order->fresh()->status);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('cod_cash_events', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->assertSame('pending', $parcel->order->fresh()->payment_status);
    }

    public function test_expired_recovery_authorization_does_not_allow_a_new_direct_platform_handover(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $admin = $this->createApprovedUser('admin');
        LogisticsHub::findOrFail($account->hub_id)->company->update(['is_active' => false]);
        $this->command($account, $admin, 'authorize-recovery', ['holder_id' => $rider->id, 'recipient_id' => $admin->id,
            'reason' => 'The original hub is restricted. Recover this recorded cash only.']);
        $this->travel(24)->hours();
        $before = $this->snapshot();
        $this->actingAs($rider)->postJson('/cash-handover/'.$account->id.'/offer', $this->input($account,
            ['holder_id' => $rider->id, 'recipient_id' => $admin->id, 'amount' => $this->pesos($account->expected_cents),
                'evidence_reference' => 'EXPIRED-RECOVERY-001']))->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_recovery_receipt_rechecks_expiry_and_admin_cancellation_keeps_the_original_holder(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $admin = $this->createApprovedUser('admin');
        LogisticsHub::findOrFail($account->hub_id)->company->update(['is_active' => false]);
        $this->command($account, $admin, 'authorize-recovery', ['holder_id' => $rider->id, 'recipient_id' => $admin->id,
            'reason' => 'The original network is restricted. Review this rider cash recovery.']);
        $offer = $this->command($account, $rider, 'offer', ['holder_id' => $rider->id, 'recipient_id' => $admin->id,
            'amount' => $this->pesos($account->expected_cents), 'evidence_reference' => 'RECOVERY-HANDOVER-001']);
        $this->travel(24)->hours();
        $before = $this->snapshot();
        $this->actingAs($admin)->postJson('/cash-handover/'.$account->id.'/receive', $this->input($account,
            ['offer_reference' => $offer->reference, 'received_amount' => $this->pesos($account->expected_cents),
                'evidence_reference' => 'EXPIRED-RECOVERY-RECEIPT-001']))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $this->command($account, $admin, 'cancel', ['offer_reference' => $offer->reference,
            'reason' => 'The recovery authority expired before a valid confirmed receipt.']);
        $this->assertSame($account->expected_cents, $account->fresh()->state['balances']['rider:'.$rider->id]);
        $this->assertSame(0, app(CodCashService::class)->atStage($account->fresh()->state, 'platform'));
    }

    public function test_governance_and_closure_use_recorded_holders_after_placement_changes_without_granting_settlement(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $admin = $this->createApprovedUser('admin');
        $receipt = $this->handover($account, $rider, $handler, $account->expected_cents, $account->expected_cents + 100);
        $this->command($account, $admin, 'adjust', ['source_reference' => $receipt->reference, 'adjustment_type' => 'overage_separated',
            'amount' => '1.00', 'evidence_reference' => 'UNALLOCATED-CASH-001', 'reason' => 'The extra peso remains separately held and excluded from order COD.']);
        $this->handover($account, $handler, $admin, $account->expected_cents);
        $this->command($account, $admin, 'reconcile', ['evidence_reference' => 'RECONCILIATION-001',
            'reason' => 'Exact saved COD has verified platform receipts and reviewed differences.']);
        $this->actingAs($account->order->buyer)->post('/buyer/orders/'.$account->order_id.'/confirm')->assertSessionHas('success');
        $otherHub = LogisticsHub::where('id', '!=', $account->hub_id)->firstOrFail();
        HubHandler::where('user_id', $handler->id)->update(['hub_id' => $otherHub->id]);
        $view = app(CodCashViewService::class)->evidence($account->delivery);
        $this->assertSame($admin->id, $view['holder_user_id']);
        $this->assertSame('reconciled', $view['reconciliation']);
        $this->assertSame(100, $view['excess_cents']);
        $work = app(AccountRestrictionService::class)->work(app(AccountRestrictionService::class)->ordersFor($handler));
        $this->assertCount(1, $work);
        $this->assertSame($admin->id, $work[0]['cash']['holder_user_id']);
        $resourceWork = app(ResourceRestrictionService::class)->ordersFor($admin, 'handler', HubHandler::where('user_id', $handler->id)->sole())->get();
        $this->assertCount(1, $resourceWork);
        $this->assertSame($account->order_id, $resourceWork->sole()->id);
        $closure = app(AccountClosureService::class)->state($handler);
        $codes = array_column($closure['blockers'], 'code');
        $this->assertContains('cash_excess', $codes);
        $this->assertContains('settlement_unverified', $codes);
        $this->assertNotContains('cash_unverified', $codes);
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_database_uniqueness_rejects_a_second_source_account_or_repeated_cash_event_identity(): void
    {
        $account = $this->collected();
        $event = $account->events()->sole();
        $before = $this->snapshot();
        foreach (['source', 'sequence', 'retry'] as $duplicate) {
            $row = $duplicate === 'source' ? $account->getAttributes() : $event->getAttributes();
            unset($row['id']);
            $row['reference'] = 'DUPLICATE-'.(string) Str::uuid();
            if ($duplicate === 'sequence') {
                $row['request_token'] = (string) Str::uuid();
            } elseif ($duplicate === 'retry') {
                $row['sequence'] = 2;
            }
            try {
                DB::table($duplicate === 'source' ? 'cod_accounts' : 'cod_cash_events')->insert($row);
                $this->fail('Duplicate sources and financial event identities must be rejected by the database.');
            } catch (QueryException $error) {
                $this->assertStringContainsString('UNIQUE', $error->getMessage());
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_reconciliation_payment_write_failure_rolls_back_the_decision_balances_and_notices(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $admin = $this->createApprovedUser('admin');
        $this->handover($account, $rider, $handler, $account->expected_cents);
        $this->handover($account, $handler, $admin, $account->expected_cents);
        $before = $this->snapshot();
        $inject = true;
        Order::updating(function ($order) use (&$inject) {
            if ($inject && $order->isDirty('payment_status')) {
                throw new RuntimeException('Payment writer unavailable.');
            }
        });
        try {
            $this->withoutExceptionHandling()->actingAs($admin)->postJson('/cash-handover/'.$account->id.'/reconcile',
                $this->input($account, ['evidence_reference' => 'RECONCILIATION-001', 'reason' => 'Full actual COD has reached the platform.']));
            $this->fail('A failed payment writer must not keep a reconciliation decision.');
        } catch (RuntimeException $error) {
            $this->assertSame('Payment writer unavailable.', $error->getMessage());
        } finally {
            $inject = false;
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_company_admin_can_remit_only_own_hub_cash_with_original_holder_provenance(): void
    {
        $account = $this->collected();
        $handler = $this->handler($account);
        $rider = User::findOrFail($account->collector_id);
        $admin = $this->createApprovedUser('admin');
        $this->handover($account, $rider, $handler, $account->expected_cents);
        $manager = LogisticsHub::findOrFail($account->hub_id)->company->user;
        $offer = $this->command($account, $manager, 'offer', ['holder_id' => $handler->id, 'recipient_id' => $admin->id,
            'amount' => $this->pesos($account->expected_cents), 'evidence_reference' => 'COMPANY-HANDOVER-001']);
        $this->assertSame($handler->id, $offer->from_user_id);
        $this->assertSame($manager->id, $offer->actor_id);
        $this->assertSame('company_cash_manager', $offer->provenance);
        $this->assertSame($account->expected_cents, $account->fresh()->state['balances']['hub:'.$handler->id]);
        $this->command($account, $admin, 'receive', ['offer_reference' => $offer->reference,
            'received_amount' => $this->pesos($account->expected_cents), 'evidence_reference' => 'PLATFORM-RECEIPT-001']);
    }

    public function test_restricted_rider_can_remit_known_cash_without_restoring_portal_or_placement(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $this->restrictFlowAccount($rider, 'suspend');
        $before = $rider->fresh()->only(['role', 'status', 'kyc_status', 'restriction_version']);
        $this->actingAs($rider)->get('/courier/deliveries')->assertRedirect('/login');
        $this->post('/cash-handover/sign-in', ['email' => $rider->email, 'password' => 'password'])->assertRedirect('/cash-handover');
        $this->getJson('/cash-handover/'.$account->id)->assertOk();
        $this->handover($account, $rider, $handler, $account->expected_cents);
        $this->assertSame($before, $rider->fresh()->only(['role', 'status', 'kyc_status', 'restriction_version']));
        $this->assertSame($account->expected_cents, $account->fresh()->state['balances']['hub:'.$handler->id]);
        $this->assertSame(0, $account->fresh()->state['balances']['rider:'.$rider->id]);
    }

    public function test_restricted_network_requires_current_platform_authority_for_direct_cash_recovery(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $admin = $this->createApprovedUser('admin');
        $company = LogisticsHub::findOrFail($account->hub_id)->company;
        $company->update(['is_active' => false]);
        $offer = ['holder_id' => $rider->id, 'recipient_id' => $admin->id, 'amount' => $this->pesos($account->expected_cents), 'evidence_reference' => 'RECOVERY-001'];
        $before = $this->snapshot();
        $this->actingAs($rider)->postJson('/cash-handover/'.$account->id.'/offer', $this->input($account, $offer))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $this->command($account, $admin, 'authorize-recovery', ['holder_id' => $rider->id, 'recipient_id' => $admin->id,
            'reason' => 'The original network is restricted. Recover only this recorded rider cash at the platform.']);
        $receipt = $this->handover($account, $rider, $admin, $account->expected_cents);
        $this->assertSame('platform', $receipt->to_stage);
        $this->assertFalse($company->fresh()->is_active);
        $this->assertSame($rider->id, $receipt->from_user_id);
    }

    public static function failures(): array
    {
        return [['event'], ['projection'], ['notice']];
    }

    #[DataProvider('failures')]
    public function test_money_projection_and_notification_audit_failures_roll_back_the_entire_receipt(string $failure): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $handler = $this->handler($account);
        $offer = $this->command($account, $rider, 'offer', ['holder_id' => $rider->id, 'recipient_id' => $handler->id, 'amount' => '10.00', 'evidence_reference' => 'HANDOVER-001']);
        $before = $this->snapshot();
        $inject = true;
        if ($failure === 'event') {
            CodCashEvent::creating(function ($event) use (&$inject) {
                if ($inject && $event->event_type === 'handover_received') {
                    throw new RuntimeException('Cash audit unavailable.');
                }
            });
        } elseif ($failure === 'projection') {
            CodAccount::updating(function () use (&$inject) {
                if ($inject) {
                    throw new RuntimeException('Cash projection unavailable.');
                }
            });
        } else {
            $this->mock(CodNoticeService::class)->shouldReceive('record')->once()->andThrow(new RuntimeException('Cash notice intent unavailable.'));
        }
        try {
            $this->withoutExceptionHandling()->actingAs($handler)->postJson('/cash-handover/'.$account->id.'/receive', $this->input($account,
                ['offer_reference' => $offer->reference, 'received_amount' => '10.00', 'evidence_reference' => 'RECEIPT-001']));
            $this->fail('A failed money or audit writer must not accept the receipt.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('unavailable', $error->getMessage());
        } finally {
            $inject = false;
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_original_events_sources_and_reconciled_records_cannot_be_updated_or_deleted(): void
    {
        $account = $this->collected();
        $event = $account->events()->sole();
        $before = $this->snapshot();
        foreach (['model-update', 'model-delete', 'query-update', 'query-delete', 'source-update'] as $operation) {
            try {
                match ($operation) {
                    'model-update' => $event->update(['amount_cents' => 1]),
                    'model-delete' => $event->delete(),
                    'query-update' => DB::table('cod_cash_events')->where('id', $event->id)->update(['amount_cents' => 1]),
                    'query-delete' => DB::table('cod_cash_events')->where('id', $event->id)->delete(),
                    default => DB::table('cod_accounts')->where('id', $account->id)->update(['expected_cents' => 1]),
                };
                $this->fail('Original cash evidence must be immutable.');
            } catch (LogicException|QueryException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $this->assertSame($before, $this->snapshot());
        $handler = $this->handler($account);
        $admin = $this->createApprovedUser('admin');
        $this->handover($account, User::findOrFail($account->collector_id), $handler, $account->expected_cents);
        $this->handover($account, $handler, $admin, $account->expected_cents);
        $this->command($account, $admin, 'reconcile', ['evidence_reference' => 'RECONCILIATION-001',
            'reason' => 'Full saved COD has both actual handover receipts at the platform.']);
        $account->refresh();
        $before = $this->snapshot();
        foreach (['model-update', 'model-delete', 'query-update', 'query-delete'] as $operation) {
            try {
                match ($operation) {
                    'model-update' => $account->update(['version' => 999]),
                    'model-delete' => $account->delete(),
                    'query-update' => DB::table('cod_accounts')->where('id', $account->id)->update(['version' => 999]),
                    default => DB::table('cod_accounts')->where('id', $account->id)->delete(),
                };
                $this->fail('A reconciled cash record must be retained.');
            } catch (LogicException|QueryException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_projection_tampering_cannot_create_a_platform_receipt(): void
    {
        $account = $this->collected();
        $admin = $this->createApprovedUser('admin');
        $state = $account->state;
        $state['balances'] = ['platform:'.$admin->id => $account->expected_cents];
        DB::table('cod_accounts')->where('id', $account->id)->update(['state' => json_encode($state)]);
        $before = $this->snapshot();
        $this->actingAs($admin)->postJson('/cash-handover/'.$account->id.'/reconcile', $this->input($account,
            ['evidence_reference' => 'REVIEW-001', 'reason' => 'Review the actual recorded platform receipts.']))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $this->getJson('/admin/cod/'.$account->id)->assertOk()->assertJsonPath('account.status', 'cash_held');
    }

    public function test_legacy_paid_or_completed_flags_only_record_a_review_and_never_invent_cash(): void
    {
        $order = $this->newFlowOrder();
        $order->update(['payment_status' => 'paid', 'status' => 'completed']);
        $parcel = $order->delivery;
        $admin = $this->createApprovedUser('admin');
        $input = ['expected_status' => $parcel->status, 'request_token' => (string) Str::uuid(),
            'reason' => 'The old paid label has no reliable collection or handover receipts.', 'evidence_reference' => 'LEGACY-REVIEW-001'];
        $this->actingAs($admin)->getJson('/admin/cod')->assertOk()->assertJsonCount(1, 'legacy');
        $this->postJson('/admin/cod/legacy/'.$parcel->id, $input)->assertOk();
        $before = $this->snapshot();
        $this->postJson('/admin/cod/legacy/'.$parcel->id, $input)->assertOk();
        $this->assertSame($before, $this->snapshot());
        $account = CodAccount::sole();
        $this->assertSame(0, $account->state['collected_cents']);
        $this->assertSame([], $account->state['balances']);
        $this->postJson('/admin/cod/'.$account->id.'/reconcile', $this->input($account,
            ['evidence_reference' => 'REVIEW-001', 'reason' => 'A paid flag is insufficient evidence of cash.']))->assertConflict();
        $this->assertDatabaseCount('commission_ledgers', 0);
    }

    public function test_cash_pages_and_notices_do_not_expose_collection_tokens_or_private_handoff_evidence(): void
    {
        $account = $this->collected();
        $rider = User::findOrFail($account->collector_id);
        $event = $account->events()->sole();
        $response = $this->actingAs($rider)->getJson('/courier/cod/'.$account->id)->assertOk();
        foreach ([$event->request_token, $event->request_fingerprint, $event->private_evidence['proof_hash'], $account->delivery->proof_image] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $intents = NotificationDelivery::where('type', 'cod-event')->get();
        $this->assertNotEmpty($intents);
        foreach ($intents as $intent) {
            $this->assertSame('cod-cash', $intent->data['target']);
            $this->assertArrayNotHasKey('private_evidence', $intent->data);
            $this->assertStringNotContainsString($event->request_token, json_encode($intent->data));
        }
    }
}
