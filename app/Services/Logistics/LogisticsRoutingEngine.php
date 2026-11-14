<?php

namespace App\Services\Logistics;

use App\Models\Delivery;
use App\Models\DeliveryAttempt;
use App\Models\DeliveryCheckpoint;
use App\Models\LogisticsCompany;
use App\Models\LogisticsHub;
use App\Models\LogisticsManifest;
use App\Models\Order;
use App\Models\PickupClaim;
use App\Models\ShipmentLossReconciliation;
use App\Models\Shop;
use App\Services\Finance\ShipmentLossReconciliationService;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LogisticsRoutingEngine
{
    /**
     * Non-contiguous island provinces/regions excluded from 100% road freight scope.
     */
    public const EXCLUDED_ISLAND_TERRITORIES = [
        'batanes',
        'palawan',
        'marinduque',
        'romblon',
        'masbate',
        'catanduanes',
        'cebu',
        'bohol',
        'siquijor',
        'guimaras',
        'biliran',
        'leyte',
        'samar',
        'basilan',
        'sulu',
        'tawi-tawi',
        'camiguin',
        'dinagat islands',
    ];

    /**
     * Validate whether an address is within contiguous land road freight boundaries.
     */
    public function isContiguousRoadServiceable(string $province, string $city): bool
    {
        $normalizedProvince = strtolower(trim($province));
        foreach (self::EXCLUDED_ISLAND_TERRITORIES as $excluded) {
            if (str_contains($normalizedProvince, $excluded)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve the Origin Bayan Hub for a seller shop.
     */
    public function resolveOriginBayanHub(Shop $shop, ?LogisticsCompany $company = null): ?LogisticsHub
    {
        $query = LogisticsHub::eligible()->where('tier', 'local_bayan_hub');

        if ($company) {
            $query->where('logistics_company_id', $company->id);
        }

        $owner = $shop->user;
        $city = $this->cityKey((string) $shop->city);
        $province = $this->normalizePlace((string) ($shop->pickup_province ?: (str_contains((string) $shop->city, ',') ? Str::afterLast($shop->city, ',') : '')));
        $postal = (string) ($shop->pickup_postal_code ?: ($this->cityKey((string) $owner?->city) === $city ? $owner?->postal_code : ''));
        $hubs = $query->orderBy('id')->get()->filter(function (LogisticsHub $hub) use ($city, $province, $postal) {
            if ($province !== '' && $this->normalizePlace($hub->province) !== $province) {
                return false;
            }

            return ! empty($hub->postal_codes) && $postal !== ''
                ? in_array($postal, $hub->postal_codes, true)
                : $city !== '' && $this->cityKey($hub->city_municipality) === $city;
        });

        return $this->unambiguousHub($hubs);
    }

    /**
     * Resolve the Destination Bayan Hub for a buyer address.
     */
    public function resolveDestinationBayanHub(string $province, string $city, ?string $barangay = null, ?LogisticsCompany $company = null, ?string $postalCode = null): ?LogisticsHub
    {
        $query = LogisticsHub::eligible()->where('tier', 'local_bayan_hub');

        if ($company) {
            $query->where('logistics_company_id', $company->id);
        }

        // A repeated barangay name or a partial city name cannot override the destination province/city.
        $hubs = $query->orderBy('id')->get()->filter(fn (LogisticsHub $hub) => $this->matchesDestination($hub, $province, $city, $barangay, $postalCode));

        $specific = $hubs->filter(fn (LogisticsHub $hub) => $barangay && $hub->coversBarangay($barangay));

        return $this->unambiguousHub($specific->isNotEmpty() ? $specific : $hubs->filter(fn (LogisticsHub $hub) => empty($hub->coverage_barangays)));
    }

    /**
     * Resolve Regional Mother Hub corresponding to a Local Bayan Hub.
     */
    public function resolveMotherHubForBayanHub(LogisticsHub $bayanHub): ?LogisticsHub
    {
        $bayanHub = LogisticsHub::eligible()->whereKey($bayanHub->id)->where('tier', 'local_bayan_hub')->first();
        if (! $bayanHub) {
            return null;
        }
        if ($bayanHub->parent_hub_id) {
            return LogisticsHub::eligible()->where('logistics_company_id', $bayanHub->logistics_company_id)
                ->where('tier', 'regional_mother_hub')->find($bayanHub->parent_hub_id);
        }
        $province = $this->normalizePlace((string) $bayanHub->province);

        $matches = LogisticsHub::eligible()->where('logistics_company_id', $bayanHub->logistics_company_id)
            ->where('tier', 'regional_mother_hub')
            ->where('is_active', true)
            ->get()->filter(fn (LogisticsHub $hub) => $this->normalizePlace((string) $hub->province) === $province);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Generate facility-to-facility hops and populate delivery routing legs.
     */
    public function planDeliveryRoute(Delivery $delivery, Order $order, Shop $shop): Delivery
    {
        return DB::transaction(function () use ($delivery, $order, $shop) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $parcel = Delivery::whereKey($delivery->id)->where('order_id', $order->id)->lockForUpdate()->firstOrFail();
            app(HandoverHoldService::class)->assertMovable($parcel);
            $shop = Shop::findOrFail($shop->id);
            if ($parcel->status !== 'unassigned' || $parcel->courier_id || $parcel->assigned_rider_id
                || $parcel->current_hub_id || $parcel->checkpoints()->exists()
                || ($order->logistics_flow_version >= 2 && ! empty($parcel->route_hub_ids))) {
                throw new DomainException('A planned or assigned parcel route requires controlled recovery before changing facilities.');
            }
            $company = $parcel->logistics_company_id
                ? LogisticsCompany::eligible()->find($parcel->logistics_company_id)
                : $this->resolveCompanyForRoute($order, $shop);
            if (! $company) {
                throw new DomainException('The selected logistics company is not eligible for routing.');
            }
            [$origin, $destination, $originMother, $destinationMother, $path, $local] = $this->resolveRoute($company, $shop, [
                'delivery_type' => $order->delivery_type, 'pickup_hub_id' => $order->pickup_hub_id,
                'shipping_province' => $order->shipping_province, 'shipping_city' => $order->shipping_city,
                'shipping_postal_code' => $order->shipping_postal_code, 'destination_barangay' => $order->destination_barangay,
            ], $order->logistics_flow_version >= 2);
            app(LogisticsEligibilityService::class)->lockNetwork($company->id, hubIds: $path);
            $bayanIds = collect([$origin->id, $destination->id])->unique();
            $motherIds = $local ? collect() : collect([$originMother->id, $destinationMother->id])->unique();
            if (LogisticsHub::eligible()->whereIn('id', $bayanIds)->where('tier', 'local_bayan_hub')->count() !== $bayanIds->count()
                || LogisticsHub::eligible()->whereIn('id', $motherIds)->where('tier', 'regional_mother_hub')->count() !== $motherIds->count()
                || ($order->delivery_type === 'hub_self_pickup' && ! LogisticsHub::eligible()->whereKey($destination->id)->where('allows_self_pickup', true)
                    ->when($order->logistics_flow_version >= 2, fn ($query) => $query->readyForSelfPickup())->exists())) {
                throw new DomainException('The selected facility capabilities changed. Check the route again.');
            }
            if (! $this->matchesDestination($destination->fresh(), $order->shipping_province ?? '', $order->shipping_city ?? '', $order->destination_barangay, $order->shipping_postal_code)) {
                throw new DomainException('The destination coverage changed. Check the delivery address again.');
            }
            $barangay = $order->destination_barangay ?? 'GENERAL';
            $parcel->fill([
                'logistics_company_id' => $company->id, 'logistics_partner' => $company->name,
                'origin_bayan_hub_id' => $origin->id, 'origin_mother_hub_id' => $local ? null : $originMother->id,
                'destination_mother_hub_id' => $local ? null : $destinationMother->id, 'destination_bayan_hub_id' => $destination->id,
                'route_hub_ids' => $order->logistics_flow_version >= 2 ? $path : null, 'same_hub_bypass' => $local,
                'current_hub_id' => null,
                'destination_bin' => $order->delivery_type === 'hub_self_pickup'
                    ? null : 'BIN: BRGY-'.strtoupper(str_replace(' ', '-', $barangay)),
            ])->save();
            if ($order->logistics_flow_version >= 2) {
                $routeHubs = LogisticsHub::whereIn('id', $path)->get()->keyBy('id');
                $order->update(['shipping_quote' => [...($order->shipping_quote ?? []), 'route' => array_map(
                    fn ($id) => $routeHubs[$id]->only(['id', 'code', 'name', 'tier']), $path), 'same_hub_bypass' => $local]]);
            }
            $delivery->setRawAttributes($parcel->getAttributes(), true)->unsetRelations();

            return $delivery;
        });
    }

    private function resolveCompanyForRoute(Order $order, Shop $shop): LogisticsCompany
    {
        $companies = LogisticsCompany::eligible()->orderBy('id')->get();

        foreach ($companies as $company) {
            $origin = $this->resolveOriginBayanHub($shop, $company);
            $destination = $order->delivery_type === 'hub_self_pickup' && $order->pickup_hub_id
                ? LogisticsHub::eligible()->whereKey($order->pickup_hub_id)
                    ->where('logistics_company_id', $company->id)
                    ->where('tier', 'local_bayan_hub')
                    ->where('allows_self_pickup', true)
                    ->first()
                : $this->resolveDestinationBayanHub(
                    $order->shipping_province ?? '',
                    $order->shipping_city ?? '',
                    $order->destination_barangay,
                    $company,
                    $order->shipping_postal_code
                );

            if ($origin && $destination && ($origin->id === $destination->id || ($this->resolveMotherHubForBayanHub($origin) && $this->resolveMotherHubForBayanHub($destination)))) {
                return $company;
            }
        }

        throw new DomainException('No logistics company can provide the complete seller-to-buyer hub route.');
    }

    /** A read-only plan. It never records receipt, assignment or parcel progress. */
    public function previewRoute(LogisticsCompany $company, Shop $shop, array $address): array
    {
        [$origin, $destination, $originMother, $destinationMother, $path, $local] = $this->resolveRoute($company, $shop, $address);
        $hubs = collect([$origin, $originMother, $destinationMother, $destination])->filter()->keyBy('id');

        return ['route' => array_map(fn ($id) => $hubs[$id]->only(['id', 'code', 'name', 'tier']), $path),
            'same_hub_bypass' => $local];
    }

    public function serves(LogisticsCompany $company, Shop $shop, array $address): bool
    {
        try {
            $this->resolveRoute($company, $shop, $address);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    /** Shared by carrier eligibility, checkout feedback and final placement. */
    private function resolveRoute(LogisticsCompany $company, Shop $shop, array $address, bool $allowLocal = true): array
    {
        if (! LogisticsCompany::eligible()->whereKey($company->id)->exists()) {
            throw new DomainException('Choose an active, approved logistics company.');
        }
        $origin = $this->resolveOriginBayanHub($shop, $company);
        $pickupHubs = $allowLocal ? LogisticsHub::readyForSelfPickup()
            : LogisticsHub::eligible()->where('tier', 'local_bayan_hub')->where('allows_self_pickup', true);
        $destination = ($address['delivery_type'] ?? 'doorstep') === 'hub_self_pickup' && ! empty($address['pickup_hub_id'])
            ? $pickupHubs->where('logistics_company_id', $company->id)->find($address['pickup_hub_id'])
            : $this->resolveDestinationBayanHub($address['shipping_province'] ?? '', $address['shipping_city'] ?? '', $address['destination_barangay'] ?? null, $company, $address['shipping_postal_code'] ?? null);
        if (! $origin || ! $destination) {
            throw new DomainException('No complete eligible hub route serves the seller and delivery address.');
        }
        if (! $this->matchesDestination($destination, $address['shipping_province'] ?? '', $address['shipping_city'] ?? '', $address['destination_barangay'] ?? null, $address['shipping_postal_code'] ?? null)) {
            throw new DomainException('Choose a pickup hub serving the stated delivery address.');
        }
        if (! $this->isContiguousRoadServiceable($origin->province, $origin->city_municipality)
            || ! $this->isContiguousRoadServiceable($destination->province, $destination->city_municipality)) {
            throw new DomainException('The selected facilities are outside the supported road network.');
        }
        $local = $allowLocal && $origin->id === $destination->id;
        $originMother = $local ? null : $this->resolveMotherHubForBayanHub($origin);
        $destinationMother = $local ? null : $this->resolveMotherHubForBayanHub($destination);
        if (! $local && (! $originMother || ! $destinationMother)) {
            throw new DomainException('The logistics company must configure both parent Mother Hubs before this route can be used.');
        }
        $path = $local ? [$origin->id] : array_values(array_unique([$origin->id, $originMother->id, $destinationMother->id, $destination->id]));

        return [$origin, $destination, $originMother, $destinationMother, $path, $local];
    }

    private function activeForwardReconciliation(Delivery $delivery): ?ShipmentLossReconciliation
    {
        $reconciled = app(ShipmentLossReconciliationService::class)->activeForParcel($delivery);

        return $reconciled?->outcome_path === 'unpaid_undelivered' ? $reconciled : null;
    }

    public function forwardSortTarget(Delivery $parcel, ?LogisticsHub $station): ?array
    {
        if (! $station
            || in_array($parcel->status, ['return_to_sender', 'return_in_transit', 'returned', 'parcel_recovery_held', 'parcel_recovery_in_transit'], true)) {
            return null;
        }

        if ($parcel->destination_bayan_hub_id === $station->id) {
            return null;
        }

        $routeHubs = $this->activeForwardReconciliation($parcel)?->forward_route_hub_ids ?? $parcel->route_hub_ids;
        $position = is_array($routeHubs) ? array_search($station->id, $routeHubs, true) : false;
        $id = $position !== false ? ($routeHubs[$position + 1] ?? null) : null;

        if (! $id) {
            $nextHop = $this->computeNextHop($parcel, $station);
            $id = $nextHop['hub_id'] ?? null;
        }

        $next = $id ? LogisticsHub::where('logistics_company_id', $parcel->logistics_company_id)->find($id) : null;

        return $next?->only(['id', 'name', 'code', 'tier']);
    }

    /**
     * Compute next hop facility and recommended bin for workstation sorting.
     */
    public function computeNextHop(Delivery $delivery, LogisticsHub $hub): ?array
    {
        if ($reconciled = $this->activeForwardReconciliation($delivery)) {
            if ($hub->logistics_company_id !== $delivery->logistics_company_id
                || in_array($delivery->status, ['return_to_sender', 'return_in_transit', 'returned', 'parcel_recovery_held', 'parcel_recovery_in_transit', 'delivered', 'customer_collected', 'cancelled'], true)) {
                return null;
            }
            $path = $reconciled->forward_route_hub_ids ?? [];
            $position = array_search($hub->id, $path, true);
            if ($position === false) {
                return null;
            }
            $nextId = $path[$position + 1] ?? null;
            if ($nextId === null && $hub->id !== $delivery->destination_bayan_hub_id) {
                return null;
            }
            $next = $nextId ? LogisticsHub::where('logistics_company_id', $delivery->logistics_company_id)->find($nextId) : $hub;

            return $next ? [
                'hub_id' => $next->id, 'hub_name' => $next->name, 'hub_code' => $next->code, 'tier' => $next->tier,
                'recommended_bin' => $nextId ? 'BIN-'.$next->code : ($delivery->destination_bin
                    ?: ($delivery->delivery_type === 'hub_self_pickup' ? 'SHELF-A1' : 'LOC-BIN-01')),
            ] : null;
        }

        // Current purchases use their retained corridor, never a newly configured topology.
        if ($delivery->order?->logistics_flow_version >= 2) {
            if ($hub->logistics_company_id !== $delivery->logistics_company_id
                || in_array($delivery->status, ['return_to_sender', 'return_in_transit', 'returned', 'parcel_recovery_held', 'parcel_recovery_in_transit', 'delivered', 'customer_collected', 'cancelled'], true)) {
                return null;
            }
            $path = $delivery->route_hub_ids ?? [];
            $position = array_search($hub->id, $path, true);
            if ($position === false) {
                return null;
            }
            $nextId = $path[$position + 1] ?? null;
            if ($nextId === null && $hub->id !== $delivery->destination_bayan_hub_id) {
                return null;
            }
            $next = $nextId ? LogisticsHub::where('logistics_company_id', $delivery->logistics_company_id)->find($nextId) : $hub;

            return $next ? [
                'hub_id' => $next->id, 'hub_name' => $next->name, 'hub_code' => $next->code, 'tier' => $next->tier,
                'recommended_bin' => $nextId ? 'BIN-'.$next->code : ($delivery->destination_bin
                    ?: ($delivery->delivery_type === 'hub_self_pickup' ? 'SHELF-A1' : 'LOC-BIN-01')),
            ] : null;
        }
        $originBayanId = $delivery->origin_bayan_hub_id;
        $originMotherId = $delivery->origin_mother_hub_id;
        $destMotherId = $delivery->destination_mother_hub_id;
        $destBayanId = $delivery->destination_bayan_hub_id;

        // If at destination_bayan_hub: it has arrived at the delivery station!
        // Destination bin is the local sorting shelf or delivery rider tote.
        if ($hub->id === $destBayanId) {
            $recommendedBin = $delivery->destination_bin;
            if (! $recommendedBin) {
                $recommendedBin = ($delivery->delivery_type === 'hub_self_pickup' || (method_exists($delivery, 'isSelfPickup') && $delivery->isSelfPickup()))
                    ? 'SHELF-A1'
                    : 'LOC-BIN-01';
            }

            return [
                'hub_id' => $hub->id,
                'hub_name' => $hub->name,
                'hub_code' => $hub->code,
                'tier' => $hub->tier,
                'recommended_bin' => $recommendedBin,
            ];
        }

        $nextHubId = null;

        if ($hub->id === $originBayanId) {
            $nextHubId = $originMotherId ?: $destBayanId;
        } elseif ($hub->id === $originMotherId) {
            $nextHubId = ($destMotherId && $destMotherId !== $originMotherId)
                ? $destMotherId
                : $destBayanId;
        } elseif ($hub->id === $destMotherId) {
            $nextHubId = $destBayanId;
        } elseif (is_array($delivery->route_hub_ids)) {
            $position = array_search($hub->id, $delivery->route_hub_ids, true);
            if ($position !== false && isset($delivery->route_hub_ids[$position + 1])) {
                $nextHubId = $delivery->route_hub_ids[$position + 1];
            }
        }

        if (! $nextHubId) {
            return null;
        }

        $nextHub = null;
        if ($nextHubId === $originMotherId && $delivery->relationLoaded('originMotherHub') && $delivery->originMotherHub) {
            $nextHub = $delivery->originMotherHub;
        } elseif ($nextHubId === $destMotherId && $delivery->relationLoaded('destinationMotherHub') && $delivery->destinationMotherHub) {
            $nextHub = $delivery->destinationMotherHub;
        } elseif ($nextHubId === $destBayanId && $delivery->relationLoaded('destinationBayanHub') && $delivery->destinationBayanHub) {
            $nextHub = $delivery->destinationBayanHub;
        }

        if (! $nextHub) {
            $nextHub = LogisticsHub::find($nextHubId);
        }

        if (! $nextHub) {
            return null;
        }

        return [
            'hub_id' => $nextHub->id,
            'hub_name' => $nextHub->name,
            'hub_code' => $nextHub->code,
            'tier' => $nextHub->tier,
            'recommended_bin' => 'BIN-'.$nextHub->code,
        ];
    }

    /**
     * Prevent parcels from being dispatched or routed backward to a hub they have already passed through.
     * If parcel is at destination_mother_hub or destination_bayan_hub, and target is origin_bayan_hub or origin_mother_hub.
     */
    public function validateBackhaul(Delivery $delivery, LogisticsHub $targetHub, ?LogisticsHub $currentHub = null): ?string
    {
        // Reverse/return logistics moves parcels backward to origin/seller by design
        if (in_array($delivery->status, [
            OrderStateMachineService::STATUS_RETURN_TO_SENDER,
            OrderStateMachineService::STATUS_RETURN_IN_TRANSIT,
            'returned',
            'returned_to_hub',
            'staged_for_seller_return',
            'returned_to_seller',
        ], true)) {
            return null;
        }

        $current = $currentHub ?? ($delivery->current_hub_id ? LogisticsHub::find($delivery->current_hub_id) : null);
        $currentHubId = $current?->id ?? $delivery->current_hub_id;

        if ($reconciled = $this->activeForwardReconciliation($delivery)) {
            $path = $reconciled->forward_route_hub_ids ?? [];
            $pos = $currentHubId ? array_search($currentHubId, $path, true) : false;
            if ($pos !== false && ($path[$pos + 1] ?? null) === $targetHub->id) {
                return null;
            }
        }

        $destMotherId = $delivery->destination_mother_hub_id;
        $destBayanId = $delivery->destination_bayan_hub_id;
        $originBayanId = $delivery->origin_bayan_hub_id;
        $originMotherId = $delivery->origin_mother_hub_id;

        // Historical same-child purchases still have an outward Mother-Hub loop.
        // Its endpoints coincide, so hub identity alone cannot establish backhaul.
        if ($delivery->order?->logistics_flow_version === 1
            && $originBayanId && $originBayanId === $destBayanId
            && $originMotherId && $originMotherId === $destMotherId) {
            $outward = $currentHubId === $originBayanId
                && $delivery->status === OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB
                && $targetHub->id === $originMotherId;
            $inward = $currentHubId === $originMotherId
                && in_array($delivery->status, [OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB, OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL], true)
                && $targetHub->id === $destBayanId;
            if ($outward || $inward) {
                return null;
            }
        }

        // Is parcel at destination_mother_hub or destination_bayan_hub?
        $isAtDestMother = false;
        if ($destMotherId) {
            if ($originMotherId && $originMotherId !== $destMotherId) {
                // Distinct mother hubs: only at destination mother hub if current facility is destination mother hub,
                // or if it has reached or passed destination mother hub with recorded checkpoint.
                $isAtDestMother = ($currentHubId === $destMotherId)
                    || ($currentHubId !== $originMotherId && $currentHubId !== $originBayanId && in_array($delivery->status, [
                        OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
                        OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
                    ], true) && $delivery->checkpoints()->where('hub_id', $destMotherId)->exists());
            } else {
                // Single mother hub in network
                $isAtDestMother = ($currentHubId === $destMotherId)
                    || in_array($delivery->status, [
                        OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
                        OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
                    ], true);
            }
        }

        $isAtDestBayan = $destBayanId && (
            $currentHubId === $destBayanId
            || in_array($delivery->status, [
                OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                OrderStateMachineService::STATUS_SORTED_TO_BARANGAY_BIN,
                OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
                OrderStateMachineService::STATUS_OUT_FOR_DELIVERY,
                OrderStateMachineService::STATUS_DELIVERED,
            ], true)
        );

        if (! $isAtDestMother && ! $isAtDestBayan) {
            return null;
        }

        // Backward hubs:
        $backwardHubIds = [];
        if ($originBayanId) {
            $backwardHubIds[] = $originBayanId;
        }
        if ($originMotherId && ($originMotherId !== $destMotherId || $isAtDestBayan)) {
            $backwardHubIds[] = $originMotherId;
        }

        if (! in_array($targetHub->id, array_unique($backwardHubIds), true)) {
            return null;
        }

        // Compute next authorized facility
        $effectiveHub = $current ?? ($isAtDestBayan ? $delivery->destinationBayanHub : $delivery->destinationMotherHub);
        if (! $effectiveHub && $currentHubId) {
            $effectiveHub = LogisticsHub::find($currentHubId);
        }
        $nextHop = $effectiveHub ? $this->computeNextHop($delivery, $effectiveHub) : null;
        $nextHopName = $nextHop['hub_name']
            ?? ($delivery->destinationBayanHub?->name ?? 'Destination Bayan Hub');

        return "Routing Violation: Parcel tracking {$delivery->tracking_number} cannot be routed backward to {$targetHub->name}. Next authorized facility is {$nextHopName}.";
    }

    public function assertNoBackhaul(Delivery $delivery, LogisticsHub $targetHub, ?LogisticsHub $currentHub = null): void
    {
        if ($error = $this->validateBackhaul($delivery, $targetHub, $currentHub)) {
            throw ValidationException::withMessages(['routing' => $error]);
        }
    }

    private function unambiguousHub(Collection $hubs): ?LogisticsHub
    {
        if ($hubs->count() > 1) {
            throw new DomainException('More than one child hub covers this address. The logistics company must correct its service coverage.');
        }

        return $hubs->first();
    }

    private function cityKey(string $place): string
    {
        return preg_replace('/^(?:city|municipality) of | (?:city|municipality)$/', '', $this->normalizePlace(Str::before($place, ',')));
    }

    private function matchesDestination(LogisticsHub $hub, string $province, string $city, ?string $barangay, ?string $postalCode = null): bool
    {

        return $this->normalizePlace($province) !== '' && $this->cityKey($city) !== ''
            && $this->normalizePlace((string) $hub->province) === $this->normalizePlace($province)
            && (! empty($hub->postal_codes)
                ? $postalCode !== null && in_array($postalCode, $hub->postal_codes, true)
                : $this->cityKey((string) $hub->city_municipality) === $this->cityKey($city))
            && (empty($hub->coverage_barangays) || ($barangay && $hub->coversBarangay($barangay)));
    }

    private function normalizePlace(string $value): string
    {
        return (string) Str::of(Str::ascii($value))
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }

    /**
     * Generate dynamic scan prompts for mobile PWA handlers.
     */
    public function getDynamicScanPrompt(Delivery $delivery, ?LogisticsHub $currentHub = null): array
    {
        $hub = $currentHub ?? $delivery->currentHub;
        $status = strtolower($delivery->status);

        if ($hub?->id === $delivery->destination_bayan_hub_id && $status === 'delivery_failed') {
            $attempt = DeliveryAttempt::where('delivery_id', $delivery->id)->latest('attempt_number')->first();
            if ($delivery->current_hub_id === null) {
                return ['action' => 'RECEIVE_FAILED_DELIVERY', 'prompt' => 'RECEIVE FAILED DELIVERY FROM RIDER',
                    'next_status' => 'delivery_failed', 'color' => 'amber', 'attempt_reference' => $attempt?->reference];
            }
            $approval = $attempt ? app(DeliveryRecoveryService::class)->event($attempt, 'retry_approved') : null;
            if ($approval && ! $approval->retry_at->isFuture()) {
                return ['action' => 'RELEASE_APPROVED_RETRY', 'prompt' => 'RELEASE APPROVED RETRY FOR BARANGAY SORTING',
                    'next_status' => 'arrived_at_destination_hub', 'color' => 'amber', 'attempt_reference' => $attempt->reference];
            }

            return ['action' => 'AWAIT_RETRY_REVIEW', 'prompt' => 'WAIT FOR COMPANY RETRY REVIEW OR THE APPROVED DATE',
                'next_status' => 'delivery_failed', 'color' => 'amber'];
        }

        if ($hub && in_array($status, ['return_to_sender', 'return_in_transit'], true)) {
            $returns = app(DeliveryReturnService::class);
            try {
                $routeReference = $returns->activeRouteReference($delivery);
                if ($status === 'return_in_transit') {
                    $custody = DeliveryCheckpoint::lastCustody($delivery);
                    $manifest = LogisticsManifest::find($custody['manifest_id'] ?? null);
                    if ($manifest?->direction === 'return' && $manifest->destination_hub_id === $hub->id) {
                        return ['action' => 'RECEIVE_RETURN_MANIFEST', 'prompt' => 'RECEIVE ON THE ACTUAL RETURN MANIFEST',
                            'next_status' => 'return_to_sender', 'color' => 'amber'];
                    }
                } elseif ($delivery->current_hub_id === $hub->id) {
                    if ($returns->stagedForSeller($delivery)) {
                        return ['action' => 'AWAIT_SELLER_RECEIPT', 'prompt' => 'WAIT FOR THE OWNING SELLER TO SCAN RECEIPT',
                            'next_status' => $status, 'color' => 'amber'];
                    }
                    if ($returns->readyForSeller($delivery)) {
                        return ['action' => 'STAGE_SELLER_RETURN', 'prompt' => 'STAGE THE ORIGINAL PARCEL FOR SELLER RECEIPT',
                            'next_status' => $status, 'color' => 'amber', 'route_reference' => $routeReference];
                    }
                    $returns->nextHub($delivery);

                    return ['action' => 'DISPATCH_RETURN_MANIFEST', 'prompt' => 'LOAD THE NEXT LEG ON A RETURN MANIFEST',
                        'next_status' => 'return_in_transit', 'color' => 'amber'];
                }
            } catch (DomainException $error) {
                return ['action' => 'AWAIT_RETURN_REVIEW', 'prompt' => $error->getMessage(), 'next_status' => $status, 'color' => 'amber'];
            }
        }

        if ($hub?->id === $delivery->destination_bayan_hub_id && $status === 'ready_for_hub_pickup') {
            $claim = PickupClaim::where('delivery_id', $delivery->id)->first();
            if ($claim?->status === 'expired') {
                return ['action' => 'START_EXPIRED_PICKUP_RETURN', 'prompt' => 'START THE RECORDED EXPIRED PICKUP RETURN',
                    'next_status' => 'return_to_sender', 'color' => 'amber', 'claim_reference' => $claim->reference];
            }

            return ['action' => 'AWAIT_BUYER_PICKUP', 'prompt' => 'WAIT FOR SECURE BUYER VERIFICATION AT THE COUNTER',
                'next_status' => $status, 'color' => 'green'];
        }

        // 1. Destination Bayan Hub inbound custody must be recorded before sorting.
        if ($hub && $hub->id === $delivery->destination_bayan_hub_id && $status === OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB) {
            return [
                'action' => 'RECEIVE_AT_DESTINATION_HUB',
                'prompt' => "RECEIVE AT DESTINATION HUB: [{$hub->code}]",
                'next_status' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                'color' => 'green',
            ];
        }

        // 2. A parcel already received at the destination is staged or awaits a separate sort action.
        if ($hub && $hub->id === $delivery->destination_bayan_hub_id && $status === OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB) {
            if ($delivery->isSelfPickup()) {
                return [
                    'action' => 'STAGE_FOR_PICKUP',
                    'prompt' => 'STAGE AT COUNTER: SHELF-PICKUP-BAY-A (NOTIFY BUYER)',
                    'next_status' => OrderStateMachineService::STATUS_READY_FOR_HUB_PICKUP,
                    'color' => 'green',
                ];
            }

            $binCode = $delivery->destination_bin ?? 'BIN: GENERAL-DELIVERY';

            return [
                'action' => 'AWAIT_BARANGAY_SORT',
                'prompt' => "SORT REQUIRED: {$binCode}",
                'next_status' => OrderStateMachineService::STATUS_ARRIVED_AT_DEST_HUB,
                'color' => 'emerald',
            ];
        }

        // 3. Origin Bayan Hub receives custody from the pickup rider.
        if ($hub && $hub->id === $delivery->origin_bayan_hub_id && in_array($status, ['assigned', 'assigned_pickup', 'picked_up'], true)) {
            return [
                'action' => 'RECEIVE_FROM_PICKUP_RIDER',
                'prompt' => "RECEIVE AT ORIGIN HUB: [{$hub->code}]",
                'next_status' => OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB,
                'color' => 'blue',
            ];
        }

        $reconciledForward = $this->activeForwardReconciliation($delivery);

        // 4. A second scan at the origin (or a released off-route Bayan Hub) loads the parcel onto a feeder manifest.
        if ($hub && $status === OrderStateMachineService::STATUS_ARRIVED_AT_ORIGIN_HUB
            && ($hub->id === $delivery->origin_bayan_hub_id || ($reconciledForward && ! $hub->isMotherHub() && $hub->id === $delivery->current_hub_id && in_array($hub->id, $reconciledForward->forward_route_hub_ids ?? [], true)))) {
            $nextHop = $this->computeNextHop($delivery, $hub);
            $motherHubCode = $nextHop['hub_code'] ?? ($delivery->originMotherHub?->code ?? 'MOTHER-HUB');

            return [
                'action' => 'DISPATCH_TO_FEEDER',
                'prompt' => "LOAD TO SHUTTLE: L300-FEEDER -> [{$motherHubCode}]",
                'next_status' => OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB,
                'color' => 'blue',
            ];
        }

        // 5. Mother Hub inbound and outbound scans are separate custody events.
        if ($hub && ($hub->id === $delivery->origin_mother_hub_id || $hub->id === $delivery->destination_mother_hub_id || $hub->isMotherHub())) {
            if ($status === OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB) {
                return [
                    'action' => 'RECEIVE_AT_MOTHER_HUB',
                    'prompt' => "RECEIVE AT MOTHER HUB: [{$hub->code}]",
                    'next_status' => OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB,
                    'color' => 'indigo',
                ];
            }

            if ($status === OrderStateMachineService::STATUS_ARRIVED_AT_MOTHER_HUB) {
                return [
                    'action' => 'SORT_TO_LINE_HAUL',
                    'prompt' => 'SORT TO DESTINATION LINE-HAUL CAGE',
                    'next_status' => OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL,
                    'color' => 'indigo',
                ];
            }

            if ($status !== OrderStateMachineService::STATUS_SORTED_TO_LINE_HAUL) {
                return [
                    'action' => 'INSPECT_WAYBILL',
                    'prompt' => "PARCEL: {$delivery->tracking_number} | STATUS: ".strtoupper($status),
                    'next_status' => $status,
                    'color' => 'gray',
                ];
            }

            $nextHop = $this->computeNextHop($delivery, $hub);
            $nextHub = $nextHop ? LogisticsHub::find($nextHop['hub_id']) : null;
            $nextMother = $nextHub ? $nextHub->isMotherHub() : ($hub->id === $delivery->origin_mother_hub_id && $delivery->origin_mother_hub_id !== $delivery->destination_mother_hub_id);
            $destHubCode = $nextHop['hub_code'] ?? ($nextMother ? ($delivery->destinationMotherHub?->code ?? 'DEST-MOTHER-HUB') : ($delivery->destinationBayanHub?->code ?? 'DEST-BAYAN-HUB'));

            return [
                'action' => 'DISPATCH_LINE_HAUL',
                'prompt' => "LOAD TO DESTINATION MANIFEST -> [{$destHubCode}]",
                'next_status' => $nextMother ? OrderStateMachineService::STATUS_IN_TRANSIT_TO_MOTHER_HUB : OrderStateMachineService::STATUS_IN_TRANSIT_TO_DEST_HUB,
                'color' => 'indigo',
            ];
        }

        return [
            'action' => 'INSPECT_WAYBILL',
            'prompt' => "PARCEL: {$delivery->tracking_number} | STATUS: ".strtoupper($status),
            'next_status' => $status,
            'color' => 'gray',
        ];
    }
}
