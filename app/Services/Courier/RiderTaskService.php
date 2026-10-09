<?php

namespace App\Services\Courier;

use App\Models\CourierProfile;
use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\Order;
use App\Models\User;
use App\Services\Finance\CodMoney;
use App\Services\Logistics\DeliveryRecoveryService;
use App\Services\Logistics\LogisticsEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class RiderTaskService
{
    public function __construct(private readonly CourierOperationsService $operations, private readonly LogisticsEligibilityService $eligibility) {}

    public function home(User $actor): array
    {
        $profile = CourierProfile::with(['company', 'hub', 'vehicle'])->where('user_id', $actor->id)->first();
        $operational = $this->eligibility->isOperational($profile);
        $pickups = Delivery::activePickupCount($actor->id);
        $canClaim = $this->eligibility->canReceivePickups($profile);

        return ['account_id' => (string) $actor->id, 'operations_api_version' => 1,
            'on_duty' => (bool) $profile?->is_available, 'observed_at' => RiderApiInput::time(now()),
            'placement' => ['company_id' => $profile?->logistics_company_id ? (string) $profile->logistics_company_id : null,
                'company_name' => $profile?->company?->name, 'hub_id' => $profile?->assigned_hub_id ? (string) $profile->assigned_hub_id : null,
                'hub_name' => $profile?->hub?->name, 'barangay' => $profile?->assigned_barangay],
            'eligibility' => ['operational' => $operational, 'can_claim' => $canClaim,
                'claim_denial' => $canClaim ? null : (! $operational ? 'PLACEMENT_UNAVAILABLE' : (! $profile->is_available ? 'OFF_DUTY' : ($pickups >= Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER ? 'PICKUP_CAPACITY' : 'OTHER_ACTIVE_WORK')))],
            'capacity' => ['active_pickups' => $pickups, 'pickup_limit' => Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER,
                'remaining_pickups' => max(0, Delivery::MAX_ACTIVE_PICKUPS_PER_RIDER - $pickups)],
            'counts' => collect(['available', 'pickup', 'final_mile'])->mapWithKeys(fn ($phase) => [$phase => $this->operations->queue($actor, $phase)->count()])->all(),
            'capabilities' => ['home' => true, 'duty' => true, 'pickup_claim' => true,
                'parcel_actions' => Route::has('rider.api.pickup') && Schema::hasTable('delivery_attempts') && Schema::hasTable('cod_cash_events'),
                'trips' => Route::has('rider.api.trips') && Schema::hasTable('delivery_attempts') && Schema::hasTable('cod_cash_events'),
                'messages' => Route::has('rider.api.conversations') && Schema::hasTable('messages'),
                'notifications' => Route::has('rider.api.notifications') && Schema::hasTable('notifications'),
                'cash' => Route::has('rider.api.cash') && Schema::hasTable('cod_cash_events'),
                'pre_custody_release' => false, 'native_restricted_recovery' => false, 'rider_earnings' => false],
            'limits' => ['per_page_max' => 50, 'poll_interval_seconds' => 30, 'request_budget_per_minute' => 30,
                'proof_bytes_max' => 5242880, 'message_characters_max' => 1000, 'idempotency_days' => 7]];
    }

    public function list(Request $request, string $phase): array
    {
        $page = RiderApiInput::page($request);
        $rows = $this->operations->queue($request->user(), $phase)->paginate((int) $page['per_page'], ['*'], 'page', (int) $page['page']);

        return ['items' => $rows->getCollection()->map(fn ($parcel) => $this->resource($parcel, $phase, $request->user()))->all(),
            'pagination' => ['page' => $rows->currentPage(), 'per_page' => $rows->perPage(), 'total' => $rows->total(), 'last_page' => $rows->lastPage()]];
    }

    public function task(User $actor, string $reference): array
    {
        abort_unless(preg_match('/\A(pickup|final_mile)-([1-9][0-9]*)\z/', $reference, $parts) === 1, 404);
        $id = RiderApiInput::id($parts[2]);

        return [$this->operations->queue($actor, $parts[1])->whereKey($id)->firstOrFail(), $parts[1]];
    }

    public function lock(Delivery $parcel): Delivery
    {
        $orderId = Delivery::whereKey($parcel->id)->value('order_id');
        $order = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

        return Delivery::whereKey($parcel->id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail()->setRelation('order', $order);
    }

    public function version(Delivery $parcel): string
    {
        return hash('sha256', json_encode([DeliveryCheckpoint::state($parcel), $parcel->order->status,
            $parcel->order->updated_at?->toISOString(), $parcel->updated_at?->toISOString(),
            $parcel->checkpoints()->max('id')], JSON_THROW_ON_ERROR));
    }

    public function assertVersion(Delivery $parcel, string $version): void
    {
        if (! hash_equals($this->version($parcel), $version)) {
            RiderApiInput::error('STALE_RESOURCE', 'This parcel changed. Refresh it before starting a new action.', 409);
        }
    }

    public function result(Delivery $parcel, string $phase): array
    {
        return ['task_id' => $phase.'-'.$parcel->id, 'delivery_id' => (string) $parcel->id,
            'commercial_status' => $parcel->order->status, 'operational_stage' => $parcel->status,
            'checkpoint_id' => ($id = $parcel->checkpoints()->whereNull('source_checkpoint_id')->max('id')) ? (string) $id : null];
    }

    public function resource(Delivery $parcel, string $phase, User $actor): array
    {
        $preview = $phase === 'available';
        $pickup = $phase !== 'final_mile';
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        $stop = $pickup ? ($parcel->status === 'picked_up' ? 'origin_hub' : 'seller')
            : (in_array($parcel->status, ['assigned_to_rider', 'delivery_failed'], true) ? 'destination_hub' : 'buyer');
        $hub = $pickup ? $parcel->originBayanHub : $parcel->destinationBayanHub;
        $address = match ($stop) {
            'seller' => $parcel->pickup_address, 'buyer' => $parcel->order->shipping_address, default => $hub?->address
        };
        $operational = $this->eligibility->isOperational($actor->courierProfile);
        $assignment = $parcel->checkpoints()->whereNull('source_checkpoint_id')
            ->where('checkpoint_type', $pickup ? 'assigned_pickup' : 'assigned_to_rider')->latest('id')->first();

        return ['id' => ($preview ? 'pickup' : $phase).'-'.$parcel->id, 'delivery_id' => (string) $parcel->id,
            'assignment_reference' => $assignment?->record_reference, 'phase' => $pickup ? 'pickup' : 'final_mile',
            'preview' => $preview, 'tracking_number' => $parcel->tracking_number, 'order_number' => $parcel->order->order_number,
            'commercial_status' => $parcel->order->status, 'operational_stage' => $parcel->status,
            'version' => $this->version($parcel), 'observed_at' => RiderApiInput::time(now()),
            'custody' => ['kind' => $custody['kind'] ?? 'unknown',
                'hub_id' => isset($custody['hub_id']) ? (string) $custody['hub_id'] : null,
                'held_by_me' => ($custody['kind'] ?? null) === 'courier' && ($custody['user_id'] ?? null) === $actor->id],
            'stop' => ['kind' => $stop, 'name' => match ($stop) {
                'seller' => $parcel->pickup_store_name, 'buyer' => $parcel->order->recipient_name, default => $hub?->name
            },
                'address' => $address, 'latitude' => ! $preview && $stop === 'buyer' ? $parcel->order->destination_latitude : null,
                'longitude' => ! $preview && $stop === 'buyer' ? $parcel->order->destination_longitude : null,
                'phone' => $preview ? null : match ($stop) {
                    'seller' => $parcel->pickup_contact_phone, 'buyer' => $parcel->order->recipient_phone, default => null
                },
                'instructions' => ! $preview && $stop === 'buyer' ? $parcel->order->notes : null],
            'payment' => $preview || $pickup ? null : ['method' => $parcel->order->payment_method,
                'cod_due_cents' => $parcel->order->payment_method === 'cod' ? (string) CodMoney::cents($parcel->order->total_amount) : null,
                'commercial_payment_status' => $parcel->order->payment_status],
            'actions' => ['claim' => $preview && $this->eligibility->canReceivePickups($actor->courierProfile),
                'pickup' => Route::has('rider.api.pickup') && ! $preview && $pickup && $operational && in_array($parcel->status, ['assigned', 'assigned_pickup'], true),
                'depart' => Route::has('rider.api.depart') && ! $pickup && $operational && $parcel->status === 'assigned_to_rider',
                'deliver' => Route::has('rider.api.deliver') && ! $pickup && $operational && $parcel->status === 'out_for_delivery' && $parcel->order->payment_method === 'cod',
                'fail' => Route::has('rider.api.fail') && ! $pickup && $operational && $parcel->status === 'out_for_delivery', 'release' => false],
            'action_denials' => ['release' => 'SHARED_RELEASE_POLICY_UNAVAILABLE',
                'deliver' => ! $pickup && $parcel->order->payment_method !== 'cod' ? 'UNSUPPORTED_PAYMENT_OUTCOME' : null],
            'failure_policy' => ! $preview && ! $pickup ? ['attempt_limit' => 3,
                'recorded_attempts' => DeliveryAttempt::where('delivery_id', $parcel->id)->count(),
                'reasons' => collect(DeliveryRecoveryService::REASONS)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()->all(),
                'requires_notes' => true, 'requires_location' => true, 'requires_private_image' => true,
                'retry_authority' => 'destination_hub', 'return_hub_id' => (string) $parcel->destination_bayan_hub_id] : null,
            'next_instruction' => match ($stop) {
                'seller' => $preview ? 'Claim before collecting this parcel.' : 'Scan the parcel waybill at the seller.',
                'origin_hub' => 'Bring the parcel to the origin hub. Its handler confirms intake.',
                'destination_hub' => $parcel->status === 'delivery_failed' ? 'Return the parcel to the destination hub. Its handler records receipt and any retry.' : 'Scan the assigned parcel out of the destination hub.',
                default => 'Record recipient handoff and exact cash. The buyer confirms receipt separately.'
            }];
    }
}
