<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryCheckpoint;
use App\Models\DeliveryReturnEvent;
use App\Models\DeliveryReturnRoute;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifestEvent;
use App\Models\LogisticsManifestParcel;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopEligibilityService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DeliveryReturnService
{
    public function __construct(private readonly LogisticsEligibilityService $eligibility) {}

    /** Freeze only the route supported by the actual failed-attempt hub receipt. */
    public function begin(Delivery $parcel, User $actor, DeliveryCheckpoint $source): DeliveryReturnRoute
    {
        $attempts = app(DeliveryRecoveryService::class);
        $attempt = $attempts->latestAttempt($parcel);
        $receipt = $attempts->event($attempt, 'hub_return');
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        if (! $actor->canAccessPortal() || ! $actor->isLogistics()
            || ! $this->eligibility->canScan($actor, LogisticsHub::findOrFail($parcel->destination_bayan_hub_id))
            || $parcel->status !== 'return_to_sender' || $parcel->order->status !== 'delivery_failed'
            || ($attempt->attempt_number !== 3 && $attempt->reason_code !== 'customer_refused')
            || ! $receipt || $receipt->hub_id !== $parcel->destination_bayan_hub_id || $receipt->actor_id !== $actor->id
            || $source->delivery_id !== $parcel->id || $source->checkpoint_type !== 'failed_delivery_hub_return'
            || $source->scanned_by_id !== $actor->id || $source->hub_id !== $receipt->hub_id || ! $source->barcode_scanned
            || ($source->custody_after['recovery_reference'] ?? null) !== $receipt->reference
            || $source->custody_after !== $custody || $parcel->current_hub_id !== $receipt->hub_id) {
            throw new DomainException('Return routing requires the actual non-retryable attempt and destination-hub receipt.');
        }
        $route = $this->forwardRoute($parcel);
        $hubs = [$route['origin_bayan_hub_id'], $route['origin_mother_hub_id']];
        if ($route['destination_mother_hub_id'] !== $route['origin_mother_hub_id']) {
            $hubs[] = $route['destination_mother_hub_id'];
        }
        $hubs[] = $route['destination_bayan_hub_id'];
        $this->assertOutboundEvidence($parcel, $hubs, $route, $source->id);

        return DeliveryReturnRoute::create(['delivery_id' => $parcel->id, 'source_checkpoint_id' => $source->id,
            'actor_id' => $actor->id, 'tracking_number_snapshot' => $parcel->tracking_number,
            'forward_route' => $route, 'hub_ids' => array_reverse($hubs)]);
    }

    public function route(Delivery $parcel): DeliveryReturnRoute
    {
        $route = DeliveryReturnRoute::where('delivery_id', $parcel->id)->first();
        if (! $route || $route->tracking_number_snapshot !== $parcel->tracking_number || $route->forward_route !== $this->forwardRoute($parcel)) {
            throw new DomainException('This return requires its retained original waybill and frozen route evidence.');
        }

        return $route;
    }

    /** Advance by actual linked receipts, including routes whose endpoint Bayan IDs coincide. */
    public function position(Delivery $parcel, DeliveryReturnRoute $route): int
    {
        $position = 0;
        $custody = DeliveryCheckpoint::findOrFail($route->source_checkpoint_id)->custody_after;
        foreach ($parcel->checkpoints()->where('id', '>', $route->source_checkpoint_id)->orderBy('id')->get() as $checkpoint) {
            if ($checkpoint->checkpoint_type === 'return_in_transit') {
                $scan = $this->transportEvidence($checkpoint, $route, 'parcel_dispatched');
                if ($position >= count($route->hub_ids) - 1 || $scan->manifest->source_hub_id !== $route->hub_ids[$position]
                    || $scan->manifest->destination_hub_id !== $route->hub_ids[$position + 1]
                    || $checkpoint->custody_before !== $custody || ($custody['kind'] ?? null) !== 'hub') {
                    throw new DomainException('The reverse departure does not match the retained custody chain.');
                }
                $custody = $checkpoint->custody_after;
            } elseif ($checkpoint->checkpoint_type === 'return_to_sender') {
                $scan = $this->transportEvidence($checkpoint, $route, 'parcel_received');
                if ($position >= count($route->hub_ids) - 1 || ($custody['kind'] ?? null) !== 'manifest'
                    || ($custody['manifest_id'] ?? null) !== $scan->manifest_id || $checkpoint->custody_before !== $custody
                    || $scan->manifest->source_hub_id !== $route->hub_ids[$position]
                    || $scan->manifest->destination_hub_id !== $route->hub_ids[$position + 1]) {
                    throw new DomainException('The reverse receipt does not match its real arriving manifest.');
                }
                $position++;
                $custody = $checkpoint->custody_after;
            }
        }

        return $position;
    }

    public function nextHub(Delivery $parcel): int
    {
        $route = $this->route($parcel);
        $position = $this->position($parcel, $route);
        $custody = DeliveryCheckpoint::lastCustody($parcel);
        if ($parcel->status !== 'return_to_sender' || $parcel->order->status !== 'delivery_failed'
            || $parcel->current_hub_id !== $route->hub_ids[$position] || ($custody['kind'] ?? null) !== 'hub'
            || ($custody['hub_id'] ?? null) !== $parcel->current_hub_id || $position >= count($route->hub_ids) - 1
            || DeliveryReturnEvent::where('delivery_return_route_id', $route->id)->exists()) {
            throw new DomainException('This return must have its actual current hub receipt and next reverse leg.');
        }
        $next = $route->hub_ids[$position + 1];
        if (! LogisticsHub::eligible()->where('logistics_company_id', $parcel->logistics_company_id)->whereKey($next)->exists()) {
            throw new DomainException('The next required return facility needs its current approval and company scope.');
        }

        return $next;
    }

    public function readyForSeller(Delivery $parcel): bool
    {
        try {
            $route = $this->route($parcel);
            $custody = DeliveryCheckpoint::lastCustody($parcel);

            return $parcel->status === 'return_to_sender' && $parcel->order->status === 'delivery_failed'
                && $this->position($parcel, $route) === count($route->hub_ids) - 1
                && $parcel->current_hub_id === $parcel->origin_bayan_hub_id
                && ($custody['kind'] ?? null) === 'hub' && ($custody['hub_id'] ?? null) === $parcel->origin_bayan_hub_id
                && ! LogisticsManifestParcel::where('active_delivery_id', $parcel->id)->exists();
        } catch (DomainException $error) {
            return false;
        }
    }

    public function stage(Delivery $delivery, User $actor, LogisticsHub $hub, string $barcode, string $expectedStatus, string $routeReference): Delivery
    {
        return DB::transaction(function () use ($delivery, $actor, $hub, $barcode, $expectedStatus, $routeReference) {
            [$parcel, $actor] = $this->lock($delivery, $actor);
            if (! $actor->isLogistics() || $hub->id !== $parcel->origin_bayan_hub_id || ! $this->eligibility->canScan($actor, $hub)) {
                throw new DomainException('Only the assigned origin-hub handler may stage this seller return.');
            }
            $barcode = app(WaybillScanInputService::class)->matchedBarcode($barcode, $parcel, allowOrderNumber: true);
            $route = $this->route($parcel);
            if ($routeReference !== $route->reference) {
                throw new DomainException('The return route changed. Scan its original waybill again.');
            }
            $existing = $this->event($route, 'seller_staged');
            if ($existing) {
                if ($existing->actor_id !== $actor->id || $existing->barcode_scanned !== $barcode) {
                    throw new DomainException('Another handler already recorded this staging scan.');
                }

                return $parcel;
            }
            if ($expectedStatus !== 'return_to_sender' || ! $this->readyForSeller($parcel)) {
                throw new DomainException('Seller staging requires every actual reverse receipt and closed manifest.');
            }
            $source = DeliveryCheckpoint::state($parcel);
            $custody = DeliveryCheckpoint::lastCustody($parcel);
            $event = $this->record($parcel, $route, $actor, $hub, 'seller_staged', $barcode, $source);
            DeliveryCheckpoint::record($parcel, 'seller_return_staged', actor: $actor, hub: $hub, barcodeScanned: $barcode,
                notes: 'Original parcel staged for the owning seller. Evidence: '.$event->reference,
                evidence: ['source_state' => $source, 'target_state' => $source, 'custody_before' => $custody,
                    'custody_after' => $custody + ['seller_staging_reference' => $event->reference]]);

            return $parcel;
        });
    }

    public function sellerReceive(Delivery $delivery, User $actor, Shop $selectedShop, array $input): Delivery
    {
        $inputs = app(WaybillScanInputService::class);
        $input = $inputs->normalize($input);
        $input = Validator::make($input, ['barcode' => $inputs->barcodeRules(), 'route_reference' => ['required', 'string', 'max:64'],
            'notes' => ['required', ...$inputs->notesRules()], 'request_token' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($delivery, $actor, $selectedShop, $input, $inputs) {
            [$parcel, $actor] = $this->lock($delivery, $actor);
            $shop = Shop::whereKey($selectedShop->id)->lockForUpdate()->firstOrFail();
            app(ShopEligibilityService::class)->lockCategories();
            if (! $actor->isSeller() || $shop->user_id !== $actor->id || ! app(ShopEligibilityService::class)->isEligible($shop)
                || ! $parcel->order->items()->where('shop_id', $shop->id)->exists()
                || $parcel->order->items()->where('shop_id', '!=', $shop->id)->exists()) {
                throw new DomainException('Only the current approved owning seller and shop may receive this returned parcel.');
            }
            $barcode = $inputs->matchedBarcode($input['barcode'], $parcel);
            $route = $this->route($parcel);
            if ($input['route_reference'] !== $route->reference) {
                throw new DomainException('Refresh the retained return route before confirming receipt.');
            }
            $fingerprint = hash('sha256', json_encode([$actor->id, $shop->id, $route->id, $barcode, $input['notes']], JSON_THROW_ON_ERROR));
            $existing = DeliveryReturnEvent::where('delivery_id', $parcel->id)->where('request_token', $input['request_token'])->first();
            if ($existing) {
                if ($existing->event_type !== 'seller_received' || $existing->actor_id !== $actor->id || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                    throw new DomainException('This receipt request already recorded different details.');
                }

                return $parcel;
            }
            $staging = $this->event($route, 'seller_staged');
            $custody = DeliveryCheckpoint::lastCustody($parcel);
            if (! $this->readyForSeller($parcel) || ! $staging || ($custody['seller_staging_reference'] ?? null) !== $staging->reference
                || $this->event($route, 'seller_received')) {
                throw new DomainException('Seller receipt requires the original parcel staged after the complete reverse route.');
            }
            $source = DeliveryCheckpoint::state($parcel);
            $parcel->status = 'returned';
            $parcel->current_hub_id = null;
            $parcel->save();
            $parcel->order->update(['status' => 'returned']);
            $hub = LogisticsHub::findOrFail($parcel->origin_bayan_hub_id);
            $event = $this->record($parcel, $route, $actor, $hub, 'seller_received', $barcode, $source,
                ['notes' => $input['notes'], 'request_token' => $input['request_token'], 'request_fingerprint' => $fingerprint]);
            DeliveryCheckpoint::record($parcel, 'parcel_returned', actor: $actor, hub: $hub, barcodeScanned: $barcode,
                notes: $input['notes'].' Receipt evidence: '.$event->reference,
                evidence: ['source_state' => $source, 'target_state' => DeliveryCheckpoint::state($parcel), 'custody_before' => $custody,
                    'custody_after' => ['kind' => 'seller', 'user_id' => $actor->id, 'shop_id' => $shop->id,
                        'return_route_reference' => $route->reference, 'seller_receipt_reference' => $event->reference]]);

            return $parcel;
        });
    }

    public function event(DeliveryReturnRoute $route, string $type): ?DeliveryReturnEvent
    {
        return DeliveryReturnEvent::where('delivery_return_route_id', $route->id)->where('event_type', $type)->first();
    }

    public function forwardRoute(Delivery $delivery): array
    {
        return $delivery->only(['order_id', 'logistics_company_id', 'origin_bayan_hub_id', 'origin_mother_hub_id', 'destination_mother_hub_id', 'destination_bayan_hub_id']);
    }

    private function assertOutboundEvidence(Delivery $parcel, array $hubs, array $route, int $beforeId): void
    {
        $lastId = 0;
        foreach (array_slice($hubs, 1) as $index => $hubId) {
            $check = $parcel->checkpoints()->where('id', '>', $lastId)->where('id', '<', $beforeId)->where('hub_id', $hubId)
                ->whereIn('checkpoint_type', ['arrived_at_mother_hub', 'arrived_at_destination_hub'])->orderBy('id')->first();
            $scan = $check ? LogisticsManifestEvent::with(['manifest', 'parcel'])->where('reference', $check->custody_after['manifest_scan_reference'] ?? '')->first() : null;
            if (! $scan || $scan->event_type !== 'parcel_received' || $scan->manifest->direction !== 'outbound'
                || $scan->manifest->source_hub_id !== $hubs[$index] || $scan->manifest->destination_hub_id !== $hubId
                || $scan->parcel->delivery_id !== $parcel->id || $scan->parcel->route_snapshot !== $route
                || $scan->hub_id !== $hubId || $scan->actor_id !== $check->scanned_by_id || ! $check->barcode_scanned) {
                throw new DomainException('The return needs actual retained outbound receipts through its original Mother Hub network.');
            }
            $lastId = $check->id;
        }
    }

    private function transportEvidence(DeliveryCheckpoint $checkpoint, DeliveryReturnRoute $route, string $type): LogisticsManifestEvent
    {
        $scan = LogisticsManifestEvent::with(['manifest', 'parcel'])->where('reference', $checkpoint->custody_after['manifest_scan_reference'] ?? '')->first();
        if (! $scan || $scan->event_type !== $type || $scan->manifest->direction !== 'return'
            || $scan->parcel->delivery_id !== $route->delivery_id || ($scan->parcel->route_snapshot['return_route_reference'] ?? null) !== $route->reference
            || $scan->actor_id !== $checkpoint->scanned_by_id || $scan->hub_id !== $checkpoint->hub_id
            || $scan->barcode_scanned !== $checkpoint->barcode_scanned || $scan->manifest->reference !== $checkpoint->manifest_number) {
            throw new DomainException('Each reverse leg requires its actual linked manifest and custody scan.');
        }

        return $scan;
    }

    private function lock(Delivery $delivery, User $actor): array
    {
        $order = Order::whereKey(Delivery::whereKey($delivery->id)->value('order_id'))->lockForUpdate()->firstOrFail();
        $parcel = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
        $parcel->setRelation('order', $order);
        $this->eligibility->lockNetwork($parcel->logistics_company_id, [$actor->id], [$parcel->origin_bayan_hub_id]);
        $actor = User::findOrFail($actor->id);
        if (! $actor->canAccessPortal()) {
            throw new DomainException('This action requires the current active approved account.');
        }

        return [$parcel, $actor];
    }

    private function record(Delivery $parcel, DeliveryReturnRoute $route, User $actor, LogisticsHub $hub, string $type, string $barcode, array $source, array $input = []): DeliveryReturnEvent
    {
        return DeliveryReturnEvent::create($input + ['delivery_id' => $parcel->id, 'delivery_return_route_id' => $route->id,
            'actor_id' => $actor->id, 'hub_id' => $hub->id, 'event_type' => $type, 'barcode_scanned' => $barcode,
            'source_state' => $source, 'target_state' => DeliveryCheckpoint::state($parcel)]);
    }
}
