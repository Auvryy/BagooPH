<?php

namespace App\Services\Courier;

use App\Models\CodAccount;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\User;
use App\Rules\ApplicationText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RiderTripService
{
    private function assignments(User $actor): Builder
    {
        return DeliveryCheckpoint::whereNull('source_checkpoint_id')->whereNotNull('source_state')->whereNotNull('target_state')
            ->where(function ($query) use ($actor) {
                $query->where(fn ($pickup) => $pickup->where('checkpoint_type', 'assigned_pickup')->where('scanned_by_id', $actor->id)
                    ->where('target_state->pickup_rider_id', $actor->id))
                    ->orWhere(fn ($final) => $final->where('checkpoint_type', 'assigned_to_rider')->where('target_state->assigned_rider_id', $actor->id));
            })->with('delivery.order');
    }

    public function list(Request $request): array
    {
        $filters = Validator::make($request->query(), ['q' => ['sometimes', 'string', new ApplicationText('search', 1, 100)],
            'payment' => ['sometimes', Rule::in(['all', 'cod', 'prepaid'])], 'phase' => ['sometimes', Rule::in(['pickup', 'final_mile'])],
            'from_date' => ['sometimes', 'date_format:Y-m-d'], 'to_date' => ['sometimes', 'date_format:Y-m-d',
                ...($request->filled('from_date') ? ['after_or_equal:from_date'] : [])]])->validate();
        $query = $this->assignments($request->user());
        if (isset($filters['q'])) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['q']).'%';
            $query->whereHas('delivery', fn ($parcel) => $parcel->where(fn ($q) => $q->whereRaw("tracking_number LIKE ? ESCAPE '!'", [$term])
                ->orWhereHas('order', fn ($order) => $order->whereRaw("order_number LIKE ? ESCAPE '!'", [$term]))));
        }
        if (isset($filters['phase'])) {
            $query->where('checkpoint_type', $filters['phase'] === 'pickup' ? 'assigned_pickup' : 'assigned_to_rider');
        }
        if (($filters['payment'] ?? 'all') !== 'all') {
            $query->whereHas('delivery.order', fn ($order) => $order->where('payment_method', $filters['payment'] === 'cod' ? '=' : '!=', 'cod'));
        }
        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from_date'], 'Asia/Manila')->startOfDay()->utc());
        }
        if (isset($filters['to_date'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to_date'], 'Asia/Manila')->startOfDay()->addDay()->utc());
        }
        $page = RiderApiInput::page($request);
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->paginate((int) $page['per_page'], ['*'], 'page', (int) $page['page']);

        return ['items' => $rows->getCollection()->map(fn ($assignment) => $this->resource($assignment, $request->user()))->all(),
            'attribution' => 'retained_assignment', 'date_basis' => 'assignment_recorded_at',
            'pagination' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]];
    }

    public function owned(User $actor, string $trip): DeliveryCheckpoint
    {
        abort_unless(preg_match('/\Atrip-([1-9][0-9]*)\z/', $trip, $parts) === 1, 404);

        return $this->assignments($actor)->whereKey(RiderApiInput::id($parts[1]))->firstOrFail();
    }

    private function events(DeliveryCheckpoint $assignment, User $actor): Builder
    {
        $pickup = $assignment->checkpoint_type === 'assigned_pickup';
        $next = DeliveryCheckpoint::where('delivery_id', $assignment->delivery_id)->where('checkpoint_type', $assignment->checkpoint_type)
            ->whereNull('source_checkpoint_id')->where('id', '>', $assignment->id)->min('id');

        return DeliveryCheckpoint::where('delivery_id', $assignment->delivery_id)->whereNull('source_checkpoint_id')
            ->where('id', '>=', $assignment->id)->when($next, fn ($query) => $query->where('id', '<', $next))
            ->whereIn('checkpoint_type', $pickup ? ['assigned_pickup', 'picked_up', 'arrived_at_origin_hub']
                : ['assigned_to_rider', 'out_for_delivery', 'delivery_failed', 'delivered'])
            ->where(fn ($query) => $query->whereKey($assignment->id)->orWhere('scanned_by_id', $actor->id)
                ->when($pickup, fn ($hub) => $hub->orWhere('checkpoint_type', 'arrived_at_origin_hub')));
    }

    public function resource(DeliveryCheckpoint $assignment, User $actor): array
    {
        $parcel = $assignment->delivery;
        $pickup = $assignment->checkpoint_type === 'assigned_pickup';
        $events = $this->events($assignment, $actor)->orderBy('id')->get();
        $last = $events->last();
        $handoff = $pickup ? null : $events->firstWhere('checkpoint_type', 'delivered');
        $cash = $handoff ? CodAccount::where('delivery_checkpoint_id', $handoff->id)->where('collector_id', $actor->id)->first() : null;
        $originalRecipient = $cash?->events()->where('event_type', 'rider_collection')->first()?->private_evidence['recipient_name'] ?? null;

        return ['id' => 'trip-'.$assignment->id, 'assignment_reference' => $assignment->record_reference,
            'delivery_id' => (string) $parcel->id, 'task_id' => ($pickup ? 'pickup' : 'final_mile').'-'.$parcel->id,
            'phase' => $pickup ? 'pickup' : 'final_mile', 'tracking_number' => $parcel->tracking_number,
            'order_number' => $parcel->order->order_number, 'outcome' => $last?->checkpoint_type,
            'assignment_recorded_at' => RiderApiInput::time($assignment->created_at), 'last_recorded_at' => RiderApiInput::time($last?->created_at),
            'commercial_status' => $parcel->order->status, 'current_operational_stage' => $parcel->status,
            'recipient' => $originalRecipient, 'address' => $handoff?->location_name,
            'payment_method' => $parcel->order->payment_method, 'collected_cents' => $cash ? (string) $cash->expected_cents : null,
            'earnings' => ['available' => false, 'confirmed_cents' => null],
            'checkpoints' => $events->map(fn ($event) => ['id' => (string) $event->id, 'reference' => $event->record_reference,
                'kind' => $event->checkpoint_type, 'recorded_at' => RiderApiInput::time($event->created_at),
                'waybill_matched' => $event->barcode_scanned !== null && $event->barcode_scanned === $parcel->tracking_number,
                'proof_url' => $event->scanned_by_id === $actor->id && $this->privateProof($event)
                    ? '/api/v1/rider/trips/trip-'.$assignment->id.'/checkpoints/'.$event->id.'/proof' : null])->all(),
            'attempts' => DeliveryAttempt::where('delivery_id', $parcel->id)->where('rider_id', $actor->id)
                ->whereIn('departure_checkpoint_id', $events->pluck('id'))->orderBy('attempt_number')->get()
                ->map(fn ($attempt) => ['id' => (string) $attempt->id, 'reference' => $attempt->reference,
                    'number' => $attempt->attempt_number, 'reason' => $attempt->reason_code, 'recorded_at' => RiderApiInput::time($attempt->attempted_at),
                    'proof_url' => '/api/v1/rider/trips/trip-'.$assignment->id.'/attempts/'.$attempt->id.'/proof'])->all()];
    }

    private function privateProof(DeliveryCheckpoint $checkpoint): bool
    {
        return is_string($checkpoint->proof_image) && preg_match('/\Adelivery-proofs\/[A-Za-z0-9][A-Za-z0-9._-]*\z/', $checkpoint->proof_image) === 1;
    }

    public function checkpointProof(User $actor, string $trip, string $checkpoint)
    {
        $assignment = $this->owned($actor, $trip);
        $event = $this->events($assignment, $actor)->whereKey(RiderApiInput::id($checkpoint))->where('scanned_by_id', $actor->id)->firstOrFail();
        abort_unless($this->privateProof($event), 404);
        $cash = CodAccount::where('delivery_checkpoint_id', $event->id)->where('collector_id', $actor->id)->firstOrFail();
        $hash = $cash->events()->where('event_type', 'rider_collection')->firstOrFail()->private_evidence['proof_hash'];

        return $this->proofResponse($event->proof_image, $hash);
    }

    public function attemptProof(User $actor, string $trip, string $attempt)
    {
        $assignment = $this->owned($actor, $trip);
        $record = DeliveryAttempt::where('delivery_id', $assignment->delivery_id)->where('rider_id', $actor->id)
            ->whereIn('departure_checkpoint_id', $this->events($assignment, $actor)->select('id'))
            ->whereKey(RiderApiInput::id($attempt))->firstOrFail();

        return $this->proofResponse($record->proof_path, $record->proof_hash);
    }

    private function proofResponse(string $path, string $hash)
    {
        abort_unless(preg_match('/\Adelivery-(?:attempt-)?proofs\/[A-Za-z0-9][A-Za-z0-9._-]*\z/', $path) === 1 && Storage::disk('local')->exists($path), 404);
        if (! hash_equals($hash, app(CourierProofService::class)->hash($path))) {
            RiderApiInput::error('EVIDENCE_CHANGED', 'The retained proof no longer matches its accepted image.', 409);
        }

        return Storage::disk('local')->response($path, 'parcel-proof', ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
