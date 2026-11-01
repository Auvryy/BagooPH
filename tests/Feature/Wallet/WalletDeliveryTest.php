<?php

namespace Tests\Feature\Wallet;

use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletHandoverReceipt;
use App\Services\Courier\CourierProofService;
use App\Services\Courier\RiderTaskService;
use App\Services\Logistics\PickupClaimService;
use App\Services\Orders\OrderTrackingService;
use App\Services\RiderAccountService;
use App\Services\Wallet\WalletCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class WalletDeliveryTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function paidOrder(bool $pickup = false): Order
    {
        $buyer = $this->createApprovedUser('buyer');
        $shop = $this->createE2EShop($this->createApprovedUser('seller'));
        $this->flowNetwork($shop);
        $shipping = ['payment_method' => 'wallet'];
        if ($pickup) {
            $hub = LogisticsHub::where('tier', 'local_bayan_hub')->where('city_municipality', 'Santa Cruz')->sole();
            $hub->update(['allows_self_pickup' => true]);
            $this->actingAs($hub->company->user)->postJson('/hub/counter/hours', ['hub_id' => $hub->id, 'operating_hours' => 'Monday to Saturday, 08:00 to 17:00'])->assertOk();
            $shipping += ['delivery_type' => 'hub_self_pickup', 'pickup_hub_id' => $hub->id];
        }
        $order = $this->checkoutFlowOrder($buyer, $shop, shipping: $shipping);
        $credits = app(WalletCreditService::class);
        $request = $credits->request($buyer, ['amount' => '10000.00', 'reason' => 'Demo credits for an actual recorded delivery.', 'request_token' => (string) Str::uuid()]);
        $credits->review($this->createApprovedUser('admin'), $request, ['decision' => 'approved', 'reason' => 'Approved demo credits for a delivery acceptance check.', 'demo_credit_confirmed' => true, 'request_token' => (string) Str::uuid()]);
        $this->actingAs($buyer)->postJson('/wallet/payments/'.$order->walletPayment->id.'/pay', ['payment_confirmed' => true, 'request_token' => (string) Str::uuid()])->assertOk()->assertJsonPath('outcome', 'paid');

        return $order->fresh(['buyer', 'items', 'delivery']);
    }

    public function test_wallet_delivery_retains_every_hub_and_buyer_receipt_without_cod_collection(): void
    {
        $order = $this->paidOrder();
        $parcel = $this->flowDelivery($order, 'delivered');
        $this->assertDatabaseCount('wallet_handover_receipts', 1);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('cod_custody_entries', 0);
        $this->assertDatabaseCount('commission_ledgers', 0);
        $this->assertTrue($parcel->checkpoints()->where('checkpoint_type', 'arrived_at_mother_hub')->exists());
        $this->assertSame('completed', $order->fresh()->status);
        $this->actingAs($order->buyer)->post('/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_prepaid_delivery_replay_retains_the_original_recipient_and_rejects_a_different_one(): void
    {
        $order = $this->paidOrder();
        $parcel = $this->flowDelivery($order, 'delivered');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $payload = ['status' => 'delivered', ...$this->codCollectionInput($parcel)];
        $this->actingAs($rider)->patch(route('courier.updateStatus', $parcel), $payload)->assertSessionHas('success');
        $this->patch(route('courier.updateStatus', $parcel), [...$payload, 'recipient_name' => 'Another Recipient'])->assertConflict();
        $this->assertSame(1, $parcel->checkpoints()->where('checkpoint_type', 'delivered')->count());
        $this->assertDatabaseCount('wallet_handover_receipts', 1);
        $this->assertDatabaseCount('cod_accounts', 0);
    }

    public function test_a_paid_wallet_parcel_rejects_cash_collection_instead_of_recording_fake_cash(): void
    {
        $order = $this->paidOrder();
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $this->actingAs(User::findOrFail($parcel->assigned_rider_id))->patch(route('courier.updateStatus', $parcel), [
            'status' => 'delivered', ...$this->codCollectionInput($parcel, ['cash_received' => (string) $order->total_amount, 'cash_confirmed' => true]),
            'proof_image_file' => UploadedFile::fake()->image('wallet-handover.png'),
        ])->assertUnprocessable();
        $this->assertSame('out_for_delivery', $parcel->fresh()->status);
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('wallet_handover_receipts', 0);
    }

    public function test_native_wallet_handover_uses_the_same_paid_source_and_needs_no_cash_fields(): void
    {
        Storage::fake('local');
        $order = $this->paidOrder();
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $this->assertStringContainsString('no cash is due', app(OrderTrackingService::class)->present($order->fresh())['next_step']);
        $this->withToken(app(RiderAccountService::class)->session($rider, 'Wallet handover test')['token']);
        $this->getJson('/api/v1/rider/tasks/final_mile-'.$parcel->id)->assertOk()->assertJsonPath('data.actions.deliver', true)
            ->assertJsonPath('data.payment.method', 'wallet')->assertJsonPath('data.payment.cod_due_cents', null)
            ->assertJsonPath('data.next_instruction', 'Record recipient handoff and private proof. This paid Wallet order needs no cash collection. Valid evidence completes the order automatically.');
        $this->withHeader('Idempotency-Key', (string) Str::uuid());
        $body = [
            'expected_version' => app(RiderTaskService::class)->version($parcel->fresh(['order'])), 'barcode' => $parcel->tracking_number,
            'recipient_name' => $order->recipient_name, 'recipient_relationship' => 'buyer', 'proof_image_file' => UploadedFile::fake()->image('wallet-proof.png'),
        ];
        $url = '/api/v1/rider/tasks/final_mile-'.$parcel->id.'/deliver';
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.result.operational_stage', 'delivered')
            ->assertJsonPath('data.result.commercial_status', 'completed');
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('wallet_handover_receipts', 1);
        $this->assertSame('completed', $order->fresh()->status);

        $receipt = WalletHandoverReceipt::where('delivery_id', $parcel->id)->sole();
        $original = $receipt->getAttributes();
        $order->update(['recipient_name' => 'Later Address Contact']);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($original, $receipt->fresh()->getAttributes());
        $assignment = $parcel->checkpoints()->where('checkpoint_type', 'assigned_to_rider')->sole();
        $trip = '/api/v1/rider/trips/trip-'.$assignment->id;
        $this->getJson('/api/v1/rider/trips?phase=final_mile&payment=prepaid')->assertOk()
            ->assertJsonPath('data.items.0.recipient', $body['recipient_name']);
        $detail = $this->getJson($trip)->assertOk()->assertJsonPath('data.recipient', $body['recipient_name'])
            ->assertJsonPath('data.collected_cents', null)->assertJsonPath('data.commercial_status', 'completed');
        $proof = collect($detail->json('data.checkpoints'))->firstWhere('kind', 'delivered')['proof_url'];
        $image = $this->get($proof)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', $image->headers->get('Cache-Control'));
        $handoff = DeliveryCheckpoint::findOrFail($receipt->delivery_checkpoint_id);
        $this->assertSame(Storage::disk('local')->get($handoff->proof_image), $image->streamedContent());
        foreach (['proof_hash', 'request_token_hash', 'fingerprint', 'delivery-proofs/'] as $private) {
            $this->assertStringNotContainsString($private, $detail->getContent());
        }
        $this->assertSame(1, $parcel->checkpoints()->where('checkpoint_type', 'delivered')->count());
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('wallet_handover_receipts', 1);
        $this->withToken(app(RiderAccountService::class)->session($this->createApprovedUser('courier'), 'Unrelated trip test')['token']);
        $this->getJson($trip)->assertNotFound();
        $this->get($proof)->assertNotFound();
    }

    public static function mismatchedReceipt(): array
    {
        return [['missing'], ['actor'], ['delivery'], ['checkpoint'], ['unsafe_path']];
    }

    #[DataProvider('mismatchedReceipt')]
    public function test_wallet_history_requires_an_original_receipt_for_the_same_actor_delivery_and_checkpoint(string $mismatch): void
    {
        $order = $this->paidOrder();
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $path = app(CourierProofService::class)->store(UploadedFile::fake()->image('unreceipted.png'));
        // Incomplete imported history must not gain proof access from an unrelated receipt.
        $handoff = DeliveryCheckpoint::record($parcel, 'delivered', actor: $rider,
            proofImage: $mismatch === 'unsafe_path' ? '../'.$path : $path,
            barcodeScanned: $parcel->tracking_number);
        if ($mismatch !== 'missing') {
            WalletHandoverReceipt::create(['delivery_id' => $mismatch === 'delivery' ? $this->newFlowOrder()->delivery->id : $parcel->id,
                'delivery_checkpoint_id' => $mismatch === 'checkpoint' ? $parcel->checkpoints()->where('checkpoint_type', 'out_for_delivery')->sole()->id : $handoff->id,
                'wallet_payment_id' => $order->walletPayment->id,
                'actor_id' => $mismatch === 'actor' ? $this->createApprovedUser('courier')->id : $rider->id,
                'recipient_name' => 'Unrelated Receipt Recipient', 'recipient_relationship' => 'buyer',
                'proof_hash' => app(CourierProofService::class)->hash($path),
                'request_token_hash' => hash('sha256', (string) Str::uuid()), 'fingerprint' => hash('sha256', 'unrelated receipt'), 'created_at' => now()]);
        }
        $this->withToken(app(RiderAccountService::class)->session($rider, 'Incomplete evidence test')['token']);
        $assignment = $parcel->checkpoints()->where('checkpoint_type', 'assigned_to_rider')->sole();
        $trip = '/api/v1/rider/trips/trip-'.$assignment->id;
        $detail = $this->getJson($trip)->assertOk()->assertJsonPath('data.collected_cents', null);
        if ($mismatch !== 'unsafe_path') {
            $detail->assertJsonPath('data.recipient', null);
        }
        $this->get($trip.'/checkpoints/'.$handoff->id.'/proof')->assertNotFound();
        $this->assertDatabaseCount('cod_accounts', 0);
    }

    public static function alteredWalletProof(): array
    {
        return [['image'], ['invalid_image'], ['missing']];
    }

    #[DataProvider('alteredWalletProof')]
    public function test_wallet_private_proof_rejects_changed_or_missing_original_content(string $change): void
    {
        $parcel = $this->flowDelivery($this->paidOrder(), 'delivered');
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $this->withToken(app(RiderAccountService::class)->session($rider, 'Proof integrity test')['token']);
        $assignment = $parcel->checkpoints()->where('checkpoint_type', 'assigned_to_rider')->sole();
        $handoff = $parcel->checkpoints()->where('checkpoint_type', 'delivered')->sole();
        if ($change === 'missing') {
            Storage::disk('local')->delete($handoff->proof_image);
        } else {
            $replacement = UploadedFile::fake()->image('replacement.png', 24, 24);
            Storage::disk('local')->put($handoff->proof_image, $change === 'image'
                ? file_get_contents($replacement->getRealPath()) : 'invalid image content');
        }
        $response = $this->get('/api/v1/rider/trips/trip-'.$assignment->id.'/checkpoints/'.$handoff->id.'/proof');
        if ($change === 'missing') {
            $response->assertNotFound();
        } else {
            $response->assertConflict()->assertJsonPath('code', 'EVIDENCE_CHANGED');
        }
    }

    public function test_wallet_retry_reassignment_keeps_private_evidence_in_the_original_assignment_window(): void
    {
        $order = $this->paidOrder();
        $parcel = $this->flowDelivery($order, 'out_for_delivery');
        $oldRider = User::findOrFail($parcel->assigned_rider_id);
        $oldAssignment = $parcel->checkpoints()->where('checkpoint_type', 'assigned_to_rider')->sole();
        $this->reportFlowFailure($parcel);
        $this->receiveFailureFlow($parcel);
        $hub = $parcel->destinationBayanHub;
        $attempt = DeliveryAttempt::where('delivery_id', $parcel->id)->sole();
        $this->actingAs($hub->company->user)->post(route('hub.recovery.retry', $parcel), [
            'retry_at' => now('Asia/Manila')->addMinutes(5)->format('Y-m-d\TH:i'),
            'notes' => 'The buyer is ready at the same saved destination.', 'request_token' => (string) Str::uuid(),
            'attempt_reference' => $attempt->reference,
        ])->assertSessionHas('success');
        $this->travel(6)->minutes();
        $this->actingAs($this->flowHandler($hub))->postJson(route('hub.scan'), ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id,
            'mode' => 'confirm', 'action' => 'RELEASE_APPROVED_RETRY', 'expected_status' => 'delivery_failed', 'attempt_reference' => $attempt->reference])->assertOk();
        $this->postJson(route('hub.sort'), ['delivery_id' => $parcel->id, 'barangay' => $order->destination_barangay])->assertOk();
        $newRider = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $this->postJson(route('hub.assignRider', $parcel), ['rider_id' => $newRider->id])->assertOk();
        $this->actingAs($newRider)->patch(route('courier.updateStatus', $parcel), [
            'status' => 'out_for_delivery', 'barcode' => $parcel->tracking_number,
        ])->assertSessionHas('success');
        $this->patch(route('courier.updateStatus', $parcel), ['status' => 'delivered', ...$this->codCollectionInput($parcel),
            'proof_image_file' => UploadedFile::fake()->image('second-rider-handoff.png'),
        ])->assertSessionHas('success');
        $this->withToken(app(RiderAccountService::class)->session($newRider, 'Reassigned Wallet history')['token']);
        $newAssignment = $parcel->checkpoints()->where('checkpoint_type', 'assigned_to_rider')->latest('id')->firstOrFail();
        $newTrip = '/api/v1/rider/trips/trip-'.$newAssignment->id;
        $newDetail = $this->getJson($newTrip)->assertOk()->assertJsonPath('data.recipient', $order->recipient_name);
        $handoff = collect($newDetail->json('data.checkpoints'))->firstWhere('kind', 'delivered');
        $this->get($handoff['proof_url'])->assertOk();
        $oldTrip = '/api/v1/rider/trips/trip-'.$oldAssignment->id;
        $this->getJson($oldTrip)->assertNotFound();
        $this->withToken(app(RiderAccountService::class)->session($oldRider, 'Original Wallet history')['token']);
        $oldDetail = $this->getJson($oldTrip)->assertOk()->assertJsonPath('data.recipient', null)->assertJsonPath('data.outcome', 'delivery_failed');
        $this->get($oldDetail->json('data.attempts.0.proof_url'))->assertOk();
        $this->getJson($newTrip)->assertNotFound();
        $this->get($handoff['proof_url'])->assertNotFound();
        $this->get($oldTrip.'/checkpoints/'.$handoff['id'].'/proof')->assertNotFound();
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('wallet_handover_receipts', 1);
    }

    public function test_wallet_counter_collection_keeps_identity_code_and_receipt_without_a_cash_receipt(): void
    {
        $order = $this->paidOrder(true);
        $parcel = $this->flowDelivery($order, 'arrived_at_destination_hub');
        $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $this->actingAs($handler)->postJson('/hub/scan', ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => 'STAGE_FOR_PICKUP', 'expected_status' => 'arrived_at_destination_hub'])->assertOk();
        $this->assertStringContainsString('requires no cash', app(OrderTrackingService::class)->present($order->fresh())['next_step']);
        $code = $this->actingAs($order->buyer)->postJson('/buyer/orders/'.$order->id.'/pickup-code')->assertOk()->json('code');
        $payload = ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id, 'claim_code' => $code,
            'buyer_id' => $order->buyer_id, 'recipient_name' => $order->buyer->name, 'identity_confirmed' => true,
            'request_token' => (string) Str::uuid(), 'cash_received' => '0', 'change_given' => '0', 'cash_confirmed' => false];
        $this->actingAs($handler)->postJson('/hub/release', $payload)->assertOk();
        $this->assertTrue(app(PickupClaimService::class)->hasCollectionEvidence($parcel->fresh(['order'])));
        $this->assertDatabaseCount('cod_accounts', 0);
        $this->assertDatabaseCount('cod_custody_entries', 0);
        $this->actingAs($order->buyer)->post('/buyer/orders/'.$order->id.'/confirm')->assertSessionHas('success');
        $this->assertSame('completed', $order->fresh()->status);
    }
}
