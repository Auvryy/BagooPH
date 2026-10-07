<?php

namespace App\Services\Logistics;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\DeliveryRecoveryEvent;
use App\Models\LogisticsHub;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class DeliveryRecoveryService
{
    public const REASONS = [
        'customer_unreachable' => 'Customer unreachable',
        'customer_unavailable' => 'Customer unavailable or requested another date',
        'address_clarification' => 'Wrong or incomplete address',
        'unsafe_conditions' => 'Unsafe conditions or severe weather',
        'cod_unavailable' => 'Exact COD payment unavailable',
        'customer_refused' => 'Customer refused the parcel',
    ];

    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    public function fail(Delivery $delivery, User $actor, array $input): Delivery
    {
        $inputs = app(WaybillScanInputService::class);
        $input = $inputs->normalize($input);
        Validator::make($input, [
            'barcode' => $inputs->barcodeRules(), 'reason' => ['required', Rule::in(array_keys(self::REASONS))],
            'notes' => ['required', ...$inputs->notesRules(500)], 'request_token' => ['required', 'uuid'],
            'location_name' => ['required', ...$inputs->notesRules(255)],
        ])->validate();
        $proof = $input['proof_path'] ?? null;
        if (! is_string($proof) || preg_match('/\Adelivery-attempt-proofs\/[A-Za-z0-9._-]+\z/', $proof) !== 1 || ! Storage::disk('local')->exists($proof)) {
            throw new DomainException('Upload an actual attempt proof image before reporting delivery failure.');
        }
        $proofHash = hash('sha256', Storage::disk('local')->get($proof));

        return DB::transaction(function () use ($delivery, $actor, $input, $proof, $proofHash, $inputs) {
            [$parcel, $actor] = $this->lock($delivery, $actor);
            $profile = CourierProfile::where('user_id', $actor->id)->lockForUpdate()->first();
            if (! $actor->isCourier()) {
                throw new DomainException('Only the assigned final-mile rider may report this attempt.');
            }
            $this->eligibility->assertCourierScope($profile, $parcel->logistics_company_id, $parcel->destination_bayan_hub_id);
            $barcode = $inputs->matchedBarcode($input['barcode'], $parcel);
            $fingerprint = $this->fingerprint([$actor->id, $barcode, $input['reason'], $input['notes'], $input['location_name'], $proofHash]);
            $existing = DeliveryAttempt::where('delivery_id', $parcel->id)->where('request_token', $input['request_token'])->first();
            if ($existing) {
                if ($existing->rider_id !== $actor->id || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This attempt request was already used with different evidence.');
                }

                return $parcel;
            }
            if ($parcel->assigned_rider_id !== $actor->id) {
                throw new DomainException('Only the assigned final-mile rider may report this attempt.');
            }
            $count = DeliveryAttempt::where('delivery_id', $parcel->id)->count();
            $departure = $parcel->checkpoints()->where('checkpoint_type', 'out_for_delivery')->latest('id')->first();
            $custody = DeliveryCheckpoint::lastCustody($parcel);
            if ($parcel->status !== 'out_for_delivery' || $parcel->order->status !== 'out_for_delivery'
                || $count >= 3 || $parcel->failure_attempts !== $count || ! $departure || ! $departure->barcode_scanned
                || $departure->scanned_by_id !== $actor->id || ($custody['kind'] ?? null) !== 'courier'
                || ($custody['user_id'] ?? null) !== $actor->id || DeliveryAttempt::where('departure_checkpoint_id', $departure->id)->exists()) {
                throw new DomainException('This parcel needs a current recorded delivery departure and consistent attempt history.');
            }
            $attempt = DeliveryAttempt::create([
                'delivery_id' => $parcel->id, 'rider_id' => $actor->id, 'departure_checkpoint_id' => $departure->id,
                'attempt_number' => $count + 1, 'reason_code' => $input['reason'], 'notes' => $input['notes'],
                'location_name' => $input['location_name'],
                'barcode_scanned' => $barcode, 'proof_path' => $proof, 'proof_hash' => $proofHash,
                'attempted_at' => now(), 'source_state' => DeliveryCheckpoint::state($parcel),
                'request_token' => $input['request_token'], 'request_fingerprint' => $fingerprint,
            ]);

            return app(OrderStateMachineService::class)->transition($parcel, 'delivery_failed', $actor, [
                'attempt_id' => $attempt->id, 'reason' => $attempt->reason_code, 'notes' => $attempt->notes,
                'barcode' => $barcode,
                'location_name' => $attempt->location_name,
            ]);
        });
    }

    public function receive(Delivery $delivery, User $actor, LogisticsHub $hub, string $barcode, string $expectedStatus, string $attemptReference): Delivery
    {
        return DB::transaction(function () use ($delivery, $actor, $hub, $barcode, $expectedStatus, $attemptReference) {
            [$parcel, $actor] = $this->lock($delivery, $actor);
            $this->assertHandler($parcel, $actor, $hub);
            $barcode = app(WaybillScanInputService::class)->matchedBarcode($barcode, $parcel, allowOrderNumber: true);
            $attempt = $this->latestAttempt($parcel);
            $this->assertAttemptReference($attempt, $attemptReference);
            $existing = $this->event($attempt, 'hub_return');
            if ($existing && $parcel->current_hub_id === $hub->id && $existing->actor_id === $actor->id) {
                return $parcel;
            }
            $custody = DeliveryCheckpoint::lastCustody($parcel);
            if ($expectedStatus !== 'delivery_failed' || $parcel->status !== $expectedStatus || $parcel->order->status !== 'delivery_failed'
                || $parcel->current_hub_id !== null || ($custody['kind'] ?? null) !== 'courier'
                || ($custody['user_id'] ?? null) !== $attempt->rider_id) {
                throw new DomainException('This failed attempt is not in the returning rider custody.');
            }
            $source = DeliveryCheckpoint::state($parcel);
            $parcel->current_hub_id = $hub->id;
            $parcel->assigned_rider_id = null;
            $parcel->assigned_at = null;
            if ($attempt->attempt_number === 3 || $attempt->reason_code === 'customer_refused') {
                $parcel->status = 'return_to_sender';
            }
            $parcel->save();
            $event = $this->record($parcel, $attempt, $actor, $hub, 'hub_return', $source, ['barcode_scanned' => $barcode]);
            DeliveryCheckpoint::record($parcel, 'failed_delivery_hub_return', actor: $actor, hub: $hub, barcodeScanned: $barcode,
                notes: 'Failed attempt returned to the destination hub. Evidence: '.$event->reference,
                evidence: ['source_state' => $source, 'target_state' => DeliveryCheckpoint::state($parcel),
                    'custody_before' => $custody, 'custody_after' => ['kind' => 'hub', 'hub_id' => $hub->id, 'recovery_reference' => $event->reference]]);

            return $parcel;
        });
    }

    public function approveRetry(Delivery $delivery, User $actor, array $input): Delivery
    {
        $inputs = app(WaybillScanInputService::class);
        $input = $inputs->normalize($input);
        Validator::make($input, ['notes' => ['required', ...$inputs->notesRules()], 'request_token' => ['required', 'uuid'],
            'retry_at' => ['required', 'date_format:Y-m-d\TH:i']])->validate();
        $retryAt = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $input['retry_at'], 'Asia/Manila')->utc();

        return DB::transaction(function () use ($delivery, $actor, $input, $retryAt) {
            [$parcel, $actor] = $this->lock($delivery, $actor);
            if (! $actor->isLogistics() || ! $this->eligibility->isCompanyAdministrator($actor, $parcel->logistics_company_id)) {
                throw new DomainException('Only the owning company administrator may approve a retry date.');
            }
            $existing = DeliveryRecoveryEvent::where('delivery_id', $parcel->id)->where('request_token', $input['request_token'])->first();
            if ($existing) {
                $fingerprint = $this->fingerprint([$actor->id, $existing->delivery_attempt_id, $input['notes'], $retryAt->toIso8601String()]);
                if ($existing->event_type !== 'retry_approved' || $existing->actor_id !== $actor->id || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This retry request was already used with different details.');
                }

                return $parcel;
            }
            $attempt = $this->latestAttempt($parcel);
            if (($input['attempt_reference'] ?? null) !== $attempt->reference) {
                throw new DomainException('The failed attempt changed. Refresh its evidence before approving a date.');
            }
            $fingerprint = $this->fingerprint([$actor->id, $attempt->id, $input['notes'], $retryAt->toIso8601String()]);
            $this->assertRetryCustody($parcel, $attempt);
            if ($retryAt->isPast() || $retryAt->equalTo(now()) || $this->event($attempt, 'retry_approved')) {
                throw new DomainException('Choose a future retry date; an approved attempt cannot be scheduled twice.');
            }
            $hub = LogisticsHub::findOrFail($parcel->destination_bayan_hub_id);
            $this->record($parcel, $attempt, $actor, $hub, 'retry_approved', DeliveryCheckpoint::state($parcel), [
                'notes' => $input['notes'], 'retry_at' => $retryAt, 'request_token' => $input['request_token'], 'request_fingerprint' => $fingerprint,
            ]);

            return $parcel;
        });
    }

    public function beginRetry(Delivery $delivery, User $actor, LogisticsHub $hub, string $barcode, string $expectedStatus, string $attemptReference): Delivery
    {
        return DB::transaction(function () use ($delivery, $actor, $hub, $barcode, $expectedStatus, $attemptReference) {
            [$parcel, $actor] = $this->lock($delivery, $actor);
            $this->assertHandler($parcel, $actor, $hub);
            $barcode = app(WaybillScanInputService::class)->matchedBarcode($barcode, $parcel, allowOrderNumber: true);
            $attempt = $this->latestAttempt($parcel);
            $this->assertAttemptReference($attempt, $attemptReference);
            $existing = $this->event($attempt, 'retry_started');
            if ($existing && $existing->actor_id === $actor->id) {
                return $parcel;
            }
            $this->assertRetryCustody($parcel, $attempt);
            $approved = $this->event($attempt, 'retry_approved');
            if ($expectedStatus !== 'delivery_failed' || ! $approved || $approved->retry_at->isFuture()) {
                throw new DomainException('This attempt needs an approved retry date that has arrived.');
            }
            $source = DeliveryCheckpoint::state($parcel);
            $custody = DeliveryCheckpoint::lastCustody($parcel);
            $parcel->status = 'arrived_at_destination_hub';
            $parcel->assigned_rider_id = null;
            $parcel->assigned_at = null;
            $parcel->save();
            $parcel->order->update(['status' => 'at_sorting_center']);
            $event = $this->record($parcel, $attempt, $actor, $hub, 'retry_started', $source, ['barcode_scanned' => $barcode]);
            DeliveryCheckpoint::record($parcel, 'delivery_rescheduled', actor: $actor, hub: $hub, barcodeScanned: $barcode,
                notes: 'Approved retry released for barangay sorting. Evidence: '.$event->reference,
                evidence: ['source_state' => $source, 'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custody, 'custody_after' => $custody]);

            return $parcel;
        });
    }

    public function latestAttempt(Delivery $delivery): DeliveryAttempt
    {
        $attempt = DeliveryAttempt::where('delivery_id', $delivery->id)->latest('attempt_number')->first();
        if (! $attempt || $delivery->failure_attempts !== $attempt->attempt_number) {
            throw new DomainException('The parcel needs consistent recorded attempt evidence before recovery.');
        }

        return $attempt;
    }

    public function event(DeliveryAttempt $attempt, string $type): ?DeliveryRecoveryEvent
    {
        return DeliveryRecoveryEvent::where('delivery_attempt_id', $attempt->id)->where('event_type', $type)->first();
    }

    private function assertRetryCustody(Delivery $parcel, DeliveryAttempt $attempt): void
    {
        $receipt = $this->event($attempt, 'hub_return');
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        if ($parcel->status !== 'delivery_failed' || $parcel->order->status !== 'delivery_failed'
            || $attempt->attempt_number >= 3 || $attempt->reason_code === 'customer_refused'
            || ! $receipt || $receipt->hub_id !== $parcel->destination_bayan_hub_id
            || $parcel->current_hub_id !== $parcel->destination_bayan_hub_id || ($custody['kind'] ?? null) !== 'hub'
            || ($custody['hub_id'] ?? null) !== $parcel->destination_bayan_hub_id || ($custody['recovery_reference'] ?? null) !== $receipt->reference) {
            throw new DomainException('Retry requires the real destination-hub return of a retryable first or second attempt.');
        }
    }

    private function assertHandler(Delivery $parcel, User $actor, LogisticsHub $hub): void
    {
        if (! $actor->isLogistics() || $hub->id !== $parcel->destination_bayan_hub_id
            || ! $this->eligibility->canScan($actor, $hub)) {
            throw new DomainException('Only an assigned destination-hub handler may receive or release this attempt.');
        }
    }

    private function assertAttemptReference(DeliveryAttempt $attempt, string $reference): void
    {
        if ($attempt->reference !== $reference) {
            throw new DomainException('The failed attempt changed. Scan the parcel again.');
        }
    }

    private function lock(Delivery $delivery, User $actor): array
    {
        $order = Order::whereKey(Delivery::whereKey($delivery->id)->value('order_id'))->lockForUpdate()->firstOrFail();
        $parcel = Delivery::whereKey($delivery->id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
        $parcel->setRelation('order', $order);
        $this->eligibility->lockNetwork($parcel->logistics_company_id, [$actor->id, $parcel->assigned_rider_id], [$parcel->destination_bayan_hub_id]);
        $actor = User::findOrFail($actor->id);
        if (! $actor->canAccessPortal() || in_array($order->status, ['cancelled', 'completed', 'returned'], true)) {
            throw new DomainException('This account or terminal order cannot change delivery recovery.');
        }
        $this->eligibility->assertBayanHub($parcel->destination_bayan_hub_id);

        return [$parcel, $actor];
    }

    private function record(Delivery $parcel, DeliveryAttempt $attempt, User $actor, LogisticsHub $hub, string $type, array $source, array $data): DeliveryRecoveryEvent
    {
        return DeliveryRecoveryEvent::create($data + ['delivery_id' => $parcel->id, 'delivery_attempt_id' => $attempt->id,
            'actor_id' => $actor->id, 'hub_id' => $hub->id, 'event_type' => $type,
            'source_state' => $source, 'target_state' => DeliveryCheckpoint::state($parcel)]);
    }

    private function fingerprint(array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
}
