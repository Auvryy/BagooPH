<?php

namespace Tests\Feature\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\DeliveryRecoveryEvent;
use App\Models\LogisticsHub;
use App\Models\User;
use App\Services\Logistics\OrderStateMachineService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\E2E\Support\AssertsDeliveryCheckpoints;
use Tests\Feature\E2E\Support\CreatesE2EOrders;
use Tests\Feature\E2E\Support\InteractsWithOrderActions;
use Tests\Feature\E2E\Support\InteractsWithRoles;
use Tests\TestCase;
use Throwable;

class DeliveryAttemptRecoveryTest extends TestCase
{
    use AssertsDeliveryCheckpoints, CreatesE2EOrders, InteractsWithOrderActions, InteractsWithRoles, RefreshDatabase;

    public static function portals(): array
    {
        return ['root' => ['/courier', '/hub'], 'subdomain' => ['http://courier.localhost', 'http://hub.localhost']];
    }

    #[DataProvider('portals')]
    public function test_actual_failure_preserves_rider_custody_and_one_original_attempt(string $courier, string $hub): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        Storage::fake('local');
        $payload = $this->failure($delivery);
        $rider = User::findOrFail($delivery->assigned_rider_id);
        $this->actingAs($rider)->patch($courier.'/deliveries/'.$delivery->id.'/status', $payload)->assertSessionHasNoErrors()->assertSessionHas('success');
        $attempt = DeliveryAttempt::sole();
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame($rider->id, $attempt->rider_id);
        $this->assertSame('courier', $attempt->actor_role);
        $this->assertSame($delivery->tracking_number, $attempt->barcode_scanned);
        $this->assertTrue(Storage::disk('local')->exists($attempt->proof_path));
        $this->assertSame(['kind' => 'courier', 'user_id' => $rider->id], DeliveryCheckpoint::lastCustody($delivery->fresh()));
        $this->assertNull($delivery->fresh()->current_hub_id);
        $this->assertSame('delivery_failed', $delivery->fresh()->status);
        $before = $this->snapshot($delivery);
        $this->patch($courier.'/deliveries/'.$delivery->id.'/status', $payload)->assertSessionHas('success');
        $this->assertSame($before, $this->snapshot($delivery));
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->patch($courier.'/deliveries/'.$delivery->id.'/status', array_replace($payload, ['courier_notes' => 'Changed failure account.']))->assertSessionHas('error');
        $this->assertSame($before, $this->snapshot($delivery));
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $handler = $this->flowHandler(LogisticsHub::findOrFail($delivery->destination_bayan_hub_id));
        $this->actingAs($handler)->get($hub.'/delivery-attempts/'.$attempt->id.'/proof')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->createApprovedUser('logistics'))->get($hub.'/delivery-attempts/'.$attempt->id.'/proof')->assertForbidden();
    }

    public static function invalidFailures(): array
    {
        return ['missing reason' => [['failure_reason' => null]], 'unknown reason' => [['failure_reason' => 'other']],
            'blank notes' => [['courier_notes' => ' ']], 'markup notes' => [['courier_notes' => '<b>Attempt</b>']],
            'control notes' => [['courier_notes' => "Attempt\0"]], 'long notes' => [['courier_notes' => str_repeat('A', 501)]],
            'missing barcode' => [['barcode' => null]], 'control barcode' => [['barcode' => "\tBGO-1"]],
            'wrong barcode' => [['barcode' => 'BGO-OTHER']], 'missing proof' => [['proof_image_file' => null]],
            'forged proof reference' => [['proof_image_file' => null, 'proof_path' => 'delivery-attempt-proofs/other.jpg']],
            'bad token' => [['request_token' => 'other']]];
    }

    #[DataProvider('invalidFailures')]
    public function test_invalid_failure_preserves_all_parcel_and_attempt_evidence(array $invalid): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        Storage::fake('local');
        $before = $this->snapshot($delivery);
        $response = $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch('/courier/deliveries/'.$delivery->id.'/status', array_replace($this->failure($delivery), $invalid));
        $response->assertSessionHasErrors();
        $this->assertSame($before, $this->snapshot($delivery));
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_another_rider_cannot_report_the_assigned_parcel(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        Storage::fake('local');
        $other = $this->flowRider(LogisticsHub::findOrFail($delivery->destination_bayan_hub_id), $this->createApprovedUser('courier'));
        $before = $this->snapshot($delivery);
        $this->actingAs($other)->patch('/courier/deliveries/'.$delivery->id.'/status', $this->failure($delivery))->assertSessionHas('error');
        $this->assertSame($before, $this->snapshot($delivery));
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    #[DataProvider('portals')]
    public function test_retry_requires_actual_return_review_due_date_sort_assignment_and_new_departure(string $courier, string $hubPrefix): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        Storage::fake('local');
        $rider = User::findOrFail($delivery->assigned_rider_id);
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $manager = $hub->company->user;
        $handler = $this->flowHandler($hub);
        $stock = $delivery->order->items->first()->product->fresh()->stock;
        for ($number = 1; $number <= 3; $number++) {
            $this->actingAs($rider)->patch($courier.'/deliveries/'.$delivery->id.'/status', $this->failure($delivery))->assertSessionHasNoErrors()->assertSessionHas('success');
            $this->assertSame($number, $delivery->fresh()->failure_attempts);
            $this->assertSame('delivery_failed', $delivery->fresh()->status);
            $this->assertFalse($delivery->fresh()->canReattempt());
            $retry = ['retry_at' => now('Asia/Manila')->addMinutes(5)->format('Y-m-d\TH:i'), 'notes' => 'The buyer confirmed availability at the same address.', 'request_token' => (string) Str::uuid()];
            $retry['attempt_reference'] = DeliveryAttempt::where('delivery_id', $delivery->id)->latest('attempt_number')->firstOrFail()->reference;
            $before = $this->snapshot($delivery);
            $this->actingAs($manager)->post($hubPrefix.'/delivery-recovery/'.$delivery->id.'/retry', $retry)->assertSessionHas('error');
            $this->assertSame($before, $this->snapshot($delivery));
            $this->scan($delivery, $handler, $hub, 'RECEIVE_FAILED_DELIVERY', $hubPrefix);
            $this->assertSame($hub->id, $delivery->fresh()->current_hub_id);
            $this->assertSame('hub', DeliveryCheckpoint::lastCustody($delivery->fresh())['kind']);
            $this->assertSame('delivery_failed', $delivery->order->fresh()->status);
            if ($number === 3) {
                $this->assertSame('return_to_sender', $delivery->fresh()->status);
                $before = $this->snapshot($delivery);
                $this->actingAs($manager)->post($hubPrefix.'/delivery-recovery/'.$delivery->id.'/retry', $retry)->assertSessionHas('error');
                $this->actingAs($rider)->patch($courier.'/deliveries/'.$delivery->id.'/status', $this->failure($delivery))->assertSessionHas('error');
                $this->assertSame($before, $this->snapshot($delivery));
                break;
            }
            $this->actingAs($manager)->post($hubPrefix.'/delivery-recovery/'.$delivery->id.'/retry', $retry)->assertSessionHasNoErrors()->assertSessionHas('success');
            $before = $this->snapshot($delivery);
            $this->post($hubPrefix.'/delivery-recovery/'.$delivery->id.'/retry', $retry)->assertSessionHas('success');
            $this->assertSame($before, $this->snapshot($delivery));
            $this->actingAs($handler)->postJson($hubPrefix.'/scan', ['barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm', 'action' => 'RELEASE_APPROVED_RETRY', 'expected_status' => 'delivery_failed'])->assertConflict();
            $this->assertSame($before, $this->snapshot($delivery));
            $this->travel(6)->minutes();
            $this->assertTrue($delivery->fresh()->canReattempt());
            $this->scan($delivery, $handler, $hub, 'RELEASE_APPROVED_RETRY', $hubPrefix);
            $this->assertNull($delivery->fresh()->assigned_rider_id);
            $this->actingAs($handler)->postJson($hubPrefix.'/sort', ['delivery_id' => $delivery->id, 'barangay' => $delivery->order->destination_barangay])->assertOk();
            $this->postJson($hubPrefix.'/deliveries/'.$delivery->id.'/assign-rider', ['rider_id' => $rider->id])->assertOk();
            $this->actingAs($rider)->patch($courier.'/deliveries/'.$delivery->id.'/status', ['status' => 'out_for_delivery', 'barcode' => $delivery->tracking_number])->assertSessionHas('success');
        }
        $this->assertSame(3, DeliveryAttempt::count());
        $this->assertSame(3, DeliveryRecoveryEvent::where('event_type', 'hub_return')->count());
        $this->assertSame(2, DeliveryRecoveryEvent::where('event_type', 'retry_approved')->count());
        $this->assertSame(2, DeliveryRecoveryEvent::where('event_type', 'retry_started')->count());
        $this->assertSame('pending', $delivery->order->fresh()->payment_status);
        $this->assertSame($stock, $delivery->order->items->first()->product->fresh()->stock);
    }

    public function test_customer_refusal_enters_reverse_queue_only_after_actual_hub_return(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($delivery, 'Customer refused');
        $this->assertSame('delivery_failed', $delivery->fresh()->status);
        $this->receiveFailureFlow($delivery);
        $this->assertSame('return_to_sender', $delivery->fresh()->status);
        $this->assertSame('delivery_failed', $delivery->order->fresh()->status);
        $this->assertFalse($delivery->fresh()->canReattempt());
    }

    public function test_unrecorded_direct_failure_transition_is_rejected(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $before = $this->snapshot($delivery);
        try {
            app(OrderStateMachineService::class)->transition($delivery, 'delivery_failed', User::findOrFail($delivery->assigned_rider_id), ['barcode' => $delivery->tracking_number, 'reason' => 'customer_unreachable']);
            $this->fail('A status-only failure must not be accepted.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('recorded attempt', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public static function rejectedReviews(): array
    {
        return ['past date' => ['past'], 'impossible date' => ['invalid'], 'stale attempt' => ['stale'],
            'handler approval' => ['handler'], 'foreign manager' => ['foreign'], 'buyer approval' => ['buyer']];
    }

    #[DataProvider('rejectedReviews')]
    public function test_invalid_or_unowned_retry_review_preserves_actual_return(string $case): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $this->receiveFailureFlow($delivery);
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $actor = match ($case) {
            'handler' => $this->flowHandler($hub), 'foreign' => $this->createApprovedUser('logistics'),
            'buyer' => $delivery->order->buyer, default => $hub->company->user,
        };
        $input = ['retry_at' => now('Asia/Manila')->addDay()->format('Y-m-d\TH:i'), 'notes' => 'Reviewed availability at the saved address.',
            'request_token' => (string) Str::uuid(), 'attempt_reference' => DeliveryAttempt::sole()->reference];
        if ($case === 'past') {
            $input['retry_at'] = now('Asia/Manila')->subMinute()->format('Y-m-d\TH:i');
        } elseif ($case === 'invalid') {
            $input['retry_at'] = '2026-02-30T09:00';
        } elseif ($case === 'stale') {
            $input['attempt_reference'] = 'DAT-'.Str::uuid();
        }
        $before = $this->snapshot($delivery);
        $response = $this->actingAs($actor)->post('/hub/delivery-recovery/'.$delivery->id.'/retry', $input);
        if ($case === 'invalid') {
            $response->assertSessionHasErrors('retry_at');
        } elseif ($case === 'buyer') {
            $response->assertForbidden();
        } else {
            $response->assertSessionHas('error');
        }
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_wrong_hub_and_stale_attempt_cannot_receive_the_failed_parcel(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $origin = LogisticsHub::findOrFail($delivery->origin_bayan_hub_id);
        $destination = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $before = $this->snapshot($delivery);
        $this->actingAs($this->flowHandler($origin))->postJson('/hub/scan', ['barcode' => $delivery->tracking_number, 'hub_id' => $origin->id, 'mode' => 'confirm',
            'action' => 'RECEIVE_FAILED_DELIVERY', 'expected_status' => 'delivery_failed', 'attempt_reference' => DeliveryAttempt::sole()->reference])->assertConflict();
        $this->actingAs($this->flowHandler($destination))->postJson('/hub/scan', ['barcode' => $delivery->tracking_number, 'hub_id' => $destination->id, 'mode' => 'confirm',
            'action' => 'RECEIVE_FAILED_DELIVERY', 'expected_status' => 'delivery_failed', 'attempt_reference' => 'DAT-'.Str::uuid()])->assertConflict();
        $this->assertSame($before, $this->snapshot($delivery));
        $this->receiveFailureFlow($delivery);
        $this->assertNull($delivery->fresh()->assigned_rider_id);
        $this->assertFalse(Delivery::riderHasActiveWork(DeliveryAttempt::sole()->rider_id));
    }

    public function test_actual_return_and_retry_start_are_idempotent_and_do_not_expose_private_paths(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $hub = LogisticsHub::findOrFail($delivery->destination_bayan_hub_id);
        $handler = $this->flowHandler($hub);
        $reference = DeliveryAttempt::sole()->reference;
        $receipt = ['barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => 'RECEIVE_FAILED_DELIVERY', 'expected_status' => 'delivery_failed', 'attempt_reference' => $reference];
        $this->actingAs($handler)->postJson('/hub/scan', $receipt)->assertOk();
        $before = $this->snapshot($delivery);
        $this->postJson('/hub/scan', $receipt)->assertOk();
        $this->assertSame($before, $this->snapshot($delivery));
        $retry = ['retry_at' => now('Asia/Manila')->addMinutes(2)->format('Y-m-d\TH:i'), 'notes' => 'Reviewed the same address and buyer availability.',
            'request_token' => (string) Str::uuid(), 'attempt_reference' => $reference];
        $this->actingAs($hub->company->user)->post('/hub/delivery-recovery/'.$delivery->id.'/retry', $retry)->assertSessionHas('success');
        $page = $this->get('/hub/delivery-recovery')->assertOk();
        $page->assertDontSee(DeliveryAttempt::sole()->proof_path)->assertDontSee(DeliveryAttempt::sole()->request_token);
        $this->travel(3)->minutes();
        $release = array_replace($receipt, ['action' => 'RELEASE_APPROVED_RETRY']);
        $this->actingAs($handler)->postJson('/hub/scan', $release)->assertOk();
        $before = $this->snapshot($delivery);
        $this->postJson('/hub/scan', $release)->assertOk();
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public function test_attempt_and_return_audit_failure_roll_back_custody_and_proof(): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        Storage::fake('local');
        $before = $this->snapshot($delivery);
        Event::listen('eloquent.creating: '.DeliveryCheckpoint::class, function ($record) {
            if ($record->checkpoint_type === 'delivery_failed') {
                throw new RuntimeException('Attempt audit unavailable.');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->actingAs(User::findOrFail($delivery->assigned_rider_id))->patch('/courier/deliveries/'.$delivery->id.'/status', $this->failure($delivery));
            $this->fail('Audit failure must roll back the attempt.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Attempt audit unavailable.', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot($delivery));
        $this->assertCount(0, Storage::disk('local')->allFiles());
        Event::forget('eloquent.creating: '.DeliveryCheckpoint::class);
        $this->withExceptionHandling();
        $this->reportFlowFailure($delivery);
        $before = $this->snapshot($delivery);
        Event::listen('eloquent.creating: '.DeliveryRecoveryEvent::class, fn () => throw new RuntimeException('Return audit unavailable.'));
        $this->withoutExceptionHandling();
        try {
            $this->receiveFailureFlow($delivery);
            $this->fail('Audit failure must roll back receipt.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Return audit unavailable.', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot($delivery));
    }

    public static function historyMutations(): array
    {
        return ['attempt SQL update' => ['delivery_attempts', 'update'], 'attempt SQL delete' => ['delivery_attempts', 'delete'],
            'return SQL update' => ['delivery_recovery_events', 'update'], 'return SQL delete' => ['delivery_recovery_events', 'delete']];
    }

    #[DataProvider('historyMutations')]
    public function test_attempt_and_recovery_history_cannot_be_rewritten(string $table, string $operation): void
    {
        $delivery = $this->flowDelivery($this->newFlowOrder(), 'out_for_delivery');
        $this->reportFlowFailure($delivery);
        $this->receiveFailureFlow($delivery);
        $before = $this->snapshot($delivery);
        try {
            DB::transaction(fn () => $operation === 'update' ? DB::table($table)->update(['notes' => 'Changed']) : DB::table($table)->delete());
            $this->fail('History cannot be rewritten.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot($delivery));
    }

    private function failure(Delivery $delivery): array
    {
        return ['status' => 'delivery_failed', 'failure_reason' => 'customer_unreachable', 'courier_notes' => 'Called at the saved address; the buyer could not be reached.',
            'location_name' => 'At the saved buyer address',
            'barcode' => $delivery->tracking_number, 'request_token' => (string) Str::uuid(), 'proof_image_file' => UploadedFile::fake()->image('attempt.jpg')];
    }

    private function scan(Delivery $delivery, User $handler, LogisticsHub $hub, string $action, string $prefix): void
    {
        $inspection = $this->actingAs($handler)->postJson($prefix.'/scan', ['barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'inspect'])
            ->assertOk()->assertJsonPath('prompt.action', $action)->assertJsonPath('prompt.requires_confirmation', true);
        $this->postJson($prefix.'/scan', ['barcode' => $delivery->tracking_number, 'hub_id' => $hub->id, 'mode' => 'confirm',
            'action' => $action, 'expected_status' => $inspection->json('prompt.expected_status'), 'attempt_reference' => $inspection->json('prompt.attempt_reference')])->assertOk()->assertJsonPath('confirmed', true);
    }

    private function snapshot(Delivery $delivery): array
    {
        return [$delivery->fresh()->getRawOriginal(), $delivery->order->fresh()->getRawOriginal(),
            $delivery->checkpoints()->orderBy('id')->get()->toArray(), DB::table('delivery_attempts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            DB::table('delivery_recovery_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }
}
