<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifestEvent;
use App\Models\PickupClaimEvent;
use App\Models\User;
use App\Rules\PhilippineContact;
use App\Services\Orders\OrderTrackingService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class HubParcelWorkstationService
{
    private const TERMINAL = ['delivered', 'customer_collected', 'completed', 'cancelled', 'returned'];

    private const STAGES = [
        'unassigned' => 'Awaiting seller preparation', 'assigned_pickup' => 'Pickup rider assigned',
        'picked_up' => 'Awaiting origin Bayan Hub intake', 'arrived_at_origin_hub' => 'Received at origin Bayan Hub',
        'in_transit_to_mother_hub' => 'On manifest to Mother Hub', 'arrived_at_mother_hub' => 'Received at Mother Hub',
        'sorted_to_line_haul' => 'Sorted for next hub transfer', 'in_transit_to_destination_hub' => 'On manifest to destination Bayan Hub',
        'arrived_at_destination_hub' => 'Received at destination Bayan Hub', 'sorted_to_barangay_bin' => 'Sorted to destination barangay bin',
        'assigned_to_rider' => 'Final-mile rider assigned; awaiting departure', 'out_for_delivery' => 'Out for delivery',
        'ready_for_hub_pickup' => 'Staged for secure counter collection', 'customer_collected' => 'Buyer collected at counter',
        'delivered' => 'Delivered to buyer', 'completed' => 'Buyer receipt confirmed', 'cancelled' => 'Cancelled',
        'delivery_failed' => 'Delivery failed', 'return_to_sender' => 'Return to seller in progress',
        'return_in_transit' => 'On return manifest', 'returned' => 'Returned to seller',
        'parcel_recovery_held' => 'Held for parcel recovery', 'parcel_recovery_in_transit' => 'On recovery manifest',
    ];

    public function __construct(
        private readonly LogisticsEligibilityService $eligibility,
        private readonly LogisticsRoutingEngine $routing,
        private readonly PickupClaimService $pickup,
    ) {}

    public function counterQueue(?LogisticsHub $hub, string $search = ''): Builder
    {
        $query = Delivery::query()->with(['order.buyer', 'order.items.product', 'currentHub', 'courier', 'assignedRider',
            'latestCustodyCheckpoint', 'latestOriginalCheckpoint', 'pickupClaim'])
            ->where('delivery_type', 'hub_self_pickup')
            ->where('destination_bayan_hub_id', $hub?->id ?? 0)
            ->where('logistics_company_id', $hub?->logistics_company_id ?? 0)
            ->where('current_hub_id', $hub?->id ?? 0)
            ->whereIn('status', ['arrived_at_destination_hub', 'ready_for_hub_pickup', 'sorted_to_barangay_bin']);

        if ($search !== '') {
            $mobile = PhilippineContact::canonical($search);
            $query->where(fn ($match) => $match->where('tracking_number', 'like', "%{$search}%")
                ->orWhereHas('order', fn ($order) => $order->where('order_number', 'like', "%{$search}%"))
                ->orWhereHas('order.buyer', fn ($buyer) => $buyer->where(fn ($contact) => $contact
                    ->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")
                    ->when($mobile !== null, fn ($buyer) => $buyer->orWhere('phone', $mobile)))));
        }

        return $query;
    }

    public function counterParcel(Delivery $parcel, LogisticsHub $hub, User $actor, string $base): array
    {
        return [
            'id' => $parcel->id,
            'tracking_number' => $parcel->tracking_number,
            'order_number' => $parcel->order?->order_number ?? 'N/A',
            'buyer_name' => $parcel->order?->buyer?->name ?? 'Customer',
            'buyer_phone' => $parcel->order?->buyer?->phone ?? 'N/A',
            'status' => $parcel->status,
            'destination_bin' => $this->recordedBin($parcel),
            'total_amount' => (float) ($parcel->order?->total_amount ?? 0),
            'payment_method' => $parcel->order?->payment_method ?? 'cod',
            'item_count' => $parcel->order?->items?->count() ?? 0,
            'items' => $parcel->order?->items?->map(fn ($item) => [
                'name' => $item->product?->name ?? 'Item', 'quantity' => $item->quantity, 'price' => (float) $item->price,
            ]) ?? [],
            'arrived_at' => $parcel->pickupClaim?->ready_at->timezone('Asia/Manila')->format('M d, H:i'),
            'buyer_id' => $parcel->order->buyer_id,
            'claim' => $parcel->pickupClaim?->only(['reference', 'status', 'expires_at', 'locked_until']),
            'guidance' => $this->guidance($parcel, $hub, $actor, collect(), $base),
        ];
    }

    public function stagedCounterCount(LogisticsHub $hub): int
    {
        return $this->counterQueue($hub)->where('status', 'ready_for_hub_pickup')
            ->whereHas('latestCustodyCheckpoint', fn ($checkpoint) => $checkpoint->where('checkpoint_type', 'ready_for_hub_pickup')
                ->where('hub_id', $hub->id)->whereNotNull('scanned_by_id')
                ->whereColumn('delivery_checkpoints.barcode_scanned', 'deliveries.tracking_number')
                ->where('custody_after->kind', 'hub')->where('custody_after->hub_id', $hub->id))->count();
    }

    public function recordedBin(?Delivery $parcel): ?string
    {
        $bin = $parcel?->destination_bin;
        // Older routes wrote this placeholder before any physical staging.
        if (! trim((string) $bin) || ($parcel->delivery_type === 'hub_self_pickup' && $bin === 'STAGE: SELF-PICKUP-SHELF')) {
            return null;
        }

        return $bin;
    }

    public function registry(?LogisticsHub $hub): Builder
    {
        if (! $hub) {
            return Delivery::query()->whereKey(0);
        }

        return Delivery::query()->where('logistics_company_id', $hub->logistics_company_id)->where(function ($query) use ($hub) {
            $query->where('current_hub_id', $hub->id)
                ->orWhere(fn ($intake) => $intake->where('origin_bayan_hub_id', $hub->id)->whereNull('current_hub_id')->where('status', 'picked_up'))
                ->orWhere(fn ($failed) => $failed->where('destination_bayan_hub_id', $hub->id)->whereNull('current_hub_id')->whereRaw("deliveries.status = 'delivery_failed'"));
        });
    }

    public function filter(Builder $query, string $status, ?LogisticsHub $hub): Builder
    {
        return match ($status) {
            'all' => $query,
            'in_hub' => $query->where('current_hub_id', $hub?->id ?? 0)->whereNotIn('status', self::TERMINAL)
                ->whereHas('latestCustodyCheckpoint', fn ($checkpoint) => $checkpoint->where('custody_after->kind', 'hub')->where('custody_after->hub_id', $hub?->id ?? 0)),
            'ready_pickup' => $query->where('delivery_type', 'hub_self_pickup')->where('destination_bayan_hub_id', $hub?->id ?? 0)
                ->whereIn('status', ['arrived_at_destination_hub', 'ready_for_hub_pickup']),
            default => $query->whereRaw('deliveries.status = ?', [$status]),
        };
    }

    public function counts(?LogisticsHub $hub): array
    {
        $start = now('Asia/Manila')->startOfDay()->utc();
        $handoverCount = DeliveryCheckpoint::query()->where('hub_id', $hub?->id ?? 0)->whereNull('source_checkpoint_id')
            ->whereNotNull('scanned_by_id')->where('created_at', '>=', $start)->where('created_at', '<', $start->copy()->addDay())
            ->whereIn('checkpoint_type', ['in_transit_to_mother_hub', 'in_transit_to_destination_hub', 'return_in_transit',
                'parcel_recovery_departed', 'out_for_delivery', 'customer_collected'])
            ->whereIn('custody_after->kind', ['manifest', 'courier', 'buyer'])
            ->whereHas('delivery', fn ($parcel) => $parcel->where('logistics_company_id', $hub?->logistics_company_id ?? 0)
                ->whereColumn('deliveries.tracking_number', 'delivery_checkpoints.barcode_scanned'))
            ->distinct()->count('delivery_id');

        return [
            'all' => $this->registry($hub)->count(),
            'in_hub' => $this->filter($this->registry($hub), 'in_hub', $hub)->count(),
            'out_for_delivery' => $this->filter($this->registry($hub), 'out_for_delivery', $hub)->count(),
            'ready_pickup' => $this->filter($this->registry($hub), 'ready_pickup', $hub)->count(),
            'handovers_today' => $handoverCount,
        ];
    }

    public function guidance(Delivery $parcel, LogisticsHub $hub, User $actor, Collection $riders, string $base): array
    {
        $custody = $parcel->latestCustodyCheckpoint?->custody_after ?? ['kind' => 'unknown'];
        $atHub = ($custody['kind'] ?? null) === 'hub' && ($custody['hub_id'] ?? null) === $hub->id && $parcel->current_hub_id === $hub->id;
        $handler = $actor->isLogistics() && $this->eligibility->canScan($actor, $hub);
        $manager = $actor->isLogistics() && $this->eligibility->isCompanyAdministrator($actor, $parcel->logistics_company_id);
        $guide = [
            'stage' => self::STAGES[$parcel->status] ?? 'Unrecognized parcel stage',
            'order_status' => OrderTrackingService::label($parcel->order, $parcel),
            'custodian' => $this->custodian($parcel, $custody),
            'custody_recorded_at' => $parcel->latestCustodyCheckpoint?->created_at->toIso8601String(),
            'next_step' => 'Review the parcel records', 'responsible' => 'Company administrator',
            'blocker' => null, 'action' => 'wait', 'can_act' => false, 'href' => null,
            'eligible_rider_ids' => [], 'ready_for_collection' => false,
        ];
        $next = fn (string $step, string $responsible, string $action = 'wait', bool $canAct = false, ?string $path = null, ?string $blocker = null): array => [...$guide, 'next_step' => $step, 'responsible' => $responsible, 'action' => $action, 'can_act' => $canAct,
            'href' => $canAct && $path ? $base.$path.'?hub_id='.$hub->id : null, 'blocker' => $blocker];

        if ($held = app(HandoverHoldService::class)->active($parcel)) {
            $readable = app(HandoverIssueService::class)->readable($actor)->whereKey($held->id)->exists();

            return [...$guide, 'next_step' => 'Continue supervised handover recovery', 'responsible' => 'Independent reviewer, named holder and receiving hub handler',
                'blocker' => 'Ordinary parcel actions are paused until the actual recovery receipt.', 'action' => 'recovery',
                'can_act' => $readable, 'href' => $readable ? '/handover-issues/'.$held->id : null];
        }
        if (($custody['kind'] ?? 'unknown') === 'unknown') {
            return $next('Review missing custody evidence', 'Company administrator', blocker: 'No retained custody checkpoint establishes the parcel holder. A status or assignment cannot replace a handover.');
        }
        $expectedOrder = match ($parcel->status) {
            'picked_up' => ['picked_up'],
            'arrived_at_origin_hub', 'arrived_at_mother_hub', 'sorted_to_line_haul', 'in_transit_to_mother_hub', 'in_transit_to_destination_hub', 'arrived_at_destination_hub' => ['at_sorting_center'],
            'sorted_to_barangay_bin', 'ready_for_hub_pickup' => ['sorted'],
            'assigned_to_rider' => ['assigned_to_rider'], 'out_for_delivery' => ['out_for_delivery'],
            'delivery_failed', 'return_to_sender', 'return_in_transit' => ['delivery_failed'],
            'delivered', 'customer_collected' => ['delivered', 'completed'], 'returned' => ['returned'],
            default => null,
        };
        if ($expectedOrder && ! in_array($parcel->order?->status, $expectedOrder, true)) {
            return $next('Review the order and parcel stages', 'Company administrator', blocker: 'The recorded commercial order state does not match this parcel stage. Refresh or review its source records before continuing.');
        }
        if (in_array($parcel->status, self::TERMINAL, true) || in_array($parcel->order?->status, ['cancelled', 'completed', 'returned'], true)) {
            $awaitBuyer = $parcel->order?->status === 'delivered' && ($custody['kind'] ?? null) === 'buyer'
                && ($custody['user_id'] ?? null) === $parcel->order->buyer_id;

            return $next($awaitBuyer ? 'Await buyer receipt confirmation' : 'Retain the recorded outcome', $awaitBuyer ? 'Owning buyer' : 'Company administrator', blocker: 'No further hub custody action is available.');
        }
        if (in_array($parcel->status, ['parcel_recovery_held', 'parcel_recovery_in_transit'], true)) {
            $readable = app(ParcelRecoveryService::class)->observations($actor)->whereHas('recoveryCase', fn ($case) => $case->where('delivery_id', $parcel->id))->exists();

            return $next('Continue the recorded parcel recovery', 'Company administrator and assigned recovery handlers', 'recovery', $readable, '/parcel-recovery', $readable ? null : 'This account has no access to the recovery observation.');
        }
        if ($parcel->status === 'picked_up' || ($parcel->status === 'delivery_failed' && $parcel->current_hub_id === null)) {
            $expectedRider = $parcel->status === 'picked_up' ? $parcel->courier_id : $parcel->assigned_rider_id;
            $expectedHub = $parcel->status === 'picked_up' ? $parcel->origin_bayan_hub_id : $parcel->destination_bayan_hub_id;
            if (($custody['kind'] ?? null) !== 'courier' || ($custody['user_id'] ?? null) !== $expectedRider
                || $hub->id !== $expectedHub || $parcel->current_hub_id !== null) {
                return $next('Review the expected rider handover', 'Company administrator', blocker: 'Recorded custody does not match the expected rider.');
            }

            return $next('Receive the physical parcel from its rider', 'Assigned receiving handler', 'scan', $handler, '/scan', $handler ? null : 'An assigned handler must record the intake scan.');
        }
        if (! $atHub) {
            return $next('Review custody before another hub action', 'Company administrator', blocker: 'The selected facility is not the recorded parcel holder.');
        }
        if ($custody['manifest_scan_reference'] ?? null) {
            $receipt = LogisticsManifestEvent::with('manifest')->where('reference', $custody['manifest_scan_reference'])
                ->where('event_type', 'parcel_received')->where('hub_id', $hub->id)
                ->whereHas('parcel', fn ($member) => $member->where('delivery_id', $parcel->id))
                ->whereHas('manifest', fn ($manifest) => $manifest->where('logistics_company_id', $parcel->logistics_company_id))->first();
            if ($receipt && $receipt->manifest->status !== 'closed') {
                return $next('Finish the incoming manifest receipt and closure', 'Assigned receiver records receipts; company administrator closes',
                    'manifest', $manager || $handler, '/manifests/'.$receipt->manifest_id,
                    'The incoming manifest is '.$receipt->manifest->status.'. Complete its receiving checks before the next handover.');
            }
        }
        if ($parcel->status === 'assigned_to_rider') {
            $profile = $parcel->assignedRider?->courierProfile;
            if (! $this->eligibility->isOperational($profile) || $profile->assigned_hub_id !== $hub->id || $profile->logistics_company_id !== $parcel->logistics_company_id) {
                return $next('Review the assigned rider\'s eligibility', 'Company administrator', blocker: 'The assigned rider is unavailable for this facility. Preserve the assignment and hub custody until authorized recovery is recorded.');
            }

            return $next('Await the assigned rider\'s departure scan', $parcel->assignedRider?->name ?? 'Assigned final-mile rider', blocker: 'Rider assignment leaves custody at the hub until that rider scans departure.');
        }
        if ($parcel->status === 'sorted_to_barangay_bin') {
            if ($parcel->delivery_type !== 'doorstep' || $parcel->order?->status !== 'sorted' || $hub->id !== $parcel->destination_bayan_hub_id) {
                return $next('Review the recorded sorting state', 'Company administrator', blocker: 'Rider assignment requires a sorted doorstep parcel at its destination Bayan Hub.');
            }
            $ids = $riders->filter(fn ($rider) => ! $rider['assigned_barangay'] || strcasecmp(trim($rider['assigned_barangay']), trim((string) $parcel->order?->destination_barangay)) === 0)->pluck('id')->values()->all();

            return [...$next('Assign an eligible final-mile rider', 'Company administrator or assigned handler', 'assign', ($manager || $handler) && $ids !== [],
                blocker: ! ($manager || $handler) ? 'This account may read the parcel but cannot assign its rider.' : ($ids === [] ? 'No available eligible rider serves this facility and barangay.' : null)), 'eligible_rider_ids' => $ids];
        }
        if ($parcel->status === 'ready_for_hub_pickup') {
            $claim = $parcel->pickupClaim;
            if ($claim?->status === 'expired' && PickupClaimEvent::where('pickup_claim_id', $claim->id)->where('event_type', 'holding_expired')->exists()) {
                return $next('Scan to start the recorded expired-pickup return', 'Assigned counter handler', 'scan', $handler, '/scan', $handler ? null : 'An assigned handler must scan the expired parcel.');
            }
            $blocker = $this->pickup->collectionBlocker($parcel, $hub);
            if ($blocker) {
                return $next('Review counter collection readiness', 'Assigned counter handler', blocker: $blocker);
            }

            return [...$next('Verify buyer identity, claim code and exact COD before release', 'Assigned counter handler', 'counter', $handler, '/counter', $handler ? null : 'An assigned counter handler must verify and release the parcel.'), 'ready_for_collection' => true];
        }

        $prompt = $this->routing->getDynamicScanPrompt($parcel, $hub);
        if ($prompt['action'] === 'AWAIT_BARANGAY_SORT') {
            return $next('Sort to the recorded destination barangay bin', 'Assigned destination handler', 'sort', $handler, blocker: $handler ? null : 'An assigned handler must record sorting.');
        }
        if ($prompt['action'] === 'STAGE_FOR_PICKUP') {
            $blocker = ! $hub->allows_self_pickup ? 'Counter pickup is disabled at this facility.'
                : (! trim((string) $hub->operating_hours) ? 'The company administrator must record actual pickup hours before staging.' : null);

            return $next('Scan to stage the parcel for secure pickup', 'Assigned counter handler', 'scan', $handler && ! $blocker, '/scan', $blocker ?? ($handler ? null : 'An assigned handler must scan pickup staging.'));
        }
        if (in_array($prompt['action'], ['DISPATCH_TO_FEEDER', 'DISPATCH_LINE_HAUL', 'DISPATCH_RETURN_MANIFEST'], true)) {
            try {
                app(LogisticsManifestService::class)->nextHub($parcel);
            } catch (DomainException $error) {
                return $next('Review manifest readiness', 'Company administrator', blocker: $error->getMessage());
            }

            return $next('Prepare the next manifest and record its load and dispatch scans', 'Company administrator prepares; assigned handler loads and dispatches', 'manifest', $manager || $handler, '/manifests', ($manager || $handler) ? null : 'Company management or an assigned handler must continue the transfer.');
        }
        if ($prompt['action'] === 'AWAIT_RETRY_REVIEW') {
            return $next('Review the failed attempt and approved retry date', 'Company administrator', 'review', $manager, '/delivery-recovery', 'A retry requires company approval and the approved date before a handler releases it.');
        }
        if ($prompt['action'] === 'AWAIT_SELLER_RECEIPT') {
            return $next('Await the owning seller\'s return receipt scan', 'Owning seller', blocker: 'Hub staging does not mark the order Returned.');
        }
        if (in_array($prompt['action'], ['SORT_TO_LINE_HAUL', 'RELEASE_APPROVED_RETRY', 'STAGE_SELLER_RETURN'], true)) {
            if ($prompt['action'] === 'SORT_TO_LINE_HAUL' && ! in_array($hub->id, [$parcel->origin_mother_hub_id, $parcel->destination_mother_hub_id], true)) {
                return $next('Review the recorded Mother Hub route', 'Company administrator', blocker: 'This facility is not a Mother Hub on the original parcel route.');
            }

            $label = match ($prompt['action']) {
                'SORT_TO_LINE_HAUL' => 'Scan Mother Hub sorting for the next transfer',
                'RELEASE_APPROVED_RETRY' => 'Scan to release the approved retry for sorting',
                default => 'Scan seller-return staging',
            };

            return $next($label, 'Assigned facility handler', 'scan', $handler, '/scan', $handler ? null : 'An assigned handler must record this scan.');
        }

        return $next('Review the parcel\'s supported recovery records', 'Company administrator', blocker: $prompt['action'] === 'AWAIT_RETURN_REVIEW' ? $prompt['prompt'] : 'No supported next hub action is established for this state.');
    }

    private function custodian(Delivery $parcel, array $custody): string
    {
        return match ($custody['kind'] ?? 'unknown') {
            'hub' => $parcel->currentHub && $parcel->currentHub->id === ($custody['hub_id'] ?? null)
                ? $parcel->currentHub->name : 'Recorded hub; facility state needs review',
            'courier' => ($custody['user_id'] ?? null) === $parcel->courier_id
                ? 'Pickup rider: '.($parcel->courier?->name ?? 'Recorded pickup rider')
                : (($custody['user_id'] ?? null) === $parcel->assigned_rider_id ? 'Delivery rider: '.($parcel->assignedRider?->name ?? 'Recorded delivery rider') : 'Recorded courier; assignment needs review'),
            'manifest' => 'Transport manifest: '.($custody['manifest_reference'] ?? 'Recorded manifest'),
            'buyer' => 'Buyer (handover recorded)',
            'seller' => 'Seller (return receipt recorded)',
            default => 'Custody not established',
        };
    }
}
