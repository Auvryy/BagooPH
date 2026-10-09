<?php

namespace Tests\Feature\Courier;

use App\Models\CodAccount;
use App\Models\DeliveryAttempt;
use App\Models\Message;
use App\Models\User;
use App\Services\Finance\CodCashService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\RiderAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;

class RiderConnectedApiTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    private function native(User $rider): void
    {
        $this->withToken(app(RiderAccountService::class)->session($rider, 'Connected test')['token']);
    }

    private function key(): string
    {
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key);

        return $key;
    }

    private function finalParcel(string $stage = 'out_for_delivery'): array
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), $stage);
        $rider = User::findOrFail($parcel->assigned_rider_id);
        $this->native($rider);

        return [$parcel, $rider];
    }

    public function test_history_filters_use_manila_assignment_days_and_keep_stable_pagination(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 23:59:59', 'Asia/Manila'));
        $first = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $this->travelTo(Carbon::parse('2026-10-09 00:00:01', 'Asia/Manila'));
        $second = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = User::findOrFail($second->courier_id);
        $this->native($rider);
        $this->getJson('/api/v1/rider/trips?phase=pickup&payment=cod&from_date=2026-10-09&to_date=2026-10-09')
            ->assertOk()->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.items.0.delivery_id', (string) $second->id);
        $response = $this->getJson('/api/v1/rider/trips?per_page=1')->assertOk()->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.delivery_id', (string) $second->id);
        $this->getJson('/api/v1/rider/trips?per_page=1&page=2')->assertOk()->assertJsonPath('data.items.0.delivery_id', (string) $first->id);
        $this->getJson('/api/v1/rider/trips?payment=prepaid')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->getJson('/api/v1/rider/trips?to_date=2026-10-08')->assertOk()->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.delivery_id', (string) $first->id);
        $this->getJson('/api/v1/rider/trips?from_date=2026-10-09&to_date=2026-10-08')->assertUnprocessable();
        $this->getJson('/api/v1/rider/trips?q='.$first->tracking_number)->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/rider/trips?from_date=2026-02-30')->assertUnprocessable();
        $this->getJson('/api/v1/rider/trips?per_page=51')->assertUnprocessable();
        $this->assertNull($response->json('data.items.0.collected_cents'));
        $this->assertNull($response->json('data.items.0.earnings.confirmed_cents'));
    }

    public function test_actual_hub_retry_reassignment_retains_original_rider_history_and_private_attempt(): void
    {
        [$parcel, $rider] = $this->finalParcel();
        $assignment = $parcel->checkpoints()->where('checkpoint_type', 'assigned_to_rider')->sole();
        $oldThread = $this->getJson('/api/v1/rider/conversations')->assertOk()->json('data.items.0.id');
        $this->reportFlowFailure($parcel);
        $this->receiveFailureFlow($parcel);
        $hub = $parcel->destinationBayanHub;
        $attempt = DeliveryAttempt::where('delivery_id', $parcel->id)->sole();
        $this->actingAs($hub->company->user)->post(route('hub.recovery.retry', $parcel), [
            'retry_at' => now('Asia/Manila')->addMinutes(5)->format('Y-m-d\TH:i'), 'notes' => 'The buyer is ready at the same saved destination.',
            'request_token' => (string) Str::uuid(), 'attempt_reference' => $attempt->reference,
        ])->assertSessionHas('success');
        $this->travel(6)->minutes();
        $handler = $this->flowHandler($hub);
        $this->actingAs($handler)->postJson(route('hub.scan'), ['barcode' => $parcel->tracking_number, 'hub_id' => $hub->id,
            'mode' => 'confirm', 'action' => 'RELEASE_APPROVED_RETRY', 'expected_status' => 'delivery_failed', 'attempt_reference' => $attempt->reference])->assertOk();
        $this->postJson(route('hub.sort'), ['delivery_id' => $parcel->id, 'barangay' => $parcel->order->destination_barangay])->assertOk();
        $newRider = $this->flowRider($hub, $this->createApprovedUser('courier'));
        $this->postJson(route('hub.assignRider', $parcel), ['rider_id' => $newRider->id])->assertOk();
        $this->native($rider);
        $this->getJson('/api/v1/rider/tasks?phase=final_mile')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $detail = $this->getJson('/api/v1/rider/trips/trip-'.$assignment->id)->assertOk()->assertJsonPath('data.outcome', 'delivery_failed')
            ->assertJsonPath('data.current_operational_stage', 'assigned_to_rider')->assertJsonPath('data.attempts.0.id', (string) $attempt->id);
        $proof = $detail->json('data.attempts.0.proof_url');
        $this->get($proof)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->key();
        $this->postJson('/api/v1/rider/conversations/'.$oldThread.'/messages', ['text' => 'This draft must stay with its original thread.'])->assertNotFound();
        $this->assertStringNotContainsString('delivery-attempt-proofs/', $detail->getContent());
        $this->native($newRider);
        $this->getJson('/api/v1/rider/trips/trip-'.$assignment->id)->assertNotFound();
        $this->get($proof)->assertNotFound();
    }

    public function test_delivered_proof_is_owned_parent_scoped_and_hash_verified(): void
    {
        [$parcel, $rider] = $this->finalParcel('delivered');
        $assignment = $parcel->checkpoints()->where('checkpoint_type', 'assigned_to_rider')->sole();
        $handoff = $parcel->checkpoints()->where('checkpoint_type', 'delivered')->sole();
        $detail = $this->getJson('/api/v1/rider/trips/trip-'.$assignment->id)->assertOk();
        $proof = collect($detail->json('data.checkpoints'))->firstWhere('kind', 'delivered')['proof_url'];
        $this->assertStringStartsWith('/api/v1/rider/trips/', $proof);
        $this->assertStringNotContainsString('delivery-proofs/', $detail->getContent());
        $this->get($proof)->assertOk();
        $this->get('/api/v1/rider/trips/trip-'.$assignment->id.'/checkpoints/9223372036854775808/proof')->assertNotFound();
        $replacement = UploadedFile::fake()->image('different.png', 24, 24);
        Storage::disk('local')->put($handoff->proof_image, file_get_contents($replacement->getRealPath()));
        $this->get($proof)->assertConflict()->assertJsonPath('code', 'EVIDENCE_CHANGED');
        $other = $this->createApprovedUser('courier');
        $this->native($other);
        $this->get($proof)->assertNotFound();
    }

    public function test_message_reads_cover_only_the_selected_displayed_boundary_and_sends_replay(): void
    {
        $parcel = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = User::findOrFail($parcel->courier_id);
        $seller = $parcel->order->items->first()->product->shop->user;
        $first = Message::create(['sender_id' => $seller->id, 'receiver_id' => $rider->id, 'order_id' => $parcel->order_id, 'message' => 'The parcel is ready.', 'is_read' => false]);
        $second = Message::create(['sender_id' => $seller->id, 'receiver_id' => $rider->id, 'order_id' => $parcel->order_id, 'message' => 'Please use the front entrance.', 'is_read' => false]);
        $this->native($rider);
        $thread = $this->getJson('/api/v1/rider/conversations')->assertOk()->assertJsonPath('data.items.0.unread_count', 2)->json('data.items.0.id');
        $this->getJson('/api/v1/rider/conversations/'.$thread.'/messages?per_page=1')->assertOk()->assertJsonPath('data.items.0.id', (string) $second->id);
        $this->key();
        $this->postJson('/api/v1/rider/conversations/'.$thread.'/read', ['through_message_id' => (string) $first->id])->assertOk();
        $this->assertTrue($first->fresh()->is_read);
        $this->assertFalse($second->fresh()->is_read);
        $key = $this->key();
        $sent = $this->postJson('/api/v1/rider/conversations/'.$thread.'/messages', ['text' => 'I am at the store entrance.'])->assertOk();
        $id = $sent->json('data.result.id');
        $this->postJson('/api/v1/rider/conversations/'.$thread.'/messages', ['text' => 'I am at the store entrance.'])->assertOk()->assertJsonPath('data.replayed', true);
        $this->getJson('/api/v1/rider/commands/'.$key)->assertOk()->assertJsonPath('data.result.id', $id);
        $this->assertSame(1, Message::whereKey($id)->count());
        $this->assertSame($seller->id, Message::findOrFail($id)->receiver_id);
        $this->postJson('/api/v1/rider/conversations/'.$thread.'/messages', ['text' => 'Changed intent.'])->assertConflict();
        $this->key();
        $this->postJson('/api/v1/rider/conversations/'.$thread.'/messages', ['text' => '<script>bad</script>'])->assertUnprocessable();
        $this->postJson('/api/v1/rider/conversations/'.$thread.'/messages', ['text' => 'Hello there', 'receiver_id' => $parcel->order->buyer_id])->assertUnprocessable();
    }

    public function test_committed_notices_read_only_displayed_ids_and_leave_a_new_arrival_unread(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 10:00:00', 'Asia/Manila'));
        $first = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        $rider = User::findOrFail($first->courier_id);
        app(NotificationDeliveryService::class)->deliverPending();
        $this->native($rider);
        $seen = $this->getJson('/api/v1/rider/notifications')->assertOk()->json('data.items.0.id');
        $this->assertNotNull($seen);
        $second = $this->flowDelivery($this->newFlowOrder(), 'assigned_pickup');
        app(NotificationDeliveryService::class)->deliverPending();
        $this->native($rider);
        $this->key();
        $this->postJson('/api/v1/rider/notifications/read-through', ['displayed_ids' => [$seen]])->assertOk();
        $notices = $this->getJson('/api/v1/rider/notifications')->assertOk()->assertJsonPath('data.unread_count', 1);
        $unseen = collect($notices->json('data.items'))->firstWhere('id', '!=', $seen);
        $this->assertNull($unseen['read_at']);
        $this->assertStringStartsWith('/api/v1/rider/tasks/', $unseen['target']['href']);
        $other = $this->createApprovedUser('courier');
        $this->native($other);
        $this->key();
        $this->postJson('/api/v1/rider/notifications/'.$seen.'/read', [])->assertNotFound();
        $this->assertSame(0, $other->notifications()->count());
    }

    public function test_cash_offer_and_actual_hub_platform_receipts_are_separate_from_earnings(): void
    {
        [$parcel, $rider] = $this->finalParcel('delivered');
        $cash = CodAccount::where('delivery_id', $parcel->id)->sole();
        $handler = $this->flowHandler($parcel->destinationBayanHub);
        $due = (string) $cash->expected_cents;
        $this->getJson('/api/v1/rider/cash/'.$cash->id)->assertOk()->assertJsonPath('data.own_held_cents', $due)
            ->assertJsonPath('data.collected_cents', $due)->assertJsonPath('data.earnings.confirmed_cents', null);
        $body = ['expected_version' => (string) $cash->version, 'recipient_id' => (string) $handler->id,
            'amount' => (string) $parcel->order->total_amount, 'evidence_reference' => 'RIDER-CASH-001'];
        $this->key();
        $offer = $this->postJson('/api/v1/rider/cash/'.$cash->id.'/offer', $body)->assertOk()->assertJsonPath('data.result.handover_state', 'awaiting_recipient_receipt');
        $this->postJson('/api/v1/rider/cash/'.$cash->id.'/offer', $body)->assertOk()->assertJsonPath('data.replayed', true);
        $this->getJson('/api/v1/rider/cash/'.$cash->id)->assertOk()->assertJsonPath('data.own_held_cents', $due)->assertJsonPath('data.own_remitted_cents', '0');
        $this->postJson('/api/v1/rider/cash/'.$cash->id.'/receive', [])->assertNotFound();
        $service = app(CodCashService::class);
        $command = fn ($actor, $action, $input) => $service->command($actor, $cash->fresh(), $action, $input + [
            'expected_version' => $cash->fresh()->version, 'request_token' => (string) Str::uuid(), 'evidence_reference' => 'CASH-RECEIPT-001',
            'reason' => 'Counted cash matches the recorded source responsibility.', 'receipt_confirmed' => true]);
        $command($handler, 'receive', ['offer_reference' => $offer->json('data.result.event_reference'), 'received_amount' => (string) $parcel->order->total_amount]);
        $this->getJson('/api/v1/rider/cash/'.$cash->id)->assertOk()->assertJsonPath('data.own_held_cents', '0')->assertJsonPath('data.own_remitted_cents', $due)
            ->assertJsonPath('data.reconciled_cents', '0');
        $admin = $this->createApprovedUser('admin');
        $nextOffer = $command($handler, 'offer', ['holder_id' => $handler->id, 'recipient_id' => $admin->id, 'amount' => (string) $parcel->order->total_amount]);
        $command($admin, 'receive', ['offer_reference' => $nextOffer->reference, 'received_amount' => (string) $parcel->order->total_amount]);
        $command($admin, 'reconcile', []);
        $this->getJson('/api/v1/rider/cash/'.$cash->id)->assertOk()->assertJsonPath('data.status', 'reconciled')->assertJsonPath('data.reconciled_cents', $due);
        $this->getJson('/api/v1/rider/cash')->assertOk()->assertJsonPath('data.summary.own_held_cents', '0')->assertJsonPath('data.earnings.available', false);
        $this->assertSame('delivered', $parcel->order->fresh()->status);
        $other = $this->createApprovedUser('courier');
        $this->native($other);
        $this->getJson('/api/v1/rider/cash/'.$cash->id)->assertNotFound();
    }
}
